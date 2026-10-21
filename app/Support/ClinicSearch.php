<?php

namespace App\Support;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\CompanySupportTicket;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Patient\PatientSearch;
use App\Services\Support\ClinicSupportTickets;
use App\Support\ClinicAccess\ClinicAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The Clinic Portal's one search: what the top bar's box finds.
 *
 * Every group answers to the same rule as the place its results open, and is
 * left out entirely — not filtered in the browser — for anyone who may not
 * open that place, or when that place has no screen yet:
 *
 * - Pages: the destinations `ClinicNavigation` gives this user.
 * - Patients: by name, phone or UHID (`PatientSearch`, indexed; identity
 *   search is clinic-wide by design), where the user may search patients and
 *   start the canonical intake — today the only place a patient opens.
 * - Appointments: at the branch being worked, on the Calendar's own rule and
 *   matching (patient, prospect, provider, `APT-` reference), opening the
 *   Calendar on that day with the appointment selected; a typed date opens
 *   the Calendar on that date.
 * - Support: the requests this member may see (`ClinicSupportTickets`).
 *
 * Clinical records, billing, staff and branches are not searched: their new
 * screens do not exist yet, so a result would have nowhere to go.
 */
class ClinicSearch
{
    /** Records need at least this much typed; pages match from the first letter. */
    public const MIN_RECORD_QUERY = 2;

    private const LIMIT = 5;

    /** How far back and ahead appointment matches are looked for. */
    private const APPOINTMENT_PAST_DAYS = 90;

    private const APPOINTMENT_AHEAD_DAYS = 365;

    public function __construct(
        protected ClinicAccess $access,
        protected ClinicContext $clinicContext,
        protected PatientSearch $patients,
        protected ClinicSupportTickets $support,
    ) {}

    /**
     * @param  array<string, string>  $routeParameters
     * @return list<array{key: string, label: string, items: list<array<string, mixed>>}>
     */
    public function search(string $query, User $user, array $routeParameters = []): array
    {
        $query = trim(mb_substr($query, 0, 80));
        $clinic = $this->clinicContext->clinic();
        if ($query === '' || ! $clinic) {
            return [];
        }

        $groups = [
            ['key' => 'pages', 'label' => 'Pages', 'items' => $this->pages($query, $routeParameters)],
        ];

        if (mb_strlen($query) >= self::MIN_RECORD_QUERY) {
            $groups[] = ['key' => 'patients', 'label' => 'Patients', 'items' => $this->patients($clinic, $query)];
            $groups[] = ['key' => 'appointments', 'label' => 'Appointments', 'items' => $this->appointments($clinic, $query, $routeParameters)];
            $groups[] = ['key' => 'support', 'label' => 'Support requests', 'items' => $this->supportRequests($clinic, $user, $query, $routeParameters)];
        }

        return array_values(array_filter($groups, fn (array $group) => $group['items'] !== []));
    }

    /** The groups this user may search at all, for the box to say so. @return list<string> */
    public function searchable(): array
    {
        return array_values(array_filter([
            $this->patientsAllowed() ? 'patients' : null,
            'pages',
            $this->appointmentsAllowed() ? 'appointments' : null,
            'support',
        ]));
    }

