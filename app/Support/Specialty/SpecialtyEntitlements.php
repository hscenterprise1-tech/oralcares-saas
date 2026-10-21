<?php

namespace App\Support\Specialty;

use App\Models\Clinic;
use App\Services\Provisioning\EntitlementResolver;
use WeakMap;

/**
 * Which specialty products a clinic has bought.
 *
 * A clinic configures the specialties it practises (`clinic_specialties`),
 * but it may only use the ones its plan sells: a plan feature named after a
 * specialty's `plan_module` (`dental`, `physiotherapy`) entitles every
 * specialty of that module (Dental also brings Orthodontics). The resolvers
 * of practised specialties (`SpecialtyRegistry::forClinic/forBranch`,
 * `ClinicSpecialties::activeKeys/active`, `ClinicCapabilities`) all pass
 * through `filter()`, so navigation, registration, the Front Desk, the
 * consultation, treatments, billing and the specialty routes agree.
 *
 * A plan that sells no specialty module (the generic Starter/Growth/
 * Enterprise plans) and a clinic with no subscription are not restricted:
 * their configured specialties stand. Records of a specialty the plan no
 * longer sells are kept; only its use stops.
 */
class SpecialtyEntitlements
{
    /** @var WeakMap<Clinic, list<string>|null> */
    private WeakMap $memo;

    public function __construct(
        protected EntitlementResolver $resolver,
        protected SpecialtyRegistry $registry,
    ) {
        $this->memo = new WeakMap;
    }

    /**
     * The specialty keys the clinic's plan entitles, or null when the plan
     * does not restrict specialties.
     *
     * @return list<string>|null
     */
    public function entitled(Clinic $clinic): ?array
    {
        if (! $clinic->exists) {
            return null;
        }

        if (! $this->memo->offsetExists($clinic)) {
            $this->memo[$clinic] = $this->fromEntitlement($this->resolver->forClinic($clinic));
        }

        return $this->memo[$clinic];
    }

    /**
     * @param  array{has_subscription: bool, modules: list<string>}|array<string, mixed>  $entitlement
     * @return list<string>|null
     */
    public function fromEntitlement(array $entitlement): ?array
    {
        if (! ($entitlement['has_subscription'] ?? false)) {
            return null;
        }

        $modules = (array) ($entitlement['modules'] ?? []);
        $keys = [];
        foreach ($this->registry->all() as $key => $definition) {
            if (isset($definition['plan_module']) && in_array($definition['plan_module'], $modules, true)) {
                $keys[] = $key;
            }
        }

        return $keys === [] ? null : $keys;
    }

    public function allows(Clinic $clinic, string $key): bool
    {
        $entitled = $this->entitled($clinic);
        $key = $this->registry->normalize([$key])[0] ?? null;

        return $key !== null && ($entitled === null || in_array($key, $entitled, true));
    }

    /**
     * The products themselves: the specialty each bought module is named
     * after (`dental`, not also `orthodontics`), or null when unrestricted.
     *
     * @return list<string>|null
     */
    public function products(Clinic $clinic): ?array
    {
        $entitled = $this->entitled($clinic);

        return $entitled === null ? null : $this->productsOf($entitled);
    }

    /**
     * @param  list<string>  $entitled
     * @return list<string>
     */
    public function productsOf(array $entitled): array
    {
        return array_values(array_filter($entitled, fn (string $key) => ($this->registry->get($key)['plan_module'] ?? null) === $key));
    }

    /**
     * The configured specialties the plan allows, in their order. When the
     * plan allows none of them (a Physiotherapy plan on a clinic configured
     * as dental), the bought products themselves: the clinic gets what it
     * bought.
     *
     * @param  list<string>  $configured
     * @return list<string>
     */
    public function filter(Clinic $clinic, array $configured, bool $fallBackToProducts = true): array
    {
        return self::restrict($configured, $this->entitled($clinic), $fallBackToProducts ? $this->products($clinic) : null);
    }

    /**
     * @param  list<string>  $configured
     * @param  list<string>|null  $entitled
     * @param  list<string>|null  $fallback  used when nothing configured is entitled
     * @return list<string>
     */
    public static function restrict(array $configured, ?array $entitled, ?array $fallback = null): array
    {
        if ($entitled === null) {
            return $configured;
        }

        $allowed = array_values(array_intersect($configured, $entitled));

        return $allowed === [] && $fallback !== null ? $fallback : $allowed;
    }

    /** Drop the memo for a clinic whose plan just changed. */
    public function forget(Clinic $clinic): void
    {
        unset($this->memo[$clinic]);
    }
}
