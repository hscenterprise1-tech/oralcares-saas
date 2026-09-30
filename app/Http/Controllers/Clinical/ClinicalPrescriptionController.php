<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\ClinicalEncounter;
use App\Policies\PatientPolicy;
use App\Services\ClinicalPrescriptionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClinicalPrescriptionController extends Controller
{
    public function index(ClinicalEncounter $encounter)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $prescriptions = $encounter->prescriptions()->with(['items', 'prescriber'])->latest()->get();

        return Inertia::render('Clinical/Encounters/Prescriptions/Index', [
            'encounter' => $encounter,
            'prescriptions' => $prescriptions,
        ]);
    }

    public function store(Request $request, ClinicalEncounter $encounter, ClinicalPrescriptionService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);
        abort_unless(app(PatientPolicy::class)->writeClinicalData(auth()->user(), $encounter->patient), 403, 'You are not authorized to write clinical data for this patient.');

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'details' => 'nullable|array',
            'items' => 'required|array|min:1',
            'items.*.drug_master_id' => 'nullable|integer',
            'items.*.drug_name' => 'required|string|max:255',
            'items.*.dosage' => 'required|string|max:255',
            'items.*.frequency' => 'required|string|max:255',
            'items.*.duration' => 'required|string|max:255',
            'items.*.instructions' => 'nullable|string',
            'items.*.details' => 'nullable|array',
        ]);

        $service->issuePrescription($encounter, [
            'notes' => $validated['notes'] ?? null,
            'details' => $validated['details'] ?? [],
        ], $validated['items']);

        return redirect()->back()->with('success', 'Prescription issued.');
    }
}
