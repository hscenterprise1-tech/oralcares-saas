<?php

namespace App;

/**
 * What kind of place this is, operationally.
 *
 * Deliberately separate from {@see OrganizationType}, which answers a
 * commercial question — is this a chain, an independent, a diagnostic business.
 * This answers an operational one, and the two do not line up: a solo
 * practitioner and a forty-five bed hospital are both `medical_center` to the
 * commercial classifier, and they are not remotely the same sale, the same
 * onboarding, or the same price.
 *
 * Five archetypes, because five is what actually changes how the deal is
 * worked. Anything finer is a branch count or a bed count, which the branches
 * already carry.
 */
enum FacilityType: string
{
    /** One practitioner, one room. The overwhelming majority by count. */
    case SoloClinic = 'solo_clinic';

    /** Several disciplines under one roof, consulting only. */
    case Polyclinic = 'polyclinic';

    /** Short-stay beds and a theatre, but not a full hospital. */
    case DaycareNursingHome = 'daycare_nursing_home';

    /** Inpatient beds, theatres, multiple departments. */
    case MultiSpecialtyHospital = 'multi_specialty_hospital';

    /** Pathology, imaging or dialysis — no consulting practice of its own. */
    case DiagnosticCenter = 'diagnostic_center';

    public function label(): string
    {
        return match ($this) {
            self::SoloClinic => 'Solo practice',
            self::Polyclinic => 'Polyclinic',
            self::DaycareNursingHome => 'Daycare / nursing home',
            self::MultiSpecialtyHospital => 'Multi-specialty hospital',
            self::DiagnosticCenter => 'Diagnostic centre',
        };
    }

    /**
     * Which capacity figure actually describes this kind of place.
     *
     * A hospital is sized in beds, a dental clinic in chairs, a polyclinic in
     * doctors. Showing "0 beds" against a physiotherapy chain is worse than
     * showing nothing — it implies we asked and the answer was none.
     */
    public function primaryCapacityMetric(): string
    {
        return match ($this) {
            self::MultiSpecialtyHospital, self::DaycareNursingHome => 'beds',
            self::DiagnosticCenter => 'stations',
            self::Polyclinic => 'doctors',
            self::SoloClinic => 'doctors',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type) => [$type->value => $type->label()])
            ->all();
    }
}
