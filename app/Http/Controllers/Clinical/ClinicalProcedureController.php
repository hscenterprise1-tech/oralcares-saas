<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ProcedureMaster;
use App\Services\ClinicalProcedureService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClinicalProcedureController extends Controller
{
    public function index(ClinicalEncounter $encounter)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $procedures = $encounter->procedures()->with(['master', 'performer'])->latest()->get();
        $masterList = ProcedureMaster::where('organization_id', auth()->user()->organization_id)->get();

        return Inertia::render('Clinical/Encounters/Procedures/Index', [
            'encounter' => $encounter,
            'procedures' => $procedures,
            'masterList' => $masterList,
        ]);
    }

    public function store(Request $request, ClinicalEncounter $encounter, ClinicalProcedureService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);

        $validated = $request->validate([
            'procedure_master_id' => 'nullable|exists:procedure_masters,id',
            'name' => 'required_without:procedure_master_id|string|max:255',
            'description' => 'nullable|string',
            'details' => 'nullable|array',
            'status' => 'nullable|string|in:planned,in_progress,completed,cancelled',
        ]);

        $service->recordProcedure($encounter, $validated);

        return redirect()->back()->with('success', 'Procedure recorded.');
    }

    public function updateStatus(Request $request, ClinicalEncounter $encounter, ClinicalProcedure $procedure, ClinicalProcedureService $service)
    {
        abort_if($encounter->organization_id !== auth()->user()->organization_id, 403);
        abort_if($procedure->encounter_id !== $encounter->id, 403);

        $validated = $request->validate([
            'status' => 'required|string|in:planned,in_progress,completed,cancelled',
        ]);

        $service->updateStatus($procedure, $validated['status']);

        return redirect()->back()->with('success', 'Procedure status updated.');
    }
}
