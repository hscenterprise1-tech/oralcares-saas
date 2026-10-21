<?php

use App\Services\Onboarding\Sections;

/*
|--------------------------------------------------------------------------
| Clinic onboarding
|--------------------------------------------------------------------------
|
| Templates decide which sections a clinic (or a staff member) goes through.
| A template is picked from the specialty set CRM provisioned, never chosen
| by the owner. A new specialty is a new template here, built from the
| existing sections plus any section of its own; the engine does not change.
|
| Sections are registered once and implement OnboardingSection. A template
| lists section keys in order; a template's `options` are handed to its
| sections (which service catalogue to suggest, what a treatment resource
| is called).
|
*/

return [

    'owner_invitation_days' => 7,

    /*
     * Specialty (as sold in CRM) → template. Two or more specialties use the
     * multi-specialty template.
     */
    'specialty_templates' => [
        'dental' => 'dental',
        'orthodontics' => 'orthodontics',
        'physiotherapy' => 'physiotherapy',
        'general_hospital' => 'general_medicine',
        'general_medicine' => 'general_medicine',
        'multi_specialty' => 'multi_specialty',
    ],

    'default_template' => 'general_medicine',

    'sections' => [
        'clinic_identity' => Sections\ClinicIdentitySection::class,
        'primary_branch' => Sections\PrimaryBranchSection::class,
        'working_hours' => Sections\WorkingHoursSection::class,
        'clinical_resources' => Sections\ClinicalResourcesSection::class,
        'services' => Sections\ServicesSection::class,
        'appointments' => Sections\AppointmentsSection::class,
        'billing_reception' => Sections\BillingReceptionSection::class,
        'communication' => Sections\CommunicationSection::class,
        'staff_invitations' => Sections\StaffInvitationsSection::class,
        'review' => Sections\ReviewSection::class,

        // Staff onboarding (an invited staff member setting up their own profile).
        'my_profile' => Sections\Staff\MyProfileSection::class,
        'my_professional' => Sections\Staff\MyProfessionalSection::class,
        'my_services' => Sections\Staff\MyServicesSection::class,
        'my_schedule' => Sections\Staff\MyScheduleSection::class,
        'my_documents' => Sections\Staff\MyDocumentsSection::class,
        'my_review' => Sections\ReviewSection::class,
    ],

    'templates' => [
        'dental' => [
            'label' => 'Dental clinic',
            'kind' => 'clinic',
            'version' => 1,
            'sections' => ['clinic_identity', 'primary_branch', 'working_hours', 'clinical_resources', 'services', 'appointments', 'billing_reception', 'communication', 'staff_invitations', 'review'],
            'options' => [
                'catalogues' => ['dental'],
                'resource' => ['type' => 'chair', 'label' => 'Dental chairs', 'singular' => 'Chair', 'default' => 2],
            ],
        ],
        'orthodontics' => [
            'label' => 'Orthodontic clinic',
            'kind' => 'clinic',
            'version' => 1,
            'sections' => ['clinic_identity', 'primary_branch', 'working_hours', 'clinical_resources', 'services', 'appointments', 'billing_reception', 'communication', 'staff_invitations', 'review'],
            'options' => [
                'catalogues' => ['orthodontics', 'dental'],
                'resource' => ['type' => 'chair', 'label' => 'Dental chairs', 'singular' => 'Chair', 'default' => 2],
            ],
        ],
        'physiotherapy' => [
            'label' => 'Physiotherapy clinic',
            'kind' => 'clinic',
            'version' => 1,
            'sections' => ['clinic_identity', 'primary_branch', 'working_hours', 'clinical_resources', 'services', 'appointments', 'billing_reception', 'communication', 'staff_invitations', 'review'],
            'options' => [
                'catalogues' => ['physiotherapy'],
                'resource' => ['type' => 'treatment_bed', 'label' => 'Treatment beds', 'singular' => 'Bed', 'default' => 3],
            ],
        ],
        'general_medicine' => [
            'label' => 'General clinic',
            'kind' => 'clinic',
            'version' => 1,
            'sections' => ['clinic_identity', 'primary_branch', 'working_hours', 'services', 'appointments', 'billing_reception', 'communication', 'staff_invitations', 'review'],
            'options' => [
                'catalogues' => ['general_medicine'],
            ],
        ],
        'multi_specialty' => [
            'label' => 'Multi-specialty clinic',
            'kind' => 'clinic',
            'version' => 1,
            'sections' => ['clinic_identity', 'primary_branch', 'working_hours', 'clinical_resources', 'services', 'appointments', 'billing_reception', 'communication', 'staff_invitations', 'review'],
            'options' => [
                // Filled per clinic from its specialties; this is the fallback.
                'catalogues' => ['general_medicine'],
                'resource' => ['type' => 'consultation', 'label' => 'Consultation rooms', 'singular' => 'Room', 'default' => 2],
            ],
        ],

        'staff_clinical' => [
            'label' => 'Practitioner profile',
            'kind' => 'staff',
            'version' => 1,
            'sections' => ['my_profile', 'my_professional', 'my_services', 'my_schedule', 'my_documents', 'my_review'],
            'options' => [],
        ],
        'staff_general' => [
            'label' => 'Staff profile',
            'kind' => 'staff',
            'version' => 1,
            'sections' => ['my_profile', 'my_documents', 'my_review'],
            'options' => [],
        ],
    ],

    /*
     * Suggested services per specialty. The owner confirms each one, sets its
     * price and duration, or switches it off; nothing is priced without them.
     */
    'catalogues' => [
        'dental' => [
            ['code' => 'DEN-CONS', 'name' => 'Dental consultation', 'category' => 'General Dentistry', 'duration' => 20, 'price' => 500],
            ['code' => 'DEN-SCAL', 'name' => 'Scaling & polishing', 'category' => 'General Dentistry', 'duration' => 45, 'price' => 1500],
            ['code' => 'DEN-FILL', 'name' => 'Composite filling', 'category' => 'Restorative', 'duration' => 45, 'price' => 2000],
            ['code' => 'DEN-RCT', 'name' => 'Root canal treatment', 'category' => 'Endodontics', 'duration' => 60, 'price' => 6000],
            ['code' => 'DEN-EXT', 'name' => 'Tooth extraction', 'category' => 'Oral Surgery', 'duration' => 30, 'price' => 1500],
            ['code' => 'DEN-XRAY', 'name' => 'Dental X-ray (IOPA)', 'category' => 'Diagnostics', 'duration' => 10, 'price' => 300],
        ],
        'orthodontics' => [
            ['code' => 'ORT-CONS', 'name' => 'Orthodontic consultation', 'category' => 'Orthodontics', 'duration' => 30, 'price' => 800],
            ['code' => 'ORT-BRAC', 'name' => 'Braces adjustment visit', 'category' => 'Orthodontics', 'duration' => 30, 'price' => 1500],
            ['code' => 'ORT-ALGN', 'name' => 'Aligner review', 'category' => 'Orthodontics', 'duration' => 20, 'price' => 1000],
        ],
        'physiotherapy' => [
            ['code' => 'PHY-ASMT', 'name' => 'Physiotherapy assessment', 'category' => 'Assessment', 'duration' => 45, 'price' => 800],
            ['code' => 'PHY-SESS', 'name' => 'Physiotherapy session', 'category' => 'Treatment', 'duration' => 45, 'price' => 600],
            ['code' => 'PHY-ELEC', 'name' => 'Electrotherapy', 'category' => 'Treatment', 'duration' => 30, 'price' => 500],
            ['code' => 'PHY-POST', 'name' => 'Post-operative rehabilitation', 'category' => 'Rehabilitation', 'duration' => 60, 'price' => 900],
        ],
        'general_medicine' => [
            ['code' => 'GEN-CONS', 'name' => 'Consultation', 'category' => 'General Medicine', 'duration' => 15, 'price' => 500],
            ['code' => 'GEN-FUP', 'name' => 'Follow-up consultation', 'category' => 'General Medicine', 'duration' => 10, 'price' => 300],
            ['code' => 'GEN-DRES', 'name' => 'Dressing', 'category' => 'Procedures', 'duration' => 15, 'price' => 200],
            ['code' => 'GEN-INJ', 'name' => 'Injection / immunisation', 'category' => 'Procedures', 'duration' => 10, 'price' => 150],
        ],
    ],

    /*
     * Roles an owner may give when inviting staff from onboarding. Owner
     * itself is never assignable; ownership comes only from provisioning.
     */
    'invitable_roles' => ['Clinic Admin', 'Doctor', 'Receptionist', 'Billing Staff'],

    'payment_methods' => ['cash', 'upi', 'card', 'bank_transfer', 'insurance'],
];
