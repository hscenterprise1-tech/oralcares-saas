<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\RegisterRepeatVisitRequest;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Services\ClinicContext;
use App\Services\Patient\PatientRegistrationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PatientVisitController extends Controller
{
    public function __construct(
        protected PatientRegistrationService $registrationService,
        protected ClinicContext $clinicContext
    ) {}

    /**
     * Create a repeat visit for an existing patient.
     */
    public function store(RegisterRepeatVisitRequest $request, Patient $patient): RedirectResponse
    {
        $this->authorize('addVisit', $patient);

        $clinic = $this->clinicContext->clinic();
        $branch = $this->clinicContext->branch();

        $visit = $this->registrationService->registerRepeatVisit(
            patient: $patient,
            visitData: $request->visitData(),
            clinic: $clinic,
            branch: $branch
        );

        return redirect()
            ->route('reception.visits.show', $visit)
            ->with('success', "Repeat visit created. Case No: {$visit->caseNumber->case_number}");
    }

    /**
     * Show a visit detail — case number, queue status, patient UHID.
     */
    public function show(PatientVisit $visit): Response
    {
        $this->authorize('view', $visit->patient);

        $visit->load(['patient.profile', 'caseNumber', 'doctor', 'queueItem', 'encounter']);

        return Inertia::render('Reception/VisitShow', [
            'visit' => $visit,
        ]);
    }
}
