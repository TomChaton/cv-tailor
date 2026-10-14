<?php

namespace Tests\Unit\Extraction;

use App\Extraction\FieldText;
use App\Extraction\JobPostingJsonLd;
use PHPUnit\Framework\TestCase;

class JobPostingJsonLdTest extends TestCase
{
    public function test_a_page_that_published_nothing_states_nothing(): void
    {
        $this->assertSame([], $this->read(null));
    }

    public function test_it_reads_the_four_fields_a_job_posting_states_as_data(): void
    {
        $fields = $this->read([
            '@type' => 'JobPosting',
            'title' => 'Senior PHP Engineer',
            'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Northwind Systems'],
            'jobLocation' => [
                '@type' => 'Place',
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressLocality' => 'Leeds',
                    'addressRegion' => 'West Yorkshire',
                    'addressCountry' => 'GB',
                ],
            ],
            'baseSalary' => [
                '@type' => 'MonetaryAmount',
                'currency' => 'GBP',
                'value' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => 65000,
                    'maxValue' => 78000,
                    'unitText' => 'YEAR',
                ],
            ],
        ]);

        $this->assertSame('Senior PHP Engineer', $fields['title']);
        $this->assertSame('Northwind Systems', $fields['company']);
        $this->assertSame('Leeds, West Yorkshire, GB', $fields['location']);
        $this->assertSame('GBP 65000-78000 YEAR', $fields['salary_text']);
    }

    public function test_a_field_the_object_omits_is_null_rather_than_inferred(): void
    {
        $fields = $this->read(['@type' => 'JobPosting', 'title' => 'Platform Engineer']);

        $this->assertSame('Platform Engineer', $fields['title']);
        $this->assertNull($fields['company']);
        $this->assertNull($fields['location']);
        $this->assertNull($fields['salary_text']);
    }

    public function test_it_falls_back_to_name_where_a_page_titles_the_posting_that_way(): void
    {
        $this->assertSame('Platform Engineer', $this->read([
            '@type' => 'JobPosting',
            'name' => 'Platform Engineer',
        ])['title']);
    }

    public function test_an_organisation_written_as_a_plain_string_is_read_as_one(): void
    {
        $this->assertSame('Northwind Systems', $this->read([
            'hiringOrganization' => 'Northwind Systems',
        ])['company']);
    }

    public function test_the_first_of_several_locations_is_the_one_stored(): void
    {
        $this->assertSame('Leeds', $this->read([
            'jobLocation' => [
                ['@type' => 'Place', 'address' => ['addressLocality' => 'Leeds']],
                ['@type' => 'Place', 'address' => ['addressLocality' => 'Hull']],
            ],
        ])['location']);
    }

    public function test_a_place_with_no_address_falls_back_to_its_name(): void
    {
        $this->assertSame('Remote, UK', $this->read([
            'jobLocation' => ['@type' => 'Place', 'name' => 'Remote, UK'],
        ])['location']);
    }

    /**
     * A page that states a salary as a sentence has already written the text,
     * so it is kept as it stands.
     */
    public function test_a_salary_written_as_a_sentence_is_kept_as_written(): void
    {
        $this->assertSame('£65,000 to £78,000 depending on experience', $this->read([
            'baseSalary' => '£65,000 to £78,000 depending on experience',
        ])['salary_text']);
    }

    public function test_a_single_salary_figure_is_joined_without_inventing_a_range(): void
    {
        $this->assertSame('GBP 70000 YEAR', $this->read([
            'baseSalary' => [
                'currency' => 'GBP',
                'value' => ['value' => 70000, 'unitText' => 'YEAR'],
            ],
        ])['salary_text']);
    }

    /**
     * Whatever a page publishes has to fit the column that holds it, and a long
     * value is cut rather than losing the posting.
     */
    public function test_a_stated_value_longer_than_its_column_is_cut(): void
    {
        $location = $this->read([
            'jobLocation' => ['@type' => 'Place', 'name' => str_repeat('Leeds ', 100)],
        ])['location'];

        $this->assertSame(FieldText::LIMIT, mb_strlen((string) $location));
    }

    public function test_a_field_published_empty_is_the_same_as_one_not_published(): void
    {
        $fields = $this->read([
            'title' => '   ',
            'hiringOrganization' => ['name' => ''],
            'baseSalary' => ['currency' => null, 'value' => null],
        ]);

        $this->assertNull($fields['title']);
        $this->assertNull($fields['company']);
        $this->assertNull($fields['salary_text']);
    }

    /**
     * @param  array<string, mixed>|null  $jsonLd
     * @return array<string, string|null>
     */
    private function read(?array $jsonLd): array
    {
        return (new JobPostingJsonLd)->fields($jsonLd);
    }
}
