<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Services\Appointments\AppointmentSettingsService;
use App\Services\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The clinic's appointment rules: slot length, default duration, buffer,
 * working days and hours, break, holidays and the booking policies.
 */
class AppointmentSettingsController extends Controller
{
    public function update(Request $request, ClinicContext $context, AppointmentSettingsService $settings): RedirectResponse
    {
        $clinic = $context->clinic() ?? abort(403);

        $settings->update($clinic, $request->validate(AppointmentSettingsService::rules()), $request->user());

        return back()->with('success', 'Appointment settings saved.');
    }
}
