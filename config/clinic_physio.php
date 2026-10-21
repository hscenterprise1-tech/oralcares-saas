<?php

/*
|--------------------------------------------------------------------------
| Physiotherapy vocabulary (registry extensions physio_assessment,
| soap_notes, exercise_plan, session_packages)
|--------------------------------------------------------------------------
|
| Quick picks for the assessment and session forms. Free text is still
| accepted where clinicians need their own wording.
|
*/

return [

    'body_regions' => [
        'neck', 'upper_back', 'lower_back', 'shoulder', 'elbow', 'wrist', 'hand',
        'hip', 'knee', 'ankle', 'foot', 'thorax', 'pelvis', 'head', 'jaw',
    ],

    'sides' => ['left' => 'Left', 'right' => 'Right', 'both' => 'Both', 'central' => 'Central'],

    'pain_types' => ['aching', 'sharp', 'burning', 'stiffness', 'numbness', 'tingling', 'throbbing', 'radiating'],

    /** Oxford scale. */
    'strength_grades' => [0, 1, 2, 3, 4, 5],

    /** Outcome measures and their maximum score. */
    'functional_measures' => [
        'ODI' => ['label' => 'Oswestry Disability Index', 'max' => 100],
        'NDI' => ['label' => 'Neck Disability Index', 'max' => 50],
        'LEFS' => ['label' => 'Lower Extremity Functional Scale', 'max' => 80],
        'DASH' => ['label' => 'DASH (arm, shoulder, hand)', 'max' => 100],
        'PSFS' => ['label' => 'Patient-Specific Functional Scale', 'max' => 10],
    ],

    'modalities' => [
        'manual_therapy' => 'Manual therapy',
        'exercise_therapy' => 'Exercise therapy',
        'tens' => 'TENS',
        'ift' => 'IFT',
        'ultrasound' => 'Ultrasound',
        'hot_pack' => 'Hot pack',
        'cold_pack' => 'Cold pack',
        'traction' => 'Traction',
        'laser' => 'Laser',
        'dry_needling' => 'Dry needling',
        'taping' => 'Taping',
        'shockwave' => 'Shockwave',
    ],
];
