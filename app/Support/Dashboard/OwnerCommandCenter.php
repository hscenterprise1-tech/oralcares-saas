<?php

namespace App\Support\Dashboard;

use App\ClinicPermission;
use App\EmploymentStatus;
use App\Models\Clinic;
use App\PaymentMethod;
use App\Services\Billing\BillingRegister;
use App\Services\Business\ClinicExpenses;
use App\Services\ClinicContext;
use App\Services\PaymentService;
use App\Services\ResourceLimitService;
use App\Support\ClinicAccess\BranchReach;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicClock;
use App\Support\ClinicFrontend;
use App\Support\ClinicOwnerSubscription;
use App\Support\Specialty\SpecialtyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Owner Command Center: the home page of whoever runs the clinic (the
 * owner, or an administrator who manages staff: the `command_center`
 * workspace).
 *
 * It is built in sections:
 *  - today: the day's operations;
 *  - money: billing for today and this month;
 *  - spending: expenses waiting on someone;
 *  - decisions;
 *  - branches;
 *  - people: people and plan;
 *  - security;
 *  - activity: sensitive actions.
 *
 * Each section is sent only when this member may see it, so data they may not
 * see never reaches the browser. The owner-only sections go to the owner with
 * the owner's privileges (`OwnerMode::authorized()`): from sign-in for an owner
 * who does not treat patients. A doctor-owner works in Doctor Mode and reaches
 * the Command Center only by entering Owner Mode. An administrator never
 * receives the owner-only sections.
 *
 * Every figure here is the count or total of the list it links to: money comes
 * from `BillingRegister`, the same read model the Billing pages use, with the
 * same branch reach and clinic-time day boundaries. A figure links only to a
 * page that exists (`ClinicFrontend`), and otherwise is not a link.
 */
class OwnerCommandCenter
{
    /** Sections only the owner sees, with the owner's privileges in effect. */
    public const OWNER_SECTIONS = ['money', 'people', 'security', 'activity'];

    /** How many clinic days the money trend covers, today included. */
    public const TREND_DAYS = 30;

    /** @var array<string, string> */
    private array $routeParameters = [];

    public function __construct(
        protected ClinicAccess $access,
        protected ClinicContext $context,
        protected DashboardData $data,
        protected BillingRegister $register,
        protected ResourceLimitService $limits,
        protected DashboardVisibility $visibility,
        protected SpecialtyRegistry $specialties,
        protected PaymentService $payments,
    ) {}

    /** Whether this member's home is the Command Center. */
    public function appliesTo(): bool
    {
        return in_array('command_center', $this->access->workspaces(), true);
    }

    /**
     * Every section this member may see, in reading order. A section that fails
     * to load reports `error` without taking the page down.
     *
     * @param  array<string, string>  $routeParameters
     * @return array<string, array<string, mixed>>
     */
    public function sections(array $routeParameters = []): array
    {
        $this->routeParameters = $routeParameters;
        $sections = [];

        foreach (['mine', 'today', 'money', 'spending', 'decisions', 'branches', 'people', 'security', 'activity'] as $name) {
            if (in_array($name, self::OWNER_SECTIONS, true) && ! ($this->access->isOwner() && $this->access->ownerModeActive())) {
                continue;
            }

            try {
                $section = $this->{$name}();
            } catch (Throwable $exception) {
                report($exception);
                $section = ['state' => 'error'];
            }

            if ($section !== null) {
                $sections[$name] = $section;
            }
        }

        return $sections;
    }

    /* ------------------------------------------------------- their own day */

    /**
     * For an owner or administrator who also treats patients (a solo dentist,
     * say): their own patients waiting, seen and notes to finish, with the
     * queue that waits for them. The same read as the practitioner's Today.
     *
     * @return array<string, mixed>|null
     */
    protected function mine(): ?array
    {
        if (! $this->may('my_queue')) {
            return null;
        }
        $queue = $this->data->source('myQueue');

        return [
            'state' => 'ready',
            'figures' => [
                ['key' => 'my_waiting', 'label' => 'Waiting for you', 'value' => $queue['total'], 'href' => null],
                ['key' => 'my_seen', 'label' => 'Seen today', 'value' => $this->data->source('mySeen')['value'], 'href' => null],
                ['key' => 'my_notes', 'label' => 'Notes to finish', 'value' => $this->data->source('myNotes')['value'], 'href' => null],
            ],
            'queue' => $queue['items'],
            'today_href' => $this->link('clinic.consultations.index'),
        ];
    }

    /* ----------------------------------------------------------- operations */

