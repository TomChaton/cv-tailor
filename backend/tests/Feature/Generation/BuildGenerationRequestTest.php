<?php

namespace Tests\Feature\Generation;

use App\Generation\BuildGenerationRequest;
use App\Generation\GenerationIsRefused;
use App\Generation\PromptVersion;
use App\Generation\SelectableIds;
use App\Llm\LlmPurpose;
use App\Llm\LlmRequest;
use App\Llm\SystemBlock;
use App\MasterCv\PresentationPolicy;
use App\Models\MasterCvVersion;
use App\Models\Revision;
use App\Observability\EventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class BuildGenerationRequestTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_it_orders_the_blocks_from_most_to_least_stable(): void
    {
        $request = $this->build($this->revision());

        $this->assertSame(LlmPurpose::Generation, $request->purpose);
        $this->assertCount(2, $request->system);
        $this->assertStringContainsString(
            "You are tailoring one person's CV",
            $request->system[0]->text,
        );
        $this->assertStringContainsString('The master CV follows.', $request->system[1]->text);
        $this->assertStringContainsString('<job_posting>', $request->prompt);
    }

    /**
     * The master CV is the large stable prefix of every request for a version,
     * so it is the block worth caching and the instructions in front of it are
     * the prefix the breakpoint sits on.
     */
    public function test_the_cache_breakpoint_goes_on_the_master_cv(): void
    {
        $request = $this->build($this->revision());

        $this->assertFalse($request->system[0]->cacheable);
        $this->assertTrue($request->system[1]->cacheable);
    }

    public function test_the_schema_constrains_the_answer_to_the_cvs_own_ids(): void
    {
        $request = $this->build($this->revision());

        $schema = $request->schema;
        $this->assertIsArray($schema);

        $defs = $schema['$defs'] ?? null;
        $this->assertIsArray($defs);

        $ids = SelectableIds::from($this->parseFixture());

        $enums = [
            'item_id' => $ids->items,
            'skill_id' => $ids->skills,
            'employment_role_id' => $ids->employmentRoles,
            'independent_project_id' => $ids->independentProjects,
        ];

        foreach ($enums as $def => $expected) {
            $enum = $defs[$def] ?? null;
            $this->assertIsArray($enum);
            $this->assertSame($expected, $enum['enum'] ?? null);
        }
    }

    /**
     * The provider refuses a schema carrying `maxItems`, so a cap the CV names
     * reaches the request as an instruction and never as a schema keyword.
     */
    public function test_a_cv_that_names_a_cap_still_gets_a_schema_without_one(): void
    {
        $keySkills = $this->keySkills($this->build($this->revisionFrom($this->fixtureCapping(5))));

        $this->assertSame(['type', 'description', 'items'], array_keys($keySkills));
        $this->assertSame('array', $keySkills['type']);
    }

    /**
     * The cap travels in the document, so it is read out of one here rather
     * than handed to the request by a policy nothing wrote.
     */
    public function test_the_key_skills_cap_comes_from_the_cvs_front_matter(): void
    {
        $request = $this->build($this->revisionFrom($this->fixtureCapping(5)));

        $this->assertStringContainsString('Key skills: at most 5', $request->prompt);
    }

    public function test_a_cv_that_names_no_cap_is_told_the_default(): void
    {
        $this->assertStringContainsString(
            'Key skills: at most '.PresentationPolicy::DEFAULT_MAX_KEY_SKILLS,
            $this->build($this->revision())->prompt,
        );
    }

    public function test_it_asks_for_the_configured_room_to_answer_in(): void
    {
        config(['cv.llm.max_output_tokens.generation' => 4321]);

        $this->assertSame(4321, $this->build($this->revision())->maxTokens);
    }

    /**
     * The whole request, not just the CV block: a contact detail that reached
     * the model through the metadata would be as bad as one that reached it
     * through the facts.
     */
    public function test_the_contact_section_appears_nowhere_in_the_request(): void
    {
        $request = $this->build($this->revision());
        $whole = implode("\n", [
            ...array_map(static fn (SystemBlock $block): string => $block->text, $request->system),
            $request->prompt,
            json_encode($request->schema, JSON_THROW_ON_ERROR),
        ]);

        $contact = $this->parseFixture()->contact->values();

        $this->assertNotSame([], $contact);

        foreach ($contact as $value) {
            $this->assertStringNotContainsString($value, $whole);
        }
    }

    /**
     * The batch lifecycle keys a result by the custom ID it carries back,
     * because the provider returns a batch's results in any order.
     */
    public function test_a_batch_request_is_tagged_with_the_revision_it_is_for(): void
    {
        $revision = $this->revision();

        $batch = $this->builder()->batchRequestFor($revision);

        $this->assertSame((string) $revision->id, $batch->customId);
        $this->assertInstanceOf(LlmRequest::class, $batch->request);
    }

    /**
     * A revision that waited in `pending` across a prompt change is generated
     * by the new prompt, so the new version is what it was generated with.
     */
    public function test_the_revision_records_the_prompt_it_is_built_from(): void
    {
        $revision = $this->revision(['prompt_version' => 'something-older']);

        $this->build($revision);

        $this->assertSame(PromptVersion::CURRENT, $revision->fresh()?->prompt_version);
    }

    public function test_it_logs_the_sizes_of_what_it_built(): void
    {
        $revision = $this->revision();
        $expected = SelectableIds::from($this->parseFixture())->count();

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('prompt.built', Mockery::on(function (array $context) use ($revision, $expected): bool {
                $this->assertSame($revision->id, $context['revision_id']);
                $this->assertSame($revision->posting_id, $context['posting_id']);
                $this->assertSame($revision->master_cv_version_id, $context['master_cv_version_id']);
                $this->assertSame(PromptVersion::CURRENT, $context['prompt_version']);
                $this->assertSame($expected, $context['enum_count']);
                $this->assertSame(
                    PresentationPolicy::DEFAULT_MAX_KEY_SKILLS,
                    $context['key_skills_cap'],
                );
                $this->assertGreaterThan(0, $context['instructions_chars']);
                $this->assertGreaterThan(0, $context['master_cv_chars']);
                $this->assertGreaterThan(0, $context['brief_chars']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->build($revision);
    }

    /**
     * Generating from a CV with duplicate or missing IDs produces a selection
     * nobody can resolve back to a fact.
     */
    public function test_it_refuses_a_cv_that_fails_lint(): void
    {
        $revision = $this->revision([], 'master-cv/broken-duplicate-id.md');

        $this->expectException(GenerationIsRefused::class);

        $this->build($revision);
    }

    public function test_it_logs_a_refusal_as_a_warning(): void
    {
        $revision = $this->revision([], 'master-cv/broken-missing-id.md');

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('generation.refused', Mockery::on(function (array $context) use ($revision): bool {
                $this->assertSame($revision->id, $context['revision_id']);
                $this->assertSame($revision->master_cv_version_id, $context['master_cv_version_id']);
                $this->assertGreaterThan(0, $context['problems']);

                $byCheck = $context['by_check'] ?? null;
                $this->assertIsArray($byCheck);
                $this->assertArrayHasKey('ids', $byCheck);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        try {
            $this->build($revision);
        } catch (GenerationIsRefused) {
            // The log line is what this test is about.
        }
    }

    public function test_a_refused_revision_keeps_the_prompt_it_already_carried(): void
    {
        $revision = $this->revision(['prompt_version' => 'something-older'], 'master-cv/broken-missing-id.md');

        try {
            $this->build($revision);
        } catch (GenerationIsRefused) {
            // Asserted below.
        }

        $this->assertSame('something-older', $revision->fresh()?->prompt_version);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function revision(array $attributes = [], string $fixture = 'master-cv.md'): Revision
    {
        return $this->revisionFrom($this->fixture($fixture), $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function revisionFrom(string $content, array $attributes = []): Revision
    {
        return Revision::factory()
            ->for(MasterCvVersion::factory()->content($content))
            ->create($attributes);
    }

    /**
     * The fictional CV with one more line in its presentation policy.
     */
    private function fixtureCapping(int $cap): string
    {
        $content = str_replace(
            "presentation:\n",
            "presentation:\n  max_key_skills: ".$cap."\n",
            $this->fixture(),
        );

        $this->assertNotSame($this->fixture(), $content);

        return $content;
    }

    /**
     * The `key_skills` node of the schema the built request carries.
     *
     * @return array<string, mixed>
     */
    private function keySkills(LlmRequest $request): array
    {
        $schema = $request->schema;
        $this->assertIsArray($schema);

        $properties = $schema['properties'] ?? null;
        $this->assertIsArray($properties);

        $keySkills = $properties['key_skills'] ?? null;
        $this->assertIsArray($keySkills);

        /** @var array<string, mixed> $keySkills */
        return $keySkills;
    }

    private function build(Revision $revision): LlmRequest
    {
        return $this->builder()->handle($revision);
    }

    private function builder(): BuildGenerationRequest
    {
        return $this->app->make(BuildGenerationRequest::class);
    }
}
