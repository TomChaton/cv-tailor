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

    'markdown' => 'Markdown',
    'saved' => 'Saved',
    'version' => 'Version',

    'counts' => [
        'roles' => 'Roles',
        'projects' => 'Independent projects',
        'achievements' => 'Achievements',
        'sub_bullets' => 'Sub-bullets',
        'skills_written' => 'Skills written',
        'skills_derived' => 'Skills derived',
    ],

    'problems' => 'Problems',
    'passes' => 'Fit to generate from.',
    'notes' => 'Notes',
    'finding' => 'line :line — :message',
    'where_used' => 'Where used',

    'checks' => [
        'sections' => 'Sections',
        'ids' => 'IDs',
        'skills_index_references' => 'Skills index',
        'skill_names' => 'Skill names',
        'block_labels' => 'Block labels',
    ],

    'actions' => [
        'save' => 'Save',
        'download' => 'Download',
        'add_ids' => 'Add missing IDs',
        'where_used' => 'Choose where used',
    ],

    'download_filename' => 'master-cv.md',
    'download_with_ids_filename' => 'master-cv-with-ids.md',

];
