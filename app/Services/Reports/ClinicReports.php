<?php

namespace App\Services\Reports;

use App\BillingStatus;
use App\ClinicPermission;
use App\Models\Clinic;
use App\Models\User;
use App\Support\ClinicClock;
use App\Support\Specialty\SpecialtyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The clinic's reports, read from the canonical records only (patients,
 * visits, queue, appointments, consultations, treatments, packages,
 * `PatientBilling`, payments, expenses, payroll, follow-ups, WhatsApp).
 *
 * Every report is clinic-scoped, filtered on the server (period, branch,
 * specialty, practitioner) and bounded: grouped reports return at most a few
 * hundred rows; list reports page and export in chunks. A specialty's
 * reports exist only for a clinic that has that specialty; finance, payroll
 * and expense reports need their own permission.
 *
 * Period boundaries are the clinic's calendar days. Instants (`created_at`,
 * payments, sessions, signatures) are compared as UTC instants; schedule
 * columns that hold clinic wall-clock digits (appointments, the queue,
 * visit dates) are compared as dates.
 */
class ClinicReports
{
    public const ROW_LIMIT = 500;

    public function __construct(protected SpecialtyRegistry $specialties) {}

    /**
     * Every report, and what it needs.
     *
     * @return array<string, array{group: string, label: string, description: string, permission?: string, extension?: string, filters: list<string>}>
     */
    public static function catalog(): array
    {
        $common = ['period', 'branch'];

        return [
            'patients_new' => ['group' => 'Patients', 'label' => 'New patients', 'description' => 'Patients registered in the period.', 'filters' => [...$common, 'specialty']],
            'patients_activity' => ['group' => 'Patients', 'label' => 'Active and inactive patients', 'description' => 'New, repeat, active in the period, and not seen for six months.', 'filters' => $common],
            'patients_mix' => ['group' => 'Patients', 'label' => 'Specialty and branch mix', 'description' => 'Visits in the period by specialty and by branch.', 'filters' => ['period']],
            'operations_visits' => ['group' => 'Operations', 'label' => 'Visits and queue', 'description' => 'Visits by day: checked in, completed, cancelled, average wait.', 'filters' => [...$common, 'specialty']],
            'operations_appointments' => ['group' => 'Operations', 'label' => 'Appointments', 'description' => 'Booked, attended, cancelled and no-shows by day.', 'filters' => [...$common, 'practitioner']],
            'staff_workload' => ['group' => 'Doctors & staff', 'label' => 'Practitioner workload', 'description' => 'Consultations, patients, treatments started and completed, sessions.', 'filters' => [...$common, 'specialty']],
            'dental_procedures' => ['group' => 'Dental', 'label' => 'Dental procedures', 'description' => 'Procedures performed and charged, by procedure.', 'extension' => 'odontogram', 'filters' => [...$common, 'practitioner']],
            'dental_treatments' => ['group' => 'Dental', 'label' => 'Dental treatments', 'description' => 'Treatments by status, their stages and revenue.', 'extension' => 'odontogram', 'permission' => 'billing.view', 'filters' => [...$common, 'practitioner']],
            'dental_teeth' => ['group' => 'Dental', 'label' => 'Tooth activity', 'description' => 'Findings and treatments charted, by tooth (FDI).', 'extension' => 'odontogram', 'filters' => $common],
            'physio_packages' => ['group' => 'Physiotherapy', 'label' => 'Packages and sessions', 'description' => 'Packages sold, sessions sold and used, utilisation, sessions left.', 'extension' => 'session_packages', 'filters' => $common],
            'physio_expiring' => ['group' => 'Physiotherapy', 'label' => 'Packages ending', 'description' => 'Active packages whose validity ends in the next 30 days.', 'extension' => 'session_packages', 'filters' => ['branch']],
            'physio_therapists' => ['group' => 'Physiotherapy', 'label' => 'Therapist workload', 'description' => 'Completed sessions and patients per therapist.', 'extension' => 'session_packages', 'filters' => [...$common, 'practitioner']],
            'finance_revenue' => ['group' => 'Finance', 'label' => 'Revenue and collection', 'description' => 'Billed, discounts, collected by method, refunds, by day.', 'permission' => 'billing.view', 'filters' => $common],
            'finance_outstanding' => ['group' => 'Finance', 'label' => 'Outstanding and overdue', 'description' => 'Every open balance, oldest first, with how long it is overdue.', 'permission' => 'billing.view', 'filters' => ['branch']],
            'staff_payroll' => ['group' => 'Staff', 'label' => 'Payroll', 'description' => 'Gross, incentives, commission, deductions, advances and net by month.', 'permission' => 'payroll.view', 'filters' => ['period']],
            'expenses' => ['group' => 'Expenses', 'label' => 'Expenses', 'description' => 'Approved and paid spending by category, vendor and branch.', 'permission' => 'expense.manage', 'filters' => $common],
            'follow_ups' => ['group' => 'Follow-ups', 'label' => 'Follow-ups', 'description' => 'Due, completed, missed and still overdue.', 'filters' => [...$common, 'practitioner']],
            'whatsapp' => ['group' => 'WhatsApp', 'label' => 'WhatsApp messages', 'description' => 'Sent, delivered, read, failed and not sent, by message.', 'filters' => ['period']],
        ];
    }

    /**
     * The reports this user may open in this clinic.
     *
     * @return array<string, array<string, mixed>>
     */
    public function available(Clinic $clinic, User $user): array
    {
        $extensions = $this->specialties->extensionsFor($this->specialties->forClinic($clinic));

        return array_filter(self::catalog(), fn (array $report) => (! isset($report['extension']) || in_array($report['extension'], $extensions, true))
            && (! isset($report['permission']) || $user->hasClinicPermission(ClinicPermission::from($report['permission']))));
    }

