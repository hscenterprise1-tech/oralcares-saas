<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\DiagnosisMaster;
use App\Services\ClinicalDiagnosisService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClinicalDiagnosisController extends Controller
{
    public function index(ClinicalEncounter $encounter)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $diagnoses = $encounter->diagnoses()->with(['master', 'author'])->latest()->get();
        $masterList = DiagnosisMaster::where('organization_id', auth()->user()->organization_id)->get();

        return Inertia::render('Clinical/Encounters/Diagnoses/Index', [
            'encounter' => $encounter,
            'diagnoses' => $diagnoses,
            'masterList' => $masterList,
        ]);
    }

    public function store(Request $request, ClinicalEncounter $encounter, ClinicalDiagnosisService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $validated = $request->validate([
            'diagnosis_master_id' => 'nullable|exists:diagnosis_masters,id',
            'description' => 'required_without:diagnosis_master_id|string|max:255',
            'details' => 'nullable|array',
            'status' => 'nullable|string',
        ]);

        $service->addDiagnosis($encounter, $validated);

        return redirect()->back()->with('success', 'Diagnosis added.');
    }

    public function updateStatus(Request $request, ClinicalEncounter $encounter, ClinicalDiagnosis $diagnosis, ClinicalDiagnosisService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);
        abort_if($diagnosis->encounter_id !== $encounter->id, 403);

        $validated = $request->validate([
            'status' => 'required|string|in:active,resolved,differential,ruled_out',
        ]);

        $service->updateStatus($diagnosis, $validated['status']);

        return redirect()->back()->with('success', 'Diagnosis status updated.');
    }
}
