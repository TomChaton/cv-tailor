<?php

/*
 * What the owner reads on a revision that could not be produced.
 *
 * It names what happened, once. The owner regenerates from the posting, and
 * that is in front of them already.
 */

return [

    'failed' => [
        'errored' => 'The model could not be reached, after :attempts attempts.',
        'expired' => 'The model did not answer in time, after :attempts attempts.',
        'canceled' => 'The request was cancelled, after :attempts attempts.',
        'unusable' => 'The model\'s answer arrived incomplete, after :attempts attempts.',
    ],

];
