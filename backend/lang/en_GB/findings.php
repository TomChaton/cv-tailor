<?php

/*
 * What the owner reads about a validation finding.
 *
 * A finding names the thing that is wrong, once. It does not explain the check
 * that raised it, and it does not say what to do next: the owner regenerates
 * or edits, and both are in front of them already.
 */

return [

    'ids_resolve' => [
        'missing' => ':id is not in this master CV.',
        'unknown' => ':id is marked TODO in the master CV.',
    ],

    'rewordings_carry_support' => [
        'summary_without_support' => 'The summary cites nothing in the master CV.',
        'item_without_support' => 'The rewording of :id cites nothing in the master CV.',
        'missing_support' => ':id is cited as support and is not in this master CV.',
        'unknown_support' => ':id is cited as support and is marked TODO in the master CV.',
    ],

    'technology_to_role' => [
        'not_in_stack' => ':term is not in :role\'s tech stack.',
    ],

    'versions' => [
        'not_recorded_for_role' => 'The master CV does not record :term :version for :role.',
        'not_recorded' => 'The master CV does not record :term :version.',
    ],

    'do_not_claim' => [
        'term' => ':term is in the master CV\'s "Do not claim" section.',
    ],

    'independent_projects_stay_independent' => [
        'under_employer' => ':id is from :source, an independent project, and is shown under an employer.',
        'under_project' => ':id is from :source and is shown under an independent project.',
        'not_an_employer' => ':id is an independent project, not an employer.',
        'not_a_project' => ':id is an employer, not an independent project.',
    ],

    'role_mode_not_demoted' => [
        'demoted' => ':role is shown as :returned where the presentation policy sets :default.',
    ],

    'promotion_explained' => [
        'unexplained' => ':role is shown as :returned rather than :default with no reason given.',
    ],

    'word_budget' => [
        'over' => 'The selection runs to :words words against a target of :budget.',
    ],

];
