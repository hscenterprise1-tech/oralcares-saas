<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DoctorAvailabilityController extends Controller
{
    public function index()
    {
        return Inertia::render('Clinical/Master/Availability/Index', [
            'availabilities' => DoctorAvailability::with(['branch', 'doctorProfile.clinicEmployee'])->get(),
            'branches' => Branch::all(),
            'doctors' => DoctorProfile::with('clinicEmployee')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'doctor_profile_id' => 'required|exists:doctor_profiles,id',
            'day_of_week' => 'required|integer|min:1|max:7',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'is_available' => 'boolean',
        ]);

        DoctorAvailability::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, DoctorAvailability $availability)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'doctor_profile_id' => 'required|exists:doctor_profiles,id',
            'day_of_week' => 'required|integer|min:1|max:7',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'is_available' => 'boolean',
        ]);

        $availability->update($validated);

        return redirect()->back();
    }

    public function destroy(DoctorAvailability $availability)
    {
        $availability->delete();

        return redirect()->back();
    }
}
