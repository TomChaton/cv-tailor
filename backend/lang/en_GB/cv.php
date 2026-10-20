<?php

// The words the CV template supplies of its own. Everything else on the page
// is the master CV's content, which comes through the generation pipeline and
// never from a catalogue.

return [
    'profile' => 'Profile',
    'skills' => 'Key skills',
    'experience' => 'Experience',
    'projects' => 'Independent projects',
    'awards' => 'Awards',
    'interests' => 'Interests',

    // Punctuation the Word file needs stated, where the PDF's stylesheet
    // supplies it in CSS. It is read as part of a line, so it lives here with
    // the rest of the words.
    'contact_field' => ':label: :value',
    'contact_separator' => ' · ',
    'role_title' => ':employer — :title',
];
