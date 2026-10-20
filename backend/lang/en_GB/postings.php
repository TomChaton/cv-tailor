<?php

/*
 * The words on the postings list and on a posting's own screen.
 *
 * "Jobs" on screen, `postings` in code: the owner browses job boards and
 * thinks in jobs, and the schema cannot call them that because Laravel's queue
 * owns the table `jobs`. The two are deliberately not made to agree.
 *
 * A heading names its column and stops. Nothing here says what a status means
 * or what an action will do next.
 */

return [

    'label' => 'Job',
    'label_plural' => 'Jobs',

    // What neither the page nor extraction gave. Neither guesses, and a blank
    // cell cannot be told from a value that is blank.
    'absent' => '—',

    // A posting's own facts. The list heads its columns with these and the
    // posting's screen labels its entries with them, because they are labels
    // for the same facts and one word stated twice eventually becomes two.
    'columns' => [
        'title' => 'Title',
        'source' => 'Source',
        'captured' => 'Captured',
        'status' => 'Status',
        'company' => 'Company',
        'location' => 'Location',
        'salary' => 'Salary',
        'cover_letter' => 'Cover letter',
    ],

    // Between the facts that qualify a title on the list — the company, the
    // location and the salary. Punctuation a person reads, so it lives here
    // and not in the resource.
    'qualifier_separator' => ' · ',

    'statuses' => [
        'captured' => 'Captured',
        'wanted' => 'Wanted',
        'queued' => 'Queued',
        'ready' => 'Ready',
        'reviewed' => 'Reviewed',
        'applied' => 'Applied',
        'discarded' => 'Discarded',
    ],

    'actions' => [
        'want' => 'Want',
        'discard' => 'Discard',
        // Two files, named for what each is: the icon says they are
        // downloads, so neither label needs to say it twice.
        'download' => 'PDF',
        'download_word' => 'Word',
        'mark_reviewed' => 'Mark reviewed',
        'mark_applied' => 'Mark applied',
    ],

    // The headings on a posting's screen. Each names what is under it and
    // stops: what the checklist is for, or how a finding was arrived at, is
    // not something the owner reads on the way past it.
    'sections' => [
        'description' => 'Job description',
        'cv' => 'CV',
        'requirements' => 'Requirements',
        'findings' => 'Findings',
        'notes' => 'Review notes',
        'gaps' => 'Gaps',
        'judgement_calls' => 'Judgement calls',
        'promotions' => 'Promotions',
    ],

    // Said only where capture found it. The owner needs it before trusting
    // what was extracted, which is a question asked while reading one posting.
    'hidden_text' => 'This listing carries text hidden from a reader.',

    'cover_letter_requested' => 'Requested',
    'cover_letter_not_requested' => 'Not requested',

    'priorities' => [
        'must' => 'Must have',
        'nice' => 'Nice to have',
    ],

    'requirement_statuses' => [
        'met' => 'Met',
        'partial' => 'Partial',
        'gap' => 'Gap',
    ],

    'severities' => [
        'blocking' => 'Blocking',
        'advisory' => 'Advisory',
    ],

    // How much of a role a CV shows, for the note that says one was promoted.
    'role_modes' => [
        'full' => 'in full',
        'condensed' => 'condensed',
        'omitted' => 'left out',
    ],

    // A promotion the model explains. The reason after it is the model's own
    // words; this line is only what it is a reason for.
    'promotion' => ':role, :to rather than :from.',

    // Nothing moves a posting off `discarded`, so discarding asks first. The
    // heading names the act and stops: the row the owner clicked is on screen
    // behind the modal and already says which job it is.
    'discard_confirmation' => [
        'heading' => 'Discard this job?',
        'confirm' => 'Discard',
    ],

    // The name the downloaded CV reaches an employer under. The company's,
    // where capture found one; the posting's id where it did not, because an
    // id is the only other thing that tells two files apart. The copy to send
    // and the copy to edit are the same name under a different extension.
    'cv_filename_for_company' => 'cv-:company.:extension',
    'cv_filename' => 'cv-:posting.:extension',

];
