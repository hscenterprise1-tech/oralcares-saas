<?php

namespace App\Support\Clinical;

/**
 * The OralCares-wide starting catalogue: the common conditions and the visit
 * types every clinic sees until it configures its own. Allergies are not
 * here; they come from the existing `allergy_masters` list, copied into the
 * catalogue by the migration that created it.
 *
 * Severity is a default a clinic can change. High and critical entries raise
 * a safety alert on the patient; the three that the old hard-coded alerts
 * flagged (heart, blood, diabetes) keep the severity they had there.
 */
final class DefaultClinicalCatalogue
{
    public const KIND_CONDITION = 'condition';

    public const KIND_ALLERGY = 'allergy';

    public const KIND_ALERT = 'alert';

    public const KIND_MEDICATION = 'medication';

    public const KIND_VISIT_TYPE = 'visit_type';

    /**
     * Why the patient came ("Braces consultation", "Knee pain"): offered by
     * the visit's specialty and practice area. `category` names the visit
     * type the reason implies.
     */
    public const KIND_VISIT_REASON = 'visit_reason';

    /** A past operation or procedure the patient reports ("Knee arthroscopy"). */
    public const KIND_SURGERY = 'surgery';

    /** A condition in the patient's family ("Diabetes — family"). */
    public const KIND_FAMILY_HISTORY = 'family_history';

    /** Kinds a clinic can configure. */
    public const KINDS = [self::KIND_CONDITION, self::KIND_ALLERGY, self::KIND_ALERT, self::KIND_MEDICATION, self::KIND_VISIT_TYPE, self::KIND_VISIT_REASON, self::KIND_SURGERY, self::KIND_FAMILY_HISTORY];

    /** Kinds recorded on a patient as clinical entries. */
    public const ENTRY_KINDS = [self::KIND_CONDITION, self::KIND_ALLERGY, self::KIND_MEDICATION, self::KIND_SURGERY, self::KIND_FAMILY_HISTORY];

    public const SEVERITIES = ['low', 'moderate', 'high', 'critical'];

    /** Severities that raise a patient safety alert. */
    public const ALERTING_SEVERITIES = ['high', 'critical'];

    /**
     * The starting lists for past surgery and family history (added with
     * those kinds). No severity: they inform, they raise no safety alert.
     * Clinics switch items off, rename them or add their own.
     *
     * @return list<array{kind: string, key: string, name: string, category: ?string, default_severity: null, position: int}>
     */
    public static function historyItems(): array
    {
        $surgeries = [
            ['cardiac_surgery', 'Heart surgery', 'cardiovascular'],
            ['joint_replacement', 'Joint replacement', 'orthopaedic'],
            ['spinal_surgery', 'Spinal surgery', 'orthopaedic'],
            ['fracture_fixation', 'Fracture fixation', 'orthopaedic'],
            ['ligament_repair', 'Ligament repair', 'orthopaedic'],
            ['caesarean_section', 'Caesarean section', 'reproductive'],
            ['abdominal_surgery', 'Abdominal surgery', 'general'],
            ['organ_transplant', 'Organ transplant', 'general'],
        ];
        $family = [
            ['family_diabetes', 'Diabetes', 'endocrine'],
            ['family_hypertension', 'Hypertension', 'cardiovascular'],
            ['family_heart_disease', 'Heart disease', 'cardiovascular'],
            ['family_stroke', 'Stroke', 'neurological'],
            ['family_cancer', 'Cancer', 'oncology'],
            ['family_thyroid', 'Thyroid disorder', 'endocrine'],
            ['family_arthritis', 'Arthritis', 'musculoskeletal'],
        ];

        $items = [];
        foreach ($surgeries as $position => [$key, $name, $category]) {
            $items[] = ['kind' => self::KIND_SURGERY, 'key' => $key, 'name' => $name, 'category' => $category, 'default_severity' => null, 'position' => $position + 1];
        }
        foreach ($family as $position => [$key, $name, $category]) {
            $items[] = ['kind' => self::KIND_FAMILY_HISTORY, 'key' => $key, 'name' => $name, 'category' => $category, 'default_severity' => null, 'position' => $position + 1];
        }

        return $items;
    }

