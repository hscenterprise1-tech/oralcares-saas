<?php

namespace App\Http\Controllers\Clinic;

use App\AppointmentSource;
use App\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentSeries;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\QueueItemStatus;
use App\Services\Appointments\AppointmentAvailabilityService;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Appointments\AppointmentPresenter;
use App\Services\Appointments\AppointmentSettingsService;
use App\Services\ClinicContext;
use App\Services\Reception\PatientFlowConfigService;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The branch's appointment calendar, laid out by date and time with every
 * authorised provider's appointments together (provider is a filter, never
 * the layout), and every change to its bookings. Each action is
 * permission-gated at the route; here every record is also held to the
 * active clinic and branch.
 */
class AppointmentController extends Controller
{
    public const VIEWS = ['day', 'week', 'month'];

    /** The most appointments one calendar view loads. */
    public const MAX_APPOINTMENTS = 1500;

    public const PERIODS = ['any', 'morning', 'afternoon', 'evening'];

    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicAccess $access,
        protected AppointmentAvailabilityService $availability,
        protected AppointmentBookingService $booking,
        protected AppointmentSettingsService $settings,
        protected PatientFlowConfigService $flowConfig,
    ) {}

    public function index(Request $request): Response
    {
        [$clinic, $branch] = $this->scope();

        $settings = $this->settings->resolve($clinic);
        $today = ClinicClock::today();
        $view = in_array($request->input('view'), self::VIEWS, true) ? $request->input('view') : 'week';
        $date = $this->parseDate($request->input('date')) ?? $today;
        [$from, $to] = $this->range($view, $date);
        $roomsEnabled = $this->flowConfig->roomsEnabled($clinic);

        $providers = $this->availability->providers($clinic, $branch);
        $providerIds = $providers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $employee = $this->clinicContext->currentEmployee();
        $canCreate = $this->access->allows(['permissions' => ['appointment.create']]);

        // A provider who cannot book opens on their own appointments; anyone
        // can widen the filter to the whole branch.
        $requestedProviders = $request->has('providers')
            ? array_values(array_intersect(array_map('intval', array_filter((array) $request->input('providers'))), $providerIds))
            : (! $canCreate && $employee && in_array($employee->id, $providerIds, true) ? [$employee->id] : []);

        $filters = [
            'providers' => $requestedProviders,
            'service_id' => $request->integer('service_id') ?: null,
            'statuses' => array_values(array_intersect((array) $request->input('statuses', []), [...AppointmentStatus::values(), 'waiting'])),
            'q' => mb_substr(trim((string) $request->input('q', '')), 0, 60),
        ];

        $appointments = $this->appointmentsQuery($clinic, $branch, $from, $to, $filters)
            ->with(['patient:id,first_name,last_name,name,uhid,phone', 'practitioner:id,name', 'service:id,name', 'room:id,name,type', 'queueItem'])
            ->orderBy('scheduled_at')
            ->limit(self::MAX_APPOINTMENTS + 1)
            ->get();

        $visibleProviderIds = $requestedProviders ?: $providerIds;
        $leaveDays = $this->availability->leaveDays($clinic, $visibleProviderIds, $from, $to);
        $truncated = $appointments->count() > self::MAX_APPOINTMENTS;
        $rows = $appointments->take(self::MAX_APPOINTMENTS)
            ->map(fn (Appointment $appointment) => AppointmentPresenter::toArray($appointment, $roomsEnabled, $leaveDays))
            ->filter(fn (array $row) => $filters['statuses'] === [] || in_array($row['display_status'], $filters['statuses'], true) || in_array($row['status'], $filters['statuses'], true))
            ->values();

        $timed = $view !== 'month';
        $plans = $timed ? $this->availability->providerPlans($clinic, $branch, $visibleProviderIds, $from, $to) : [];

        return Inertia::render('ClinicPortal/Appointments/Index', [
            'branch' => ['id' => $branch->id, 'name' => $branch->display_name ?: $branch->name],
            'view' => $view,
            'date' => $date->toDateString(),
            'today' => $today->toDateString(),
            'now' => ClinicClock::now()->format('Y-m-d\TH:i:s'),
            'range' => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
            'filters' => $filters,
            'appointments' => $rows,
            'truncated' => $truncated,
            'kpis' => $this->kpis($clinic, $branch, $today),
            'days' => $this->availability->branchDays($clinic, $branch, $from, $to),
            'dayWindows' => $timed ? $this->unionWindows($plans) : [],
            'providers' => $providers->map(fn (ClinicEmployee $provider) => [
                'id' => (int) $provider->id,
                'name' => $provider->name,
                'specialty' => $provider->practitionerProfile?->specialty,
                'is_me' => $employee?->id === $provider->id,
                // The services they provide; empty means not restricted.
                'service_ids' => $provider->services->pluck('id')->map(fn ($id) => (int) $id)->values(),
            ])->values(),
            'services' => $this->services($clinic, $branch),
            'settings' => $settings + ['configured' => $clinic->appointment_config !== null],
            'options' => [
                'statuses' => collect(AppointmentStatus::cases())->map(fn (AppointmentStatus $status) => ['value' => $status->value, 'label' => $status->label()])->push(['value' => 'waiting', 'label' => 'Waiting'])->values(),
                'frequencies' => AppointmentSeries::FREQUENCIES,
                'periods' => self::PERIODS,
            ],
            'can' => [
                'create' => $canCreate,
                'manage' => $this->access->allows(['permissions' => ['appointment.manage']]),
                'check_in' => $this->access->allows(['permissions' => ['queue.manage']]),
                'settings' => $this->access->allows(['permissions' => ['settings.manage']]),
                'view_patient' => $this->access->allows(['permissions' => ['patient.view'], 'module' => 'patients']),
                'register_patient' => $this->access->allows(['permissions' => ['patient.create'], 'module' => 'patients']),
            ],
            'links' => [
                'index' => route('clinic.appointments.index'),
                'store' => route('clinic.appointments.store'),
                'availability' => route('clinic.appointments.availability'),
                'patientSearch' => route('clinic.patients.search'),
                'patient' => route('clinic.patients.show', ['patient' => '__ID__']),
                'registerPatient' => route('clinic.patients.create'),
                'checkIn' => route('clinic.reception.check-in'),
                'settings' => route('clinic.appointments.settings'),
            ],
            // Loaded after the calendar: the side panel, the open times of a
            // single day, and bookings hit by leave.
            'context' => Inertia::defer(fn () => $this->context($clinic, $branch, $providers, $settings)),
            'freeTimes' => Inertia::defer(fn () => $view === 'day' && $date->gte($today)
                ? $this->freeTimes($clinic, $branch, $visibleProviderIds, $date, $settings)
                : []),
        ]);
    }

    /**
     * Free slots for a service, with one provider or any: what the booking
     * form, Reschedule and Find next available offer.
     */
    public function availability(Request $request): JsonResponse
    {
        [$clinic, $branch] = $this->scope();
        $settings = $this->settings->resolve($clinic);

        $validated = $request->validate([
            'provider_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'appointment_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.AppointmentAvailabilityService::MAX_SEARCH_DAYS],
            'period' => ['nullable', Rule::in(self::PERIODS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        // Moving an appointment: its own length, and its own slot counts as free.
        $moving = isset($validated['appointment_id'])
            ? Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)->find($validated['appointment_id'])
            : null;
        $service = isset($validated['service_id']) ? $this->service($clinic, $branch, (int) $validated['service_id']) : null;

        // Only providers who offer the service are searched.
        $providers = $this->availability->providers($clinic, $branch, $service['id'] ?? $moving?->service_id)->keyBy('id');
        $ids = isset($validated['provider_id'])
            ? ($providers->has($validated['provider_id']) ? [(int) $validated['provider_id']] : [])
            : $providers->keys()->map(fn ($id) => (int) $id)->all();
        $duration = $moving?->duration_minutes ?? $service['duration_minutes'] ?? $settings['default_duration_minutes'];

        $from = $this->parseDate($validated['from'] ?? null) ?? ClinicClock::today();
        if ($from->lt(ClinicClock::today())) {
            $from = ClinicClock::today();
        }

        $slots = $this->availability->freeSlots(
            $clinic,
            $branch,
            $ids,
            $from,
            $validated['days'] ?? 14,
            (int) $duration,
            $validated['limit'] ?? 24,
            null,
            ($validated['period'] ?? 'any') === 'any' ? null : $validated['period'],
            $moving?->id,
        );

        return response()->json([
            'duration_minutes' => (int) $duration,
            'slots' => array_map(fn (array $slot) => $slot + ['provider_name' => $providers->get($slot['provider_id'])?->name], $slots),
        ]);
    }

    /**
     * Book an existing patient, or a new case by name and mobile. The room or
     * chair, the source and the length are decided here, not on the form.
     */
    public function store(Request $request): RedirectResponse
    {
        [$clinic, $branch] = $this->scope();
        $settings = $this->settings->resolve($clinic);
        $providerIds = $this->availability->providers($clinic, $branch)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $newCase = $request->filled('new_case');

        $validated = $request->validate([
            'patient_id' => [Rule::requiredIf(! $newCase), 'nullable', 'integer'],
            'new_case' => ['nullable', 'array'],
            'new_case.name' => [Rule::requiredIf($newCase), 'nullable', 'string', 'max:150'],
            'new_case.phone' => [Rule::requiredIf($newCase), 'nullable', 'string', 'regex:/^[0-9+\-\s]{7,20}$/'],
            'new_case.not_a_duplicate' => ['nullable', 'boolean'],
            'practitioner_id' => [
                'required', 'integer',
                Rule::exists('clinic_employees', 'id')->where('clinic_id', $clinic->id),
                Rule::in($providerIds),
            ],
            'service_id' => ['nullable', 'integer'],
            'scheduled_at' => ['required', 'date'],
            // Not on the form: the service sets the length. Kept for API use.
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'recurrence' => ['nullable', 'array'],
            'recurrence.frequency' => ['required_with:recurrence', Rule::in(AppointmentSeries::FREQUENCIES)],
            'recurrence.occurrences' => ['required_with:recurrence', 'integer', 'min:2', 'max:'.$settings['max_recurring_occurrences']],
        ], [
            'practitioner_id.in' => 'Choose a provider who works at this branch.',
            'new_case.phone.regex' => 'Enter a valid mobile number.',
            'patient_id.required' => 'Choose a registered patient, or book them as a new case.',
            'new_case.name.required' => 'Enter their name.',
            'new_case.phone.required' => 'Enter a mobile number.',
        ]);

        if (! empty($validated['recurrence']) && ! $settings['recurring_enabled']) {
            throw ValidationException::withMessages(['recurrence' => 'Recurring appointments are turned off for this clinic.']);
        }

        $service = isset($validated['service_id']) ? $this->service($clinic, $branch, (int) $validated['service_id']) : null;
        if (isset($validated['service_id']) && ! $service) {
            throw ValidationException::withMessages(['service_id' => 'Choose a service offered at this branch.']);
        }

        $patient = null;
        $prospect = null;
        if ($newCase) {
            $prospect = ['name' => trim($validated['new_case']['name']), 'phone' => preg_replace('/\s+/', '', $validated['new_case']['phone'])];
            $this->guardAgainstDuplicate($clinic, $prospect['phone'], (bool) ($validated['new_case']['not_a_duplicate'] ?? false));
        } else {
            $patient = Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->find($validated['patient_id'])
                ?? throw ValidationException::withMessages(['patient_id' => 'Choose a patient of this clinic.']);
        }

        $provider = ClinicEmployee::withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($validated['practitioner_id']);
        $this->assertProvides($clinic, $branch, $provider, $service);
        $employee = $this->clinicContext->currentEmployee();

        $appointment = $this->booking->book($clinic, $branch, $patient, $provider, [
            'scheduled_at' => CarbonImmutable::parse($validated['scheduled_at'])->setSecond(0),
            'duration_minutes' => (int) ($validated['duration_minutes'] ?? $service['duration_minutes'] ?? $settings['default_duration_minutes']),
            'service_id' => $service['id'] ?? null,
            'appointment_type' => $patient ? 'consultation' : 'new_case',
            'source' => $employee && in_array($employee->id, $providerIds, true) ? AppointmentSource::Doctor : AppointmentSource::Reception,
            'notes' => $validated['notes'] ?? null,
            'recurrence' => $validated['recurrence'] ?? null,
            'prospect' => $prospect,
        ], $request->user());

        $who = $patient?->full_name ?? $prospect['name'];
        $when = $appointment->scheduled_at->format('D j M \a\t g:i A');
        $series = ! empty($validated['recurrence']) ? " ({$validated['recurrence']['occurrences']} appointments)" : '';

        return back()->with('success', "Booked {$who} with {$provider->name} on {$when}{$series}.");
    }

    /**
     * Move an appointment to another free time or provider. Drag and drop and
     * Reschedule both come here; the length stays the appointment's own.
     */
    public function reschedule(Request $request, Appointment $appointment): RedirectResponse
    {
        [$clinic, $branch] = $this->scope();
        $this->assertOwn($appointment, $clinic, $branch);
        $providerIds = $this->availability->providers($clinic, $branch)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $validated = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'practitioner_id' => ['nullable', 'integer', Rule::in($providerIds)],
            'scope' => ['nullable', Rule::in([AppointmentBookingService::SCOPE_THIS, AppointmentBookingService::SCOPE_FOLLOWING, AppointmentBookingService::SCOPE_SERIES])],
        ], [
            'practitioner_id.in' => 'Choose a provider who works at this branch.',
        ]);

        $provider = ClinicEmployee::withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->findOrFail($validated['practitioner_id'] ?? $appointment->practitioner_id);
        if ($appointment->service_id && (int) $provider->id !== (int) $appointment->practitioner_id) {
            $this->assertProvides($clinic, $branch, $provider, $this->service($clinic, $branch, (int) $appointment->service_id));
        }
        $scheduledAt = CarbonImmutable::parse($validated['scheduled_at'])->setSecond(0);

        $moved = $this->booking->reschedule($clinic, $branch, $appointment, [
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => (int) ($validated['duration_minutes'] ?? $appointment->duration_minutes ?? 15),
            'practitioner' => $provider,
        ], $validated['scope'] ?? AppointmentBookingService::SCOPE_THIS, $request->user());

        return back()->with('success', $moved > 1
            ? "Moved {$moved} appointments in the series."
            : 'Moved to '.$scheduledAt->format('D j M \a\t g:i A')." with {$provider->name}.");
    }

    /**
     * The calendar's status steps (confirm, arrived, no-show). Cancelling has
     * its own action; check-in goes through Reception.
     */
    public function updateStatus(Request $request, Appointment $appointment): RedirectResponse
    {
        [$clinic, $branch] = $this->scope();
        $this->assertOwn($appointment, $clinic, $branch);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(AppointmentStatus::values())],
        ]);
        $status = AppointmentStatus::from($validated['status']);

        if ($status === AppointmentStatus::Cancelled) {
            $this->booking->cancel($clinic, $appointment, null, AppointmentBookingService::SCOPE_THIS, $request->user());

            return back()->with('success', 'Appointment cancelled.');
        }

        $this->booking->setStatus($clinic, $appointment, $status, $request->user());

        return back()->with('success', "Marked {$status->label()}.");
    }

    public function cancel(Request $request, Appointment $appointment): RedirectResponse
    {
        [$clinic, $branch] = $this->scope();
        $this->assertOwn($appointment, $clinic, $branch);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'scope' => ['nullable', Rule::in([AppointmentBookingService::SCOPE_THIS, AppointmentBookingService::SCOPE_FOLLOWING, AppointmentBookingService::SCOPE_SERIES])],
        ]);

        $count = $this->booking->cancel($clinic, $appointment, $validated['reason'] ?? null, $validated['scope'] ?? AppointmentBookingService::SCOPE_THIS, $request->user());

        return back()->with('success', $count > 1 ? "Cancelled {$count} appointments." : 'Appointment cancelled.');
    }

    /**
     * A new case arrived and was registered: link the booking to their record.
     */
    public function linkPatient(Request $request, Appointment $appointment): RedirectResponse
    {
        [$clinic, $branch] = $this->scope();
        $this->assertOwn($appointment, $clinic, $branch);

        $validated = $request->validate(['patient_id' => ['required', 'integer']]);
        $patient = Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->find($validated['patient_id'])
            ?? throw ValidationException::withMessages(['patient_id' => 'Choose a patient of this clinic.']);

        $this->booking->linkPatient($clinic, $appointment, $patient, $request->user());

        return back()->with('success', "Linked to {$patient->full_name}. They can be checked in now.");
    }

    /**
     * @return array{0: Clinic, 1: Branch}
     */
    private function scope(): array
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403, 'No active clinic selected.');

        return [$clinic, $this->clinicContext->requireBranch()];
    }

    /**
     * An appointment of another clinic or another branch does not exist here.
     */
    private function assertOwn(Appointment $appointment, Clinic $clinic, Branch $branch): void
    {
        if ((int) $appointment->clinic_id !== $clinic->id || (int) $appointment->branch_id !== $branch->id) {
            abort(404);
        }
    }

    /**
     * A provider with services configured is only booked for those services.
     *
     * @param  array{id: int, name: string}|null  $service
     */
    private function assertProvides(Clinic $clinic, Branch $branch, ClinicEmployee $provider, ?array $service): void
    {
        if (! $service) {
            return;
        }

        $eligible = $this->availability->providers($clinic, $branch, $service['id'])->contains('id', $provider->id);
        if (! $eligible) {
            throw ValidationException::withMessages(['practitioner_id' => "{$provider->name} does not provide {$service['name']}."]);
        }
    }

    /**
     * A new case whose mobile already belongs to a registered patient is
     * probably that patient: book them instead, unless the desk confirms it
     * is someone else on the same number (a family phone).
     */
    private function guardAgainstDuplicate(Clinic $clinic, string $phone, bool $confirmedDistinct): void
    {
        if ($confirmedDistinct) {
            return;
        }

        $matches = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where(fn ($q) => $q->where('phone', $phone)->orWhere('alternate_phone', $phone))
            ->limit(3)
            ->get(['id', 'name', 'first_name', 'last_name', 'uhid']);

        if ($matches->isNotEmpty()) {
            throw ValidationException::withMessages([
                'new_case.phone' => 'Already registered on this number: '.$matches->map(fn (Patient $p) => trim($p->full_name.' '.($p->uhid ? "({$p->uhid})" : '')))->implode(', ')
                    .'. Book them as an existing patient, or confirm this is someone else.',
            ]);
        }
    }

    /**
     * @param  array{providers: list<int>, service_id: int|null, statuses: list<string>, q: string}  $filters
     * @return Builder<Appointment>
     */
    private function appointmentsQuery(Clinic $clinic, Branch $branch, CarbonImmutable $from, CarbonImmutable $to, array $filters): Builder
    {
        $search = $filters['q'];
        $reference = preg_match('/^(?:APT-?)?0*(\d{1,9})$/i', $search, $match) ? (int) $match[1] : null;

        return Appointment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->whereBetween('scheduled_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($filters['providers'] !== [], fn ($q) => $q->whereIn('practitioner_id', $filters['providers']))
            ->when($filters['service_id'], fn ($q, $id) => $q->where('service_id', $id))
            ->when($filters['statuses'] !== [], function ($q) use ($filters) {
                $stored = array_values(array_unique(array_map(fn ($status) => $status === 'waiting' ? AppointmentStatus::CheckedIn->value : $status, $filters['statuses'])));
                $q->whereIn('status', $stored);
            })
            ->when(mb_strlen($search) >= 2, fn ($q) => $q->where(function ($match) use ($search, $reference) {
                $match->whereHas('patient', fn ($patient) => $patient->withoutGlobalScopes()->matchingSearch($search))
                    ->orWhere('prospect_name', 'like', "%{$search}%")
                    ->orWhere('prospect_phone', 'like', "{$search}%")
                    ->orWhereHas('practitioner', fn ($provider) => $provider->withoutGlobalScopes()->where('name', 'like', "%{$search}%"));
                if ($reference) {
                    $match->orWhere('appointments.id', $reference);
                }
            }));
    }

    /**
     * Today's figures at the branch, in two bounded queries.
     *
     * @return array<string, int>
     */
    private function kpis(Clinic $clinic, Branch $branch, CarbonImmutable $today): array
    {
        $base = fn () => Appointment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->whereBetween('scheduled_at', [$today, $today->endOfDay()]);

        $counts = $base()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $waiting = $base()
            ->where('status', AppointmentStatus::CheckedIn->value)
            ->whereHas('queueItem', fn ($q) => $q->whereIn('status', [QueueItemStatus::Waiting->value, QueueItemStatus::Calling->value, QueueItemStatus::Called->value, QueueItemStatus::Recalled->value]))
            ->count();

        $count = fn (AppointmentStatus $status) => (int) ($counts[$status->value] ?? 0);

        return [
            'today' => (int) $counts->sum() - $count(AppointmentStatus::Cancelled),
            'confirmed' => $count(AppointmentStatus::Confirmed),
            'arrived' => $count(AppointmentStatus::Arrived),
            'waiting' => $waiting,
            'in_consultation' => $count(AppointmentStatus::InConsultation),
            'completed' => $count(AppointmentStatus::Completed),
            'cancelled' => $count(AppointmentStatus::Cancelled),
            'no_show' => $count(AppointmentStatus::NoShow),
        ];
    }

    /**
     * The side panel: who is free and when, and bookings hit by leave.
     *
     * @param  Collection<int, ClinicEmployee>  $providers
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function context(Clinic $clinic, Branch $branch, Collection $providers, array $settings): array
    {
        return [
            'providers' => $this->availability->providerStatuses($clinic, $branch, $providers, $settings['default_duration_minutes']),
            'onLeave' => $this->availability->appointmentsOnLeave($clinic, $branch)
                ->map(fn (Appointment $appointment) => AppointmentPresenter::toArray($appointment, false, [$appointment->practitioner_id.'|'.$appointment->scheduled_at->toDateString() => true]))
                ->values(),
        ];
    }

    /**
     * The open times of one day for any of the visible providers, for the Day
     * view's "available" marks.
     *
     * @param  list<int>  $providerIds
     * @param  array<string, mixed>  $settings
     * @return list<array{start: string, providers: int}>
     */
    private function freeTimes(Clinic $clinic, Branch $branch, array $providerIds, CarbonImmutable $date, array $settings): array
    {
        return collect($this->availability->freeSlots($clinic, $branch, $providerIds, $date, 1, $settings['default_duration_minutes'], 400))
            ->groupBy('start')
            ->map(fn (Collection $slots, string $start) => ['start' => $start, 'providers' => $slots->count()])
            ->values()->all();
    }

    /**
     * The services bookable at this branch, with the branch's own duration
     * where it sets one.
     *
     * @return list<array{id: int, name: string, category: string|null, duration_minutes: int|null}>
     */
    private function services(Clinic $clinic, Branch $branch): array
    {
        return ClinicalProcedure::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('is_active', true)
            ->with(['prices' => fn ($q) => $q->withoutGlobalScopes()->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branch->id))])
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'category', 'default_duration_minutes'])
            ->reject(fn (ClinicalProcedure $procedure) => $procedure->prices->firstWhere('branch_id', $branch->id)?->is_available === false)
            ->map(fn (ClinicalProcedure $procedure) => [
                'id' => $procedure->id,
                'name' => $procedure->name,
                'category' => $procedure->category,
                'duration_minutes' => $procedure->prices->firstWhere('branch_id', $branch->id)?->duration_minutes
                    ?? $procedure->prices->firstWhere('branch_id', null)?->duration_minutes
                    ?? $procedure->default_duration_minutes,
            ])
            ->values()->all();
    }

    /**
     * @return array{id: int, name: string, category: string|null, duration_minutes: int|null}|null
     */
    private function service(Clinic $clinic, Branch $branch, int $id): ?array
    {
        return collect($this->services($clinic, $branch))->firstWhere('id', $id);
    }

    /**
     * Union of the visible providers' working windows per day, for shading
     * time when nobody works.
     *
     * @param  array<string, array<int, array{state: string, scheduled: bool, windows: list<array{0: int, 1: int}>}>>  $plans
     * @return array<string, list<array{0: int, 1: int}>>
     */
    private function unionWindows(array $plans): array
    {
        $union = [];
        foreach ($plans as $date => $byProvider) {
            $windows = collect($byProvider)->flatMap(fn (array $plan) => $plan['windows'])->sortBy(0)->values()->all();
            $merged = [];
            foreach ($windows as [$start, $end]) {
                $last = count($merged) - 1;
                if ($last >= 0 && $start <= $merged[$last][1]) {
                    $merged[$last][1] = max($merged[$last][1], $end);
                } else {
                    $merged[] = [$start, $end];
                }
            }
            $union[$date] = $merged;
        }

        return $union;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(string $view, CarbonImmutable $date): array
    {
        $monday = $date->startOfWeek(CarbonImmutable::MONDAY);

        return match ($view) {
            'week' => [$monday, $monday->addDays(6)],
            'month' => [$date->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY), $date->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            default => [$date, $date],
        };
    }
}
