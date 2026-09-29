<?php

namespace App\Http\Controllers;

use App\Models\DiagnosisMaster;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DiagnosisMasterController extends Controller
{
    public function index()
    {
        return Inertia::render('Clinical/Master/Diagnoses/Index', [
            'diagnoses' => DiagnosisMaster::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        DiagnosisMaster::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, DiagnosisMaster $diagnosis)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $diagnosis->update($validated);

        return redirect()->back();
    }

    public function destroy(DiagnosisMaster $diagnosis)
    {
        $diagnosis->delete();

        return redirect()->back();
    }
}
