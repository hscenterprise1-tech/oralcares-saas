<?php

/*
|--------------------------------------------------------------------------
| Default consent types
|--------------------------------------------------------------------------
|
| The consents every clinic starts with. A clinic changes the wording (each
| change is a new version), switches types off, or adds its own. A type with
| a `specialty` is offered only to clinics that bought that specialty.
|
*/

return [
    'defaults' => [
        'treatment' => ['label' => 'General treatment', 'specialty' => null,
            'text' => 'I agree to the examination and treatment explained to me. I have been able to ask questions and understand that I may refuse or stop treatment at any time.'],
        'procedure' => ['label' => 'Procedure', 'specialty' => null,
            'text' => 'The procedure, its purpose, its usual risks and the alternatives have been explained to me, and I agree to it.'],
        'communication' => ['label' => 'Communication', 'specialty' => null,
            'text' => 'I agree that the clinic may contact me about my appointments, bills and care by phone, SMS, e-mail or WhatsApp.'],
        'whatsapp' => ['label' => 'WhatsApp messages', 'specialty' => null,
            'text' => 'I agree to receive appointment, billing and care reminders from the clinic on WhatsApp. I can stop them at any time.'],
        'data_processing' => ['label' => 'Use of my health records', 'specialty' => null,
            'text' => 'I agree that the clinic keeps and uses my health records to provide my care, as described in its privacy notice.'],
        'photography' => ['label' => 'Clinical photographs', 'specialty' => null,
            'text' => 'I agree that the clinic may take photographs and images for my clinical record.'],
        'telehealth' => ['label' => 'Remote consultation', 'specialty' => null,
            'text' => 'I agree to be consulted remotely and understand its limits compared with an in-person visit.'],

        'dental_treatment' => ['label' => 'Dental treatment', 'specialty' => 'dental',
            'text' => 'The dental treatment plan, its stages, costs, usual risks and alternatives have been explained to me, and I agree to it.'],
        'extraction' => ['label' => 'Tooth extraction', 'specialty' => 'dental',
            'text' => 'The extraction, its usual risks (pain, swelling, bleeding, infection, numbness) and the alternatives have been explained to me, and I agree to it.'],
        'oral_surgery' => ['label' => 'Oral surgery', 'specialty' => 'dental',
            'text' => 'The surgical procedure, anaesthesia, its risks, recovery and alternatives have been explained to me, and I agree to it.'],

        'physio_treatment' => ['label' => 'Physiotherapy treatment', 'specialty' => 'physiotherapy',
            'text' => 'The physiotherapy assessment and treatment, including manual therapy and modalities, have been explained to me, and I agree to them.'],
        'package_terms' => ['label' => 'Session package terms', 'specialty' => 'physiotherapy',
            'text' => 'I understand the package\'s sessions, validity period and the clinic\'s policy on missed sessions and refunds.'],
        'exercise_therapy' => ['label' => 'Exercise therapy', 'specialty' => 'physiotherapy',
            'text' => 'I agree to the exercise programme prescribed for me and will tell my therapist of any pain or discomfort.'],
    ],
];
