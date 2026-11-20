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
            // Diagnostics
            ['code' => 'DEN-CONS', 'name' => 'Dental consultation', 'category' => 'Diagnostics', 'duration' => 20, 'price' => 500],
            ['code' => 'DEN-FUP', 'name' => 'Follow-up consultation', 'category' => 'Diagnostics', 'duration' => 15, 'price' => 300],
            ['code' => 'DEN-XRAY', 'name' => 'Dental X-ray (IOPA)', 'category' => 'Diagnostics', 'duration' => 10, 'price' => 300],
            ['code' => 'DEN-OPG', 'name' => 'OPG (panoramic X-ray)', 'category' => 'Diagnostics', 'duration' => 15, 'price' => 800],
            ['code' => 'DEN-CBCT', 'name' => 'CBCT scan', 'category' => 'Diagnostics', 'duration' => 20, 'price' => 3500],
            ['code' => 'DEN-CEPH', 'name' => 'Cephalogram', 'category' => 'Diagnostics', 'duration' => 15, 'price' => 800],
            ['code' => 'DEN-PHOTO', 'name' => 'Intra-oral photographs', 'category' => 'Diagnostics', 'duration' => 10, 'price' => 300],
            ['code' => 'DEN-STUDY', 'name' => 'Study models', 'category' => 'Diagnostics', 'duration' => 20, 'price' => 1000],
            // Preventive
            ['code' => 'DEN-SCAL', 'name' => 'Scaling & polishing', 'category' => 'Preventive', 'duration' => 45, 'price' => 1500],
            ['code' => 'DEN-FLUO', 'name' => 'Fluoride application', 'category' => 'Preventive', 'duration' => 15, 'price' => 600],
            ['code' => 'DEN-SEAL', 'name' => 'Pit & fissure sealant (per tooth)', 'category' => 'Preventive', 'duration' => 15, 'price' => 700],
            ['code' => 'DEN-ORAL', 'name' => 'Oral hygiene instruction', 'category' => 'Preventive', 'duration' => 15, 'price' => 300],
            // Restorative
            ['code' => 'DEN-FILL', 'name' => 'Composite filling', 'category' => 'Restorative', 'duration' => 45, 'price' => 2000],
            ['code' => 'DEN-GIC', 'name' => 'Glass ionomer filling', 'category' => 'Restorative', 'duration' => 30, 'price' => 1200],
            ['code' => 'DEN-INLAY', 'name' => 'Inlay / onlay', 'category' => 'Restorative', 'duration' => 60, 'price' => 7000],
            ['code' => 'DEN-POST', 'name' => 'Post & core build-up', 'category' => 'Restorative', 'duration' => 45, 'price' => 2500],
            ['code' => 'DEN-DESENS', 'name' => 'Desensitisation', 'category' => 'Restorative', 'duration' => 20, 'price' => 800],
            // Endodontics
            ['code' => 'DEN-RCT', 'name' => 'Root canal treatment', 'category' => 'Endodontics', 'duration' => 60, 'price' => 6000],
            ['code' => 'DEN-RCT-RE', 'name' => 'Root canal re-treatment', 'category' => 'Endodontics', 'duration' => 75, 'price' => 9000],
            ['code' => 'DEN-PULPOT', 'name' => 'Pulpotomy', 'category' => 'Endodontics', 'duration' => 30, 'price' => 2500],
            ['code' => 'DEN-APICO', 'name' => 'Apicoectomy', 'category' => 'Endodontics', 'duration' => 60, 'price' => 8000],
            // Periodontics
            ['code' => 'DEN-SRP', 'name' => 'Scaling and root planing (per quadrant)', 'category' => 'Periodontics', 'duration' => 45, 'price' => 2000],
            ['code' => 'DEN-GING', 'name' => 'Gingivectomy / gingivoplasty', 'category' => 'Periodontics', 'duration' => 45, 'price' => 4000],
            ['code' => 'DEN-FLAP', 'name' => 'Flap surgery (per quadrant)', 'category' => 'Periodontics', 'duration' => 60, 'price' => 8000],
            ['code' => 'DEN-SPLINT', 'name' => 'Periodontal splinting', 'category' => 'Periodontics', 'duration' => 45, 'price' => 3500],
            // Prosthodontics
            ['code' => 'DEN-CRN-PFM', 'name' => 'Crown - porcelain fused to metal', 'category' => 'Prosthodontics', 'duration' => 45, 'price' => 4000],
            ['code' => 'DEN-CRN-ZIR', 'name' => 'Crown - zirconia', 'category' => 'Prosthodontics', 'duration' => 45, 'price' => 9000],
            ['code' => 'DEN-BRIDGE', 'name' => 'Fixed bridge (per unit)', 'category' => 'Prosthodontics', 'duration' => 45, 'price' => 5000],
            ['code' => 'DEN-VENEER', 'name' => 'Veneer (per tooth)', 'category' => 'Prosthodontics', 'duration' => 60, 'price' => 12000],
            ['code' => 'DEN-RPD', 'name' => 'Removable partial denture', 'category' => 'Prosthodontics', 'duration' => 45, 'price' => 8000],
            ['code' => 'DEN-CD', 'name' => 'Complete denture (per arch)', 'category' => 'Prosthodontics', 'duration' => 45, 'price' => 12000],
            ['code' => 'DEN-DENTREP', 'name' => 'Denture repair / reline', 'category' => 'Prosthodontics', 'duration' => 30, 'price' => 1500],
            // Implantology
            ['code' => 'DEN-IMP', 'name' => 'Dental implant (fixture)', 'category' => 'Implantology', 'duration' => 60, 'price' => 30000],
            ['code' => 'DEN-IMP-CRN', 'name' => 'Implant abutment and crown', 'category' => 'Implantology', 'duration' => 45, 'price' => 15000],
            ['code' => 'DEN-SINUS', 'name' => 'Sinus lift', 'category' => 'Implantology', 'duration' => 90, 'price' => 25000],
            ['code' => 'DEN-BONE', 'name' => 'Bone graft', 'category' => 'Implantology', 'duration' => 60, 'price' => 15000],
            // Oral surgery
            ['code' => 'DEN-EXT', 'name' => 'Tooth extraction', 'category' => 'Oral Surgery', 'duration' => 30, 'price' => 1500],
            ['code' => 'DEN-SURG', 'name' => 'Surgical extraction', 'category' => 'Oral Surgery', 'duration' => 45, 'price' => 4000],
            ['code' => 'DEN-WISDOM', 'name' => 'Impacted wisdom tooth removal', 'category' => 'Oral Surgery', 'duration' => 60, 'price' => 7000],
            ['code' => 'DEN-ABSC', 'name' => 'Abscess drainage', 'category' => 'Oral Surgery', 'duration' => 30, 'price' => 1500],
            ['code' => 'DEN-BIOPSY', 'name' => 'Oral biopsy', 'category' => 'Oral Surgery', 'duration' => 30, 'price' => 3000],
            ['code' => 'DEN-FRENEC', 'name' => 'Frenectomy', 'category' => 'Oral Surgery', 'duration' => 30, 'price' => 3500],
            // Orthodontics
            ['code' => 'DEN-BRACE-M', 'name' => 'Metal braces (full treatment)', 'category' => 'Orthodontics', 'duration' => 60, 'price' => 35000],
            ['code' => 'DEN-BRACE-C', 'name' => 'Ceramic braces (full treatment)', 'category' => 'Orthodontics', 'duration' => 60, 'price' => 55000],
            ['code' => 'DEN-ALIGN', 'name' => 'Clear aligners (full treatment)', 'category' => 'Orthodontics', 'duration' => 45, 'price' => 120000],
            ['code' => 'DEN-RETAIN', 'name' => 'Retainer', 'category' => 'Orthodontics', 'duration' => 30, 'price' => 4000],
            // Paediatric
            ['code' => 'DEN-PED-FILL', 'name' => 'Paediatric filling', 'category' => 'Paediatric', 'duration' => 30, 'price' => 1200],
            ['code' => 'DEN-PED-EXT', 'name' => 'Milk tooth extraction', 'category' => 'Paediatric', 'duration' => 20, 'price' => 800],
            ['code' => 'DEN-PED-CRN', 'name' => 'Stainless steel crown', 'category' => 'Paediatric', 'duration' => 30, 'price' => 3000],
            ['code' => 'DEN-SPACE', 'name' => 'Space maintainer', 'category' => 'Paediatric', 'duration' => 30, 'price' => 3000],
            // Cosmetic
            ['code' => 'DEN-WHITE', 'name' => 'Teeth whitening (in-office)', 'category' => 'Cosmetic', 'duration' => 60, 'price' => 8000],
            ['code' => 'DEN-BOND', 'name' => 'Cosmetic bonding / smile correction', 'category' => 'Cosmetic', 'duration' => 60, 'price' => 6000],
            ['code' => 'DEN-GUM-DEP', 'name' => 'Gum depigmentation', 'category' => 'Cosmetic', 'duration' => 45, 'price' => 8000],
            // Other
            ['code' => 'DEN-NIGHT', 'name' => 'Night guard / occlusal splint', 'category' => 'Other', 'duration' => 30, 'price' => 5000],
            ['code' => 'DEN-TMJ', 'name' => 'TMJ therapy session', 'category' => 'Other', 'duration' => 30, 'price' => 1500],
            ['code' => 'DEN-EMERG', 'name' => 'Emergency visit / pain relief', 'category' => 'Other', 'duration' => 20, 'price' => 800],
            ['code' => 'DEN-LASER', 'name' => 'Laser dental procedure', 'category' => 'Other', 'duration' => 30, 'price' => 3000],
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