    /** @return array<string, mixed>|null */
    protected function today(): ?array
    {
        $figures = [];
        $add = function (string $widget, string $label, ?string $href = null) use (&$figures): void {
            if ($this->may($widget)) {
                $figures[] = ['key' => $widget, 'label' => $label, ...$this->data->source(DashboardWidgets::definition($widget)['source']), 'href' => $href];
            }
        };

        $add('patients_today', 'Patients today');
        $add('appointments_today', 'Appointments', $this->link('clinic.appointments.index', ['view' => 'day']));
        $add('waiting_now', 'Waiting now');
        $add('with_doctor_now', 'With a doctor');

        return $figures === [] ? null : ['state' => 'ready', 'figures' => $figures];
    }

    /* ---------------------------------------------------------------- money */

    /**
     * Billed, collected, refunded and net, today and this month, plus the
     * balances still due now. Every figure is the total of a Billing list,
     * taken from that list's own filters.
     *
     * @return array<string, mixed>|null
     */
    protected function money(): ?array
    {
        if (! $this->access->allows(['permissions' => ['billing.view'], 'module' => 'billing'])) {
            return null;
        }

        $clinic = $this->clinic();
        $reach = $this->register->reach(ClinicPermission::BillingView);
        $branch = $this->context->branch()?->id;
        $today = ClinicClock::today();
        // The clinic's desk methods (every method when it has not narrowed them), for saying which took nothing.
        $deskMethods = $this->payments->acceptedMethods($clinic, atDesk: true);
        $period = function (CarbonImmutable $from) use ($clinic, $reach, $branch, $today, $deskMethods): array {
            $filters = ['q' => '', 'status' => null, 'branch' => $branch, 'from' => $from->toDateString(), 'to' => $today->toDateString(), 'method' => null, 'type' => null];
            $summary = $this->register->summary($clinic->id, $reach, null, $filters);
            $figures = $summary['figures'];
            $range = array_filter(['from' => $filters['from'], 'to' => $filters['to'], 'branch' => $branch]);
            $used = collect($summary['by_method'])->pluck('method')->all();

            return [
                // Payments received by method: the same rows as `collected`, which is their sum (refunds are not
                // taken off here; they are their own figure). Each opens the Collections list for that method.
                'methods' => collect($summary['by_method'])->map(fn (array $row) => [
                    'key' => $row['method'],
                    'label' => PaymentMethod::tryFrom($row['method'])?->label() ?? Str::headline($row['method']),
                    'amount' => $row['total'],
                    'count' => $row['count'],
                    'href' => $this->link('clinic.billing.index', ['view' => 'collections', 'method' => $row['method']] + $range),
                ])->values()->all(),
                'unused_methods' => collect($deskMethods)->reject(fn (PaymentMethod $method) => in_array($method->value, $used, true))->map(fn (PaymentMethod $method) => $method->label())->values()->all(),
                'billed' => ['value' => $figures['billed'], 'count' => $figures['bills'], 'href' => $this->link('clinic.billing.index', $range)],
                'collected' => ['value' => $figures['collected'], 'href' => $this->link('clinic.billing.index', ['view' => 'collections'] + $range)],
                'refunded' => ['value' => $figures['refunded'], 'href' => $this->link('clinic.billing.reversals', ['type' => 'refund'] + $range)],
                'adjusted' => ['value' => $figures['adjusted'], 'href' => $this->link('clinic.billing.reversals', ['type' => 'adjustment'] + $range)],
                'net_collected' => ['value' => $figures['net_collected']],
                'discounts' => ['value' => $figures['discounts']],
            ];
        };
        $month = $period($today->startOfMonth());

        return [
            'state' => 'ready',
            'currency' => (string) config('reception.currency', 'INR'),
            'today' => $period($today),
            'month' => $month,
            // A balance as of now, over every open bill however old.
            'outstanding' => [
                'value' => $this->register->summary($clinic->id, $reach, null, ['q' => '', 'status' => null, 'branch' => $branch, 'from' => null, 'to' => null, 'method' => null, 'type' => null])['figures']['outstanding'],
                'href' => $this->link('clinic.billing.index', array_filter(['branch' => $branch])),
            ],
            'summary_href' => $this->link('clinic.billing.summary', array_filter(['branch' => $branch])),
            // Billed and collected per clinic day for the last 30 days, on the summary's own basis.
            'trend' => $this->register->daily($clinic->id, $reach, $branch, $today->subDays(self::TREND_DAYS - 1)->toDateString(), $today->toDateString()),
        ];
    }

    /* ------------------------------------------------------------- spending */

