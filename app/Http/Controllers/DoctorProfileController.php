<?php

namespace App\Http\Controllers;

use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\DoctorProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DoctorProfileController extends Controller
{
    public function index()
    {
        return Inertia::render('Clinical/Master/Doctors/Index', [
            'doctors' => DoctorProfile::with(['clinicEmployee', 'specialties'])->get(),
            'employees' => ClinicEmployee::all(),
            'specialties' => ClinicalSpecialty::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'clinic_employee_id' => 'required|exists:clinic_employees,id',
            'registration_number' => 'nullable|string|max:255',
            'qualifications' => 'nullable|string|max:255',
            'experience_years' => 'nullable|integer|min:0',
            'bio' => 'nullable|string',
            'is_active' => 'boolean',
            'specialty_ids' => 'nullable|array',
            'specialty_ids.*' => 'exists:clinical_specialties,id',
        ]);

        $doctor = DoctorProfile::create($validated);
        if (isset($validated['specialty_ids'])) {
            $doctor->specialties()->sync($validated['specialty_ids']);
        }

        return redirect()->back();
    }

    public function update(Request $request, DoctorProfile $doctor)
    {
        $validated = $request->validate([
            'clinic_employee_id' => 'required|exists:clinic_employees,id',
            'registration_number' => 'nullable|string|max:255',
            'qualifications' => 'nullable|string|max:255',
            'experience_years' => 'nullable|integer|min:0',
            'bio' => 'nullable|string',
            'is_active' => 'boolean',
            'specialty_ids' => 'nullable|array',
            'specialty_ids.*' => 'exists:clinical_specialties,id',
        ]);

        $doctor->update($validated);
        if (isset($validated['specialty_ids'])) {
            $doctor->specialties()->sync($validated['specialty_ids']);
        }

        return redirect()->back();
    }

    public function destroy(DoctorProfile $doctor)
    {
        $doctor->delete();

        return redirect()->back();
    }
}