    /**
     * @param  array<string, string>  $routeParameters
     * @return list<array<string, mixed>>
     */
    private function pages(string $query, array $routeParameters): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower($query)) ?: []));

        return collect(ClinicNavigation::for($this->access, $routeParameters))
            ->filter(fn (array $page) => $page['available'] && $page['href'] !== null)
            ->filter(function (array $page) use ($words) {
                $haystack = mb_strtolower(implode(' ', [$page['label'], ...$page['keywords']]));

                return collect($words)->every(fn (string $word) => str_contains($haystack, $word));
            })
            ->take(6)
            ->map(fn (array $page) => [
                'key' => 'page-'.$page['key'],
                'title' => $page['label'],
                'subtitle' => null,
                'icon' => $page['icon'],
                'href' => $page['href'],
                'action' => null,
            ])
            ->values()
            ->all();
    }

    /** Patients are found where the user may look them up and open one: today, by starting their Repeat Case. */
    private function patientsAllowed(): bool
    {
        return $this->access->allows(['permissions' => ['patient.search'], 'module' => 'patients', 'route' => 'clinic.patients.search'])
            && ClinicNavigation::intake($this->access)['available'];
    }

    /** @return list<array<string, mixed>> */
    private function patients(Clinic $clinic, string $query): array
    {
        if (! $this->patientsAllowed()) {
            return [];
        }

        return $this->patients
            ->find($clinic, $query, self::LIMIT, workingOnly: false, columns: ['id', 'uhid', 'name', 'first_name', 'middle_name', 'last_name', 'phone', 'gender', 'age', 'date_of_birth'])
            ->map(fn (Patient $patient) => [
                'key' => 'patient-'.$patient->id,
                'title' => $patient->full_name,
                'subtitle' => implode(' · ', array_filter([$patient->uhid, $patient->phone, $patient->age !== null ? "{$patient->age} y" : null])),
                'icon' => 'patients',
                'href' => null,
                'action' => ['type' => 'repeat_case', 'label' => 'Repeat Case', 'patient' => ['id' => $patient->id, 'uhid' => $patient->uhid, 'name' => $patient->full_name]],
            ])
            ->values()
            ->all();
    }

    /** The Calendar's own rule, at a branch: the Calendar works one branch at a time. */
    private function appointmentsAllowed(): bool
    {
        return $this->clinicContext->branch() !== null && $this->access->allows(ClinicNavigation::CALENDAR);
    }

    /**
     * @param  array<string, string>  $routeParameters
     * @return list<array<string, mixed>>
     */
    private function appointments(Clinic $clinic, string $query, array $routeParameters): array
    {
        $branch = $this->clinicContext->branch();
        if (! $branch || ! $this->appointmentsAllowed()) {
            return [];
        }

        $calendar = fn (array $parameters) => route('clinic.appointments.index', $routeParameters + $parameters, false);
        $items = [];

        if ($date = $this->dateIn($query)) {
            $items[] = [
                'key' => 'calendar-'.$date->toDateString(),
                'title' => 'Appointments on '.$date->format('D, j M Y'),
                'subtitle' => 'Open the Calendar on this day',
                'icon' => 'appointments',
                'href' => $calendar(['view' => 'day', 'date' => $date->toDateString()]),
                'action' => null,
            ];
        }

        $reference = preg_match('/^(?:APT-?)?0*(\d{1,9})$/i', $query, $match) ? (int) $match[1] : null;
        $today = ClinicClock::today();
        // The Calendar's matching: the patient, a prospect's name or mobile, the provider, or an `APT-` reference.
        $matching = fn () => Appointment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->where(function (Builder $match) use ($query, $reference) {
                $match->whereHas('patient', fn ($patient) => $patient->withoutGlobalScopes()->matchingSearch($query))
                    ->orWhere('prospect_name', 'like', '%'.self::escapeLike($query).'%')
                    ->orWhere('prospect_phone', 'like', self::escapeLike($query).'%')
                    ->orWhereHas('practitioner', fn ($provider) => $provider->withoutGlobalScopes()->where('name', 'like', '%'.self::escapeLike($query).'%'));
                if ($reference) {
                    $match->orWhere('appointments.id', $reference);
                }
            })
            ->with(['patient' => fn ($patient) => $patient->withoutGlobalScopes()->select('id', 'first_name', 'middle_name', 'last_name', 'name'), 'practitioner' => fn ($provider) => $provider->withoutGlobalScopes()->select('id', 'name')]);

        // The next ones first, then the most recent.
        $upcoming = $matching()->whereBetween('scheduled_at', [$today, $today->addDays(self::APPOINTMENT_AHEAD_DAYS)->endOfDay()])->orderBy('scheduled_at')->limit(self::LIMIT)->get();
        $past = $upcoming->count() < self::LIMIT
            ? $matching()->whereBetween('scheduled_at', [$today->subDays(self::APPOINTMENT_PAST_DAYS), $today])->orderByDesc('scheduled_at')->limit(self::LIMIT - $upcoming->count())->get()
            : collect();

        foreach ($upcoming->concat($past) as $appointment) {
            $at = CarbonImmutable::parse($appointment->scheduled_at);
            $status = $appointment->status instanceof AppointmentStatus ? $appointment->status : AppointmentStatus::tryFrom((string) $appointment->status);
            $items[] = [
                'key' => 'appointment-'.$appointment->id,
                'title' => $appointment->patient?->full_name ?? ($appointment->prospect_name ?: 'New case'),
                'subtitle' => implode(' · ', array_filter([$at->format('D, j M Y · g:i A'), $appointment->practitioner?->name, $status?->label()])),
                'icon' => 'appointments',
                // The day it is on, every provider shown, with this one opened.
                'href' => $calendar(['view' => 'day', 'date' => $at->toDateString(), 'providers' => '', 'appointment' => $appointment->id]),
                'action' => null,
            ];
        }

        return $items;
    }

    /** What was typed, matched literally: `%` and `_` are not wildcards here. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** A date as people type one (2026-10-14, 14/10/2026, 14-10, 14 Oct, 14 October 2026). */
    private function dateIn(string $query): ?CarbonImmutable
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m', 'd-m', 'j M Y', 'j F Y', 'j M', 'j F'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $query, ClinicClock::timezone());
            } catch (Throwable) {
                continue;
            }
            if ($date !== false && $date->format($format) === $query) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $routeParameters
     * @return list<array<string, mixed>>
     */
    private function supportRequests(Clinic $clinic, User $user, string $query, array $routeParameters): array
    {
        if (! $this->access->allows(['route' => 'clinic.support.index'])) {
            return [];
        }
        $like = '%'.self::escapeLike($query).'%';

        return $this->support->visible($clinic, $user)
            ->where(fn (Builder $match) => $match->where('reference', 'like', $like)->orWhere('subject', 'like', $like))
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'reference', 'subject', 'status'])
            ->map(fn (CompanySupportTicket $ticket) => [
                'key' => 'support-'.$ticket->id,
                'title' => $ticket->subject,
                'subtitle' => implode(' · ', [$ticket->reference, $ticket->status->label()]),
                'icon' => 'support',
                'href' => route('clinic.support.show', $routeParameters + ['ticket' => $ticket->id], false),
                'action' => null,
            ])
            ->values()
            ->all();
    }
}