    /**
     * Expenses waiting on someone, however old: to decide, and approved but
     * unpaid. Counted with the Expenses list's own reach, so each opens to
     * exactly these.
     *
     * @return array<string, mixed>|null
     */
    protected function spending(): ?array
    {
        if (! $this->access->allows(['permissions' => ['expense.manage']])) {
            return null;
        }

        $membership = $this->context->membership();
        $user = auth()->user();
        if ($membership === null || $user === null) {
            return null;
        }
        $reach = BranchReach::for($membership, $user, ClinicPermission::ExpenseManage);
        $byStatus = function (string $status) use ($reach): array {
            $query = DB::table('clinic_expenses')->where('clinic_id', $this->clinic()->id)->where('status', $status);
            $reach->apply($query, 'clinic_expenses.branch_id');
            $row = $query->selectRaw('count(*) as n, sum(amount) as total')->first();

            return [
                'count' => (int) $row->n,
                'amount' => round((float) $row->total, 2),
                'href' => $this->link('clinic.expenses.index', ['status' => $status, 'period' => 'all']),
            ];
        };

        // This month as the Expenses list counts it by default (`ExpenseController::index`): dated from the 1st to
        // today, at the same branches; spent is approved plus paid, never what still waits on a decision.
        $today = ClinicClock::today();
        $month = DB::table('clinic_expenses')->where('clinic_id', $this->clinic()->id)
            ->where('spent_on', '>=', $today->startOfMonth()->toDateString())->where('spent_on', '<', $today->addDay()->toDateString());
        $reach->apply($month, 'clinic_expenses.branch_id');
        $monthBy = (clone $month)->selectRaw('status, sum(amount) as total')->groupBy('status')->pluck('total', 'status');
        $categories = app(ClinicExpenses::class)->categories($this->clinic());
        $topCategories = (clone $month)->whereIn('status', ['approved', 'paid'])->selectRaw('category_id, sum(amount) as total')
            ->groupBy('category_id')->orderByDesc('total')->limit(3)->pluck('total', 'category_id');

        return [
            'state' => 'ready',
            'currency' => (string) config('reception.currency', 'INR'),
            'month' => [
                'spent' => round((float) ($monthBy['approved'] ?? 0) + (float) ($monthBy['paid'] ?? 0), 2),
                'paid' => round((float) ($monthBy['paid'] ?? 0), 2),
                'href' => $this->link('clinic.expenses.index'),
                'categories' => $topCategories->map(fn ($total, $id) => ['name' => $categories->firstWhere('id', $id)?->name ?? 'Other', 'total' => round((float) $total, 2)])->values()->all(),
            ],
            'to_decide' => $byStatus('pending'),
            'to_pay' => $byStatus('approved'),
        ];
    }

    /* ------------------------------------------------------------ decisions */

    /**
     * What waits on someone here: the dashboard's shared attention rules, and
     * the follow-ups due at this branch (their own rule, counted once).
     *
     * @return array<string, mixed>|null
     */
    protected function decisions(): ?array
    {
        $attention = $this->may('needs_attention') ? $this->data->source('needsAttention') : ['total' => 0, 'items' => []];
        $items = $attention['items'];

        if ($this->may('follow_ups_due')) {
            $due = (int) $this->data->source('followUpsDue')['total'];
            if ($due > 0) {
                $items[] = ['id' => 'follow_ups_due', 'primary' => 'Follow-ups due', 'secondary' => 'Due today or earlier, not booked', 'count' => $due, 'status' => 'pending', 'href' => $this->link('clinic.follow-ups.index', ['tab' => 'overdue', 'mine' => '0'])];
            }
        } elseif (! $this->may('needs_attention')) {
            return null;
        }

        return ['state' => 'ready', 'total' => (int) array_sum(array_column($items, 'count')), 'items' => $items];
    }

    /* ------------------------------------------------------------- branches */

    /** Each branch's day, for a clinic with several. @return array<string, mixed>|null */
    protected function branches(): ?array
    {
        if (! $this->may('branch_activity')) {
            return null;
        }

        return ['state' => 'ready', ...$this->data->source('branchActivity')];
    }

    /* --------------------------------------------------------------- people */

