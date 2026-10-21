<?php

use App\Services\Treatment\Targets\ToothTarget;

/*
|--------------------------------------------------------------------------
| Specialty registry
|--------------------------------------------------------------------------
|
| What each specialty adds to the shared clinical core. Core code asks
| `App\Support\Specialty\SpecialtyRegistry` for an *extension* or a *flag*
| ("does this branch chart teeth?", "does it register new/repeat cases?") and
| never branches on a specialty name. A new specialty is an entry here, plus
| any extension it brings.
|
| A branch practises the specialties on `branches.specialties` (else its
| clinic's `clinics.specialties`, set by CRM provisioning). Several
| specialties on one branch make it multi-specialty: it gets the union of
| their extensions.
|
*/

return [

    /** Used when a clinic or branch names no specialty. */
    'default' => 'general_medicine',

    /** Legacy and CRM spellings that mean a registered specialty. */
    'aliases' => [
        'general' => 'general_medicine',
        'general_hospital' => 'general_medicine',
        'general_practice' => 'general_medicine',
        'physio' => 'physiotherapy',
        'ortho' => 'orthodontics',
        'multispecialty' => 'multi_specialty',
        // Practitioner titles, as recorded on a clinician's profile.
        'dentist' => 'dental',
        'dental_surgeon' => 'dental',
        'oral_surgeon' => 'dental',
        'periodontist' => 'dental',
        'endodontist' => 'dental',
        'prosthodontist' => 'dental',
        'orthodontist' => 'orthodontics',
        'physiotherapist' => 'physiotherapy',
        'physical_therapist' => 'physiotherapy',
    ],

    /*
     * Extensions: specialty-owned parts of the clinical record, each hanging
     * off the patient and encounter. `requires_capability` is a plan
     * capability (see ClinicCapabilities) the extension also needs.
     */
    'extensions' => [
        'odontogram' => ['label' => 'Dental chart', 'description' => 'FDI tooth chart with surfaces, conditions and tooth history'],
        'dental_lab' => ['label' => 'Dental lab work', 'description' => 'Crowns, bridges and prosthetics sent to a lab', 'requires_capability' => 'lab'],
        'dental_imaging' => ['label' => 'Dental imaging', 'description' => 'IOPA, OPG and CBCT studies linked to teeth', 'requires_capability' => 'xray'],
        'physio_assessment' => ['label' => 'Physio assessment', 'description' => 'Body chart, pain, range of motion, strength, posture and function'],
        'soap_notes' => ['label' => 'SOAP session notes', 'description' => 'Subjective, objective, assessment and plan for each session'],
        'exercise_plan' => ['label' => 'Exercise plan', 'description' => 'Home and in-clinic exercises with sets, reps and frequency'],
        'session_packages' => ['label' => 'Session packages', 'description' => 'Prepaid treatment packages with sessions punched per visit'],
    ],

    /*
    | What a treatment can be aimed at, declared by the specialty that needs it
    | (dental declares `tooth`). The core treatment engine knows only target
    | keys; each key has a handler that validates and stores its items.
    */
    'targets' => [
        'tooth' => ['label' => 'Tooth', 'handler' => ToothTarget::class],
    ],

    /*
    | `master` is the `healthcare_specialty_masters` key a specialty maps to
    | (the global catalog behind `clinic_specialties`). `multi_specialty` has
    | none: a multi-specialty clinic is several clinic specialties, not one.
    */
    'specialties' => [
        'dental' => [
            'master' => 'general_dentistry',
            'targets' => ['tooth'],
            'label' => 'Dental',
            'plan_module' => 'dental',
            'extensions' => ['odontogram', 'dental_lab', 'dental_imaging'],
            'flags' => ['case_registration'],
            'encounter_label' => 'Consultation',
        ],
        'orthodontics' => [
            'master' => 'orthodontics',
            'targets' => ['tooth'],
            'label' => 'Orthodontics',
            'plan_module' => 'dental',
            'extensions' => ['odontogram', 'dental_lab', 'dental_imaging'],
            'flags' => ['case_registration'],
            'encounter_label' => 'Consultation',
        ],
        'physiotherapy' => [
            'master' => 'physiotherapy',
            'label' => 'Physiotherapy',
            'plan_module' => 'physiotherapy',
            'extensions' => ['physio_assessment', 'soap_notes', 'exercise_plan', 'session_packages'],
            // Wording only: physiotherapy calls a first visit a "first visit", not a "new case".
            // Registration and check-in are the same for every specialty.
            'flags' => [],
            'encounter_label' => 'Session',
        ],
        'general_medicine' => [
            'master' => 'general_medicine',
            'label' => 'General Medicine',
            'extensions' => [],
            'flags' => ['case_registration'],
            'encounter_label' => 'Consultation',
        ],
        'multi_specialty' => [
            'label' => 'Multi-specialty',
            'extensions' => [],
            'flags' => ['case_registration'],
            'encounter_label' => 'Consultation',
        ],
        'ophthalmology' => ['master' => 'ophthalmology', 'label' => 'Ophthalmology', 'extensions' => [], 'flags' => ['case_registration'], 'encounter_label' => 'Consultation'],
        'dermatology' => ['master' => 'dermatology', 'label' => 'Dermatology', 'extensions' => [], 'flags' => ['case_registration'], 'encounter_label' => 'Consultation'],
        'ent' => ['master' => 'ent', 'label' => 'ENT', 'extensions' => [], 'flags' => ['case_registration'], 'encounter_label' => 'Consultation'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Healthcare modules
    |--------------------------------------------------------------------------
    |
    | The clinical domain a specialty belongs to (Dental, Physiotherapy, …).
    | A specialty's module is its `plan_module`, else its own key. `specialty`
    | is the module's root specialty: the one a practice area falls back to
    | when the clinic does not practise the area's own specialty.
    | `capabilities` are what every practitioner of the module can do; a
    | practice area adds its own.
    |
    */
    'modules' => [
        'dental' => [
            'label' => 'Dental',
            'specialty' => 'dental',
            'default_area' => 'general_dentistry',
            'capabilities' => [
                'dental_consultation' => 'Dental consultation',
                'dental_examination' => 'Dental examination',
                'dental_diagnosis' => 'Diagnosis',
                'dental_treatment_planning' => 'Treatment planning',
                'dental_imaging_review' => 'Imaging review',
                'dental_follow_up' => 'Follow-up',
            ],
        ],
        'physiotherapy' => [
            'label' => 'Physiotherapy',
            'specialty' => 'physiotherapy',
            'default_area' => 'physiotherapy',
            // Patient 360 section a physio care stream opens (others open Treatment).
            'care_section' => 'physio',
            // No consultation charge when a patient checks in: sessions, services and
            // packages are billed at their own stage (`CheckInCharges::dueAtCheckIn`).
            'charges_at_check_in' => false,
            'capabilities' => [
                'physio_assessment' => 'Assessment',
                'physio_impression' => 'Diagnosis / impression',
                'physio_treatment_planning' => 'Treatment planning',
                'exercise_prescription' => 'Exercise prescription',
                'manual_therapy' => 'Manual therapy',
                'physio_modalities' => 'Modalities',
                'progress_assessment' => 'Progress assessment',
                'home_exercise_program' => 'Home exercise program',
            ],
        ],
        'general_medicine' => [
            'label' => 'General Medicine',
            'specialty' => 'general_medicine',
            'default_area' => 'general_medicine',
            'capabilities' => ['medical_consultation' => 'Consultation', 'medical_diagnosis' => 'Diagnosis', 'prescribing' => 'Prescribing'],
        ],
        'ophthalmology' => ['label' => 'Ophthalmology', 'specialty' => 'ophthalmology', 'default_area' => 'ophthalmology', 'capabilities' => ['eye_consultation' => 'Eye consultation']],
        'dermatology' => ['label' => 'Dermatology', 'specialty' => 'dermatology', 'default_area' => 'dermatology', 'capabilities' => ['skin_consultation' => 'Skin consultation']],
        'ent' => ['label' => 'ENT', 'specialty' => 'ent', 'default_area' => 'ent', 'capabilities' => ['ent_consultation' => 'ENT consultation']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Practice areas (sub-specialties)
    |--------------------------------------------------------------------------
    |
    | What a practitioner practises within a module: Orthodontics, Endodontics,
    | Sports Rehabilitation. A practitioner's areas come from their specialty
    | assignments (`clinical_specialties.practice_area_key`), never from their
    | qualifications: MDS is a qualification, Orthodontics is an area, Dental
    | is the module.
    |
    | - `module`: the module the area belongs to.
    | - `specialty`: the registry specialty its visits are recorded under when
    |   the clinic practises it; otherwise the module's root specialty.
    | - `aliases`: titles and specialization names that mean this area, used
    |   only to read profiles recorded before areas existed.
    | - `focus`: where the module's clinical workspace opens for this area.
    | - `capabilities`: what the area adds to its module's.
    |
    */
    'practice_areas' => [
        // Dental
        'general_dentistry' => [
            'module' => 'dental',
            'label' => 'General Dentistry',
            'aliases' => ['general_dentist', 'general_dental_practice'],
            'focus' => 'general',
            'capabilities' => ['basic_dental_procedures' => 'Basic dental procedures'],
        ],
        'orthodontics' => [
            'module' => 'dental',
            'specialty' => 'orthodontics',
            'label' => 'Orthodontics',
            'aliases' => ['orthodontist', 'orthodontics_dentofacial_orthopedics', 'orthodontics_and_dentofacial_orthopaedics', 'orthodontics_dentofacial_orthopaedics'],
            'focus' => 'orthodontic',
            'capabilities' => [
                'orthodontic_assessment' => 'Orthodontic assessment',
                'malocclusion_assessment' => 'Malocclusion assessment',
                'orthodontic_treatment_planning' => 'Orthodontic treatment planning',
                'braces_treatment' => 'Braces treatment',
                'aligner_treatment' => 'Aligner treatment',
                'tooth_movement_tracking' => 'Tooth movement tracking',
                'retention_follow_up' => 'Retention follow-up',
            ],
        ],
        'oral_maxillofacial_surgery' => [
            'module' => 'dental',
            'label' => 'Oral & Maxillofacial Surgery',
            'short_label' => 'Oral Surgery',
            'aliases' => ['oral_surgeon', 'oral_surgery', 'maxillofacial_surgeon', 'maxillofacial_surgery', 'oral_and_maxillofacial_surgery', 'omfs'],
            'focus' => 'implant',
            'capabilities' => ['oral_surgery' => 'Oral surgery', 'extractions_surgical' => 'Surgical extractions', 'implant_surgery' => 'Implant surgery'],
        ],
        'prosthodontics' => [
            'module' => 'dental',
            'label' => 'Prosthodontics',
            'aliases' => ['prosthodontist', 'prosthodontics_and_crown_bridge', 'prosthodontics_crown_bridge'],
            'focus' => 'prosthetic',
            'capabilities' => ['prosthetic_planning' => 'Prosthetic planning', 'crowns_bridges' => 'Crowns & bridges', 'dentures' => 'Dentures'],
        ],
        'endodontics' => [
            'module' => 'dental',
            'label' => 'Conservative Dentistry & Endodontics',
            'short_label' => 'Endodontics',
            'aliases' => ['endodontist', 'endodontics', 'conservative_dentistry', 'conservative_dentistry_and_endodontics', 'conservative_dentistry_endodontics'],
            'focus' => 'endodontic',
            'capabilities' => ['pulp_diagnosis' => 'Pulp diagnosis', 'periapical_diagnosis' => 'Periapical diagnosis', 'root_canal_treatment' => 'Root canal treatment', 'restorations' => 'Restorations'],
        ],
        'periodontics' => [
            'module' => 'dental',
            'label' => 'Periodontics',
            'aliases' => ['periodontist', 'periodontology'],
            'focus' => 'periodontal',
            'capabilities' => ['periodontal_charting' => 'Periodontal charting', 'scaling_root_planing' => 'Scaling & root planing', 'periodontal_surgery' => 'Periodontal surgery'],
        ],
        'pediatric_dentistry' => [
            'module' => 'dental',
            'label' => 'Pediatric & Preventive Dentistry',
            'short_label' => 'Pediatric Dentistry',
            'aliases' => ['pedodontist', 'pedodontics', 'paediatric_dentistry', 'pediatric_dentist', 'paediatric_dentist', 'pediatric_and_preventive_dentistry', 'paediatric_and_preventive_dentistry', 'pedodontics_and_preventive_dentistry'],
            'focus' => 'general',
            'capabilities' => ['pediatric_dental_care' => 'Pediatric dental care', 'preventive_care' => 'Preventive care'],
        ],
        'oral_medicine_radiology' => [
            'module' => 'dental',
            'label' => 'Oral Medicine & Radiology',
            'aliases' => ['oral_medicine', 'oral_radiology', 'oral_medicine_and_radiology', 'oral_medicine_diagnosis_and_radiology'],
            'focus' => 'general',
            'capabilities' => ['oral_medicine' => 'Oral medicine', 'radiology_reporting' => 'Radiology reporting'],
        ],
        'oral_pathology' => [
            'module' => 'dental',
            'label' => 'Oral Pathology & Microbiology',
            'short_label' => 'Oral Pathology',
            'aliases' => ['oral_pathologist', 'oral_pathology_and_microbiology', 'oral_and_maxillofacial_pathology'],
            'focus' => 'general',
            'capabilities' => ['oral_pathology_reporting' => 'Pathology reporting'],
        ],
        'public_health_dentistry' => [
            'module' => 'dental',
            'label' => 'Public Health Dentistry',
            'aliases' => ['community_dentistry', 'public_health_dentist'],
            'focus' => 'general',
            'capabilities' => ['community_screening' => 'Community screening'],
        ],

        // Physiotherapy
        'physiotherapy' => [
            'module' => 'physiotherapy',
            'label' => 'Physiotherapy',
            'aliases' => ['physiotherapist', 'physical_therapist', 'physical_therapy', 'general_physiotherapy'],
            'capabilities' => [],
        ],
        'orthopedic_rehabilitation' => [
            'module' => 'physiotherapy',
            'label' => 'Orthopedic Rehabilitation',
            'aliases' => ['orthopaedic_rehabilitation', 'musculoskeletal_physiotherapy', 'orthopedic_physiotherapy', 'orthopaedic_physiotherapy', 'mpt_orthopedics', 'mpt_orthopaedics'],
            'capabilities' => ['musculoskeletal_rehabilitation' => 'Musculoskeletal rehabilitation'],
        ],
        'sports_rehabilitation' => [
            'module' => 'physiotherapy',
            'label' => 'Sports Rehabilitation',
            'aliases' => ['sports_physiotherapy', 'sports_physiotherapist', 'mpt_sports'],
            'capabilities' => ['sports_rehabilitation' => 'Sports rehabilitation', 'return_to_play' => 'Return-to-play testing'],
        ],
        'neurological_rehabilitation' => [
            'module' => 'physiotherapy',
            'label' => 'Neurological Rehabilitation',
            'aliases' => ['neuro_rehabilitation', 'neurological_physiotherapy', 'neuro_physiotherapy', 'mpt_neurology'],
            'capabilities' => ['neuro_rehabilitation' => 'Neurological rehabilitation'],
        ],
        'post_operative_rehabilitation' => [
            'module' => 'physiotherapy',
            'label' => 'Post-operative Rehabilitation',
            'aliases' => ['post_operative_physiotherapy', 'post_surgical_rehabilitation'],
            'capabilities' => ['post_operative_rehabilitation' => 'Post-operative rehabilitation'],
        ],
        'pain_management' => [
            'module' => 'physiotherapy',
            'label' => 'Pain Management',
            'aliases' => ['pain_physiotherapy'],
            'capabilities' => ['pain_management' => 'Pain management'],
        ],
        'mobility_rehabilitation' => [
            'module' => 'physiotherapy',
            'label' => 'Mobility Rehabilitation',
            'aliases' => ['geriatric_physiotherapy', 'mobility_training'],
            'capabilities' => ['mobility_training' => 'Mobility training'],
        ],
        'strength_conditioning' => [
            'module' => 'physiotherapy',
            'label' => 'Strength & Conditioning',
            'aliases' => ['strength_and_conditioning'],
            'capabilities' => ['strength_conditioning' => 'Strength & conditioning'],
        ],

        // Single-area modules
        'general_medicine' => ['module' => 'general_medicine', 'label' => 'General Medicine', 'aliases' => ['general_physician', 'physician', 'family_medicine'], 'capabilities' => []],
        'ophthalmology' => ['module' => 'ophthalmology', 'label' => 'Ophthalmology', 'aliases' => ['ophthalmologist', 'eye_specialist'], 'capabilities' => []],
        'dermatology' => ['module' => 'dermatology', 'label' => 'Dermatology', 'aliases' => ['dermatologist', 'skin_specialist'], 'capabilities' => []],
        'ent' => ['module' => 'ent', 'label' => 'ENT', 'aliases' => ['ent_specialist', 'otolaryngology', 'otorhinolaryngology'], 'capabilities' => []],
    ],

];
