<?php

namespace App\Http\Controllers;

use App\Models\ClinicalDepartment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClinicalDepartmentController extends Controller
{
    public function index()
    {
        return Inertia::render('Clinical/Master/Departments/Index', [
            'departments' => ClinicalDepartment::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        ClinicalDepartment::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, ClinicalDepartment $department)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $department->update($validated);

        return redirect()->back();
    }

    public function destroy(ClinicalDepartment $department)
    {
        $department->delete();

        return redirect()->back();
    }
}
