<?php

namespace App\Services\Governance\Actions;

use App\BillingStatus;
use App\ClinicPermission;
use App\Models\DeletionRequest;
use App\Models\PatientBilling;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ClinicContext;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * A bill is never deleted: it is cancelled (voided) through the canonical
 * billing service, and only while no money stands against it.
 */
class VoidBill extends BaseGovernedAction
{
    public function __construct(
        protected BillingService $billing,
        protected ClinicContext $clinicContext,
    ) {}

    public function key(): string
    {
        return 'bill.void';
    }

    public function label(): string
    {
        return 'Void bill';
    }

    public function targetType(): string
    {
        return 'bill';
    }

    public function requestPermission(): ClinicPermission
    {
        return ClinicPermission::BillingCreate;
    }

    public function find(int $clinicId, int $targetId): ?Model
    {
        return PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereKey($targetId)->first();
    }

    public function describe(Model $target): array
    {
        /** @var PatientBilling $target */
        return [
            'label' => "Bill {$target->billing_number} (₹".number_format((float) $target->final_amount, 2).')',
            'patient_id' => $target->patient_id,
            'branch_id' => $target->branch_id,
        ];
    }

    public function fingerprint(Model $target): string
    {
        /** @var PatientBilling $target */
        return $this->hash([$target->id, $target->status?->value, (string) $target->final_amount, (string) $target->paid_amount, (string) $target->refunded_amount]);
    }

    public function assertActionable(Model $target): void
    {
        /** @var PatientBilling $target */
        if ($target->status === BillingStatus::Cancelled) {
            throw new DomainException('This bill is already void.');
        }
        if ((float) $target->paid_amount > (float) $target->refunded_amount) {
            throw new DomainException('This bill has payments against it. Refund them before voiding the bill.');
        }
    }

    public function execute(Model $target, DeletionRequest $request, User $actor): void
    {
        /** @var PatientBilling $target */
        $this->billing->cancelBill($target, $this->clinicContext->currentEmployee(), "Voided (request #{$request->id}): {$request->reason}");
    }
}
