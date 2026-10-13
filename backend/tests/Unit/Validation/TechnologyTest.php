<?php

namespace Tests\Unit\Validation;

use App\Validation\Technology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TechnologyTest extends TestCase
{
    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('versions')]
    public function test_it_reads_the_versions_a_text_pins_to_a_technology(
        string $name,
        string $text,
        array $expected,
    ): void {
        $this->assertSame($expected, (new Technology($name, [$name]))->versionsIn($text));
    }

    /**
     * @return list<array{0: string, 1: string, 2: list<string>}>
     */
    public static function versions(): array
    {
        return [
            ['PHP', 'PHP 8.x (8.3)', ['8.x', '8.3']],
            ['PHP', 'PHP 7.4 to 8.3 modernisation.', ['7.4', '8.3']],
            ['Elasticsearch', 'from Elasticsearch 7 to 8 with no downtime', ['7', '8']],
            ['MySQL', 'MySQL (5.0, 5.6, 5.7)', ['5.0', '5.6', '5.7']],
            ['Vue', 'Vue 3 (operations console)', ['3']],
            ['Redis', 'Redis', []],
            // A version on something else on the line is not this
            // technology's.
            ['Laminas', 'Laminas (legacy platform), PostgreSQL 15', []],
        ];
    }

    public function test_an_entry_is_matched_as_words_rather_than_as_a_string(): void
    {
        $slim = new Technology('Slim Framework', ['Slim Framework', 'Slim']);

        $this->assertTrue($slim->isCoveredBy(['Slim 4 (dispatch API)']));
        $this->assertFalse($slim->isCoveredBy(['Symfony 4.4', 'RabbitMQ']));
    }

    public function test_a_name_inside_a_longer_word_is_not_a_mention(): void
    {
        $sql = new Technology('SQL', ['SQL']);

        $this->assertTrue($sql->occursIn('Wrote the SQL by hand.'));
        $this->assertFalse($sql->occursIn('Moved it onto PostgreSQL.'));
    }

    public function test_a_name_may_end_in_punctuation(): void
    {
        $this->assertTrue((new Technology('C++', ['C++']))->occursIn('Wrote the indexer in C++.'));
        $this->assertFalse((new Technology('C++', ['C++']))->occursIn('Wrote it against C++11.'));
    }
}