    /**
     * @return list<array{kind: string, key: string, name: string, category: ?string, default_severity: ?string, position: int}>
     */
    public static function items(): array
    {
        $conditions = [
            ['diabetes', 'Diabetes', 'endocrine', 'high'],
            ['hypertension', 'Hypertension', 'cardiovascular', 'moderate'],
            ['heart_disease', 'Heart disease', 'cardiovascular', 'critical'],
            ['asthma', 'Asthma', 'respiratory', 'moderate'],
            ['thyroid_disorder', 'Thyroid disorder', 'endocrine', 'moderate'],
            ['kidney_disease', 'Kidney disease', 'renal', 'moderate'],
            ['liver_disease', 'Liver disease', 'hepatic', 'moderate'],
            ['epilepsy', 'Epilepsy', 'neurological', 'high'],
            ['cancer', 'Cancer', 'oncology', 'moderate'],
            ['blood_disorder', 'Blood disorder', 'haematological', 'critical'],
            ['pregnancy', 'Pregnancy', 'reproductive', 'high'],
        ];
        $visitTypes = [
            ['consultation', 'Consultation'],
            ['follow_up', 'Follow-up'],
            ['procedure', 'Procedure'],
            ['review', 'Review'],
        ];

        $items = [];
        foreach ($conditions as $position => [$key, $name, $category, $severity]) {
            $items[] = ['kind' => self::KIND_CONDITION, 'key' => $key, 'name' => $name, 'category' => $category, 'default_severity' => $severity, 'position' => $position + 1];
        }
        foreach ($visitTypes as $position => [$key, $name]) {
            $items[] = ['kind' => self::KIND_VISIT_TYPE, 'key' => $key, 'name' => $name, 'category' => null, 'default_severity' => null, 'position' => $position + 1];
        }

        return $items;
    }

