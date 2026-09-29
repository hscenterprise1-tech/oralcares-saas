<?php

namespace App\Http\Controllers;

use App\Models\TreatmentCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TreatmentCatalogController extends Controller
{
    public function index()
    {
        return Inertia::render('Clinical/Master/Treatments/Index', [
            'treatments' => TreatmentCatalog::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        TreatmentCatalog::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, TreatmentCatalog $treatment)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $treatment->update($validated);

        return redirect()->back();
    }

    public function destroy(TreatmentCatalog $treatment)
    {
        $treatment->delete();

        return redirect()->back();
    }
}
