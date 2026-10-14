<?php

namespace App\Extraction;

use App\Llm\LlmClient;
use App\Llm\LlmPurpose;
use App\Llm\LlmRequest;
use App\Llm\SystemBlock;
use App\Models\Posting;
use Illuminate\Support\Facades\Date;

/**
 * Asks a model what one captured page advertises, and records the answer.
 *
 * Finding the job description on an arbitrary page is the hard part, and it is
 * a judgement rather than a parse. A model reading the page's text does it for
 * every board at once, where an extractor per site is a maintenance burden
 * multiplied by the number of boards anybody browses.
 *
 * **It is a live call, not a batch.** The postings list is unusable until
 * extraction has run and the owner is looking at it, so a wait measured in
 * hours would buy a discount on the one call in the system nobody can wait for.
 *
 * Nothing the model returns is an instruction. Its description is tailored
 * against exactly as a job description already is, and the fields it reports
 * are data about a job.
 */
class ExtractPosting
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ExtractionInstructions $instructions,
        private readonly PageBrief $brief,
        private readonly ExtractionSchema $schema,
        private readonly JobPostingJsonLd $jsonLd,
        private readonly ExtractionEvents $events,
        private readonly int $maxTokens,
    ) {}

    public function handle(Posting $posting): Posting
    {
        // A duplicate dispatch is the ordinary consequence of a retried job,
        // and a second reading of the same page would be paid for twice to
        // reach the same answer. Already extracted is already answered.
        if ($posting->extracted_at !== null) {
            return $posting;
        }

        $response = $this->llm->complete(new LlmRequest(
            purpose: LlmPurpose::Extraction,
            system: [SystemBlock::text($this->instructions->text())],
            prompt: $this->brief->text($posting),
            maxTokens: $this->maxTokens,
            schema: $this->schema->definition(),
        ));

        $fields = ExtractedFields::merge(
            $this->answer($posting, $response->text),
            $this->jsonLd->fields($posting->json_ld),
        );

        $posting->fill([...$fields->values, 'extracted_at' => Date::now()])->save();

        $this->events->extracted($posting, $response, $fields);

        return $posting;
    }

    /**
     * The model's answer, read as the five fields the schema asks for and
     * nothing else. A value that is not a string is a value the page did not
     * state.
     *
     * @return array<string, string|null>
     */
    private function answer(Posting $posting, string $text): array
    {
        $decoded = json_decode($text, associative: true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw ExtractionOutputIsUnusable::notAnObject($posting);
        }

        return [
            'title' => FieldText::column($decoded['title'] ?? null),
            'company' => FieldText::column($decoded['company'] ?? null),
            'location' => FieldText::column($decoded['location'] ?? null),
            'salary_text' => FieldText::column($decoded['salary_text'] ?? null),
            'description' => FieldText::body($decoded['description'] ?? null),
        ];
    }
}