    /**
     * The starting visit reasons and safety/operational alerts (added after
     * `items()`, with practice-area applicability). A reason applies to a
     * visit when its specialties include the visit's and, when it names
     * practice areas, the visit's area is one of them; no specialties means
     * every visit. Alerts are flags reception may set, never diagnoses.
     *
     * @return list<array{kind: string, key: string, name: string, category: ?string, default_severity: ?string, specialty_keys: ?list<string>, practice_area_keys: ?list<string>, position: int}>
     */
    public static function intakeItems(): array
    {
        $dental = ['dental', 'orthodontics'];
        $reasons = [
            // [key, name, implied visit type, specialties, practice areas]
            ['tooth_pain', 'Tooth pain', 'consultation', $dental, ['general_dentistry', 'endodontics', 'pediatric_dentistry', 'oral_maxillofacial_surgery']],
            ['dental_checkup', 'Check-up', 'consultation', $dental, ['general_dentistry', 'pediatric_dentistry', 'periodontics', 'public_health_dentistry', 'oral_medicine_radiology']],
            ['cleaning', 'Cleaning', 'procedure', $dental, ['general_dentistry', 'periodontics', 'pediatric_dentistry']],
            ['filling', 'Filling', 'procedure', $dental, ['general_dentistry', 'endodontics', 'pediatric_dentistry']],
            ['extraction', 'Extraction', 'procedure', $dental, ['general_dentistry', 'oral_maxillofacial_surgery', 'pediatric_dentistry']],
            ['rct', 'Root canal (RCT)', 'procedure', $dental, ['general_dentistry', 'endodontics']],
            ['rct_follow_up', 'RCT follow-up', 'follow_up', $dental, ['endodontics']],
            ['endodontic_review', 'Endodontic review', 'review', $dental, ['endodontics']],
            ['implant', 'Implant', 'consultation', $dental, ['general_dentistry', 'oral_maxillofacial_surgery', 'prosthodontics']],
            ['crown_bridge', 'Crown / bridge', 'consultation', $dental, ['general_dentistry', 'prosthodontics']],
            ['dentures', 'Dentures', 'consultation', $dental, ['general_dentistry', 'prosthodontics']],
            ['wisdom_tooth', 'Wisdom tooth', 'consultation', $dental, ['general_dentistry', 'oral_maxillofacial_surgery']],
            ['jaw_pain', 'Jaw / facial pain', 'consultation', $dental, ['oral_maxillofacial_surgery', 'oral_medicine_radiology']],
            ['post_op_review', 'Post-op review', 'review', $dental, ['oral_maxillofacial_surgery']],
            ['bleeding_gums', 'Bleeding gums', 'consultation', $dental, ['general_dentistry', 'periodontics']],
            ['dental_imaging', 'X-ray / imaging', 'procedure', $dental, ['general_dentistry', 'oral_medicine_radiology']],
            ['braces_consultation', 'Braces consultation', 'consultation', $dental, ['orthodontics']],
            ['aligner_consultation', 'Aligner consultation', 'consultation', $dental, ['orthodontics']],
            ['malocclusion_consultation', 'Bite / malocclusion', 'consultation', $dental, ['orthodontics']],
            ['braces_adjustment', 'Braces adjustment', 'procedure', $dental, ['orthodontics']],
            ['retainer_check', 'Retainer check', 'follow_up', $dental, ['orthodontics']],
            ['knee_pain', 'Knee pain', 'consultation', ['physiotherapy'], null],
            ['back_pain', 'Back pain', 'consultation', ['physiotherapy'], null],
            ['neck_pain', 'Neck pain', 'consultation', ['physiotherapy'], null],
            ['shoulder_pain', 'Shoulder pain', 'consultation', ['physiotherapy'], null],
            ['sports_injury', 'Sports injury', 'consultation', ['physiotherapy'], null],
            ['post_op_rehab', 'Post-op rehab', 'consultation', ['physiotherapy'], null],
            ['mobility_issue', 'Mobility issue', 'consultation', ['physiotherapy'], null],
            ['pain_management', 'Pain management', 'consultation', ['physiotherapy'], null],
            ['exercise_therapy', 'Exercise therapy', 'procedure', ['physiotherapy'], null],
            ['fever', 'Fever', 'consultation', ['general_medicine'], null],
            ['general_checkup', 'General check-up', 'consultation', ['general_medicine'], null],
            ['follow_up', 'Follow-up', 'follow_up', null, null],
        ];
        $alerts = [
            ['fall_risk', 'Fall risk', 'high'],
            ['infection_precaution', 'Infection precaution', 'high'],
            ['mobility_assistance', 'Needs mobility assistance', 'moderate'],
            ['hearing_vision', 'Hearing or vision difficulty', 'moderate'],
            ['language_assistance', 'Needs language assistance', 'low'],
            ['anxious_patient', 'Anxious patient', 'low'],
        ];

        $items = [];
        foreach ($reasons as $position => [$key, $name, $type, $specialties, $areas]) {
            $items[] = ['kind' => self::KIND_VISIT_REASON, 'key' => $key, 'name' => $name, 'category' => $type, 'default_severity' => null, 'specialty_keys' => $specialties, 'practice_area_keys' => $areas, 'position' => $position + 1];
        }
        foreach ($alerts as $position => [$key, $name, $severity]) {
            $items[] = ['kind' => self::KIND_ALERT, 'key' => $key, 'name' => $name, 'category' => 'safety', 'default_severity' => $severity, 'specialty_keys' => null, 'practice_area_keys' => null, 'position' => $position + 1];
        }

        return $items;
    }

    /**
     * The old yes/no medical-history columns and the condition each one means.
     *
     * @return array<string, string>
     */
    public static function legacyConditionColumns(): array
    {
        return [
            'diabetes' => 'diabetes',
            'hypertension' => 'hypertension',
            'cardiac_condition' => 'heart_disease',
            'blood_disorder' => 'blood_disorder',
            'pregnancy_status' => 'pregnancy',
        ];
    }
}
