<?php

namespace App\Services\Reception;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\PatientCase;
use App\Models\PatientProfile;
use App\PatientStatus;
use App\Services\Patient\CaseNumberFormatter;
use App\Services\Patient\PatientBranchAccess;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\Patient\PatientSearch;
use App\Services\PatientFieldConfigService;
use App\Services\QueueStateMachine;
use App\Support\ClinicClock;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The patients Reception's Repeat Case dialog offers: a bounded search and a
 * short "recent at this branch" list, each row with enough identity to pick
 * the right person and today's state (booked, already queued).
 *
 * Identity search is clinic-wide on purpose, so a returning patient seen at
 * another branch is found rather than registered twice. Safety flags (and
 * only those, never clinical detail) are added for patients the member's
 * branch access lets them open. Every lookup is batched: a page of results
 * costs the same handful of queries whatever its size.
 */
class ReceptionPatientLookup
{
    public const SEARCH_LIMIT = 8;

    public const RECENT_LIMIT = 6;

    public const RECENT_DAYS = 14;

    public function __construct(
        private QueueBoard $board,
        private DoctorRoster $roster,
        private PatientClinicalEntries $clinicalEntries,
        private PatientBranchAccess $branchAccess,
        private PatientSearch $patients,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function search(Clinic $clinic, Branch $branch, string $query): array
    {
        $patients = $this->patients->find($clinic, $query, self::SEARCH_LIMIT);

        return $this->present($clinic, $branch, $patients);
    }

    /**
     * Patients seen at this branch in the last fortnight who are not already
     * in today's queue, most recent first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(Clinic $clinic, Branch $branch): array
    {
        $now = ClinicClock::now();
        $queued = $this->queuedToday($clinic, $branch)->keys()->filter()->all();

        $ids = DB::table('patient_branch_activity')
            ->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->where('last_seen_at', '>=', $now->subDays(self::RECENT_DAYS))
            ->when($queued !== [], fn ($q) => $q->whereNotIn('patient_id', $queued))
            ->orderByDesc('last_seen_at')
            ->limit(self::RECENT_LIMIT * 2)
            ->pluck('patient_id')->all();

        $patients = Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereIn('id', $ids)
            ->whereIn('status', PatientStatus::working())->get()
            ->sortBy(fn (Patient $patient) => array_search($patient->id, $ids, true))->take(self::RECENT_LIMIT)->values();

        return $this->present($clinic, $branch, $patients);
    }

    /**
     * @param  Collection<int, Patient>  $patients
     * @return list<array<string, mixed>>
     */
    private function present(Clinic $clinic, Branch $branch, Collection $patients): array
    {
        if ($patients->isEmpty()) {
            return [];
        }

        $ids = $patients->pluck('id')->all();
        $now = ClinicClock::now();

        $appointments = Appointment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->whereIn('patient_id', $ids)
            ->whereOnDate('scheduled_at', $now)
            ->whereIn('status', AppointmentStatus::checkable())
            ->with('practitioner')
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy('patient_id');

        $queued = $this->queuedToday($clinic, $branch);
        $working = $this->roster->forBranch($clinic, $branch, $now)->pluck('employee.id')->all();

        $specialties = DB::table('patient_specialties as ps')
            ->join('clinic_specialties as cs', 'cs.id', '=', 'ps.clinic_specialty_id')
            ->where('ps.clinic_id', $clinic->id)->whereIn('ps.patient_id', $ids)->where('ps.status', 'active')
            ->get(['ps.patient_id', 'cs.specialty_key'])
            ->groupBy('patient_id')->map(fn (Collection $rows) => $rows->pluck('specialty_key')->values()->all());

        $lastSeen = DB::table('patient_branch_activity as pba')
            ->join('branches as b', 'b.id', '=', 'pba.branch_id')
            ->where('pba.clinic_id', $clinic->id)->whereIn('pba.patient_id', $ids)
            ->orderByDesc('pba.last_seen_at')
            ->get(['pba.patient_id', 'pba.branch_id', 'pba.last_seen_at', 'b.name', 'b.display_name'])
            ->groupBy('patient_id');

        // Safety flags only for patients this member may open.
        $allowedIds = $this->openableIds($clinic, $ids, $lastSeen);
        $flags = $this->clinicalEntries->safetyFlags($clinic->id, $allowedIds);
        $addresses = $this->addresses($clinic, $allowedIds);

        // Phase C: Batch-load active cases for returning patients
        $casesByPatient = PatientCase::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->whereIn('patient_id', $ids)
            ->whereIn('status', PatientCase::OPEN)
            ->with(['leadPractitioner', 'branch'])
            ->orderByDesc('opened_at')
            ->get()
            ->groupBy('patient_id');

        return $patients->map(function (Patient $patient) use ($appointments, $queued, $working, $specialties, $lastSeen, $flags, $addresses, $casesByPatient) {
            $appointment = $appointments->get($patient->id)?->first();
            $inQueue = $queued->get($patient->id);
            $seen = $lastSeen->get($patient->id)?->first();

            return [
                'id' => $patient->id,
                'name' => $patient->full_name,
                'short_name' => $patient->short_name,
                'phone' => $patient->phone,
                'uhid' => $patient->uhid,
                'age' => $patient->age,
                'gender' => $patient->gender,
                'status' => $patient->status?->value ?? 'active',
                'specialties' => $specialties->get($patient->id, []),
                'last_seen' => $seen ? ['at' => Carbon::parse($seen->last_seen_at)->toDateString(), 'branch' => $seen->display_name ?: $seen->name] : null,
                'safety_flags' => $flags[$patient->id] ?? [],
                'address' => $addresses[$patient->id] ?? null,
                'cases' => $casesByPatient->get($patient->id, collect())->map(fn (PatientCase $c) => [
                    'id' => $c->id,
                    'case_number' => $c->case_number,
                    'specialty' => $c->specialty,
                    'title' => $c->title,
                    'chief_complaint' => $c->chief_complaint,
                    'status' => $c->status,
                    'doctor' => $c->leadPractitioner ? ['id' => $c->leadPractitioner->id, 'name' => $c->leadPractitioner->name] : null,
                    'branch' => $c->branch ? ['id' => $c->branch->id, 'name' => $c->branch->display_name ?: $c->branch->name] : null,
                    'opened_at' => $c->opened_at?->toDateString(),
                ])->values()->all(),
                'appointment' => $appointment ? [
                    'id' => $appointment->id,
                    'time' => $appointment->scheduled_at->format(QueueBoard::DATETIME),
                    'doctor' => $appointment->practitioner ? ['id' => $appointment->practitioner->id, 'name' => $appointment->practitioner->name] : null,
                ] : null,
                'in_queue' => $inQueue ? [
                    'status' => $inQueue->status->value,
                    'case_short' => $inQueue->patientVisit?->caseNumber ? CaseNumberFormatter::shortLabel($inQueue->patientVisit->caseNumber->sequence) : null,
                ] : null,
                'assigned_doctor_id' => in_array($patient->current_practitioner_id, $working, true) ? $patient->current_practitioner_id : null,
            ];
        })->values()->all();
    }

    /**
     * The address on file, one line, for Repeat Case to confirm against —
     * read-only (checking in never changes it), only for patients this
     * member may open, and only when the clinic shows them the address.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function addresses(Clinic $clinic, array $ids): array
    {
        $fields = app(PatientFieldConfigService::class)->effective($clinic, auth()->user());
        if ($ids === [] || ($fields['address']['state'] ?? PatientFieldConfigService::HIDDEN) === PatientFieldConfigService::HIDDEN) {
            return [];
        }
        $parts = ['address' => 'address', 'locality' => 'address_locality', 'city' => 'address_city', 'state' => 'address_state', 'postal_code' => 'address_pincode', 'country' => 'address_country'];
        $shown = array_keys(array_filter($parts, fn (string $field) => ($fields[$field]['state'] ?? PatientFieldConfigService::HIDDEN) !== PatientFieldConfigService::HIDDEN));

        return PatientProfile::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereIn('patient_id', $ids)
            ->get(['patient_id', ...$shown])
            ->mapWithKeys(fn (PatientProfile $profile) => [(int) $profile->patient_id => collect($shown)->map(fn (string $column) => trim((string) $profile->getAttributes()[$column]))->filter()->implode(', ')])
            ->filter()->all();
    }

    /**
     * @return Collection<int|string, ClinicQueueItem>
     */
    private function queuedToday(Clinic $clinic, Branch $branch): Collection
    {
        return $this->board->todaysItems($clinic, $branch, ClinicClock::now())
            ->filter(fn (ClinicQueueItem $item) => in_array($item->status, QueueStateMachine::ACTIVE, true))
            ->keyBy(fn (ClinicQueueItem $item) => $item->patientVisit?->patient_id);
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int|string, Collection<int, object>>  $lastSeen
     * @return list<int>
     */
    private function openableIds(Clinic $clinic, array $ids, Collection $lastSeen): array
    {
        $branchIds = $this->branchAccess->branchIds();
        if ($branchIds === null) {
            return $ids;
        }

        return array_values(array_filter($ids, fn (int $id) => $lastSeen->get($id, collect())->contains(fn ($row) => in_array((int) $row->branch_id, $branchIds, true))));
    }
}
