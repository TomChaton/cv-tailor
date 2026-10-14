<?php

/*
 * The words on the postings list.
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

    'columns' => [
        'title' => 'Title',
        'company' => 'Company',
        'location' => 'Location',
        'salary' => 'Salary',
        'source' => 'Source',
        'captured' => 'Captured',
        'status' => 'Status',
        'hidden_text' => 'Hidden text',
    ],

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
        'download' => 'Download',
    ],

    // Nothing moves a posting off `discarded`, so discarding asks first. The
    // heading names the act and stops: the row the owner clicked is on screen
    // behind the modal and already says which job it is.
    'discard_confirmation' => [
        'heading' => 'Discard this job?',
        'confirm' => 'Discard',
    ],

    // The name the downloaded CV reaches an employer under. The company's,
    // where capture found one; the posting's id where it did not, because an
    // id is the only other thing that tells two files apart.
    'pdf_filename_for_company' => 'cv-:company.pdf',
    'pdf_filename' => 'cv-:posting.pdf',

];
