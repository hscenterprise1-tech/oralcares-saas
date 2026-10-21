<?php

namespace App\Services\Treatment\Targets;

use App\Models\PatientTreatment;

/**
 * Something a treatment can be aimed at, declared by a specialty extension
 * (dental declares `tooth`). The core engine never knows what a target is;
 * it only asks the handler to validate, store and describe its items.
 */
interface TreatmentTarget
{
    /** The key a specialty declares and a catalog version requires: `tooth`. */
    public function key(): string;

    /**
     * Validate and normalise the items given for this target.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException
     */
    public function normalize(array $items): array;

    /**
     * Store the items against a newly started treatment.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function store(PatientTreatment $treatment, array $items): void;

    /**
     * The items of a treatment, for reading.
     *
     * @return list<array<string, mixed>>
     */
    public function read(PatientTreatment $treatment): array;

    /**
     * The items of many treatments in one query, keyed by treatment id.
     *
     * @param  list<int>  $treatmentIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function readMany(int $clinicId, array $treatmentIds): array;

    /**
     * A short suffix describing a performed procedure's target, from its
     * details (" (tooth 36)"), or null when it has none.
     *
     * @param  array<string, mixed>  $details
     */
    public function describe(array $details): ?string;
}
