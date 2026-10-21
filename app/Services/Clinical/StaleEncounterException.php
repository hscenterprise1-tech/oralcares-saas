<?php

namespace App\Services\Clinical;

use App\Models\ClinicalEncounter;
use RuntimeException;

/**
 * A draft was saved over a version the screen had not seen (another tab or
 * colleague saved first). Carries the current record so the screen can
 * reconcile instead of overwriting it.
 */
class StaleEncounterException extends RuntimeException
{
    public function __construct(public readonly ClinicalEncounter $current)
    {
        parent::__construct('This consultation changed since it was opened.');
    }
}
