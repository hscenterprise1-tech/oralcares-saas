<?php

namespace App\Http\Controllers;

use App\Models\ProcedureMaster;
use App\Models\TreatmentCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProcedureMasterController extends Controller
{
    public function index()
    {
        return Inertia::render('Clinical/Master/Procedures/Index', [
            'procedures' => ProcedureMaster::with('treatmentCatalog')->get(),
            'catalogs' => TreatmentCatalog::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'treatment_catalog_id' => 'nullable|exists:treatment_catalogs,id',
            'code' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'base_price' => 'nullable|numeric|min:0',
            'duration_minutes' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
        ]);

        ProcedureMaster::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, ProcedureMaster $procedure)
    {
        $validated = $request->validate([
            'treatment_catalog_id' => 'nullable|exists:treatment_catalogs,id',
            'code' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'base_price' => 'nullable|numeric|min:0',
            'duration_minutes' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
        ]);

        $procedure->update($validated);

        return redirect()->back();
    }

    public function destroy(ProcedureMaster $procedure)
    {
        $procedure->delete();

        return redirect()->back();
    }
}
