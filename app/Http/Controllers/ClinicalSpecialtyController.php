<?php

namespace App\Http\Controllers;

use App\Models\ClinicalSpecialty;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClinicalSpecialtyController extends Controller
{
    public function index()
    {
        return Inertia::render('Clinical/Master/Specialties/Index', [
            'specialties' => ClinicalSpecialty::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        ClinicalSpecialty::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, ClinicalSpecialty $specialty)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $specialty->update($validated);

        return redirect()->back();
    }

    public function destroy(ClinicalSpecialty $specialty)
    {
        $specialty->delete();

        return redirect()->back();
    }
}
