<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Policies\PatientPolicy;
use App\Services\EncounterManagementService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EncounterController extends Controller
{
    public function index(Request $request)
    {
        $encounters = ClinicalEncounter::with(['patient', 'provider', 'branch'])
            ->where('organization_id', auth()->user()->organization_id)
            ->latest()
            ->paginate(20);

        return Inertia::render('Clinical/Encounters/Index', [
            'encounters' => $encounters,
        ]);
    }

    public function store(Request $request, EncounterManagementService $service)
    {
        $validated = $request->validate([
            'patient_id' => 'required|exists:patients,id',
            'branch_id' => 'required|exists:branches,id',
            'provider_id' => 'nullable|exists:doctor_profiles,id',
            'encounter_type' => 'nullable|string',
        ]);

        $patient = Patient::findOrFail($validated['patient_id']);
        $branch = Branch::findOrFail($validated['branch_id']);

        abort_unless(app(PatientPolicy::class)->writeClinicalData(auth()->user(), $patient), 403, 'You are not authorized to write clinical data for this patient.');

        $provider = null;

        if (! empty($validated['provider_id'])) {
            $provider = DoctorProfile::find($validated['provider_id']);
        }

        $encounter = $service->startEncounter($patient, $branch, $provider, $validated['encounter_type'] ?? 'consultation');

        return redirect()->route('clinical.encounters.workspace', $encounter->id);
    }

    public function workspace(ClinicalEncounter $encounter)
    {
        // Must belong to user's org
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $encounter->load([
            'patient.profile',
            'patient.alerts',
            'patient.prescriptions' => function ($query) {
                $query->where('status', 'active');
            },
            'provider',
            'clinicalNotes',
            'diagnoses',
            'treatments',
        ]);

        // Load timeline events specifically for this encounter
        $timelineEvents = PatientTimelineEvent::where('patient_id', $encounter->patient_id)
            ->where('organization_id', $encounter->organization_id)
            ->latest()
            ->take(10)
            ->get();

        return Inertia::render('Clinical/Encounters/Workspace', [
            'encounter' => $encounter,
            'timelineEvents' => $timelineEvents,
        ]);
    }

    public function complete(ClinicalEncounter $encounter, EncounterManagementService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);
        abort_unless(app(PatientPolicy::class)->writeClinicalData(auth()->user(), $encounter->patient), 403, 'You are not authorized to write clinical data for this patient.');

        $service->completeEncounter($encounter);

        return redirect()->back()->with('success', 'Encounter completed successfully.');
    }
}
