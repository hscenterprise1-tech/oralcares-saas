<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Concerns\AnswersDomainErrors;
use App\Http\Controllers\Concerns\BindsToActiveClinic;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\ConsentRecord;
use App\Models\Patient;
use App\Services\Patient\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Consents API (X1). `ConsentService` authorises (`patient.view` to read,
 * `consent.manage` to capture or revoke) and keeps the evidence.
 */
class PatientConsentController extends Controller
{
    use AnswersDomainErrors, BindsToActiveClinic;

    public function __construct(protected ConsentService $consents) {}

    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->inActiveClinic($patient);

        $clinic = Clinic::query()->findOrFail($patient->clinic_id);

        return response()->json(['data' => $this->consents->for($patient, $request->user()), 'types' => $this->consents->types($clinic), 'options' => $this->consents->options($clinic), 'methods' => ConsentRecord::METHODS,
            'can_manage' => $request->user()->hasClinicPermission(ClinicPermission::ConsentManage)]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->inActiveClinic($patient);
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in($this->consents->types(Clinic::query()->findOrFail($patient->clinic_id)))],
            'method' => ['required', 'string', Rule::in(ConsentRecord::METHODS)],
            'version' => ['nullable', 'string', 'max:40'],
            'text' => ['nullable', 'string', 'max:20000'],
        ]);

        return $this->attemptJson(fn () => ['data' => ['id' => $this->consents->capture($patient, $data, $request->user())->id]], 201);
    }

    public function revoke(Request $request, Patient $patient, ConsentRecord $consent): JsonResponse
    {
        $this->inActiveClinic($patient, $consent);
        abort_unless($consent->patient_id === $patient->id, 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->attemptJson(fn () => ['data' => ['id' => $this->consents->revoke($consent, $reason, $request->user())->id, 'status' => ConsentRecord::REVOKED]]);
    }
}
