<?php

namespace App\Http\Controllers\Clinic;

use App\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClinicEmployee;
use App\Models\ClinicOperatingHour;
use App\Models\ClinicRoom;
use App\Models\DoctorAvailability;
use App\Models\Leave;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Services\AppointmentConflictService;
use App\Services\ClinicContext;
use App\Services\Reception\PatientFlowConfigService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected AppointmentConflictService $conflictService,
        protected PatientFlowConfigService $flowConfig,
    ) {}

    /**
     * Display the appointment calendar — Day (one column per visible
     * doctor), Week, or Month. Deliberately range-scoped rather than
     * paginated: a calendar needs every appointment for the visible range
     * at once, not a page of rows.
     */
    public function index(Request $request): Response
    {
        $clinic = $this->clinicContext->clinic();
        $branch = $this->clinicContext->branch();

        if (! $clinic) {
            abort(403, 'No active clinic selected.');
        }

        $date = $request->input('date', today()->format('Y-m-d'));
        $carbonDate = Carbon::parse($date);
        $view = in_array($request->input('view'), ['day', 'week', 'month'], true) ? $request->input('view') : 'day';
        // Cast to int — query-string values always arrive as strings, and
        // the strict `in_array()` comparison below (and the `whereIn()`
        // query further down) both need to match `$p['id']`'s actual int
        // type or every doctor silently filters out to none.
        $requestedPractitionerIds = array_map('intval', array_filter((array) $request->input('practitioner_ids', [])));
        // Rooms off (Settings > Patient Flow): no room filter, no room shown.
        $roomsEnabled = $this->flowConfig->roomsEnabled($clinic);
        $roomId = $roomsEnabled ? $request->input('room_id') : null;

        // Week/Month need every appointment across the visible range, not
        // just `$date` — Week/Month views render several days at once, and
        // Month's grid also shows the leading/trailing days of adjacent
        // months (the same days FullCalendar's `dayGridMonth` renders), so
        // the query range is padded out to that full displayed grid.
        [$rangeStart, $rangeEnd] = match ($view) {
            'week' => [
                $carbonDate->copy()->startOfWeek(Carbon::MONDAY),
                $carbonDate->copy()->startOfWeek(Carbon::MONDAY)->addDays(6),
            ],
            'month' => [
                $carbonDate->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY),
                $carbonDate->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY),
            ],
            default => [$carbonDate->copy(), $carbonDate->copy()],
        };

        $headerTitle = $view === 'month' ? $carbonDate->format('F Y') : ($view === 'week' ? $rangeStart->format('F Y') : $carbonDate->format('l, d F Y'));
        $headerSubtitle = $view === 'day' ? null : $rangeStart->format('d M').' – '.$rangeEnd->format('d M Y');

        // Every active practitioner for the picker, and `ClinicEmployee::
        // activePractitioners()` already reads `practitionerProfile->specialty`
        // correctly — the old `index()` here instead selected `job_title`/
        // `designation` directly off `clinic_employees`, columns that don't
        // exist on that table (same latent bug already found and fixed the
        // same way in `Patient360Controller`/`PatientController`).
        $allPractitioners = ClinicEmployee::activePractitioners($clinic->id);

        $visiblePractitioners = empty($requestedPractitionerIds)
            ? $allPractitioners
            : array_values(array_filter($allPractitioners, fn ($p) => in_array($p['id'], $requestedPractitionerIds, true)));

        $visibleIds = array_column($visiblePractitioners, 'id');

        $appointments = Appointment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->whereBetween('scheduled_at', [$rangeStart->copy()->startOfDay(), $rangeEnd->copy()->endOfDay()])
            ->when(! empty($visibleIds), fn ($q) => $q->whereIn('practitioner_id', $visibleIds))
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->with(['patient', 'room'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Appointment $appt) => [
                'id' => $appt->id,
                'patient_id' => $appt->patient_id,
                'patient_name' => $appt->patient?->full_name ?? 'Patient',
                'patient_uhid' => $appt->patient?->uhid ?? '—',
                'patient_phone' => $appt->patient?->phone ?? '—',
                'practitioner_id' => $appt->practitioner_id,
                'room_name' => $roomsEnabled ? $appt->room?->name : null,
                // Deliberately NOT `toIso8601String()` — this app stores and
                // reads `scheduled_at` as literal clinic-wall-clock digits
                // (see the note on `store()`), but Eloquent always *labels*
                // a freshly-read datetime with `config('app.timezone')`
                // (UTC) regardless of what timezone wrote it. An ISO string
                // with that (wrong) "+00:00" suffix told the browser this
                // was a real UTC instant and it "correctly" shifted the
                // display by the browser's own UTC offset — the appointment
                // silently moved 5.5 hours in this deployment's real usage
                // (IST). A timezone-less string leaves the browser/
                // FullCalendar to interpret the same literal digits in its
                // own local time, which is what was actually intended.
                'scheduled_at' => $appt->scheduled_at?->format('Y-m-d\TH:i:s'),
                'scheduled_time' => $appt->scheduled_at?->format('h:i A'),
                'duration_minutes' => $appt->duration_minutes ?? 15,
                'status' => $appt->status?->value ?? 'scheduled',
                'appointment_type' => $appt->appointment_type ?? 'consultation',
                'notes' => $appt->notes,
            ]);

        // Doctors on approved leave that date — `Leave.employee_id` is a
        // `clinic_employees.id`, the same id `Appointment.practitioner_id`
        // and this page's practitioner list both use.
        $leaveEmployeeIds = Leave::where('clinic_id', $clinic->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id')
            ->all();

        // Recurring split-shift windows for the visible doctors, for this
        // day of week. `DoctorAvailability` is keyed by `doctor_profiles.id`
        // (via `practitionerProfile`), not `clinic_employees.id` — resolved
        // per practitioner below, same FK direction `ClinicalEncounter.
        // provider_id` already uses elsewhere in this codebase.
        $availabilityDayOfWeek = $carbonDate->dayOfWeek === 0 ? 7 : $carbonDate->dayOfWeek;
        $employeesWithProfiles = ClinicEmployee::withoutGlobalScopes()
            ->whereIn('id', $visibleIds)
            ->with('practitionerProfile')
            ->get(['id']);

        $availabilityByPractitioner = [];
        foreach ($employeesWithProfiles as $employee) {
            $doctorProfileId = $employee->practitionerProfile?->id;
            if (! $doctorProfileId) {
                continue;
            }

            $rows = DoctorAvailability::where('doctor_profile_id', $doctorProfileId)
                ->where('day_of_week', $availabilityDayOfWeek)
                ->orderBy('start_time')
                ->get(['start_time', 'end_time', 'is_available']);

            if ($rows->isNotEmpty()) {
                $availabilityByPractitioner[$employee->id] = $rows->map(fn ($row) => [
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                    'is_available' => $row->is_available,
                ])->all();
            }
        }

        $operatingHour = $branch
            ? ClinicOperatingHour::where('branch_id', $branch->id)->where('day_of_week', $carbonDate->dayOfWeek)->first()
            : null;

        $rooms = $branch && $roomsEnabled
            ? ClinicRoom::where('branch_id', $branch->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type'])
            : collect();

        return Inertia::render('Clinic/Appointments/Index', [
            'date' => $date,
            'dateFormatted' => $carbonDate->format('l, d M Y'),
            'view' => $view,
            'headerTitle' => $headerTitle,
            'headerSubtitle' => $headerSubtitle,
            'rangeStart' => $rangeStart->format('Y-m-d'),
            'rangeEnd' => $rangeEnd->format('Y-m-d'),
            'practitioners' => array_values($visiblePractitioners),
            'allPractitioners' => $allPractitioners,
            'appointments' => $appointments,
            'leaveEmployeeIds' => $leaveEmployeeIds,
            'availabilityByPractitioner' => $availabilityByPractitioner,
            'operatingHours' => $operatingHour ? [
                'is_closed' => $operatingHour->is_closed,
                'open_time' => $operatingHour->open_time,
                'close_time' => $operatingHour->close_time,
            ] : null,
            'rooms' => $rooms,
            'filters' => [
                'date' => $date,
                'view' => $view,
                'practitioner_ids' => $requestedPractitionerIds,
                'room_id' => $roomId,
            ],
        ]);
    }

    /**
     * Schedule a new appointment — now conflict-checked (branch hours,
     * doctor leave, doctor availability, double-booking), where the old
     * version accepted any date/time unconditionally.
     */
    public function store(Request $request): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic();
        $branch = $this->clinicContext->branch();

        if (! $clinic) {
            abort(403, 'No active clinic selected.');
        }
        if (! $branch) {
            abort(403, 'No active branch selected.');
        }

        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'practitioner_id' => ['required', 'integer', 'exists:clinic_employees,id'],
            'room_id' => ['nullable', 'integer', 'exists:clinic_rooms,id'],
            'scheduled_at' => ['required', 'date', 'after_or_equal:now - 1 hour'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'appointment_type' => ['nullable', 'string', 'in:consultation,follow_up,procedure,emergency,general'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $patient = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->findOrFail($validated['patient_id']);

        // No explicit timezone here, matching how a stored value is always
        // *read* back (tagged with `app.timezone`/UTC regardless of what
        // wrote it, per Eloquent's datetime cast) — parsing with the same
        // implicit default here keeps every `scheduled_at` Carbon in this
        // request (this one, and the ones `AppointmentConflictService`
        // reads back from the DB to check against) using one consistent
        // labeling, so comparisons between them stay correct. The literal
        // digits are the clinic's real wall-clock time regardless of the
        // (technically wrong) UTC label — see `index()`'s serialization
        // note for the one place that label actually had to be worked
        // around, on the way out to the browser.
        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        $duration = $validated['duration_minutes'] ?? 15;

        $conflict = $this->conflictService->checkConflict(
            $branch,
            $validated['practitioner_id'],
            $scheduledAt,
            $duration
        );

        if ($conflict) {
            return back()->withErrors(['scheduled_at' => $conflict])->withInput();
        }

        $appointment = Appointment::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            // Ignored while rooms are off; rooms already stored stay untouched.
            'room_id' => $this->flowConfig->roomsEnabled($clinic) ? ($validated['room_id'] ?? null) : null,
            'patient_id' => $patient->id,
            'practitioner_id' => $validated['practitioner_id'],
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => $duration,
            'status' => AppointmentStatus::Scheduled->value,
            'appointment_type' => $validated['appointment_type'] ?? 'consultation',
            'notes' => $validated['notes'] ?? null,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $practitioner = $appointment->practitioner;
        $docName = $practitioner?->name ?? 'attending provider';
        $scheduledTime = $scheduledAt->format('d M Y at h:i A');

        PatientTimelineEvent::create([
            'organization_id' => $clinic->organization_id,
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'actor_id' => auth()->id(),
            'event_type' => 'appointment_scheduled',
            'context' => [
                'appointment_id' => $appointment->id,
                'practitioner' => $docName,
                'scheduled_at' => $scheduledTime,
                'booked_by' => auth()->user()?->name ?? 'Reception',
            ],
        ]);

        return back()->with('success', "Appointment scheduled for {$patient->full_name} on {$scheduledTime}");
    }

    /**
     * Update appointment status.
     */
    public function updateStatus(Appointment $appointment, Request $request): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic();
        if (! $clinic || $appointment->clinic_id !== $clinic->id) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', AppointmentStatus::values())],
        ]);

        $appointment->update([
            'status' => $validated['status'],
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', "Appointment status updated to {$validated['status']}");
    }
}