    /**
     * Logins in use against the plan's limits, counted as `ResourceLimitService`
     * enforces them; branches; profiles without a login (never a seat); and who
     * is on approved leave today.
     *
     * @return array<string, mixed>
     */
    protected function people(): array
    {
        $clinic = $this->clinic();
        $subscription = $this->limits->getActiveSubscription($clinic);
        $doctors = $this->limits->getCurrentDoctorCount($clinic);
        $logins = $this->limits->getCurrentUserCount($clinic);
        $staffAllowed = $subscription ? $this->limits->getAllowedStaff($subscription) : null;
        $today = ClinicClock::today()->toDateString();

        return [
            'state' => 'ready',
            'plan' => $subscription === null ? null : [
                'name' => $subscription->plan?->name,
                'status' => $subscription->status?->value,
                'ends_at' => $subscription->ends_at?->toDateString(),
                'grace_ends_at' => $subscription->grace_ends_at?->toDateString(),
            ],
            'seats' => array_values(array_filter([
                ['key' => 'doctors', 'label' => 'Doctor logins', 'used' => $doctors, 'allowed' => $subscription ? $this->limits->getAllowedDoctors($subscription) : null],
                $staffAllowed === null ? null : ['key' => 'staff', 'label' => 'Staff logins', 'used' => $logins - $doctors, 'allowed' => $staffAllowed],
                ['key' => 'logins', 'label' => 'All logins', 'used' => $logins, 'allowed' => $subscription ? $this->limits->getAllowedUsers($subscription) : null],
                ['key' => 'branches', 'label' => 'Branches', 'used' => $this->limits->getCurrentBranchCount($clinic), 'allowed' => $subscription ? $this->limits->getAllowedBranches($subscription) : null],
            ])),
            'without_login' => DB::table('clinic_employees')->where('clinic_id', $clinic->id)->whereNull('user_id')
                ->whereNotIn('status', EmploymentStatus::accessEnding())->count(),
            'on_leave_today' => DB::table('leaves')->where('clinic_id', $clinic->id)->where('status', 'approved')
                ->where('start_date', '<=', $today)->where('end_date', '>=', $today)->distinct()->count('employee_id'),
            // The access window as the platform enforces it (Company-managed): `state` is `none` with no subscription.
            'subscription' => ClinicOwnerSubscription::for($this->access, $clinic),
            'workforce' => $this->workforce($clinic),
            // What the clinic may use: separately sold modules that are on, and the specialties it practises.
            'modules' => collect($this->access->clinicModules())->filter()->keys()
                ->map(fn (string $key) => ['key' => $key, 'label' => (string) (config("clinic_modules.{$key}.label") ?? $key)])->values()->all(),
            'specialties' => collect($this->access->specialties())
                ->map(fn (string $key) => ['key' => $key, 'label' => (string) ($this->specialties->get($key)['label'] ?? $key)])->values()->all(),
        ];
    }

    /**
     * Who works here: active staff profiles, the active practitioners among
     * them, and how the active logins reach branches (every branch, or some).
     *
     * @return array{staff: int, practitioners: int, all_branches: int, some_branches: int}
     */
    private function workforce(Clinic $clinic): array
    {
        $staff = DB::table('clinic_employees')->where('clinic_id', $clinic->id)->where('status', EmploymentStatus::Active->value);
        $access = DB::table('clinic_memberships')->where('clinic_id', $clinic->id)->where('status', 'active')
            ->selectRaw('sum(case when has_all_branches_access then 1 else 0 end) as all_branches, sum(case when has_all_branches_access then 0 else 1 end) as some_branches')->first();

        return [
            'staff' => (clone $staff)->count(),
            'practitioners' => DB::table('doctor_profiles')->where('clinic_id', $clinic->id)->where('is_active', true)
                ->whereIn('clinic_employee_id', (clone $staff)->select('id'))->count(),
            'all_branches' => (int) ($access->all_branches ?? 0),
            'some_branches' => (int) ($access->some_branches ?? 0),
        ];
    }

    /* ------------------------------------------------------------- security */

    /** Sign-in and Owner Mode checks, last 7 days. @return array<string, mixed> */
    protected function security(): array
    {
        return ['state' => 'ready', ...$this->data->source('ownerSecurity')];
    }

    /** Money, access and governance changes, last 7 days. @return array<string, mixed> */
    protected function activity(): array
    {
        return ['state' => 'ready', ...$this->data->source('ownerActivity')];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * A registry widget's own requirements hold, and the owner has not hidden
     * it for this member's roles. The owner's own view is never narrowed.
     */
    private function may(string $widget): bool
    {
        if (! $this->access->allows(DashboardWidgets::definition($widget)['requires'])) {
            return false;
        }
        $clinic = $this->access->clinic();

        return $this->access->isOwner() || $clinic === null || ! $this->visibility->hides($clinic, $this->access->roles(), $widget);
    }

    /** @param  array<string, mixed>  $query */
    private function link(string $route, array $query = []): ?string
    {
        return ClinicFrontend::available($route) && $this->access->allows(['route' => $route])
            ? route($route, $this->routeParameters + $query, false)
            : null;
    }

    private function clinic(): Clinic
    {
        return $this->context->clinic() ?? abort(403);
    }
}
