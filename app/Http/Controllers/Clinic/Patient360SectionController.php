<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Services\ClinicContext;
use App\Services\DentalChartService;
use App\Services\Patient\Patient360\Patient360Access;
use App\Services\Patient\Patient360\Patient360SectionPolicy;
use App\Services\Patient\Patient360\Patient360Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Patient 360's sections, each fetched when opened, one bounded page at a
 * time. Every request checks the patient is in the active clinic and asks
 * `Patient360SectionPolicy` whether this user may open the section; the
 * section builders then send only the fields the policy grants. Branch-held
 * records are limited to the member's branches by `Patient360Sections`.
 */
class Patient360SectionController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected Patient360Access $access,
        protected Patient360SectionPolicy $policy,
        protected Patient360Sections $sections,
    ) {}

    public function show(Request $request, Patient $patient, string $section): JsonResponse
    {
        $this->authorize('view', $patient);
        abort_unless($patient->clinic_id === $this->clinicContext->clinic()?->id, 404);

        $user = $request->user();
        abort_unless(array_key_exists($section, self::SECTIONS), 404);
        abort_unless($this->policy->allows($user, $patient, $section), 403);
        $page = max(1, (int) $request->query('page', 1));

        $data = match ($section) {
            'timeline' => $this->sections->timeline($patient, $user, $request->query('category') ?: null, $page),
            'visits' => $this->sections->visits($patient, $user, $page),
            'encounters' => $this->sections->encounters($patient, $user, $page),
            'appointments' => $this->sections->appointments(
                $patient, $user, $request->validate(['scope' => ['nullable', Rule::in(['upcoming', 'history'])]])['scope'] ?? 'upcoming', $page,
            ),
            'treatment' => $this->sections->treatment($patient, $user, $page),
            'prescriptions' => $this->sections->prescriptions($patient, $user, $page),
            'investigations' => $this->sections->investigations(
                $patient, $user, $request->validate(['status' => ['nullable', Rule::in(['pending', 'ordered', 'collected', 'resulted', 'reviewed', 'cancelled'])]])['status'] ?? null, $page,
            ),
            'billing' => $this->sections->billing($patient, $user, $page),
            'documents' => $this->sections->documents($patient, $user, $page),
            'follow-ups' => $this->sections->followUps($patient, $user),
            'communications' => $this->sections->communications($patient, $user, $page),
            'requests' => $this->sections->requests($patient, $user, $page),
            'audit' => $this->sections->audit($patient, $page),
            'dental' => $this->sections->dental($patient, $user),
            'physio' => $this->sections->physio($patient, $user),
        };

        return response()->json($data);
    }

    /** Endpoint names this controller answers. */
    private const SECTIONS = [
        'timeline' => true, 'visits' => true, 'encounters' => true, 'appointments' => true, 'treatment' => true, 'prescriptions' => true,
        'investigations' => true, 'billing' => true, 'documents' => true, 'follow-ups' => true, 'communications' => true,
        'requests' => true, 'audit' => true, 'dental' => true, 'physio' => true,
    ];

    public function bill(Request $request, Patient $patient, int $bill): JsonResponse
    {
        $this->authorize('view', $patient);
        abort_unless($patient->clinic_id === $this->clinicContext->clinic()?->id, 404);
        abort_unless($this->policy->allows($request->user(), $patient, 'billing'), 403);

        $record = PatientBilling::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->when($this->access->branchIds() !== null, fn ($query) => $query->whereIn('branch_id', $this->access->branchIds()))
            ->findOrFail($bill);

        return response()->json($this->sections->bill($record, $request->user()));
    }

    public function tooth(Request $request, Patient $patient, int $tooth): JsonResponse
    {
        $this->authorize('view', $patient);
        abort_unless($patient->clinic_id === $this->clinicContext->clinic()?->id, 404);
        // Tooth detail is clinical: the front desk and billing get the dental summary only.
        abort_unless($this->policy->field($request->user(), $patient, 'dental'), 403);
        abort_unless(app(DentalChartService::class)->isValidFdiToothNumber($tooth), 404);

        return response()->json($this->sections->tooth($patient, $request->user(), $tooth));
    }
}
