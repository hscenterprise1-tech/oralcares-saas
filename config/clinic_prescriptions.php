<?php

/*
|--------------------------------------------------------------------------
| Prescription vocabulary
|--------------------------------------------------------------------------
|
| The choices Prescription Studio offers for each line. A doctor may still
| type their own wording (e.g. "1-0-1" or "alternate days"); these are the
| quick picks, printed as their label.
|
*/

return [

    'frequencies' => [
        'OD' => 'Once a day',
        'BD' => 'Twice a day',
        'TDS' => 'Three times a day',
        'QID' => 'Four times a day',
        'HS' => 'At bedtime',
        'SOS' => 'When required',
        'STAT' => 'Immediately, once',
        'WEEKLY' => 'Once a week',
    ],

    'timings' => [
        'after_food' => 'After food',
        'before_food' => 'Before food',
        'with_food' => 'With food',
        'empty_stomach' => 'On an empty stomach',
        'bedtime' => 'At bedtime',
    ],

    'routes' => [
        'oral' => 'Oral',
        'topical' => 'Apply locally',
        'mouthwash' => 'Rinse / gargle',
        'sublingual' => 'Under the tongue',
        'inhalation' => 'Inhale',
        'eye' => 'Eye',
        'ear' => 'Ear',
        'nasal' => 'Nasal',
        'injection' => 'Injection',
    ],

    'forms' => ['Tablet', 'Capsule', 'Syrup', 'Suspension', 'Gel', 'Ointment', 'Cream', 'Mouthwash', 'Drops', 'Spray', 'Injection', 'Powder', 'Lozenge', 'Toothpaste'],

    /** Lines on one prescription. */
    'max_items' => 30,
];
