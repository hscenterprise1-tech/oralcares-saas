<?php

namespace App\Services\Treatment\Targets;

use App\Models\PatientProcedure;
use App\Models\PatientTreatment;
use App\Support\Specialty\SpecialtyRegistry;
use InvalidArgumentException;

/**
 * The registry of treatment targets declared by specialty extensions
 * (config/clinic_specialties.php `targets`). The core asks it which targets
 * a specialty may use, and hands target items to their handler.
 */
class TreatmentTargets
{
    public function __construct(protected SpecialtyRegistry $specialties) {}

    /**
     * Target keys a specialty declares.
     *
     * @return list<string>
     */
    public function forSpecialty(?string $specialtyKey): array
    {
        return $specialtyKey === null ? [] : array_values((array) ($this->specialties->get($specialtyKey)['targets'] ?? []));
    }

    public function handler(string $key): TreatmentTarget
    {
        $class = config("clinic_specialties.targets.{$key}.handler") ?? throw new InvalidArgumentException("Unknown treatment target “{$key}”.");

        return app($class);
    }

    /** The target's display name, e.g. “Tooth”. */
    public function label(string $key): string
    {
        return (string) config("clinic_specialties.targets.{$key}.label", $key);
    }

    /**
     * Validate a specialty's targets for a catalog version.
     *
     * @param  list<string>  $targets
     * @return list<string>
     */
    public function assertDeclared(?string $specialtyKey, array $targets): array
    {
        $declared = $this->forSpecialty($specialtyKey);
        foreach ($targets as $target) {
            if (! in_array($target, $declared, true)) {
                throw new InvalidArgumentException("This specialty does not declare the target “{$target}”.");
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * Everything a treatment is aimed at, per target key.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function read(PatientTreatment $treatment): array
    {
        $out = [];
        foreach ($this->forSpecialty($treatment->specialty_key) as $key) {
            $out[$key] = $this->handler($key)->read($treatment);
        }

        return $out;
    }

    /**
     * Targets of many treatments: one query per declared target key.
     *
     * @param  iterable<PatientTreatment>  $treatments
     * @return array<int, array<string, list<array<string, mixed>>>> treatment id => target key => items
     */
    public function readMany(iterable $treatments): array
    {
        $byKey = [];
        $clinicId = null;
        foreach ($treatments as $treatment) {
            $clinicId ??= $treatment->clinic_id;
            foreach ($this->forSpecialty($treatment->specialty_key) as $key) {
                $byKey[$key][] = $treatment->id;
            }
        }

        $out = [];
        foreach ($byKey as $key => $ids) {
            $items = $this->handler($key)->readMany((int) $clinicId, $ids);
            foreach ($ids as $id) {
                $out[$id][$key] = $items[$id] ?? [];
            }
        }

        return $out;
    }

    /**
     * What a procedure done for this treatment was done on: the treatment's
     * target when it names exactly one (one tooth); nothing when ambiguous.
     *
     * @return array<string, mixed>
     */
    public function procedureDetails(PatientTreatment $treatment): array
    {
        foreach ($this->read($treatment) as $items) {
            if (count($items) === 1) {
                return array_filter($items[0], fn ($value) => $value !== null);
            }
        }

        return [];
    }

    /** How a performed procedure reads on a bill or a record: its name and, when a target describes it, what it was done on. */
    public function describeProcedure(PatientProcedure $procedure): string
    {
        foreach (array_keys((array) config('clinic_specialties.targets', [])) as $key) {
            $suffix = $this->handler($key)->describe((array) ($procedure->details ?? []));
            if ($suffix !== null) {
                return $procedure->name.$suffix;
            }
        }

        return (string) $procedure->name;
    }
}
