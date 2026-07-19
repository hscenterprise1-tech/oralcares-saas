<?php

namespace App\Services;

use App\Events\LaboratoryAccepted;
use App\Events\LaboratoryCompleted;
use App\Events\LaboratoryDelivered;
use App\Events\LaboratoryProductionStarted;
use App\Events\LaboratoryReady;
use App\LabCaseStatus;
use App\Models\LabOrder;
use Illuminate\Support\Facades\DB;

class LaboratoryWorkflowService
{
    /**
     * Accept a laboratory request case.
     */
    public function acceptCase(LabOrder $labCase): void
    {
        DB::transaction(function () use ($labCase) {
            $labCase->update(['status' => LabCaseStatus::Accepted]);
            event(new LaboratoryAccepted($labCase));
        });
    }

    /**
     * Start prosthetic case production.
     */
    public function startProduction(LabOrder $labCase): void
    {
        DB::transaction(function () use ($labCase) {
            $labCase->update(['status' => LabCaseStatus::InProduction]);
            event(new LaboratoryProductionStarted($labCase));
        });
    }

    /**
     * Mark the prosthetic case finished and quality checked.
     */
    public function markReady(LabOrder $labCase): void
    {
        DB::transaction(function () use ($labCase) {
            $labCase->update(['status' => LabCaseStatus::ReadyForDelivery]);
            event(new LaboratoryReady($labCase));
        });
    }

    /**
     * Deliver the prosthetic case to the clinic branch.
     */
    public function deliverCase(LabOrder $labCase): void
    {
        DB::transaction(function () use ($labCase) {
            $labCase->update(['status' => LabCaseStatus::Delivered]);
            event(new LaboratoryDelivered($labCase));
        });
    }

    /**
     * Complete the prosthetic case (fitted and finished).
     */
    public function completeCase(LabOrder $labCase): void
    {
        DB::transaction(function () use ($labCase) {
            $labCase->update(['status' => LabCaseStatus::Completed]);
            event(new LaboratoryCompleted($labCase));
        });
    }
}