    /**
     * Normalised filters: the period (default this month), branch,
     * specialty (only one the clinic has) and practitioner (only its own).
     *
     * @param  array<string, mixed>  $input
     * @return array{from: string, to: string, branch_id: ?int, specialty: ?string, practitioner_id: ?int}
     */
    public function filters(Clinic $clinic, array $input): array
    {
        $date = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        $today = ClinicClock::now();
        $from = $date($input['from'] ?? null) ?? $today->startOfMonth()->toDateString();
        $to = $date($input['to'] ?? null) ?? $today->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // At most two years in one report.
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 731) {
            $from = CarbonImmutable::parse($to)->subDays(731)->toDateString();
        }
        $branchId = isset($input['branch_id']) && DB::table('branches')->where('clinic_id', $clinic->id)->where('id', (int) $input['branch_id'])->exists() ? (int) $input['branch_id'] : null;
        $specialty = isset($input['specialty']) && in_array($input['specialty'], $this->specialties->forClinic($clinic), true) ? (string) $input['specialty'] : null;
        $practitionerId = isset($input['practitioner_id']) && DB::table('clinic_employees')->where('clinic_id', $clinic->id)->where('id', (int) $input['practitioner_id'])->exists() ? (int) $input['practitioner_id'] : null;

        return ['from' => $from, 'to' => $to, 'branch_id' => $branchId, 'specialty' => $specialty, 'practitioner_id' => $practitionerId];
    }

    /**
     * Build one report.
     *
     * @param  array{from: string, to: string, branch_id: ?int, specialty: ?string, practitioner_id: ?int}  $f
     * @return array{summary: list<array{label: string, value: float|int|string, format?: string}>, columns: list<array{key: string, label: string, format?: string}>, rows: list<array<string, mixed>>, truncated: bool}
     */
    public function build(Clinic $clinic, string $report, array $f, int $limit = self::ROW_LIMIT): array
    {
        $method = 'report'.str_replace(' ', '', ucwords(str_replace('_', ' ', $report)));

        return $this->{$method}($clinic, $f, $limit);
    }

    /* ------------------------------------------------------------- patients */

    protected function reportPatientsNew(Clinic $clinic, array $f, int $limit): array
    {
        $q = DB::table('patients')->where('patients.clinic_id', $clinic->id)->whereNull('patients.archived_at')
            ->where('patients.created_at', '>=', $this->start($f))->where('patients.created_at', '<', $this->end($f))
            ->when($f['specialty'], fn (Builder $q, $s) => $q->whereExists(fn ($e) => $e->from('patient_specialties')->join('clinic_specialties', 'clinic_specialties.id', '=', 'patient_specialties.clinic_specialty_id')
                ->whereColumn('patient_specialties.patient_id', 'patients.id')->where('clinic_specialties.specialty_key', $s)))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->whereExists(fn ($e) => $e->from('patient_branch_activity')->whereColumn('patient_branch_activity.patient_id', 'patients.id')->where('patient_branch_activity.branch_id', $b)));
        $total = (clone $q)->count();
        $rows = (clone $q)->orderByDesc('patients.id')->limit($limit)->get(['patients.uhid', 'patients.first_name', 'patients.last_name', 'patients.gender', 'patients.age', 'patients.phone', 'patients.created_at']);

        return $this->result(
            [['label' => 'New patients', 'value' => $total]],
            [['key' => 'uhid', 'label' => 'UHID'], ['key' => 'name', 'label' => 'Patient'], ['key' => 'gender', 'label' => 'Gender'], ['key' => 'age', 'label' => 'Age'], ['key' => 'phone', 'label' => 'Mobile'], ['key' => 'registered', 'label' => 'Registered', 'format' => 'date']],
            $rows->map(fn ($r) => ['uhid' => $r->uhid, 'name' => trim($r->first_name.' '.$r->last_name), 'gender' => $r->gender, 'age' => $r->age, 'phone' => $r->phone, 'registered' => $this->localDate($r->created_at)])->all(),
            $total > $limit,
        );
    }

    protected function reportPatientsActivity(Clinic $clinic, array $f, int $limit): array
    {
        $visits = DB::table('patient_visits')->where('clinic_id', $clinic->id)->where('visit_date', '>=', $f['from'])->where('visit_date', '<', $this->dayAfter($f['to']))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b));
        $new = (clone $visits)->where('registration_type', 'new')->distinct()->count('patient_id');
        $repeat = (clone $visits)->where('registration_type', 'repeat')->distinct()->count('patient_id');
        $active = (clone $visits)->distinct()->count('patient_id');
        $cutoff = CarbonImmutable::parse($f['to'])->subMonths(6)->toDateString();
        $inactive = DB::table('patients')->where('clinic_id', $clinic->id)->whereNull('archived_at')->where('status', 'active')
            ->whereNotExists(fn ($e) => $e->from('patient_visits')->whereColumn('patient_visits.patient_id', 'patients.id')->where('patient_visits.visit_date', '>=', $cutoff))->count();

        return $this->result(
            [['label' => 'Seen in the period', 'value' => $active], ['label' => 'First visit', 'value' => $new], ['label' => 'Returning', 'value' => $repeat], ['label' => 'Not seen for 6 months', 'value' => $inactive]],
            [['key' => 'measure', 'label' => 'Measure'], ['key' => 'patients', 'label' => 'Patients', 'format' => 'number']],
            [['measure' => 'Seen in the period', 'patients' => $active], ['measure' => 'First visit', 'patients' => $new], ['measure' => 'Returning', 'patients' => $repeat], ['measure' => 'Not seen for 6 months', 'patients' => $inactive]],
        );
    }

    protected function reportPatientsMix(Clinic $clinic, array $f, int $limit): array
    {
        $visits = fn () => DB::table('patient_visits')->where('patient_visits.clinic_id', $clinic->id)->where('visit_date', '>=', $f['from'])->where('visit_date', '<', $this->dayAfter($f['to']));
        $bySpecialty = $visits()->leftJoin('clinic_specialties', 'clinic_specialties.id', '=', 'patient_visits.clinic_specialty_id')
            ->selectRaw('clinic_specialties.specialty_key as k, count(*) as visits, count(distinct patient_visits.patient_id) as patients')->groupBy('clinic_specialties.specialty_key')->get();
        $byBranch = $visits()->join('branches', 'branches.id', '=', 'patient_visits.branch_id')
            ->selectRaw('branches.name as k, count(*) as visits, count(distinct patient_visits.patient_id) as patients')->groupBy('branches.name')->get();

        return $this->result(
            [['label' => 'Visits', 'value' => (int) $byBranch->sum('visits')]],
            [['key' => 'by', 'label' => 'By'], ['key' => 'name', 'label' => 'Name'], ['key' => 'visits', 'label' => 'Visits', 'format' => 'number'], ['key' => 'patients', 'label' => 'Patients', 'format' => 'number']],
            [
                ...$bySpecialty->map(fn ($r) => ['by' => 'Specialty', 'name' => $r->k ? ($this->specialties->get($r->k)['label'] ?? $r->k) : 'Not set', 'visits' => (int) $r->visits, 'patients' => (int) $r->patients])->all(),
                ...$byBranch->map(fn ($r) => ['by' => 'Branch', 'name' => $r->k, 'visits' => (int) $r->visits, 'patients' => (int) $r->patients])->all(),
            ],
        );
    }

    /* ----------------------------------------------------------- operations */

    protected function reportOperationsVisits(Clinic $clinic, array $f, int $limit): array
    {
        $items = DB::table('clinic_queue_items')->join('clinic_queues', 'clinic_queues.id', '=', 'clinic_queue_items.clinic_queue_id')
            ->leftJoin('patient_visits', 'patient_visits.id', '=', 'clinic_queue_items.patient_visit_id')
            ->leftJoin('clinic_specialties', 'clinic_specialties.id', '=', 'patient_visits.clinic_specialty_id')
            ->where('clinic_queues.clinic_id', $clinic->id)->where('clinic_queues.queue_date', '>=', $f['from'])->where('clinic_queues.queue_date', '<', $this->dayAfter($f['to']))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('clinic_queues.branch_id', $b))
            ->when($f['specialty'], fn (Builder $q, $s) => $q->where('clinic_specialties.specialty_key', $s));
        $rows = (clone $items)->selectRaw("date(clinic_queues.queue_date) as day, count(*) as checked_in,
                sum(case when clinic_queue_items.status = 'completed' then 1 else 0 end) as completed,
                sum(case when clinic_queue_items.status in ('cancelled', 'left') then 1 else 0 end) as cancelled,
                avg(case when clinic_queue_items.started_at is not null and clinic_queue_items.checked_in_at is not null then {$this->minutesBetween('clinic_queue_items.checked_in_at', 'clinic_queue_items.started_at')} end) as avg_wait")
            ->groupByRaw('date(clinic_queues.queue_date)')->orderBy('day')->limit(800)->get();

        $all = $rows->map(fn ($r) => ['day' => (string) $r->day, 'checked_in' => (int) $r->checked_in, 'completed' => (int) $r->completed, 'cancelled' => (int) $r->cancelled, 'avg_wait' => $r->avg_wait !== null ? round((float) $r->avg_wait, 1) : null]);
        $weighted = $all->filter(fn ($r) => $r['avg_wait'] !== null);

        return $this->result(
            [
                ['label' => 'Checked in', 'value' => $all->sum('checked_in')],
                ['label' => 'Completed', 'value' => $all->sum('completed')],
                ['label' => 'Cancelled or left', 'value' => $all->sum('cancelled')],
                ['label' => 'Average wait', 'value' => $weighted->isEmpty() ? '—' : round($weighted->avg('avg_wait'), 1).' min'],
            ],
            [['key' => 'day', 'label' => 'Day', 'format' => 'date'], ['key' => 'checked_in', 'label' => 'Checked in', 'format' => 'number'], ['key' => 'completed', 'label' => 'Completed', 'format' => 'number'], ['key' => 'cancelled', 'label' => 'Cancelled or left', 'format' => 'number'], ['key' => 'avg_wait', 'label' => 'Average wait (min)', 'format' => 'number']],
            $all->values()->all(),
        );
    }

    protected function reportOperationsAppointments(Clinic $clinic, array $f, int $limit): array
    {
        $rows = DB::table('appointments')->where('clinic_id', $clinic->id)->where('scheduled_at', '>=', $f['from'])->where('scheduled_at', '<', $this->dayAfter($f['to']))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b))
            ->when($f['practitioner_id'], fn (Builder $q, $p) => $q->where('practitioner_id', $p))
            ->selectRaw("date(scheduled_at) as day, count(*) as booked,
                sum(case when status in ('arrived', 'in_progress', 'completed', 'checked_in') then 1 else 0 end) as attended,
                sum(case when status = 'cancelled' then 1 else 0 end) as cancelled,
                sum(case when status = 'no_show' then 1 else 0 end) as no_show")
            ->groupByRaw('date(scheduled_at)')->orderBy('day')->limit(800)->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'booked' => (int) $r->booked, 'attended' => (int) $r->attended, 'cancelled' => (int) $r->cancelled, 'no_show' => (int) $r->no_show]);

        return $this->result(
            [['label' => 'Booked', 'value' => $rows->sum('booked')], ['label' => 'Attended', 'value' => $rows->sum('attended')], ['label' => 'Cancelled', 'value' => $rows->sum('cancelled')], ['label' => 'No-shows', 'value' => $rows->sum('no_show')]],
            [['key' => 'day', 'label' => 'Day', 'format' => 'date'], ['key' => 'booked', 'label' => 'Booked', 'format' => 'number'], ['key' => 'attended', 'label' => 'Attended', 'format' => 'number'], ['key' => 'cancelled', 'label' => 'Cancelled', 'format' => 'number'], ['key' => 'no_show', 'label' => 'No-shows', 'format' => 'number']],
            $rows->values()->all(),
        );
    }

    /* ---------------------------------------------------------------- staff */

    protected function reportStaffWorkload(Clinic $clinic, array $f, int $limit): array
    {
        $encounters = DB::table('clinical_encounters')->where('clinic_id', $clinic->id)->whereNotNull('finalized_at')->whereNull('voided_at')
            ->where('finalized_at', '>=', $this->start($f))->where('finalized_at', '<', $this->end($f))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b))->when($f['specialty'], fn (Builder $q, $s) => $q->where('specialty', $s))
            ->selectRaw('doctor_id as id, count(*) as consultations, count(distinct patient_id) as patients')->groupBy('doctor_id')->get()->keyBy('id');
        $treatments = DB::table('patient_treatments')->where('clinic_id', $clinic->id)
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b))->when($f['specialty'], fn (Builder $q, $s) => $q->where('specialty_key', $s));
        $started = (clone $treatments)->where('created_at', '>=', $this->start($f))->where('created_at', '<', $this->end($f))
            ->selectRaw('practitioner_id as id, count(*) as n')->groupBy('practitioner_id')->pluck('n', 'id');
        $completed = (clone $treatments)->where('status', 'completed')->where('updated_at', '>=', $this->start($f))->where('updated_at', '<', $this->end($f))
            ->selectRaw('practitioner_id as id, count(*) as n')->groupBy('practitioner_id')->pluck('n', 'id');
        $sessions = DB::table('physio_sessions')->where('clinic_id', $clinic->id)->where('status', 'completed')->whereNull('reversed_at')
            ->where('completed_at', '>=', $this->start($f))->where('completed_at', '<', $this->end($f))
            ->selectRaw('therapist_id as id, count(*) as n')->groupBy('therapist_id')->pluck('n', 'id');
        $ids = collect([...$encounters->keys(), ...$started->keys(), ...$completed->keys(), ...$sessions->keys()])->filter()->unique();
        $names = DB::table('clinic_employees')->where('clinic_id', $clinic->id)->whereIn('id', $ids)->pluck('name', 'id');

        $rows = $ids->map(fn ($id) => [
            'practitioner' => $names[$id] ?? 'Former staff',
            'consultations' => (int) ($encounters[$id]->consultations ?? 0),
            'patients' => (int) ($encounters[$id]->patients ?? 0),
            'treatments_started' => (int) ($started[$id] ?? 0),
            'treatments_completed' => (int) ($completed[$id] ?? 0),
            'sessions' => (int) ($sessions[$id] ?? 0),
        ])->sortByDesc('consultations')->values();

        return $this->result(
            [['label' => 'Consultations signed', 'value' => $rows->sum('consultations')], ['label' => 'Treatments started', 'value' => $rows->sum('treatments_started')], ['label' => 'Treatments completed', 'value' => $rows->sum('treatments_completed')], ['label' => 'Sessions completed', 'value' => $rows->sum('sessions')]],
            [['key' => 'practitioner', 'label' => 'Practitioner'], ['key' => 'consultations', 'label' => 'Consultations', 'format' => 'number'], ['key' => 'patients', 'label' => 'Patients', 'format' => 'number'], ['key' => 'treatments_started', 'label' => 'Treatments started', 'format' => 'number'], ['key' => 'treatments_completed', 'label' => 'Treatments completed', 'format' => 'number'], ['key' => 'sessions', 'label' => 'Sessions', 'format' => 'number']],
            $rows->all(),
        );
    }

    /* --------------------------------------------------------------- dental */

    protected function reportDentalProcedures(Clinic $clinic, array $f, int $limit): array
    {
        $rows = DB::table('patient_procedures')->join('clinical_procedures', 'clinical_procedures.id', '=', 'patient_procedures.clinical_procedure_id')
            ->join('clinical_encounters', 'clinical_encounters.id', '=', 'patient_procedures.encounter_id')
            ->where('patient_procedures.clinic_id', $clinic->id)->whereIn('clinical_procedures.specialty_key', $this->specialtiesWith('odontogram'))
            ->where('patient_procedures.performed_at', '>=', $this->start($f))->where('patient_procedures.performed_at', '<', $this->end($f))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('clinical_encounters.branch_id', $b))
            ->when($f['practitioner_id'], fn (Builder $q, $p) => $q->where('clinical_encounters.doctor_id', $p))
            ->selectRaw('clinical_procedures.name as procedure_name, sum(patient_procedures.quantity) as performed, count(distinct patient_procedures.patient_id) as patients, sum(coalesce(patient_procedures.charged_amount, 0)) as charged')
            ->groupBy('clinical_procedures.name')->orderByDesc('performed')->limit($limit)->get()
            ->map(fn ($r) => ['procedure' => $r->procedure_name, 'performed' => (int) $r->performed, 'patients' => (int) $r->patients, 'charged' => round((float) $r->charged, 2)]);

        return $this->result(
            [['label' => 'Procedures performed', 'value' => $rows->sum('performed')], ['label' => 'Charged through treatments', 'value' => $rows->sum('charged'), 'format' => 'money']],
            [['key' => 'procedure', 'label' => 'Procedure'], ['key' => 'performed', 'label' => 'Performed', 'format' => 'number'], ['key' => 'patients', 'label' => 'Patients', 'format' => 'number'], ['key' => 'charged', 'label' => 'Charged', 'format' => 'money']],
            $rows->all(),
        );
    }

    protected function reportDentalTreatments(Clinic $clinic, array $f, int $limit): array
    {
        $treatments = DB::table('patient_treatments')->where('clinic_id', $clinic->id)->whereIn('specialty_key', $this->specialtiesWith('odontogram'))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b))->when($f['practitioner_id'], fn (Builder $q, $p) => $q->where('practitioner_id', $p));
        $byStatus = (clone $treatments)->selectRaw('status, count(*) as n, sum(stages_completed) as done, sum(stages_total) as stages')->groupBy('status')->get();
        $startedInPeriod = (clone $treatments)->where('created_at', '>=', $this->start($f))->where('created_at', '<', $this->end($f))->count();
        $revenue = (float) DB::table('patient_billing_items')->join('patient_billings', 'patient_billings.id', '=', 'patient_billing_items.patient_billing_id')
            ->join('patient_treatments', 'patient_treatments.id', '=', 'patient_billing_items.patient_treatment_id')
            ->where('patient_billings.clinic_id', $clinic->id)->where('patient_billings.status', '!=', BillingStatus::Cancelled->value)
            ->whereIn('patient_treatments.specialty_key', $this->specialtiesWith('odontogram'))
            ->where('patient_billings.issued_at', '>=', $this->start($f))->where('patient_billings.issued_at', '<', $this->end($f))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('patient_billings.branch_id', $b))
            ->sum('patient_billing_items.final_amount');

        return $this->result(
            [['label' => 'Started in the period', 'value' => $startedInPeriod], ['label' => 'In progress', 'value' => (int) ($byStatus->firstWhere('status', 'in_progress')->n ?? 0)], ['label' => 'Completed', 'value' => (int) ($byStatus->firstWhere('status', 'completed')->n ?? 0)], ['label' => 'Treatment revenue billed', 'value' => round($revenue, 2), 'format' => 'money']],
            [['key' => 'status', 'label' => 'Status'], ['key' => 'treatments', 'label' => 'Treatments', 'format' => 'number'], ['key' => 'stages', 'label' => 'Stages done / planned']],
            $byStatus->map(fn ($r) => ['status' => ucfirst(str_replace('_', ' ', (string) $r->status)), 'treatments' => (int) $r->n, 'stages' => (int) $r->done.' / '.(int) $r->stages])->values()->all(),
        );
    }

    protected function reportDentalTeeth(Clinic $clinic, array $f, int $limit): array
    {
        $rows = DB::table('patient_tooth_status_histories')->leftJoin('clinical_encounters', 'clinical_encounters.id', '=', 'patient_tooth_status_histories.encounter_id')
            ->where('patient_tooth_status_histories.clinic_id', $clinic->id)->whereNull('patient_tooth_status_histories.voided_at')
            ->where('patient_tooth_status_histories.created_at', '>=', $this->start($f))->where('patient_tooth_status_histories.created_at', '<', $this->end($f))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('clinical_encounters.branch_id', $b))
            ->selectRaw("patient_tooth_status_histories.tooth_number as tooth,
                sum(case when patient_tooth_status_histories.kind = 'finding' then 1 else 0 end) as findings,
                sum(case when patient_tooth_status_histories.kind <> 'finding' then 1 else 0 end) as treated,
                count(distinct patient_tooth_status_histories.patient_id) as patients")
            ->groupBy('patient_tooth_status_histories.tooth_number')->orderBy('tooth')->limit(64)->get()
            ->map(fn ($r) => ['tooth' => (int) $r->tooth, 'findings' => (int) $r->findings, 'treated' => (int) $r->treated, 'patients' => (int) $r->patients]);

        return $this->result(
            [['label' => 'Teeth charted', 'value' => $rows->count()], ['label' => 'Findings', 'value' => $rows->sum('findings')], ['label' => 'Treatments charted', 'value' => $rows->sum('treated')]],
            [['key' => 'tooth', 'label' => 'Tooth (FDI)'], ['key' => 'findings', 'label' => 'Findings', 'format' => 'number'], ['key' => 'treated', 'label' => 'Treated', 'format' => 'number'], ['key' => 'patients', 'label' => 'Patients', 'format' => 'number']],
            $rows->all(),
        );
    }

    /* --------------------------------------------------------------- physio */

    protected function reportPhysioPackages(Clinic $clinic, array $f, int $limit): array
    {
        $sold = DB::table('patient_session_packages')->where('clinic_id', $clinic->id)
            ->where('created_at', '>=', $this->start($f))->where('created_at', '<', $this->end($f))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b))
            ->selectRaw('name, count(*) as packages, sum(sessions_total) as sessions')->groupBy('name')->orderByDesc('packages')->limit($limit)->get();
        $consumed = (int) abs((int) DB::table('session_package_usages')->where('clinic_id', $clinic->id)->where('entry_type', 'consume')
            ->where('created_at', '>=', $this->start($f))->where('created_at', '<', $this->end($f))->sum('quantity'));
        $completed = DB::table('physio_sessions')->where('clinic_id', $clinic->id)->where('status', 'completed')->whereNull('reversed_at')
            ->where('completed_at', '>=', $this->start($f))->where('completed_at', '<', $this->end($f))->count();
        $active = DB::table('patient_session_packages')->where('clinic_id', $clinic->id)->where('status', 'active')
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b))
            ->selectRaw('count(*) as packages, sum(sessions_total + sessions_adjusted - sessions_used) as remaining')->first();
        $sessionsSold = (int) $sold->sum('sessions');

        return $this->result(
            [
                ['label' => 'Packages sold', 'value' => (int) $sold->sum('packages')],
                ['label' => 'Sessions sold', 'value' => $sessionsSold],
                ['label' => 'Sessions completed', 'value' => $completed],
                ['label' => 'Utilisation', 'value' => $sessionsSold > 0 ? round(100 * $consumed / $sessionsSold, 1).'%' : '—'],
                ['label' => 'Sessions left on active packages', 'value' => (int) ($active->remaining ?? 0)],
            ],
            [['key' => 'package', 'label' => 'Package'], ['key' => 'packages', 'label' => 'Sold', 'format' => 'number'], ['key' => 'sessions', 'label' => 'Sessions sold', 'format' => 'number']],
            $sold->map(fn ($r) => ['package' => $r->name, 'packages' => (int) $r->packages, 'sessions' => (int) $r->sessions])->all(),
        );
    }

    protected function reportPhysioExpiring(Clinic $clinic, array $f, int $limit): array
    {
        $today = ClinicClock::now()->toDateString();
        $q = DB::table('patient_session_packages')->join('patients', 'patients.id', '=', 'patient_session_packages.patient_id')
            ->where('patient_session_packages.clinic_id', $clinic->id)->where('patient_session_packages.status', 'active')
            ->whereBetween('patient_session_packages.valid_until', [$today, ClinicClock::now()->addDays(30)->toDateString()])
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('patient_session_packages.branch_id', $b));
        $total = (clone $q)->count();
        $rows = (clone $q)->orderBy('patient_session_packages.valid_until')->limit($limit)
            ->get(['patients.uhid', 'patients.first_name', 'patients.last_name', 'patients.phone', 'patient_session_packages.name', 'patient_session_packages.valid_until',
                DB::raw('(patient_session_packages.sessions_total + patient_session_packages.sessions_adjusted - patient_session_packages.sessions_used) as remaining')]);

        return $this->result(
            [['label' => 'Packages ending in 30 days', 'value' => $total], ['label' => 'Sessions still unused', 'value' => (int) $rows->sum('remaining')]],
            [['key' => 'patient', 'label' => 'Patient'], ['key' => 'uhid', 'label' => 'UHID'], ['key' => 'phone', 'label' => 'Mobile'], ['key' => 'package', 'label' => 'Package'], ['key' => 'remaining', 'label' => 'Sessions left', 'format' => 'number'], ['key' => 'valid_until', 'label' => 'Valid until', 'format' => 'date']],
            $rows->map(fn ($r) => ['patient' => trim($r->first_name.' '.$r->last_name), 'uhid' => $r->uhid, 'phone' => $r->phone, 'package' => $r->name, 'remaining' => (int) $r->remaining, 'valid_until' => substr((string) $r->valid_until, 0, 10)])->all(),
            $total > $limit,
        );
    }

    protected function reportPhysioTherapists(Clinic $clinic, array $f, int $limit): array
    {
        $rows = DB::table('physio_sessions')->join('clinic_employees', 'clinic_employees.id', '=', 'physio_sessions.therapist_id')
            ->leftJoin('patient_treatments', 'patient_treatments.id', '=', 'physio_sessions.patient_treatment_id')
            ->where('physio_sessions.clinic_id', $clinic->id)->where('physio_sessions.status', 'completed')->whereNull('physio_sessions.reversed_at')
            ->where('physio_sessions.completed_at', '>=', $this->start($f))->where('physio_sessions.completed_at', '<', $this->end($f))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('patient_treatments.branch_id', $b))
            ->when($f['practitioner_id'], fn (Builder $q, $p) => $q->where('physio_sessions.therapist_id', $p))
            ->selectRaw('clinic_employees.name as therapist, count(*) as sessions, count(distinct physio_sessions.patient_id) as patients, count(distinct physio_sessions.patient_treatment_id) as treatments')
            ->groupBy('clinic_employees.name')->orderByDesc('sessions')->limit($limit)->get()
            ->map(fn ($r) => ['therapist' => $r->therapist, 'sessions' => (int) $r->sessions, 'patients' => (int) $r->patients, 'treatments' => (int) $r->treatments]);

        return $this->result(
            [['label' => 'Sessions completed', 'value' => $rows->sum('sessions')], ['label' => 'Therapists', 'value' => $rows->count()]],
            [['key' => 'therapist', 'label' => 'Therapist'], ['key' => 'sessions', 'label' => 'Sessions', 'format' => 'number'], ['key' => 'patients', 'label' => 'Patients', 'format' => 'number'], ['key' => 'treatments', 'label' => 'Treatments', 'format' => 'number']],
            $rows->all(),
        );
    }

    /* -------------------------------------------------------------- finance */

    protected function reportFinanceRevenue(Clinic $clinic, array $f, int $limit): array
    {
        $bills = DB::table('patient_billings')->where('clinic_id', $clinic->id)->where('status', '!=', BillingStatus::Cancelled->value)
            ->where('issued_at', '>=', $this->start($f))->where('issued_at', '<', $this->end($f))->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b));
        $payments = DB::table('patient_billing_payments')->where('clinic_id', $clinic->id)
            ->where('paid_at', '>=', $this->start($f))->where('paid_at', '<', $this->end($f))->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b));
        $refunds = DB::table('patient_billing_refunds')->where('clinic_id', $clinic->id)
            ->where('created_at', '>=', $this->start($f))->where('created_at', '<', $this->end($f))->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b));
        $billed = (clone $bills)->selectRaw('count(*) as n, sum(final_amount) as total, sum(discount_amount) as discounts')->first();
        $byMethod = (clone $payments)->selectRaw('payment_method, sum(amount) as total, count(*) as n')->groupBy('payment_method')->get();
        $refunded = (float) (clone $refunds)->sum('amount');
        $adjusted = (float) DB::table('patient_billing_adjustments')->where('clinic_id', $clinic->id)
            ->where('created_at', '>=', $this->start($f))->where('created_at', '<', $this->end($f))->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b))->sum('amount');
        $collected = (float) $byMethod->sum('total');

        return $this->result(
            [
                ['label' => 'Billed', 'value' => round((float) ($billed->total ?? 0), 2), 'format' => 'money'],
                ['label' => 'Discounts', 'value' => round((float) ($billed->discounts ?? 0), 2), 'format' => 'money'],
                ['label' => 'Collected', 'value' => round($collected, 2), 'format' => 'money'],
                ['label' => 'Refunded', 'value' => round($refunded, 2), 'format' => 'money'],
                ['label' => 'Adjusted (concessions, write-offs)', 'value' => round($adjusted, 2), 'format' => 'money'],
                ['label' => 'Net collected', 'value' => round($collected - $refunded, 2), 'format' => 'money'],
            ],
            [['key' => 'method', 'label' => 'Payment method'], ['key' => 'payments', 'label' => 'Payments', 'format' => 'number'], ['key' => 'amount', 'label' => 'Collected', 'format' => 'money']],
            $byMethod->map(fn ($r) => ['method' => ucfirst((string) $r->payment_method), 'payments' => (int) $r->n, 'amount' => round((float) $r->total, 2)])->values()->all(),
        );
    }

    protected function reportFinanceOutstanding(Clinic $clinic, array $f, int $limit): array
    {
        $terms = (int) config('clinic_whatsapp.overdue_after_days', 7);
        $today = CarbonImmutable::now();
        $q = DB::table('patient_billings')->join('patients', 'patients.id', '=', 'patient_billings.patient_id')
            ->where('patient_billings.clinic_id', $clinic->id)->whereIn('patient_billings.status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])
            ->where('patient_billings.outstanding_balance', '>', 0)->when($f['branch_id'], fn (Builder $q, $b) => $q->where('patient_billings.branch_id', $b));
        $totals = (clone $q)->selectRaw('count(*) as n, sum(patient_billings.outstanding_balance) as total, sum(case when patient_billings.issued_at < ? then patient_billings.outstanding_balance else 0 end) as overdue', [$today->subDays($terms)->toDateTimeString()])->first();
        $rows = (clone $q)->orderBy('patient_billings.issued_at')->limit($limit)
            ->get(['patient_billings.billing_number', 'patient_billings.issued_at', 'patient_billings.final_amount', 'patient_billings.outstanding_balance', 'patients.first_name', 'patients.last_name', 'patients.uhid', 'patients.phone']);

        return $this->result(
            [['label' => 'Open bills', 'value' => (int) ($totals->n ?? 0)], ['label' => 'Outstanding', 'value' => round((float) ($totals->total ?? 0), 2), 'format' => 'money'], ['label' => "Overdue (over {$terms} days)", 'value' => round((float) ($totals->overdue ?? 0), 2), 'format' => 'money']],
            [['key' => 'bill', 'label' => 'Bill'], ['key' => 'patient', 'label' => 'Patient'], ['key' => 'uhid', 'label' => 'UHID'], ['key' => 'phone', 'label' => 'Mobile'], ['key' => 'issued', 'label' => 'Issued', 'format' => 'date'], ['key' => 'total', 'label' => 'Bill total', 'format' => 'money'], ['key' => 'outstanding', 'label' => 'Outstanding', 'format' => 'money'], ['key' => 'days', 'label' => 'Days open', 'format' => 'number']],
            $rows->map(fn ($r) => [
                'bill' => $r->billing_number, 'patient' => trim($r->first_name.' '.$r->last_name), 'uhid' => $r->uhid, 'phone' => $r->phone, 'issued' => $this->localDate($r->issued_at),
                'total' => round((float) $r->final_amount, 2), 'outstanding' => round((float) $r->outstanding_balance, 2), 'days' => $r->issued_at ? (int) CarbonImmutable::parse($r->issued_at)->diffInDays($today) : null,
            ])->all(),
            (int) ($totals->n ?? 0) > $limit,
        );
    }

    /* ------------------------------------------------------- staff, expenses */

    protected function reportStaffPayroll(Clinic $clinic, array $f, int $limit): array
    {
        $kinds = DB::table('clinic_payroll_entry_lines')->join('clinic_payroll_entries', 'clinic_payroll_entries.id', '=', 'clinic_payroll_entry_lines.payroll_entry_id')
            ->join('clinic_payroll_periods', 'clinic_payroll_periods.id', '=', 'clinic_payroll_entries.payroll_period_id')
            ->where('clinic_payroll_periods.clinic_id', $clinic->id)->where('clinic_payroll_periods.period_start', '>=', CarbonImmutable::parse($f['from'])->startOfMonth()->toDateString())
            ->where('clinic_payroll_periods.period_start', '<=', $f['to'])
            ->selectRaw('clinic_payroll_periods.period_start as month, clinic_payroll_entry_lines.kind as kind, sum(clinic_payroll_entry_lines.amount) as total')
            ->groupBy('clinic_payroll_periods.period_start', 'clinic_payroll_entry_lines.kind')->get()->groupBy(fn ($r) => substr((string) $r->month, 0, 7));
        $rows = $kinds->map(function ($lines, $month) {
            $sum = fn (array $k) => round((float) $lines->whereIn('kind', $k)->sum('total'), 2);
            $gross = round((float) $lines->filter(fn ($l) => (float) $l->total > 0)->sum('total'), 2);
            $deductions = round(abs((float) $lines->filter(fn ($l) => (float) $l->total < 0)->sum('total')), 2);

            return ['month' => $month, 'base' => $sum(['base', 'session_pay']), 'incentives' => $sum(['incentive', 'allowance']), 'commission' => $sum(['commission']),
                'deductions' => abs($sum(['deduction', 'unpaid_leave'])), 'advances' => abs($sum(['advance_recovery'])), 'gross' => $gross, 'net' => round($gross - $deductions, 2)];
        })->sortKeys()->values();
        $givenAdvances = (float) DB::table('clinic_salary_advances')->where('clinic_id', $clinic->id)->where('given_on', '>=', $f['from'])->where('given_on', '<', $this->dayAfter($f['to']))->sum('amount');

        return $this->result(
            [['label' => 'Gross pay', 'value' => $rows->sum('gross'), 'format' => 'money'], ['label' => 'Incentives and allowances', 'value' => $rows->sum('incentives'), 'format' => 'money'], ['label' => 'Commission', 'value' => $rows->sum('commission'), 'format' => 'money'], ['label' => 'Net pay', 'value' => $rows->sum('net'), 'format' => 'money'], ['label' => 'Advances given', 'value' => round($givenAdvances, 2), 'format' => 'money']],
            [['key' => 'month', 'label' => 'Month'], ['key' => 'base', 'label' => 'Base and sessions', 'format' => 'money'], ['key' => 'incentives', 'label' => 'Incentives', 'format' => 'money'], ['key' => 'commission', 'label' => 'Commission', 'format' => 'money'], ['key' => 'deductions', 'label' => 'Deductions', 'format' => 'money'], ['key' => 'advances', 'label' => 'Advances recovered', 'format' => 'money'], ['key' => 'net', 'label' => 'Net', 'format' => 'money']],
            $rows->all(),
        );
    }

    protected function reportExpenses(Clinic $clinic, array $f, int $limit): array
    {
        $q = fn () => DB::table('clinic_expenses')->where('clinic_expenses.clinic_id', $clinic->id)->whereIn('clinic_expenses.status', ['approved', 'paid'])
            ->where('clinic_expenses.spent_on', '>=', $f['from'])->where('clinic_expenses.spent_on', '<', $this->dayAfter($f['to']))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('clinic_expenses.branch_id', $b));
        $byCategory = $q()->join('clinic_expense_categories', 'clinic_expense_categories.id', '=', 'clinic_expenses.category_id')
            ->selectRaw('clinic_expense_categories.name as k, sum(clinic_expenses.amount) as total, count(*) as n')->groupBy('clinic_expense_categories.name')->orderByDesc('total')->get();
        $byVendor = $q()->join('clinic_vendors', 'clinic_vendors.id', '=', 'clinic_expenses.vendor_id')
            ->selectRaw('clinic_vendors.name as k, sum(clinic_expenses.amount) as total, count(*) as n')->groupBy('clinic_vendors.name')->orderByDesc('total')->limit(50)->get();
        $byBranch = $q()->leftJoin('branches', 'branches.id', '=', 'clinic_expenses.branch_id')
            ->selectRaw('branches.name as k, sum(clinic_expenses.amount) as total, count(*) as n')->groupBy('branches.name')->orderByDesc('total')->get();
        $row = fn (string $by) => fn ($r) => ['by' => $by, 'name' => $r->k ?? 'Clinic-wide', 'expenses' => (int) $r->n, 'amount' => round((float) $r->total, 2)];

        return $this->result(
            [['label' => 'Spent', 'value' => round((float) $byCategory->sum('total'), 2), 'format' => 'money'], ['label' => 'Expenses', 'value' => (int) $byCategory->sum('n')]],
            [['key' => 'by', 'label' => 'By'], ['key' => 'name', 'label' => 'Name'], ['key' => 'expenses', 'label' => 'Expenses', 'format' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money']],
            [...$byCategory->map($row('Category'))->all(), ...$byVendor->map($row('Vendor'))->all(), ...$byBranch->map($row('Branch'))->all()],
        );
    }

    /* ---------------------------------------------------- follow-ups, WhatsApp */

    protected function reportFollowUps(Clinic $clinic, array $f, int $limit): array
    {
        $today = ClinicClock::now()->toDateString();
        $rows = DB::table('patient_follow_ups')->where('clinic_id', $clinic->id)->where('due_on', '>=', $f['from'])->where('due_on', '<', $this->dayAfter($f['to']))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('branch_id', $b))->when($f['practitioner_id'], fn (Builder $q, $p) => $q->where('practitioner_id', $p))
            ->selectRaw("count(*) as due,
                sum(case when status = 'completed' then 1 else 0 end) as completed,
                sum(case when status = 'booked' then 1 else 0 end) as booked,
                sum(case when status = 'cancelled' then 1 else 0 end) as cancelled,
                sum(case when status = 'due' and due_on < ? then 1 else 0 end) as overdue", [$today])->first();

        return $this->result(
            [['label' => 'Due in the period', 'value' => (int) ($rows->due ?? 0)], ['label' => 'Completed', 'value' => (int) ($rows->completed ?? 0)], ['label' => 'Booked', 'value' => (int) ($rows->booked ?? 0)], ['label' => 'Missed (still overdue)', 'value' => (int) ($rows->overdue ?? 0)], ['label' => 'Cancelled', 'value' => (int) ($rows->cancelled ?? 0)]],
            [['key' => 'status', 'label' => 'Status'], ['key' => 'count', 'label' => 'Follow-ups', 'format' => 'number']],
            collect(['completed' => 'Completed', 'booked' => 'Booked', 'overdue' => 'Missed (still overdue)', 'cancelled' => 'Cancelled'])->map(fn ($label, $key) => ['status' => $label, 'count' => (int) ($rows->{$key} ?? 0)])->values()->all(),
        );
    }

    protected function reportWhatsapp(Clinic $clinic, array $f, int $limit): array
    {
        $rows = DB::table('clinic_whatsapp_messages')->where('clinic_id', $clinic->id)->where('direction', 'outbound')
            ->where('created_at', '>=', $this->start($f))->where('created_at', '<', $this->end($f))
            ->selectRaw("automation_key, count(*) as total,
                sum(case when status in ('sent', 'delivered', 'read') then 1 else 0 end) as sent,
                sum(case when status in ('delivered', 'read') then 1 else 0 end) as delivered,
                sum(case when status = 'read' then 1 else 0 end) as read_count,
                sum(case when status = 'failed' then 1 else 0 end) as failed,
                sum(case when status = 'skipped' then 1 else 0 end) as skipped,
                sum(case when status = 'queued' then 1 else 0 end) as pending")
            ->groupBy('automation_key')->orderByDesc('total')->get();
        $catalog = (array) config('clinic_whatsapp.automations', []);
        $mapped = $rows->map(fn ($r) => ['message' => $catalog[$r->automation_key]['label'] ?? (string) $r->automation_key, 'sent' => (int) $r->sent, 'delivered' => (int) $r->delivered, 'read' => (int) $r->read_count, 'failed' => (int) $r->failed, 'not_sent' => (int) $r->skipped, 'pending' => (int) $r->pending]);

        return $this->result(
            [['label' => 'Sent', 'value' => $mapped->sum('sent')], ['label' => 'Delivered', 'value' => $mapped->sum('delivered')], ['label' => 'Read', 'value' => $mapped->sum('read')], ['label' => 'Failed', 'value' => $mapped->sum('failed')], ['label' => 'Pending', 'value' => $mapped->sum('pending')]],
            [['key' => 'message', 'label' => 'Message'], ['key' => 'sent', 'label' => 'Sent', 'format' => 'number'], ['key' => 'delivered', 'label' => 'Delivered', 'format' => 'number'], ['key' => 'read', 'label' => 'Read', 'format' => 'number'], ['key' => 'failed', 'label' => 'Failed', 'format' => 'number'], ['key' => 'not_sent', 'label' => 'Not sent', 'format' => 'number'], ['key' => 'pending', 'label' => 'Pending', 'format' => 'number']],
            $mapped->all(),
        );
    }

    /* --------------------------------------------------------------- helpers */

    /** Minutes from one timestamp column to another, in this database's SQL. */
    private function minutesBetween(string $from, string $to): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "((julianday({$to}) - julianday({$from})) * 1440)"
            : "timestampdiff(minute, {$from}, {$to})";
    }

    /** @return list<string> the clinic specialty keys whose specialty brings this extension */
    private function specialtiesWith(string $extension): array
    {
        return array_keys(array_filter($this->specialties->all(), fn (array $s) => in_array($extension, $s['extensions'] ?? [], true)));
    }

    private function start(array $f): string
    {
        return CarbonImmutable::parse($f['from'], ClinicClock::timezone())->startOfDay()->utc()->toDateTimeString();
    }

    private function end(array $f): string
    {
        return CarbonImmutable::parse($f['to'], ClinicClock::timezone())->addDay()->startOfDay()->utc()->toDateTimeString();
    }

    private function dayAfter(string $date): string
    {
        return CarbonImmutable::parse($date)->addDay()->toDateString();
    }

    private function localDate(mixed $instant): ?string
    {
        return $instant ? ClinicClock::fromUtc(CarbonImmutable::parse((string) $instant, 'UTC'))->toDateString() : null;
    }

    /**
     * @return array{summary: list<array<string, mixed>>, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, truncated: bool}
     */
    private function result(array $summary, array $columns, array $rows, bool $truncated = false): array
    {
        return ['summary' => $summary, 'columns' => $columns, 'rows' => array_values($rows), 'truncated' => $truncated];
    }
}
