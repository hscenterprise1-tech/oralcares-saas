<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Concerns\AnswersDomainErrors;
use App\Http\Controllers\Concerns\BindsToActiveClinic;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\ClinicConsentType;
use App\Models\ConsentRecord;
use App\Models\Patient;
use App\Services\ClinicContext;
use App\Services\Patient\ConsentService;
use App\Services\Patient\ConsentTypes;
use App\Services\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    /**
     * A consent form to print and sign: the wording of every consent the clinic asks for now (or the one named by
     * `type`), the patient's identity, and signature lines. Printing records nothing.
     */
    public function print(Request $request, Patient $patient, ConsentTypes $types): Response
    {
        $this->inActiveClinic($patient);
        $this->authorize('view', $patient);
        abort_unless($request->user()->hasClinicPermission(ClinicPermission::ConsentManage), 403);
        $clinic = Clinic::query()->findOrFail($patient->clinic_id);
        $only = $request->query('type');

        $forms = $types->available($clinic)
            ->when(is_string($only) && $only !== '', fn ($list) => $list->where('key', $only))
            ->map(fn (ClinicConsentType $type) => ['label' => $type->label, 'version' => (int) $type->current_version, 'text' => (string) $types->current($type)?->text])
            ->filter(fn (array $form) => $form['text'] !== '')
            ->values()->all();
        abort_if($forms === [], 404);

        app(SecurityAudit::class)->record('patient.consent_form_printed', $patient, ['consents' => count($forms)]);

        return response()->view('clinic.consent_form_printable', [
            'clinic' => $clinic,
            'branch' => app(ClinicContext::class)->workingBranch(),
            'patient' => $patient,
            'forms' => $forms,
        ]);
    }

    public function revoke(Request $request, Patient $patient, ConsentRecord $consent): JsonResponse
    {
        $this->inActiveClinic($patient, $consent);
        abort_unless($consent->patient_id === $patient->id, 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->attemptJson(fn () => ['data' => ['id' => $this->consents->revoke($consent, $reason, $request->user())->id, 'status' => ConsentRecord::REVOKED]]);
    }
}
