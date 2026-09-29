<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientVisit;
use App\Models\Practitioner;
use App\Services\ClinicContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClinicDashboardController extends Controller
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Operational Clinic Command Center Dashboard.
     */
    public function index(Request $request): Response
    {
        $clinic = $this->clinicContext->clinic();
        $branch = $this->clinicContext->branch();

        $branchId = $branch?->id;

        // 1. Appointments Today
        $appointmentsQuery = Appointment::where('clinic_id', $clinic->id)
            ->whereDate('scheduled_at', today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $totalAppointmentsToday = (clone $appointmentsQuery)->count();
        $scheduledAppointments = (clone $appointmentsQuery)->where('status', 'scheduled')->count();
        $checkedInAppointments = (clone $appointmentsQuery)->where('status', 'checked_in')->count();
        $completedAppointments = (clone $appointmentsQuery)->where('status', 'completed')->count();

        $upcomingAppointments = (clone $appointmentsQuery)
            ->with(['patient', 'practitioner'])
            ->orderBy('scheduled_at')
            ->take(6)
            ->get()
            ->map(fn ($appt) => [
                'id' => $appt->id,
                'patient_name' => $appt->patient ? ($appt->patient->first_name.' '.$appt->patient->last_name) : 'Patient #'.$appt->patient_id,
                'practitioner_name' => $appt->practitioner ? ($appt->practitioner->first_name.' '.$appt->practitioner->last_name) : 'Any Available',
                'scheduled_at' => $appt->scheduled_at ? $appt->scheduled_at->format('h:i A') : '--:--',
                'status' => $appt->status?->value,
                'type' => $appt->type ?? 'Consultation',
            ]);

        // 2. Queue Today
        $rawQueue = ClinicQueueItem::whereHas('queue', function ($q) use ($clinic, $branchId) {
            $q->where('clinic_id', $clinic->id)
                ->when($branchId, fn ($b) => $b->where('branch_id', $branchId));
        })
            ->whereDate('created_at', today())
            ->whereIn('status', ['waiting', 'in_consultation'])
            ->with(['patientVisit.patient', 'patientVisit.doctor', 'encounter.patient', 'encounter.doctor'])
            ->orderBy('position')
            ->take(8)
            ->get();

        $waitingCount = $rawQueue->where('status', 'waiting')->count();
        $inConsultationCount = $rawQueue->where('status', 'in_consultation')->count();

        $queueItems = $rawQueue->map(function ($item) {
            $patient = $item->patientVisit?->patient ?? $item->encounter?->patient;
            $doctor = $item->patientVisit?->doctor ?? $item->encounter?->doctor;

            return [
                'id' => $item->id,
                'case_number' => $item->case_number ?? ('Q-'.$item->position),
                'position' => $item->position,
                'priority' => $item->priority instanceof \BackedEnum ? $item->priority->value : ($item->priority ?? 'normal'),
                'status' => $item->status instanceof \BackedEnum ? $item->status->value : ($item->status ?? 'waiting'),
                'patient_name' => $patient ? ($patient->first_name.' '.$patient->last_name) : 'Walk-in Patient',
                'doctor_name' => $doctor ? ($doctor->first_name.' '.$doctor->last_name) : null,
                'called_at' => $item->called_at?->format('h:i A'),
            ];
        });

        // 3. New Patients Registered Today
        $patientsRegisteredToday = Patient::where('clinic_id', $clinic->id)
            ->whereDate('created_at', today())
            ->count();

        // 4. Visits Today
        $todaysVisitsCount = PatientVisit::where('clinic_id', $clinic->id)
            ->whereDate('visit_date', today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();

        // 5. Billing Snapshot Today
        $collectionsToday = PatientBilling::where('clinic_id', $clinic->id)
            ->whereDate('created_at', today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('final_amount');

        // 6. Active Practitioners
        $practitioners = Practitioner::where('clinic_id', $clinic->id)
            ->where('is_active', true)
            ->with('employee')
            ->take(6)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'specialty' => $p->specialty ?? 'General Practice',
                'qualifications' => $p->qualifications,
                'is_active' => $p->is_active,
            ]);

        // 7. "My day" — only meaningful for a practitioner, since it's built
        // entirely from `Patient::assignedTo()`, the same scope backing
        // "My Patients" on the directory. Reuses `$rawQueue` (already
        // fetched above for the clinic-wide queue) rather than a second
        // query.
        $employee = $this->clinicContext->currentEmployee();
        $myDay = null;

        if ($employee?->practitionerProfile !== null) {
            $myPatientIds = Patient::assignedTo($employee->id)->where('clinic_id', $clinic->id)->pluck('id');

            $myDay = [
                'today_appointments' => (clone $appointmentsQuery)->whereIn('patient_id', $myPatientIds)->count(),
                'patients_in_queue' => $rawQueue->filter(function ($item) use ($myPatientIds) {
                    $patientId = $item->patientVisit?->patient_id ?? $item->encounter?->patient_id;

                    return $patientId && $myPatientIds->contains($patientId);
                })->count(),
                'total_assigned' => $myPatientIds->count(),
            ];
        }

        return Inertia::render('Clinic/Dashboard', [
            'metrics' => [
                'totalAppointments' => $totalAppointmentsToday,
                'scheduledAppointments' => $scheduledAppointments,
                'checkedInAppointments' => $checkedInAppointments,
                'completedAppointments' => $completedAppointments,
                'waitingCount' => $waitingCount,
                'inConsultationCount' => $inConsultationCount,
                'patientsRegisteredToday' => $patientsRegisteredToday,
                'todaysVisitsCount' => $todaysVisitsCount,
                'collectionsToday' => (float) $collectionsToday,
            ],
            'upcomingAppointments' => $upcomingAppointments,
            'queue' => $queueItems,
            'practitioners' => $practitioners,
            'myDay' => $myDay,
        ]);
    }
}
