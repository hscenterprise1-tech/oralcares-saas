<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Concerns\AnswersDomainErrors;
use App\Http\Controllers\Concerns\BindsToActiveClinic;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\ClinicContext;
use App\Services\Treatment\TreatmentCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Treatment charges outside a consultation: what this patient owes on their
 * treatments (upfront, completed stages, procedures from finalised
 * consultations) and billing a chosen set of them. `TreatmentCheckout`
 * authorises, locks, re-checks and audits.
 */
class TreatmentChargeController extends Controller
{
    use AnswersDomainErrors, BindsToActiveClinic;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected TreatmentCheckout $checkout,
    ) {}

    public function index(Patient $patient): JsonResponse
    {
        $this->inActiveClinic($patient);
        $this->authorize('view', $patient);

        return response()->json(['data' => collect($this->checkout->dueCharges($patient))
            ->map(fn (array $line) => collect($line)->except('source')->all())->values()]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->inActiveClinic($patient);
        $data = $request->validate([
            'charges' => ['required', 'array', 'min:1', 'max:100'],
            'charges.*' => ['string', 'max:40', 'distinct'],
            'discount' => ['nullable', 'numeric', 'min:0'],
        ], ['charges.required' => 'Choose at least one charge to bill.']);

        return $this->attemptJson(function () use ($request, $patient, $data) {
            $bill = $this->checkout->bill($patient, $this->clinicContext->requireBranch(), $data['charges'], $request->user(), $this->clinicContext->currentEmployee(), (float) ($data['discount'] ?? 0));

            return ['data' => ['id' => $bill->id, 'billing_number' => $bill->billing_number, 'total' => (float) $bill->total_amount]];
        }, 201);
    }
}
