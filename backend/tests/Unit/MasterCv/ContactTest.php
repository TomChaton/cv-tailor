<?php

namespace Tests\Unit\MasterCv;

use App\MasterCv\Contact;
use App\MasterCv\PresentationPolicy;
use PHPUnit\Framework\TestCase;

class ContactTest extends TestCase
{
    public function test_the_name_is_the_documents_title_and_is_not_a_header_field(): void
    {
        $contact = $this->contact();

        $this->assertSame('Jordan Avery', $contact->name());
        $this->assertArrayNotHasKey('Name', $contact->only(PresentationPolicy::DEFAULT_CONTACT_FIELDS));
    }

    public function test_the_policys_order_is_the_order_the_header_prints(): void
    {
        $this->assertSame(
            ['Email', 'Location'],
            array_keys($this->contact()->only(['Email', 'Location'])),
        );
    }

    /**
     * A contact section is the source of application-form answers as much as
     * it is a CV header. The policy says which of it belongs on the page.
     */
    public function test_a_field_the_policy_does_not_name_is_left_off(): void
    {
        $fields = $this->contact()->only(PresentationPolicy::DEFAULT_CONTACT_FIELDS);

        $this->assertSame(['Location', 'Phone', 'Email', 'LinkedIn profile'], array_keys($fields));
        $this->assertArrayNotHasKey('Full postal address (use only if a form requires it)', $fields);
    }

    /**
     * Labels are matched the way section headings are, so a policy written
     * against one CV does not have to be reworded for the next.
     */
    public function test_a_label_is_matched_forgivingly_of_its_exact_wording(): void
    {
        $contact = $this->contact();

        $this->assertSame(
            ['LinkedIn profile' => 'https://www.linkedin.com/in/example/'],
            $contact->only(['LinkedIn']),
        );
        $this->assertSame(['Phone' => '07700 900123'], $contact->only(['Phone number']));
        $this->assertSame(
            ['Full postal address (use only if a form requires it)' => '14 Beech Grove'],
            $contact->only(['Full postal address']),
        );
    }

    /**
     * The policy is a preference about a header, not an assertion about the
     * file: a CV with no phone number is a fact about its author.
     */
    public function test_a_named_field_the_cv_does_not_have_is_skipped(): void
    {
        $contact = new Contact(['Name' => 'Robin Sparrow', 'Email' => 'robin.sparrow@example.com']);

        $this->assertSame(
            ['Email' => 'robin.sparrow@example.com'],
            $contact->only(PresentationPolicy::DEFAULT_CONTACT_FIELDS),
        );
    }

    public function test_a_field_with_no_value_is_not_printed(): void
    {
        $contact = new Contact(['Location' => '  ', 'Email' => 'robin.sparrow@example.com']);

        $this->assertSame(['Email'], array_keys($contact->only(['Location', 'Email'])));
    }

    public function test_a_cv_with_no_name_field_has_no_name(): void
    {
        $this->assertNull((new Contact(['Email' => 'robin.sparrow@example.com']))->name());
    }

    private function contact(): Contact
    {
        return new Contact([
            'Name' => 'Jordan Avery',
            'Location' => 'Didsbury, Manchester, UK',
            'Phone' => '07700 900123',
            'Email' => 'jordan.avery@example.com',
            'Full postal address (use only if a form requires it)' => '14 Beech Grove',
            'LinkedIn profile' => 'https://www.linkedin.com/in/example/',
            'Right to work' => 'British citizen',
        ]);
    }
}
