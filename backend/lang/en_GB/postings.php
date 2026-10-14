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

    // What capture did not find. Capture omits rather than guesses, and a
    // blank cell cannot be told from a value that is blank.
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

    'pdf_filename' => 'cv-:posting.pdf',

];
