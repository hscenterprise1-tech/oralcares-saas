<?php

namespace App\Services;

use App\Events\LaboratoryAccepted;
use App\Events\LaboratoryCompleted;
use App\Events\LaboratoryDelivered;
use App\Events\LaboratoryProductionStarted;
use App\Events\LaboratoryReady;
use App\LabCaseStatus;
use App\Models\Clinic;
use App\Models\LabOrder;
use App\Models\PatientTimelineEvent;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LaboratoryWorkflowService
{
    /**
     * The clinic's lab workflow: ordered → sent → in progress → received →
     * fitted → completed, or cancelled (with a reason) before completion; a
     * received case can go back for a remake. Each step keeps when it
     * happened, the timeline and the audit trail.
     *
     * @var array<string, array{from: list<string>, to: LabCaseStatus, stamp: ?string}>
     */
    public const STEPS = [
        'send' => ['from' => ['draft'], 'to' => LabCaseStatus::Requested, 'stamp' => 'sent_at'],
        'start' => ['from' => ['requested', 'accepted'], 'to' => LabCaseStatus::InProduction, 'stamp' => null],
        'receive' => ['from' => ['requested', 'accepted', 'in_production', 'quality_check', 'ready_for_delivery'], 'to' => LabCaseStatus::Delivered, 'stamp' => 'received_at'],
        'remake' => ['from' => ['delivered', 'fitted'], 'to' => LabCaseStatus::InProduction, 'stamp' => null],
        'fit' => ['from' => ['delivered'], 'to' => LabCaseStatus::Fitted, 'stamp' => 'fitted_at'],
        'complete' => ['from' => ['fitted'], 'to' => LabCaseStatus::Completed, 'stamp' => 'completed_at'],
        'cancel' => ['from' => ['draft', 'requested', 'accepted', 'in_production', 'quality_check', 'ready_for_delivery', 'delivered'], 'to' => LabCaseStatus::Cancelled, 'stamp' => 'cancelled_at'],
    ];

    /** Steps the front desk takes (sending out, receiving back); the rest are clinical. */
    public const DESK_STEPS = ['send', 'receive'];

    /**
     * @throws DomainException|InvalidArgumentException
     */
    public function advance(LabOrder $labCase, string $step, ?string $reason = null): LabOrder
    {
        $rule = self::STEPS[$step] ?? throw new InvalidArgumentException('Unknown lab step.');

        return DB::transaction(function () use ($labCase, $step, $rule, $reason) {
            /** @var LabOrder $locked */
            $locked = LabOrder::query()->withoutGlobalScopes()->whereKey($labCase->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status instanceof LabCaseStatus ? $locked->status->value : (string) $locked->status;
            if (! in_array($from, $rule['from'], true)) {
                throw new DomainException("A lab case that is {$from} cannot be moved to {$rule['to']->value}.");
            }
            if ($step === 'cancel' && mb_strlen(trim((string) $reason)) < 3) {
                throw new InvalidArgumentException('Say why the lab case is cancelled.');
            }

            $locked->update(array_filter([
                'status' => $rule['to'],
                $rule['stamp'] => $rule['stamp'] ? now() : null,
                'cancellation_reason' => $step === 'cancel' ? mb_substr(trim((string) $reason), 0, 500) : null,
            ], fn ($value, $key) => $key !== '' && ($value !== null || $key === 'status'), ARRAY_FILTER_USE_BOTH));

            $this->recordStep($locked, "lab_case_{$step}", $reason);

            return $locked;
        });
    }

    /** Timeline and audit for a lab step. */
    public function recordStep(LabOrder $labCase, string $event, ?string $reason): void
    {
        PatientTimelineEvent::create([
            'organization_id' => Clinic::query()->whereKey($labCase->clinic_id)->value('organization_id'),
            'clinic_id' => $labCase->clinic_id,
            'patient_id' => $labCase->patient_id,
            'actor_id' => auth()->id(),
            'event_type' => $event,
            'context' => ['lab_order_number' => $labCase->lab_order_number, 'restoration' => $labCase->restoration, 'teeth' => $labCase->requested_teeth, 'reason' => $reason],
        ]);
        app(SecurityAudit::class)->record("dental.lab.{$event}", $labCase, ['patient_id' => $labCase->patient_id, 'reason' => $reason], ['clinic_id' => $labCase->clinic_id]);
    }

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
