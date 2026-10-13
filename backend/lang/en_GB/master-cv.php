<?php

/*
 * The words on the master CV page.
 *
 * Lint's own problem and note messages are not here: they come from the linter,
 * written for the CV's author, and the page prints them as it is given them.
 *
 * The check headings are keyed on the check's name, which is also the name its
 * problem counts are logged against. Add a check and add its heading.
 */

return [

    'title' => 'Master CV',

    'path' => 'Path',
    'modified' => 'Modified',
    'version' => 'Version',

    'unreadable' => [
        'not_found' => 'Not found.',
        'directory' => 'A directory, not a file.',
        'not_regular_file' => 'Not a regular file.',
        'not_readable' => 'Not readable.',
    ],

    'counts' => [
        'roles' => 'Roles',
        'projects' => 'Independent projects',
        'achievements' => 'Achievements',
        'sub_bullets' => 'Sub-bullets',
        'skills' => 'Skills',
    ],

    'problems' => 'Problems',
    'passes' => 'Fit to generate from.',
    'notes' => 'Notes',
    'finding' => 'line :line — :message',

    'checks' => [
        'sections' => 'Sections',
        'ids' => 'IDs',
        'skills_index_references' => 'Skills index',
        'tech_stack_coverage' => 'Tech stacks',
        'block_labels' => 'Block labels',
    ],

    'actions' => [
        'recheck' => 'Re-check',
        'add_ids' => 'Add missing IDs',
    ],

    'download_filename' => 'master-cv-with-ids.md',

];
