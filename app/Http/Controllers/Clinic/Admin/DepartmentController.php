<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClinicDepartment;
use App\Services\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $departments = ClinicDepartment::latest()->get();

        return Inertia::render('Clinic/Admin/Infrastructure/Departments', [
            'departments' => $departments,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, ClinicContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'key' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('clinic_departments')->where(function ($query) use ($context) {
                    return $query->where('clinic_id', $context->clinic()->id);
                }),
            ],
            'is_active' => ['boolean'],
        ]);

        $validated['clinic_id'] = $context->clinic()->id;

        ClinicDepartment::create($validated);

        return redirect()->route('admin.departments.index')->with('success', 'Department created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ClinicDepartment $department, ClinicContext $context): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'key' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('clinic_departments')->ignore($department->id)->where(function ($query) use ($context) {
                    return $query->where('clinic_id', $context->clinic()->id);
                }),
            ],
            'is_active' => ['boolean'],
        ]);

        $department->update($validated);

        return redirect()->route('admin.departments.index')->with('success', 'Department updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ClinicDepartment $department): RedirectResponse
    {
        $department->delete();

        return redirect()->route('admin.departments.index')->with('success', 'Department deleted successfully.');
    }
}
