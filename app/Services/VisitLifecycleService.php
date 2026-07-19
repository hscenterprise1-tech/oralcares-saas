<?php

namespace App\Services;

use App\Events\VisitCancelled;
use App\Events\VisitCompleted;
use App\Models\ClinicalEncounter;
use App\VisitStatus;

class VisitLifecycleService
{
    /**
     * Transition a clinical encounter's lifecycle status.
     */
    public function transitionStatus(ClinicalEncounter $encounter, VisitStatus $newStatus): void
    {
        $encounter->status = $newStatus;

        if ($newStatus === VisitStatus::Completed) {
            $encounter->ended_at = now();
            $encounter->save();
            event(new VisitCompleted($encounter));

            return;
        }

        if ($newStatus === VisitStatus::Cancelled) {
            $encounter->save();
            event(new VisitCancelled($encounter));

            return;
        }

        $encounter->save();
    }
}
