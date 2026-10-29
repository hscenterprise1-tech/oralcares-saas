<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\PatientVisit;
use App\Services\ClinicContext;
use App\Services\Patient\PatientCareGuard;
use App\Support\ClinicClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VisitController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext
    ) {}

    /**
     * Display clinic visits list.
     */
    public function index(Request $request): Response
    {
        $clinic = $this->clinicContext->clinic();

        if (! $clinic) {
            abort(403, 'No active clinic selected.');
        }

        $branch = $this->clinicContext->branchScopeOrAbort();
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? null;

        $query = PatientVisit::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->when($date, fn ($q) => $q->whereOnDate('visit_date', $date))
            ->with(['patient', 'doctor', 'branch', 'caseNumber', 'queueItem'])
            ->orderBy('visit_date', 'desc')
            ->orderBy('id', 'desc');

        $visits = $query->paginate(20)->through(function (PatientVisit $visit) {
            return [
                'id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'patient_name' => $visit->patient?->full_name ?? 'Patient',
                'patient_uhid' => $visit->patient?->uhid ?? '—',
                'case_number' => $visit->caseNumber?->case_number ?? sprintf('#V-%d', $visit->id),
                'practitioner_name' => $visit->doctor?->name ?? 'Attending Provider',
                'branch_name' => $visit->branch?->name ?? 'Main Branch',
                'visit_date' => $visit->visit_date?->format('d M Y') ?? '—',
                'visit_status' => $visit->visit_status ?? 'registered',
                'queue_status' => $visit->queue_status ?? 'not_queued',
                'notes' => $visit->notes,
            ];
        });

        return Inertia::render('Clinic/Visits/Index', [
            'visits' => $visits,
            'filters' => [
                'date' => $date,
            ],
        ]);
    }

    /**
     * Start / Create a new visit.
     */
    public function store(Request $request): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic();

        if (! $clinic) {
            abort(403, 'No active clinic selected.');
        }

        $branch = $this->clinicContext->requireBranch();

        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'doctor_id' => ['nullable', 'integer', Rule::exists('clinic_employees', 'id')->where('clinic_id', $clinic->id)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $patient = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->findOrFail($validated['patient_id']);
        app(PatientCareGuard::class)->assertCanReceiveCare($patient);

        // Classified like a check-in (New until the patient has been seen) and dated
        // on the clinic's own calendar day, under the patient's lock.
        $visit = DB::transaction(function () use ($clinic, $branch, $patient, $validated) {
            Patient::withoutGlobalScopes()->whereKey($patient->id)->lockForUpdate()->first(['id']);

            return PatientVisit::create([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'doctor_id' => $validated['doctor_id'] ?? null,
                'registration_type' => PatientVisit::classifyNext($clinic->id, $patient->id)->value,
                'visit_date' => ClinicClock::now()->toDateString(),
                'queue_status' => 'waiting',
                'visit_status' => 'in_progress',
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        PatientTimelineEvent::create([
            'organization_id' => $clinic->organization_id,
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'actor_id' => auth()->id(),
            'event_type' => 'visit_started',
            'context' => [
                'visit_id' => $visit->id,
                'created_by' => auth()->user()?->name ?? 'Staff',
            ],
        ]);

        return redirect()->route('clinic.patients.show', $patient->id)
            ->with('success', "Visit created for {$patient->full_name}");
    }
}
