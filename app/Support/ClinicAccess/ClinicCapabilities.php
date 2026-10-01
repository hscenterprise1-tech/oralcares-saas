<?php

namespace App\Support\ClinicAccess;

use App\ClinicType;
use App\Models\Clinic;
use App\Models\Organization;

/**
 * What a clinic practises (specialties) and which optional capabilities it
 * has switched on, from data the platform already holds. Nothing here is
 * hard-coded per clinic or per role.
 *
 * Specialties come from the organisation's `clinic_type` plus any specialty
 * module the plan grants (`dental`, `physiotherapy`). A clinic whose
 * specialty is unknown is `general`.
 *
 * Capabilities (X-ray, dental lab, inventory, analytics, EMR, WhatsApp) are
 * plan features. Unlike the core modules, which a clinic with no subscription
 * keeps for compatibility (see `EnsureClinicEntitled`), a capability is on
 * only when a plan explicitly grants it.
 */
class ClinicCapabilities
{
    /** Plan feature keys that switch on an optional capability. */
    public const CAPABILITIES = ['xray', 'lab', 'inventory', 'analytics', 'emr', 'whatsapp'];

    /** Plan feature keys that also declare a specialty. */
    public const SPECIALTY_MODULES = ['dental', 'physiotherapy'];

    public const GENERAL = 'general';

    /**
     * @param  array{has_subscription: bool, modules: list<string>}  $entitlement
     * @return list<string>
     */
    public function specialties(Clinic $clinic, array $entitlement): array
    {
        $specialties = [];

        $type = $clinic->organization_id
            ? Organization::query()->withoutGlobalScopes()->whereKey($clinic->organization_id)->value('clinic_type')
            : null;
        $fromType = self::specialtyForType($type instanceof ClinicType ? $type : ClinicType::tryFrom((string) $type));
        if ($fromType !== null) {
            $specialties[] = $fromType;
        }

        if ($entitlement['has_subscription']) {
            foreach (self::SPECIALTY_MODULES as $module) {
                if (in_array($module, $entitlement['modules'], true)) {
                    $specialties[] = $module;
                }
            }
        }

        $specialties = array_values(array_unique($specialties));

        return $specialties === [] ? [self::GENERAL] : $specialties;
    }

    /**
     * @param  array{has_subscription: bool, modules: list<string>}  $entitlement
     * @return list<string>
     */
    public function capabilities(array $entitlement): array
    {
        if (! $entitlement['has_subscription']) {
            return [];
        }

        return array_values(array_intersect(self::CAPABILITIES, $entitlement['modules']));
    }

    public static function specialtyForType(?ClinicType $type): ?string
    {
        return match ($type) {
            ClinicType::DentalClinic, ClinicType::DentalHospital => 'dental',
            ClinicType::Physiotherapy => 'physiotherapy',
            ClinicType::EyeHospital => 'ophthalmology',
            ClinicType::GeneralHospital => 'general_hospital',
            ClinicType::MultiSpecialityHospital => 'multi_specialty',
            null => null,
            default => self::GENERAL,
        };
    }
}
