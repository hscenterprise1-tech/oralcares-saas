<?php

namespace App\Services\Patient;

use App\ClinicPermission;
use App\Models\Clinic;
use App\Models\ClinicSpecialty;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use App\Support\Specialty\SpecialtyEntitlements;
use App\Support\Specialty\SpecialtyRegistry;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of `clinic_specialties`: which specialties a clinic
 * practises. Deactivating keeps the row, so patient enrolments and history
 * pointing at it stay valid.
 *
 * `clinics.specialties` (JSON) is the legacy configuration. While it still
 * has writers (CRM provisioning, older tests), `Clinic` mirrors any write to
 * it into this table via `mirrorLegacy()`; canonical writes through `sync()`
 * refresh the JSON as a read cache.
 */
class ClinicSpecialties
{
    public function __construct(
        protected SpecialtyRegistry $registry,
        protected SecurityAudit $audit,
        protected SpecialtyEntitlements $entitlements,
    ) {}

    /**
     * @return Collection<int, ClinicSpecialty>
     */
    public function all(Clinic $clinic): Collection
    {
        return ClinicSpecialty::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->orderBy('id')->get();
    }

    /**
     * Registry keys of the clinic's active specialties that its plan sells
     * (`SpecialtyEntitlements`). A specialty the plan no longer sells stays
     * active in the table, so its records keep their enrolment, but cannot
     * be chosen for anything new.
     *
     * @return list<string>
     */
    public function activeKeys(Clinic $clinic): array
    {
        $active = $this->all($clinic)->where('status', ClinicSpecialty::ACTIVE)->pluck('specialty_key')->values()->all();
        $usable = $this->entitlements->filter($clinic, $active, fallBackToProducts: false);
        $products = $this->entitlements->products($clinic);

        // A clinic that bought a specialty it has not set up yet (configured
        // as dental, sold physiotherapy) gets it switched on, audited, so its
        // registration, check-in and treatments can use what it bought.
        if ($usable === [] && $products !== null && $products !== []) {
            foreach ($products as $key) {
                $this->activate($clinic, $key);
            }

            return $products;
        }

        return $usable;
    }

    /** The clinic's active, entitled specialty for a registry key (aliases resolved), or null. */
    public function active(Clinic $clinic, string $key): ?ClinicSpecialty
    {
        $key = $this->registry->normalize([$key])[0] ?? null;
        if ($key === null || ! $this->entitlements->allows($clinic, $key)) {
            return null;
        }
        $find = fn () => ClinicSpecialty::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)->where('specialty_key', $key)->where('status', ClinicSpecialty::ACTIVE)->first();

        // A bought product not set up yet is switched on (see activeKeys()).
        return $find() ?? (in_array($key, $this->activeKeys($clinic), true) ? $find() : null);
    }

    /**
     * The owner deciding which specialties the clinic practises
     * (`specialty.configure`, which only the owner holds).
     *
     * @param  iterable<string>  $keys
     * @return list<string>
     */
    public function configure(Clinic $clinic, iterable $keys, User $by): array
    {
        if (! $by->hasClinicPermission(ClinicPermission::SpecialtyConfigure)) {
            throw new AuthorizationException('Only the clinic owner decides which specialties the clinic practises.');
        }
        $keys = is_array($keys) ? $keys : iterator_to_array($keys, false);
        if ($this->registry->normalize($keys) === []) {
            throw new InvalidArgumentException('A clinic practises at least one known specialty.');
        }
        $notBought = array_filter($this->registry->normalize($keys), fn (string $key) => ! $this->entitlements->allows($clinic, $key));
        if ($notBought !== []) {
            $labels = implode(', ', array_map(fn (string $key) => $this->registry->get($key)['label'] ?? $key, $notBought));
            throw new InvalidArgumentException("{$labels} is not part of this clinic's plan.");
        }

        return $this->sync($clinic, $keys);
    }

    /**
     * Make exactly these specialties active: new ones are created or
     * reactivated, the rest deactivated (never deleted).
     *
     * @param  iterable<string>  $keys
     * @return list<string> the active keys afterwards
     */
    public function sync(Clinic $clinic, iterable $keys, bool $refreshLegacyCache = true): array
    {
        $wanted = $this->registry->normalize($keys);

        DB::transaction(function () use ($clinic, $wanted) {
            foreach ($wanted as $key) {
                $this->activate($clinic, $key);
            }
            foreach ($this->all($clinic) as $specialty) {
                if ($specialty->isActive() && ! in_array($specialty->specialty_key, $wanted, true)) {
                    $this->deactivate($clinic, $specialty->specialty_key);
                }
            }
        });

        $active = $this->activeKeys($clinic);
        if ($refreshLegacyCache) {
            DB::table('clinics')->where('id', $clinic->id)->update(['specialties' => json_encode($active)]);
            $clinic->setAttribute('specialties', $active);
            $clinic->syncOriginalAttribute('specialties');
        }
        $clinic->unsetRelation('clinicSpecialties');

        return $active;
    }

    public function activate(Clinic $clinic, string $key): ClinicSpecialty
    {
        $key = $this->registry->normalize([$key])[0] ?? throw new DomainException("“{$key}” is not a registered specialty.");

        $specialty = ClinicSpecialty::query()->withoutGlobalScopes()->firstOrNew(['clinic_id' => $clinic->id, 'specialty_key' => $key]);
        if ($specialty->exists && $specialty->isActive()) {
            return $specialty;
        }

        $specialty->fill([
            'healthcare_specialty_master_id' => $this->masterId($key),
            'status' => ClinicSpecialty::ACTIVE,
            'activated_at' => now(),
            'deactivated_at' => null,
        ])->save();
        $clinic->unsetRelation('clinicSpecialties');
        $this->audit->record('clinic.specialty.activated', $specialty, ['specialty' => $key]);

        return $specialty;
    }

    public function deactivate(Clinic $clinic, string $key): void
    {
        $specialty = ClinicSpecialty::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('specialty_key', $key)->first();
        if (! $specialty || ! $specialty->isActive()) {
            return;
        }

        $specialty->update(['status' => ClinicSpecialty::INACTIVE, 'deactivated_at' => now()]);
        $clinic->unsetRelation('clinicSpecialties');
        $this->audit->record('clinic.specialty.deactivated', $specialty, ['specialty' => $key]);
    }

    /**
     * Mirror a direct write of the legacy JSON into the table, without
     * rewriting the JSON (a key the registry does not know stays where it was).
     */
    public function mirrorLegacy(Clinic $clinic): void
    {
        $this->sync($clinic, (array) ($clinic->specialties ?? []), refreshLegacyCache: false);
    }

    private function masterId(string $key): ?int
    {
        $masterKey = $this->registry->get($key)['master'] ?? null;

        return $masterKey === null ? null : HealthcareSpecialtyMaster::query()->where('key', $masterKey)->value('id');
    }
}
