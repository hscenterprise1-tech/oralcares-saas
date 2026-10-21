<?php

namespace App\Support\ClinicAccess;

use App\ClinicType;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use App\Support\Specialty\SpecialtyEntitlements;
use App\Support\Specialty\SpecialtyRegistry;

/**
 * What a clinic practises (specialties) and which optional capabilities it
 * has switched on, from data the platform already holds. Nothing here is
 * hard-coded per clinic or per role.
 *
 * Specialties are registry keys (`config/clinic_specialties.php`) from the
 * branch's or clinic's configured set, plus any specialty a plan module
 * grants. A clinic whose specialty is unknown gets the registry default.
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

    public function __construct(protected SpecialtyRegistry $registry) {}

    /**
     * The specialties practised where the user is working, as registry keys:
     * the active branch's configured set, else the clinic's, else (clinics
     * provisioned before specialties were recorded) the organisation's clinic
     * type — plus any specialty a plan module declares.
     *
     * @param  array{has_subscription: bool, modules: list<string>}  $entitlement
     * @return list<string>
     */
    public function specialties(Clinic $clinic, array $entitlement, ?Branch $branch = null): array
    {
        $specialties = $this->registry->normalize((array) ($branch?->specialties ?? []));

        if ($specialties === []) {
            $specialties = $this->registry->normalize((array) ($clinic->specialties ?? []));
        }

        if ($specialties === []) {
            $type = $clinic->organization_id
                ? Organization::query()->withoutGlobalScopes()->whereKey($clinic->organization_id)->value('clinic_type')
                : null;
            $specialties = $this->registry->normalize([self::specialtyForType($type instanceof ClinicType ? $type : ClinicType::tryFrom((string) $type))]);
        }

        $specialties = $specialties === [] ? [$this->registry->default()] : $specialties;

        // The plan decides which specialty products may be used (see `SpecialtyEntitlements`).
        $entitlements = app(SpecialtyEntitlements::class);
        $entitled = $entitlements->fromEntitlement($entitlement);

        return SpecialtyEntitlements::restrict($specialties, $entitled, $entitled === null ? null : $entitlements->productsOf($entitled));
    }

    /**
     * The specialty extensions available here: what the specialties bring,
     * less any that need a capability the plan does not grant.
     *
     * @param  list<string>  $specialties
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    public function extensions(array $specialties, array $capabilities): array
    {
        return $this->registry->availableExtensions($specialties, $capabilities);
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
            default => 'general',
        };
    }
}
