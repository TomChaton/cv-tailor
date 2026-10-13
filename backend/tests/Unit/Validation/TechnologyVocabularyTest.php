<?php

namespace Tests\Unit\Validation;

use App\Validation\Technology;
use App\Validation\TechnologyVocabulary;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class TechnologyVocabularyTest extends TestCase
{
    use MasterCvFixtures;

    public function test_a_version_is_not_part_of_a_technologys_name(): void
    {
        $this->assertContains('PHP', $this->names());
        $this->assertContains('Elasticsearch', $this->names());
        $this->assertContains('Zend Framework', $this->names());
        $this->assertNotContains('PHP 8.x', $this->names());
    }

    /**
     * One row can name several technologies: `Linux: Debian 12, Ubuntu 22.04,
     * CentOS 7` is four.
     */
    public function test_a_row_that_lists_a_family_names_its_members_too(): void
    {
        foreach (['Linux', 'Debian', 'Ubuntu', 'CentOS'] as $name) {
            $this->assertContains($name, $this->names());
        }
    }

    /**
     * A parenthetical that pins a version is another name for the same
     * technology; one that lists aspects names its parts, and taking "logs"
     * for a technology would make the word a claim.
     */
    public function test_only_a_parenthetical_that_pins_a_version_becomes_an_alias(): void
    {
        $this->assertSame(['Slim Framework', 'Slim'], $this->aliasesOf('Slim Framework'));
        $this->assertSame(['AWS CloudWatch'], $this->aliasesOf('AWS CloudWatch'));
    }

    public function test_it_finds_the_technologies_a_sentence_names(): void
    {
        $detected = array_map(
            static fn (Technology $technology): string => $technology->name,
            $this->vocabulary()->detect('Moved stop search from Elasticsearch 7 to 8 on PostgreSQL.'),
        );

        $this->assertContains('Elasticsearch', $detected);
        $this->assertContains('PostgreSQL', $detected);
        $this->assertNotContains('SQL', $detected);
    }

    public function test_a_name_inside_a_longer_word_is_not_a_mention(): void
    {
        $detected = array_map(
            static fn (Technology $technology): string => $technology->name,
            $this->vocabulary()->detect('Replaced Apache Solr with MySQL full-text search.'),
        );

        $this->assertContains('MySQL', $detected);
        $this->assertNotContains('SQL', $detected);
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(static fn (Technology $technology): string => $technology->name, $this->vocabulary()->all());
    }

    /**
     * @return list<string>
     */
    private function aliasesOf(string $name): array
    {
        foreach ($this->vocabulary()->all() as $technology) {
            if ($technology->name === $name) {
                return $technology->aliases;
            }
        }

        return [];
    }

    private function vocabulary(): TechnologyVocabulary
    {
        return new TechnologyVocabulary($this->parseFixture()->allSkills());
    }
}
