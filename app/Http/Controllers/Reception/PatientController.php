<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\RegisterPatientRequest;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Services\ClinicContext;
use App\Services\Patient\PatientRegistrationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public function __construct(
        protected PatientRegistrationService $registrationService,
        protected ClinicContext $clinicContext
    ) {}

    /**
     * Reception dashboard — today's visits.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Patient::class);

        $clinic = $this->clinicContext->clinic();
        $branch = $this->clinicContext->branch();

        $todaysVisits = PatientVisit::with(['patient', 'caseNumber', 'doctor', 'queueItem'])
            ->where('clinic_id', $clinic->id)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->whereDate('visit_date', today())
            ->latest()
            ->get();

        return Inertia::render('Reception/PatientIndex', [
            'visits' => $todaysVisits,
        ]);
    }

    /**
     * New patient registration form.
     */
    public function create(): Response
    {
        $this->authorize('create', Patient::class);

        return Inertia::render('Reception/PatientCreate');
    }

    /**
     * Store a new patient and their first visit.
     */
    public function store(RegisterPatientRequest $request): RedirectResponse
    {
        $this->authorize('create', Patient::class);

        $clinic = $this->clinicContext->clinic();
        $branch = $this->clinicContext->branch();

        $visit = $this->registrationService->registerNewPatient(
            patientData: $request->patientData(),
            visitData: $request->visitData(),
            clinic: $clinic,
            branch: $branch
        );

        // Create profile record if address/emergency data was provided
        $profileData = $request->profileData();
        if (array_filter($profileData)) {
            $visit->patient->profile()->create($profileData);
        }

        return redirect()
            ->route('reception.visits.show', $visit)
            ->with('success', "Patient registered. UHID: {$visit->patient->uhid} | Case No: {$visit->caseNumber->case_number}");
    }
}
