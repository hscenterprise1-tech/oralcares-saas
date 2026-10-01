<?php

/*
|--------------------------------------------------------------------------
| Clinic staff catalogues
|--------------------------------------------------------------------------
|
| The starting catalogues for staff management. Nothing here is a rule the
| code enforces by name: staff types are labels with a "clinical" flag
| (clinical staff get a practitioner profile and can be booked), and the
| document types and checklist below only seed each clinic's own editable
| records the first time staff documents are opened. Clinics change their
| catalogue and checklist from there; new entries here reach new clinics.
|
*/

return [

    // key => [label, clinical]. Clinical staff are bookable providers.
    'staff_types' => [
        'doctor' => ['label' => 'Doctor', 'clinical' => true],
        'dentist' => ['label' => 'Dentist', 'clinical' => true],
        'physiotherapist' => ['label' => 'Physiotherapist', 'clinical' => true],
        'therapist' => ['label' => 'Therapist', 'clinical' => true],
        'nurse' => ['label' => 'Nurse', 'clinical' => false],
        'assistant' => ['label' => 'Clinical assistant', 'clinical' => false],
        'lab_staff' => ['label' => 'Lab staff', 'clinical' => false],
        'receptionist' => ['label' => 'Receptionist', 'clinical' => false],
        'billing' => ['label' => 'Billing', 'clinical' => false],
        'accountant' => ['label' => 'Accountant', 'clinical' => false],
        'admin' => ['label' => 'Administrator', 'clinical' => false],
        'other' => ['label' => 'Other staff', 'clinical' => false],
    ],

    'degree_types' => [
        'bachelor' => 'Bachelor\'s degree',
        'master' => 'Master\'s degree',
        'doctorate' => 'Doctorate',
        'diploma' => 'Diploma',
        'fellowship' => 'Fellowship',
        'certification' => 'Certification',
        'other' => 'Other',
    ],

    'registration_types' => [
        'permanent' => 'Permanent',
        'provisional' => 'Provisional',
        'temporary' => 'Temporary',
        'additional_qualification' => 'Additional qualification',
    ],

    'leave_types' => [
        'casual' => 'Casual leave',
        'sick' => 'Sick leave',
        'earned' => 'Earned leave',
        'conference' => 'Conference / training',
        'personal' => 'Personal',
        'other' => 'Other',
    ],

    // Specialty suggestions come from the global specialty catalogue
    // (`healthcare_specialty_masters`), narrowed to the categories that fit
    // each clinic specialty module.
    'specialty_categories' => [
        'dental' => ['dental'],
        'physiotherapy' => ['rehabilitation'],
    ],

    // Days before expiry at which a document reads "Expires soon".
    'expiring_soon_days' => 30,

    /*
     | Default document types. `audience` is who the type is offered for
     | (practitioner, staff or all); `default` is the starting requirement
     | for clinical and for other staff types (required, optional,
     | not_applicable).
     */
    'document_types' => [
        ['key' => 'government_id', 'name' => 'Government ID', 'audience' => 'all', 'has_expiry' => false, 'default' => ['clinical' => 'required', 'staff' => 'required']],
        ['key' => 'address_proof', 'name' => 'Address proof', 'audience' => 'all', 'has_expiry' => false, 'default' => ['clinical' => 'optional', 'staff' => 'optional']],
        ['key' => 'degree_certificate', 'name' => 'Degree certificate', 'audience' => 'practitioner', 'has_expiry' => false, 'default' => ['clinical' => 'required', 'staff' => 'not_applicable']],
        ['key' => 'postgraduate_certificate', 'name' => 'Postgraduate certificate', 'audience' => 'practitioner', 'has_expiry' => false, 'default' => ['clinical' => 'optional', 'staff' => 'not_applicable']],
        ['key' => 'specialization_certificate', 'name' => 'Specialization certificate', 'audience' => 'practitioner', 'has_expiry' => false, 'default' => ['clinical' => 'optional', 'staff' => 'not_applicable']],
        ['key' => 'fellowship_certificate', 'name' => 'Fellowship certificate', 'audience' => 'practitioner', 'has_expiry' => false, 'default' => ['clinical' => 'optional', 'staff' => 'not_applicable']],
        ['key' => 'registration_certificate', 'name' => 'Professional registration certificate', 'audience' => 'practitioner', 'has_expiry' => true, 'default' => ['clinical' => 'required', 'staff' => 'not_applicable']],
        ['key' => 'registration_renewal', 'name' => 'Registration renewal', 'audience' => 'practitioner', 'has_expiry' => true, 'default' => ['clinical' => 'optional', 'staff' => 'not_applicable']],
        ['key' => 'qualification_certificate', 'name' => 'Qualification certificate', 'audience' => 'staff', 'has_expiry' => false, 'default' => ['clinical' => 'not_applicable', 'staff' => 'optional']],
        ['key' => 'experience_certificate', 'name' => 'Experience certificate', 'audience' => 'all', 'has_expiry' => false, 'default' => ['clinical' => 'optional', 'staff' => 'optional']],
        ['key' => 'employment_document', 'name' => 'Employment / joining document', 'audience' => 'all', 'has_expiry' => false, 'default' => ['clinical' => 'optional', 'staff' => 'optional']],
        ['key' => 'training_certificate', 'name' => 'Training / certification', 'audience' => 'all', 'has_expiry' => true, 'default' => ['clinical' => 'optional', 'staff' => 'optional']],
        ['key' => 'other', 'name' => 'Other document', 'audience' => 'all', 'has_expiry' => false, 'default' => ['clinical' => 'optional', 'staff' => 'optional']],
    ],

];
