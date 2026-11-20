<?php

namespace App\Services\Reports;

use App\BillingStatus;
use App\ClinicPermission;
use App\Models\Clinic;
use App\Models\PatientTreatment;
use App\Models\User;
use App\Services\ClinicContext;
use App\Support\ClinicAccess\ClinicAccess;
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
            // `group` is the topic; each report in it is one view of that topic. `mine`: a practitioner may open it for their own work.
            'patients_list' => ['group' => 'Patients', 'label' => 'Every patient', 'description' => 'Each patient seen or registered in the period: new or repeat, visits, doctor, treatments, billed, paid and balance.', 'filters' => [...$common, 'specialty', 'practitioner'], 'facets' => ['patient_type', 'treatment', 'treatment_name', 'payment']],
            'patients_mix' => ['group' => 'Patients', 'label' => 'By specialty and branch', 'description' => 'Visits in the period by specialty and by branch.', 'filters' => ['period']],
            'operations_visits' => ['group' => 'Visits & appointments', 'label' => 'Visits and queue', 'description' => 'Visits by day: checked in, completed, cancelled, average wait.', 'filters' => [...$common, 'specialty']],
            'operations_appointments' => ['group' => 'Visits & appointments', 'label' => 'Appointments', 'description' => 'Booked, attended, cancelled and no-shows by day.', 'filters' => [...$common, 'practitioner'], 'mine' => true],
            'clinical_consultations' => ['group' => 'Clinical work', 'label' => 'Consultations', 'description' => 'Every signed consultation: patient, diagnosis, procedures, prescriptions and tests.', 'filters' => [...$common, 'specialty', 'practitioner'], 'mine' => true],
            'clinical_treatments' => ['group' => 'Clinical work', 'label' => 'Treatments', 'description' => 'Every treatment started: patient, status, sessions done of planned, billed, paid and balance.', 'filters' => [...$common, 'specialty', 'practitioner'], 'facets' => ['treatment_status', 'treatment_name', 'payment'], 'mine' => true],
            'staff_workload' => ['group' => 'Clinical work', 'label' => 'By practitioner', 'description' => 'Consultations, patients, treatments started and completed, sessions — per practitioner.', 'filters' => [...$common, 'specialty']],
            'dental_procedures' => ['group' => 'Clinical work', 'label' => 'Dental procedures', 'description' => 'Procedures performed and charged, by procedure.', 'extension' => 'odontogram', 'filters' => [...$common, 'practitioner'], 'mine' => true],
            'dental_treatments' => ['group' => 'Clinical work', 'label' => 'Dental treatments and revenue', 'description' => 'Treatments by status, their stages and revenue.', 'extension' => 'odontogram', 'permission' => 'billing.view', 'filters' => [...$common, 'practitioner']],
            'dental_teeth' => ['group' => 'Clinical work', 'label' => 'Tooth activity', 'description' => 'Findings and treatments charted, by tooth (FDI).', 'extension' => 'odontogram', 'filters' => $common],
            'physio_therapists' => ['group' => 'Clinical work', 'label' => 'Therapy sessions', 'description' => 'Completed sessions and patients per therapist.', 'extension' => 'session_packages', 'filters' => [...$common, 'practitioner'], 'mine' => true],
            'physio_packages' => ['group' => 'Packages', 'label' => 'Packages and sessions', 'description' => 'Packages sold, sessions sold and used, utilisation, sessions left.', 'extension' => 'session_packages', 'filters' => $common],
            'physio_expiring' => ['group' => 'Packages', 'label' => 'Ending soon', 'description' => 'Active packages whose validity ends in the next 30 days.', 'extension' => 'session_packages', 'filters' => ['branch']],
            'finance_items' => ['group' => 'Billing', 'label' => 'What was billed', 'description' => 'Every line billed: patient, what it was for, the treatment, the doctor, amount, and how much of its bill is paid.', 'permission' => 'billing.view', 'filters' => [...$common, 'practitioner'], 'facets' => ['payment', 'treatment_name', 'item_type']],
            'finance_revenue' => ['group' => 'Billing', 'label' => 'Collection by day and method', 'description' => 'Billed, discounts, collected by method, refunds, by day.', 'permission' => 'billing.view', 'filters' => $common],
            'finance_outstanding' => ['group' => 'Billing', 'label' => 'Outstanding', 'description' => 'Every open balance, oldest first, with how long it is overdue.', 'permission' => 'billing.view', 'filters' => ['branch']],
            'follow_ups' => ['group' => 'Follow-ups', 'label' => 'Follow-ups', 'description' => 'Due, completed, missed and still overdue.', 'filters' => [...$common, 'practitioner'], 'mine' => true],
            'staff_payroll' => ['group' => 'Staff & spending', 'label' => 'Payroll', 'description' => 'Gross, incentives, commission, deductions, advances and net by month.', 'permission' => 'payroll.view', 'filters' => ['period']],
            'expenses' => ['group' => 'Staff & spending', 'label' => 'Expenses', 'description' => 'Approved and paid spending by category, vendor and branch.', 'permission' => 'expense.manage', 'filters' => $common],
            'whatsapp' => ['group' => 'WhatsApp', 'label' => 'WhatsApp messages', 'description' => 'Sent, delivered, read, failed and not sent, by message.', 'filters' => ['period']],
        ];
    }

    /**
     * A report's own filters ("facets"): each narrows its rows on the server.
     * `treatment_name` and `item_type` take their choices from the clinic's
     * own records (`facetOptions`).
     *
     * @var array<string, array{label: string, all: string, options?: array<string, string>}>
     */
    public const FACETS = [
        'patient_type' => ['label' => 'Patients', 'all' => 'New and repeat', 'options' => ['new' => 'New patients', 'repeat' => 'Repeat patients']],
        'treatment' => ['label' => 'Treatment', 'all' => 'Any treatment', 'options' => ['under_way' => 'Treatment under way', 'completed' => 'Treatment completed', 'none' => 'No treatment']],
        'treatment_status' => ['label' => 'Status', 'all' => 'Any status', 'options' => ['under_way' => 'Under way', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
        'treatment_name' => ['label' => 'Which treatment', 'all' => 'All treatments'],
        'payment' => ['label' => 'Payment', 'all' => 'Any payment', 'options' => ['balance' => 'Balance due', 'paid' => 'Paid in full', 'not_billed' => 'Not billed']],
        'item_type' => ['label' => 'Billed for', 'all' => 'Everything billed'],
    ];

    /**
     * The choices of one facet in this clinic: fixed ones, or the treatment
     * names and bill line kinds the clinic has actually used (at most 60).
     *
     * @return array<string, string>
     */
    public function facetOptions(Clinic $clinic, string $facet): array
    {
        return match ($facet) {
            'treatment_name' => DB::table('patient_treatments')->where('clinic_id', $clinic->id)->whereNotNull('title')
                ->groupBy('title')->orderByRaw('count(*) desc')->limit(60)->pluck('title')->mapWithKeys(fn ($title) => [$title => $title])->all(),
            'item_type' => DB::table('patient_billing_items')->where('clinic_id', $clinic->id)->whereNotNull('item_type')
                ->groupBy('item_type')->orderByRaw('count(*) desc')->limit(60)->pluck('item_type')->mapWithKeys(fn ($type) => [$type => ucfirst(str_replace('_', ' ', $type))])->all(),
            default => self::FACETS[$facet]['options'] ?? [],
        };
    }

    /**
     * The practitioner a member's reports are limited to: their own work,
     * when they do not hold the clinic's reports (`reports.view` in the
     * Command Center) but are a practitioner here. Null for those who see the
     * clinic's reports, and for anyone else.
     */
    public function scopedPractitioner(User $user): ?int
    {
        if ($this->seesClinicReports($user)) {
            return null;
        }
        $employee = app(ClinicContext::class)->currentEmployee();

        return $employee?->practitionerProfile !== null ? $employee->id : null;
    }

    public function seesClinicReports(User $user): bool
    {
        return $user->hasClinicPermission(ClinicPermission::ReportsView) && in_array('command_center', app(ClinicAccess::class)->workspaces(), true);
    }

    /**
     * The reports this user may open in this clinic.
     *
     * @return array<string, array<string, mixed>>
     */
    public function available(Clinic $clinic, User $user): array
    {
        $extensions = $this->specialties->extensionsFor($this->specialties->forClinic($clinic));
        $clinicWide = $this->seesClinicReports($user);
        if (! $clinicWide && $this->scopedPractitioner($user) === null) {
            return [];
        }

        return array_filter(self::catalog(), fn (array $report) => ($clinicWide || ($report['mine'] ?? false))
            && (! isset($report['extension']) || in_array($report['extension'], $extensions, true))
            && (! isset($report['permission']) || $user->hasClinicPermission(ClinicPermission::from($report['permission']))));
    }

    /**
     * Normalised filters: the period (default this month), branch,
     * specialty (only one the clinic has) and practitioner (only its own).
     *
     * Plus any facet chosen (`FACETS`), by its key.
     *
     * @param  array<string, mixed>  $input
     * @return array{from: string, to: string, branch_id: ?int, specialty: ?string, practitioner_id: ?int, patient_type?: string, treatment?: string, treatment_status?: string, treatment_name?: string, payment?: string, item_type?: string}
     */
    public function filters(Clinic $clinic, array $input, ?int $practitioner = null): array
    {
        // A practitioner's own reports: always their work, whatever was asked.
        if ($practitioner !== null) {
            $input['practitioner_id'] = $practitioner;
        }
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
        // A facet keeps only a value it offers; anything else means "all".
        $facets = [];
        foreach (array_keys(self::FACETS) as $facet) {
            $value = $input[$facet] ?? null;
            if (is_string($value) && $value !== '' && array_key_exists($value, $this->facetOptions($clinic, $facet))) {
                $facets[$facet] = $value;
            }
        }

        return ['from' => $from, 'to' => $to, 'branch_id' => $branchId, 'specialty' => $specialty, 'practitioner_id' => $practitionerId, ...$facets];
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

        $result = $this->{$method}($clinic, $f, $limit);
        // Someone who may not see money gets the report without its money figures and columns.
        if (($f['money'] ?? true) === false) {
            $money = fn (array $entry) => ($entry['format'] ?? null) === 'money';
            $hidden = array_column(array_filter($result['columns'], $money), 'key');
            $result['summary'] = array_values(array_filter($result['summary'], fn (array $entry) => ! $money($entry)));
            $result['columns'] = array_values(array_filter($result['columns'], fn (array $entry) => ! $money($entry)));
            $result['rows'] = array_map(fn (array $row) => array_diff_key($row, array_flip($hidden)), $result['rows']);
        }

        return $result;
    }

    /**
     * Whether this member sees money in reports: the clinic's billing, or —
     * in a practitioner's own reports — their own fees.
     */
    public function seesMoney(User $user, bool $mine): bool
    {
        return $user->hasClinicPermission(ClinicPermission::BillingView)
            || ($mine && $user->hasClinicPermission(ClinicPermission::BillingViewOwnFees));
    }

    /* ------------------------------------------------------------- patients */

    /**
     * Every patient seen (an attended visit) or registered in the period, one
     * row each with their whole picture: new or repeat, visits, doctor,
     * treatments, and money — billed and collected in the period, and what
     * they still owe. Narrowed by its facets: new/repeat, treatment state,
     * a treatment by name, payment.
     */
    protected function reportPatientsList(Clinic $clinic, array $f, int $limit): array
    {
        $attended = ['checked_in', 'in_queue', 'waiting', 'with_doctor', 'in_progress', 'completed'];
        $visits = fn () => DB::table('patient_visits as v')->where('v.clinic_id', $clinic->id)->whereIn('v.visit_status', $attended)
            ->where('v.visit_date', '>=', $f['from'])->where('v.visit_date', '<', $this->dayAfter($f['to']))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('v.branch_id', $b))
            ->when($f['practitioner_id'], fn (Builder $q, $p) => $q->where('v.doctor_id', $p))
            ->when($f['specialty'], fn (Builder $q, $s) => $q->whereIn('v.clinic_specialty_id', DB::table('clinic_specialties')->select('id')->where('clinic_id', $clinic->id)->where('specialty_key', $s)));
        $live = fn (Builder $bills) => $bills->where('b.clinic_id', $clinic->id)->where('b.status', '!=', BillingStatus::Cancelled->value);
        $underWay = [PatientTreatment::PLANNED, PatientTreatment::IN_PROGRESS, PatientTreatment::PAUSED];

        $q = DB::table('patients as p')->where('p.clinic_id', $clinic->id)
            ->where(function (Builder $q) use ($visits, $f) {
                $q->whereExists($visits()->whereColumn('v.patient_id', 'p.id'));
                // Registered in the period without a visit yet (not when the list is limited to a doctor, branch or specialty).
                if (! $f['practitioner_id'] && ! $f['branch_id'] && ! $f['specialty']) {
                    $q->orWhere(fn ($r) => $r->where('p.created_at', '>=', $this->start($f))->where('p.created_at', '<', $this->end($f)));
                }
            });
        $earlier = fn ($e) => $e->from('patient_visits as ev')->whereColumn('ev.patient_id', 'p.id')->where('ev.clinic_id', $clinic->id)->whereIn('ev.visit_status', $attended)->where('ev.visit_date', '<', $f['from']);
        match ($f['patient_type'] ?? null) {
            'new' => $q->whereNotExists($earlier),
            'repeat' => $q->whereExists($earlier),
            default => null,
        };
        $treatments = fn ($e) => $e->from('patient_treatments as t')->whereColumn('t.patient_id', 'p.id')->where('t.clinic_id', $clinic->id);
        match ($f['treatment'] ?? null) {
            'under_way' => $q->whereExists(fn ($e) => $treatments($e)->whereIn('t.status', $underWay)),
            'completed' => $q->whereExists(fn ($e) => $treatments($e)->where('t.status', PatientTreatment::COMPLETED)),
            'none' => $q->whereNotExists($treatments),
            default => null,
        };
        if (isset($f['treatment_name'])) {
            $q->whereExists(fn ($e) => $treatments($e)->where('t.title', $f['treatment_name']));
        }
        $bills = fn ($e) => $live($e->from('patient_billings as b')->whereColumn('b.patient_id', 'p.id'));
        match ($f['payment'] ?? null) {
            'balance' => $q->whereExists(fn ($e) => $bills($e)->where('b.outstanding_balance', '>', 0)),
            'paid' => $q->whereExists(fn ($e) => $bills($e)->where('b.issued_at', '>=', $this->start($f))->where('b.issued_at', '<', $this->end($f)))
                ->whereNotExists(fn ($e) => $bills($e)->where('b.outstanding_balance', '>', 0)),
            'not_billed' => $q->whereNotExists(fn ($e) => $bills($e)->where('b.issued_at', '>=', $this->start($f))->where('b.issued_at', '<', $this->end($f))),
            default => null,
        };

        $ids = (clone $q)->select('p.id');
        $total = (clone $q)->count();
        $new = (clone $q)->whereNotExists($earlier)->count();
        $inPeriod = fn (string $column) => fn (Builder $query) => $query->where($column, '>=', $this->start($f))->where($column, '<', $this->end($f));
        // Billed and collected as the revenue report counts them: bills by issue date, payments by when they were taken.
        $billed = (float) $live(DB::table('patient_billings as b'))->whereIn('b.patient_id', $ids)->tap($inPeriod('b.issued_at'))->sum('b.final_amount');
        $collected = (float) DB::table('patient_billing_payments as pay')->join('patient_billings as b', 'b.id', '=', 'pay.patient_billing_id')->where('pay.clinic_id', $clinic->id)
            ->whereIn('b.patient_id', $ids)->tap($inPeriod('pay.paid_at'))->sum('pay.amount');
        $owed = (float) $live(DB::table('patient_billings as b'))->whereIn('b.patient_id', $ids)->sum('b.outstanding_balance');

        $rows = (clone $q)->leftJoin('clinic_employees as d', 'd.id', '=', 'p.current_practitioner_id')
            ->orderByDesc('p.id')->limit($limit)->get(['p.id', 'p.uhid', 'p.first_name', 'p.last_name', 'p.gender', 'p.age', 'p.phone', 'p.created_at', 'd.name as doctor']);
        $page = $rows->pluck('id');
        // The page's facts, one grouped query each (never per patient).
        $visitFacts = $visits()->whereIn('v.patient_id', $page)->groupBy('v.patient_id')->selectRaw('v.patient_id, count(*) as n, max(v.visit_date) as last_visit')->get()->keyBy('patient_id');
        $before = DB::table('patient_visits')->where('clinic_id', $clinic->id)->whereIn('visit_status', $attended)->where('visit_date', '<', $f['from'])->whereIn('patient_id', $page)->distinct()->pluck('patient_id')->flip();
        $treatmentRows = DB::table('patient_treatments')->where('clinic_id', $clinic->id)->whereIn('patient_id', $page)->orderByDesc('id')->get(['patient_id', 'title', 'status'])->groupBy('patient_id');
        $money = fn (Builder $query, string $column) => $query->whereIn('b.patient_id', $page)->groupBy('b.patient_id')->selectRaw("b.patient_id, sum({$column}) as n")->pluck('n', 'patient_id');
        $billedBy = $money($live(DB::table('patient_billings as b'))->tap($inPeriod('b.issued_at')), 'b.final_amount');
        $paidBy = $money(DB::table('patient_billing_payments as pay')->join('patient_billings as b', 'b.id', '=', 'pay.patient_billing_id')->where('pay.clinic_id', $clinic->id)->tap($inPeriod('pay.paid_at')), 'pay.amount');
        $owedBy = $money($live(DB::table('patient_billings as b')), 'b.outstanding_balance');

        return $this->result(
            [['label' => 'Patients', 'value' => $total], ['label' => 'New', 'value' => $new], ['label' => 'Repeat', 'value' => $total - $new],
                ['label' => 'Billed', 'value' => round($billed, 2), 'format' => 'money'], ['label' => 'Collected', 'value' => round($collected, 2), 'format' => 'money'], ['label' => 'Still owed', 'value' => round($owed, 2), 'format' => 'money']],
            [['key' => 'patient', 'label' => 'Patient'], ['key' => 'uhid', 'label' => 'UHID'], ['key' => 'age_gender', 'label' => 'Age / sex'], ['key' => 'phone', 'label' => 'Mobile'],
                ['key' => 'type', 'label' => 'New / repeat'], ['key' => 'visits', 'label' => 'Visits', 'format' => 'number'], ['key' => 'last_visit', 'label' => 'Last visit', 'format' => 'date'],
                ['key' => 'doctor', 'label' => 'Doctor'], ['key' => 'treatments', 'label' => 'Treatments'], ['key' => 'treatment_state', 'label' => 'Treatment state'],
                ['key' => 'billed', 'label' => 'Billed', 'format' => 'money'], ['key' => 'paid', 'label' => 'Paid', 'format' => 'money'], ['key' => 'owed', 'label' => 'Balance', 'format' => 'money']],
            $rows->map(function ($r) use ($visitFacts, $before, $treatmentRows, $billedBy, $paidBy, $owedBy, $underWay) {
                $treatments = $treatmentRows->get($r->id, collect());
                $open = $treatments->whereIn('status', $underWay)->count();
                $done = $treatments->where('status', PatientTreatment::COMPLETED)->count();

                return [
                    'patient' => trim($r->first_name.' '.$r->last_name), 'uhid' => $r->uhid,
                    'age_gender' => trim(($r->age !== null ? $r->age.' y' : '').($r->gender ? ' · '.ucfirst((string) $r->gender) : ''), ' ·') ?: null,
                    'phone' => $r->phone, 'type' => isset($before[$r->id]) ? 'Repeat' : 'New',
                    'visits' => (int) ($visitFacts[$r->id]->n ?? 0), 'last_visit' => $visitFacts[$r->id]->last_visit ?? null, 'doctor' => $r->doctor,
                    'treatments' => $treatments->pluck('title')->unique()->take(3)->implode(', ') ?: null,
                    'treatment_state' => $treatments->isEmpty() ? 'None' : (collect([$open ? "{$open} under way" : null, $done ? "{$done} completed" : null])->filter()->implode(', ') ?: 'Stopped'),
                    'billed' => round((float) ($billedBy[$r->id] ?? 0), 2), 'paid' => round((float) ($paidBy[$r->id] ?? 0), 2), 'owed' => round((float) ($owedBy[$r->id] ?? 0), 2),
                ];
            })->all(),
            $total > $limit,
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

    /* ------------------------------------------------------------- clinical */

    /** Every consultation signed in the period, one row each, with what was done in it. */
    protected function reportClinicalConsultations(Clinic $clinic, array $f, int $limit): array
    {
        $q = DB::table('clinical_encounters as e')->join('patients as p', 'p.id', '=', 'e.patient_id')->leftJoin('clinic_employees as d', 'd.id', '=', 'e.doctor_id')
            ->where('e.clinic_id', $clinic->id)->whereNotNull('e.finalized_at')->whereNull('e.voided_at')
            ->where('e.finalized_at', '>=', $this->start($f))->where('e.finalized_at', '<', $this->end($f))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('e.branch_id', $b))->when($f['specialty'], fn (Builder $q, $s) => $q->where('e.specialty', $s))
            ->when($f['practitioner_id'], fn (Builder $q, $p) => $q->where('e.doctor_id', $p));
        $count = fn (string $table) => DB::table($table)->selectRaw('count(*)')->whereColumn("{$table}.encounter_id", 'e.id');
        $ids = (clone $q)->select('e.id');
        $sum = fn (string $table) => DB::table($table)->whereIn('encounter_id', $ids)->count();
        $total = (clone $q)->count();
        $patients = (clone $q)->distinct()->count('e.patient_id');
        // Patients whose first consultation here is in this period.
        $firstTime = (clone $q)->whereNotExists(fn ($e) => $e->from('clinical_encounters as earlier')->whereColumn('earlier.patient_id', 'e.patient_id')
            ->where('earlier.clinic_id', $clinic->id)->whereNull('earlier.voided_at')->whereColumn('earlier.started_at', '<', 'e.started_at'))->distinct()->count('e.patient_id');

        $rows = (clone $q)->orderByDesc('e.finalized_at')->limit($limit)
            ->select(['e.id', 'e.finalized_at', 'e.specialty', 'e.chief_complaint', 'p.uhid', 'p.first_name', 'p.last_name', 'd.name as doctor'])
            ->addSelect([
                'diagnosis' => DB::table('clinical_diagnoses')->select('description')->whereColumn('clinical_diagnoses.encounter_id', 'e.id')->orderByDesc('is_primary')->orderBy('id')->limit(1),
                'procedures' => $count('patient_procedures'), 'prescriptions' => $count('prescriptions'), 'tests' => $count('clinical_orders'),
            ])->get();

        return $this->result(
            [['label' => 'Consultations', 'value' => $total], ['label' => 'Patients', 'value' => $patients], ['label' => 'First-time patients', 'value' => $firstTime],
                ['label' => 'Procedures', 'value' => $sum('patient_procedures')], ['label' => 'Prescriptions', 'value' => $sum('prescriptions')], ['label' => 'Tests ordered', 'value' => $sum('clinical_orders')]],
            [['key' => 'signed', 'label' => 'Signed', 'format' => 'date'], ['key' => 'patient', 'label' => 'Patient'], ['key' => 'uhid', 'label' => 'UHID'], ['key' => 'practitioner', 'label' => 'Practitioner'],
                ['key' => 'specialty', 'label' => 'Specialty'], ['key' => 'complaint', 'label' => 'Complaint'], ['key' => 'diagnosis', 'label' => 'Diagnosis'],
                ['key' => 'procedures', 'label' => 'Procedures', 'format' => 'number'], ['key' => 'prescriptions', 'label' => 'Prescriptions', 'format' => 'number'], ['key' => 'tests', 'label' => 'Tests', 'format' => 'number']],
            $rows->map(fn ($r) => [
                'signed' => $this->localDate($r->finalized_at), 'patient' => trim($r->first_name.' '.$r->last_name), 'uhid' => $r->uhid, 'practitioner' => $r->doctor,
                'specialty' => $r->specialty ? ($this->specialties->get($r->specialty)['label'] ?? $r->specialty) : null, 'complaint' => $r->chief_complaint, 'diagnosis' => $r->diagnosis,
                'procedures' => (int) $r->procedures, 'prescriptions' => (int) $r->prescriptions, 'tests' => (int) $r->tests,
            ])->all(),
            $total > $limit,
        );
    }

    /** Every treatment started in the period, one row each: where it stands and its sessions. */
    protected function reportClinicalTreatments(Clinic $clinic, array $f, int $limit): array
    {
        $q = DB::table('patient_treatments as t')->join('patients as p', 'p.id', '=', 't.patient_id')->leftJoin('clinic_employees as d', 'd.id', '=', 't.practitioner_id')
            ->where('t.clinic_id', $clinic->id)->where('t.created_at', '>=', $this->start($f))->where('t.created_at', '<', $this->end($f))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('t.branch_id', $b))->when($f['specialty'], fn (Builder $q, $s) => $q->where('t.specialty_key', $s))
            ->when($f['practitioner_id'], fn (Builder $q, $p) => $q->where('t.practitioner_id', $p));
        match ($f['treatment_status'] ?? null) {
            'under_way' => $q->whereIn('t.status', [PatientTreatment::PLANNED, PatientTreatment::IN_PROGRESS, PatientTreatment::PAUSED]),
            'completed' => $q->where('t.status', PatientTreatment::COMPLETED),
            'cancelled' => $q->where('t.status', PatientTreatment::CANCELLED),
            default => null,
        };
        if (isset($f['treatment_name'])) {
            $q->where('t.title', $f['treatment_name']);
        }
        $items = fn ($e) => $this->liveItems($e, $clinic)->whereColumn('i.patient_treatment_id', 't.id');
        match ($f['payment'] ?? null) {
            'balance' => $q->whereExists(fn ($e) => $items($e)->where('b.outstanding_balance', '>', 0)),
            'paid' => $q->whereExists($items)->whereNotExists(fn ($e) => $items($e)->where('b.outstanding_balance', '>', 0)),
            'not_billed' => $q->whereNotExists($items),
            default => null,
        };

        $byStatus = (clone $q)->selectRaw('t.status, count(*) as n')->groupBy('t.status')->pluck('n', 'status');
        $total = (int) $byStatus->sum();
        $status = fn (string ...$keys) => (int) collect($keys)->sum(fn ($key) => $byStatus[$key] ?? 0);
        $money = fn ($ids) => $this->liveItems(DB::table('patient_billing_items as i'), $clinic)->whereIn('i.patient_treatment_id', $ids)
            ->groupBy('i.patient_treatment_id')->selectRaw('i.patient_treatment_id as id, sum(i.final_amount) as billed, sum('.self::PAID_SHARE.') as paid')->get()->keyBy('id');
        $totals = $money((clone $q)->select('t.id'));
        $rows = (clone $q)->orderByDesc('t.created_at')->limit($limit)
            ->get(['t.id', 't.created_at', 't.title', 't.specialty_key', 't.status', 't.sessions_planned', 't.sessions_completed', 'p.uhid', 'p.first_name', 'p.last_name', 'd.name as doctor']);

        return $this->result(
            [['label' => 'Treatments started', 'value' => $total], ['label' => 'Under way', 'value' => $status(PatientTreatment::PLANNED, PatientTreatment::IN_PROGRESS, PatientTreatment::PAUSED)],
                ['label' => 'Completed', 'value' => $status(PatientTreatment::COMPLETED)], ['label' => 'Cancelled', 'value' => $status(PatientTreatment::CANCELLED)],
                ['label' => 'Sessions done', 'value' => (int) (clone $q)->sum('t.sessions_completed')],
                ['label' => 'Billed', 'value' => round((float) $totals->sum('billed'), 2), 'format' => 'money'], ['label' => 'Paid', 'value' => round((float) $totals->sum('paid'), 2), 'format' => 'money']],
            [['key' => 'started', 'label' => 'Started', 'format' => 'date'], ['key' => 'patient', 'label' => 'Patient'], ['key' => 'uhid', 'label' => 'UHID'], ['key' => 'treatment', 'label' => 'Treatment'],
                ['key' => 'specialty', 'label' => 'Specialty'], ['key' => 'practitioner', 'label' => 'Practitioner'], ['key' => 'status', 'label' => 'Status'],
                ['key' => 'sessions_done', 'label' => 'Sessions done', 'format' => 'number'], ['key' => 'sessions_planned', 'label' => 'Planned', 'format' => 'number'],
                ['key' => 'billed', 'label' => 'Billed', 'format' => 'money'], ['key' => 'paid', 'label' => 'Paid', 'format' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'format' => 'money']],
            $rows->map(function ($r) use ($totals) {
                $billed = round((float) ($totals[$r->id]->billed ?? 0), 2);
                $paid = round((float) ($totals[$r->id]->paid ?? 0), 2);

                return [
                    'started' => $this->localDate($r->created_at), 'patient' => trim($r->first_name.' '.$r->last_name), 'uhid' => $r->uhid, 'treatment' => $r->title,
                    'specialty' => $r->specialty_key ? ($this->specialties->get($r->specialty_key)['label'] ?? $r->specialty_key) : null, 'practitioner' => $r->doctor,
                    'status' => ucfirst(str_replace('_', ' ', (string) $r->status)), 'sessions_done' => (int) $r->sessions_completed, 'sessions_planned' => $r->sessions_planned !== null ? (int) $r->sessions_planned : null,
                    'billed' => $billed, 'paid' => $paid, 'balance' => round(max(0, $billed - $paid), 2),
                ];
            })->all(),
            $total > $limit,
        );
    }

    /* ---------------------------------------------------------------- money */

    /**
     * What a bill line's share of its bill's payments comes to: the line's
     * amount times the part of the bill paid (net of refunds). A bill is paid
     * as a whole, so a line's "paid" is this share, never a payment of its own.
     */
    private const PAID_SHARE = 'case when b.final_amount > 0 then i.final_amount * (b.paid_amount - coalesce(b.refunded_amount, 0)) / b.final_amount else 0 end';

    /** Bill lines on this clinic's bills that are not cancelled, joined to their bill as `b`. */
    private function liveItems(Builder $items, Clinic $clinic): Builder
    {
        if ($items->from === null || $items->from === '') {
            $items->from('patient_billing_items as i');
        }

        return $items->join('patient_billings as b', 'b.id', '=', 'i.patient_billing_id')
            ->where('i.clinic_id', $clinic->id)->where('b.status', '!=', BillingStatus::Cancelled->value);
    }

    /**
     * Every line billed in the period (bills by issue date): patient, what it
     * was for, the treatment, the doctor, its amount and its share of what the
     * bill has been paid. Narrowed by payment, treatment and kind of line.
     */
    protected function reportFinanceItems(Clinic $clinic, array $f, int $limit): array
    {
        $q = $this->liveItems(DB::table('patient_billing_items as i'), $clinic)
            ->join('patients as p', 'p.id', '=', 'b.patient_id')
            ->leftJoin('patient_treatments as t', 't.id', '=', 'i.patient_treatment_id')
            ->leftJoin('clinic_employees as d', 'd.id', '=', DB::raw('coalesce(i.doctor_id, b.doctor_id)'))
            ->where('b.issued_at', '>=', $this->start($f))->where('b.issued_at', '<', $this->end($f))
            ->when($f['branch_id'], fn (Builder $q, $b) => $q->where('b.branch_id', $b))
            ->when($f['practitioner_id'], fn (Builder $q, $p) => $q->whereRaw('coalesce(i.doctor_id, b.doctor_id) = ?', [$p]));
        match ($f['payment'] ?? null) {
            'balance' => $q->where('b.outstanding_balance', '>', 0),
            'paid' => $q->where('b.outstanding_balance', '<=', 0),
            default => null,
        };
        if (isset($f['treatment_name'])) {
            $q->where('t.title', $f['treatment_name']);
        }
        if (isset($f['item_type'])) {
            $q->where('i.item_type', $f['item_type']);
        }

        $sums = (clone $q)->selectRaw('count(*) as n, count(distinct b.patient_id) as patients, sum(i.final_amount) as billed, sum(i.discount_amount) as discounts, sum('.self::PAID_SHARE.') as paid')->first();
        $total = (int) ($sums->n ?? 0);
        $rows = (clone $q)->orderByDesc('b.issued_at')->orderByDesc('i.id')->limit($limit)
            ->selectRaw('b.issued_at, b.billing_number, b.status as bill_status, i.description, i.item_type, i.quantity, i.discount_amount, i.final_amount, t.title as treatment, d.name as doctor, p.uhid, p.first_name, p.last_name, '.self::PAID_SHARE.' as paid')
            ->get();
        $billed = round((float) ($sums->billed ?? 0), 2);
        $paid = round((float) ($sums->paid ?? 0), 2);

        return $this->result(
            [['label' => 'Lines billed', 'value' => $total], ['label' => 'Patients', 'value' => (int) ($sums->patients ?? 0)], ['label' => 'Billed', 'value' => $billed, 'format' => 'money'],
                ['label' => 'Discounts', 'value' => round((float) ($sums->discounts ?? 0), 2), 'format' => 'money'], ['label' => 'Paid', 'value' => $paid, 'format' => 'money'], ['label' => 'Balance', 'value' => round(max(0, $billed - $paid), 2), 'format' => 'money']],
            [['key' => 'date', 'label' => 'Billed', 'format' => 'date'], ['key' => 'bill', 'label' => 'Bill'], ['key' => 'patient', 'label' => 'Patient'], ['key' => 'uhid', 'label' => 'UHID'],
                ['key' => 'item', 'label' => 'For'], ['key' => 'type', 'label' => 'Kind'], ['key' => 'treatment', 'label' => 'Treatment'], ['key' => 'doctor', 'label' => 'Doctor'],
                ['key' => 'quantity', 'label' => 'Qty', 'format' => 'number'], ['key' => 'discount', 'label' => 'Discount', 'format' => 'money'], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
                ['key' => 'paid', 'label' => 'Paid', 'format' => 'money'], ['key' => 'bill_status', 'label' => 'Bill status']],
            $rows->map(fn ($r) => [
                'date' => $this->localDate($r->issued_at), 'bill' => $r->billing_number, 'patient' => trim($r->first_name.' '.$r->last_name), 'uhid' => $r->uhid,
                'item' => $r->description, 'type' => $r->item_type ? ucfirst(str_replace('_', ' ', $r->item_type)) : null, 'treatment' => $r->treatment, 'doctor' => $r->doctor,
                'quantity' => (int) $r->quantity, 'discount' => round((float) $r->discount_amount, 2), 'amount' => round((float) $r->final_amount, 2), 'paid' => round((float) $r->paid, 2),
                'bill_status' => ucfirst(str_replace('_', ' ', (string) $r->bill_status)),
            ])->all(),
            $total > $limit,
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
