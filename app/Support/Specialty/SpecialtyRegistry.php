<?php

namespace App\Support\Specialty;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicSpecialty;

/**
 * The specialty registry (`config/clinic_specialties.php`).
 *
 * Specialty-specific behaviour is reached only through extensions and flags
 * asked of this class — "does this branch chart teeth", "does it register
 * new/repeat cases" — so the clinical core never contains a branch on a
 * specialty name.
 */
class SpecialtyRegistry
{
    /**
     * Every registered specialty, keyed by its canonical key.
     *
     * @return array<string, array{label: string, plan_module?: string, extensions: list<string>, flags: list<string>, encounter_label: string}>
     */
    public function all(): array
    {
        return config('clinic_specialties.specialties', []);
    }

    /**
     * @return array{label: string, plan_module?: string, extensions: list<string>, flags: list<string>, encounter_label: string}|null
     */
    public function get(string $key): ?array
    {
        return $this->all()[$this->canonical($key)] ?? null;
    }

    /**
     * Canonical keys for a list of stored or sold specialty names: aliases
     * resolved, unknown names dropped, duplicates removed, order kept.
     *
     * @param  iterable<mixed>  $keys
     * @return list<string>
     */
    public function normalize(iterable $keys): array
    {
        $known = $this->all();
        $resolved = [];

        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $canonical = $this->canonical($key);
            if (isset($known[$canonical]) && ! in_array($canonical, $resolved, true)) {
                $resolved[] = $canonical;
            }
        }

        return $resolved;
    }

    /**
     * The specialties a branch practises: its own set, else its clinic's,
     * else the registry default — always within what the clinic's plan
     * sells (`SpecialtyEntitlements`).
     *
     * @return list<string>
     */
    public function forBranch(Branch $branch, ?Clinic $clinic = null): array
    {
        $clinic ??= Clinic::query()->withoutGlobalScopes()->find($branch->clinic_id);
        $own = $this->normalize((array) ($branch->specialties ?? []));
        if ($own !== []) {
            return $clinic ? app(SpecialtyEntitlements::class)->filter($clinic, $own) : $own;
        }

        return $clinic ? $this->forClinic($clinic) : [$this->default()];
    }

    /**
     * The specialties a clinic practises (its configured set, else the
     * default), within what its plan sells.
     *
     * @return list<string>
     */
    public function forClinic(Clinic $clinic): array
    {
        return app(SpecialtyEntitlements::class)->filter($clinic, $this->configuredForClinic($clinic));
    }

    /**
     * The clinic's own configuration, before the plan: what the owner's
     * specialty settings show.
     *
     * @return list<string>
     */
    public function configuredForClinic(Clinic $clinic): array
    {
        // `clinic_specialties` is canonical; loaded once per clinic instance.
        // A clinic with no rows yet (before `patients:backfill-foundation`)
        // still reads its legacy JSON.
        if (! $clinic->relationLoaded('clinicSpecialties')) {
            $clinic->setRelation('clinicSpecialties', ClinicSpecialty::query()->withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)->get(['id', 'clinic_id', 'specialty_key', 'status']));
        }
        $rows = $clinic->getRelation('clinicSpecialties');

        $own = $rows->isNotEmpty()
            ? $this->normalize($rows->where('status', ClinicSpecialty::ACTIVE)->pluck('specialty_key'))
            : $this->normalize((array) ($clinic->specialties ?? []));

        return $own !== [] ? $own : [$this->default()];
    }

    /**
     * The extensions a set of specialties brings, before plan capabilities.
     *
     * @param  list<string>  $specialties
     * @return list<string>
     */
    public function extensionsFor(array $specialties): array
    {
        $extensions = [];
        foreach ($this->normalize($specialties) as $key) {
            foreach ($this->all()[$key]['extensions'] ?? [] as $extension) {
                if (! in_array($extension, $extensions, true)) {
                    $extensions[] = $extension;
                }
            }
        }

        return $extensions;
    }

    /**
     * The extensions available to a set of specialties under a plan's
     * capabilities: an extension that needs a capability the plan does not
     * grant is left out.
     *
     * @param  list<string>  $specialties
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    public function availableExtensions(array $specialties, array $capabilities): array
    {
        return array_values(array_filter(
            $this->extensionsFor($specialties),
            function (string $extension) use ($capabilities) {
                $needs = config("clinic_specialties.extensions.{$extension}.requires_capability");

                return $needs === null || in_array($needs, $capabilities, true);
            },
        ));
    }

    /**
     * Whether any of the specialties carries a flag (e.g. `case_registration`).
     *
     * @param  list<string>  $specialties
     */
    public function hasFlag(array $specialties, string $flag): bool
    {
        foreach ($this->normalize($specialties) as $key) {
            if (in_array($flag, $this->all()[$key]['flags'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What an encounter is called for these specialties ("Consultation",
     * "Session"): the first specialty's label when they agree, else the
     * default specialty's.
     *
     * @param  list<string>  $specialties
     */
    public function encounterLabel(array $specialties): string
    {
        $labels = array_values(array_unique(array_map(
            fn (string $key) => $this->all()[$key]['encounter_label'] ?? 'Consultation',
            $this->normalize($specialties),
        )));

        return count($labels) === 1 ? $labels[0] : ($this->all()[$this->default()]['encounter_label'] ?? 'Consultation');
    }

    /**
     * @return array<string, array{label: string, description: string, requires_capability?: string}>
     */
    public function extensions(): array
    {
        return config('clinic_specialties.extensions', []);
    }

    public function default(): string
    {
        return (string) config('clinic_specialties.default', 'general_medicine');
    }

    private function canonical(string $key): string
    {
        $key = strtolower(trim($key));

        return config("clinic_specialties.aliases.{$key}", $key);
    }
}
