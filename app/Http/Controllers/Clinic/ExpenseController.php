<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ClinicDepartment;
use App\Models\ClinicExpense;
use App\Models\ClinicRecurringExpense;
use App\Models\ClinicVendor;
use App\Services\Business\ClinicExpenses;
use App\Services\ClinicContext;
use App\Services\Security\Malware\UploadRejectedException;
use App\Support\BillingTabs;
use App\Support\ClinicAccess\BranchReach;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses: what the clinic spends, by period, category, vendor and branch;
 * approval, payment and recurring costs. Behind `expense.manage`; deciding
 * is `expense.approve`. Lists are paged; totals are one grouped read.
 */
class ExpenseController extends Controller
{
    private const PAGE = 25;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicExpenses $expenses,
    ) {}

    /**
     * Expenses in a period (this month by default; `period=all` for every
     * date, which is what a dashboard count of everything pending opens),
     * at the branches this member may manage expenses for (`BranchReach`),
     * with totals of the same rows. Each row says which actions the server
     * would allow this member on it; the actions authorize themselves again.
     */
    public function index(Request $request, ClinicAccess $access): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $user = $request->user();
        $reach = BranchReach::for($this->clinicContext->membership() ?? abort(403), $user, ClinicPermission::ExpenseManage);
        $today = ClinicClock::now();
        $allDates = $request->query('period') === 'all';
        $from = $allDates ? null : ($this->date($request->query('from')) ?? $today->startOfMonth()->toDateString());
        $to = $allDates ? null : ($this->date($request->query('to')) ?? $today->toDateString());
        $filters = [
            'from' => $from, 'to' => $to, 'period' => $allDates ? 'all' : null,
            'status' => in_array($request->query('status'), [ClinicExpense::PENDING, ClinicExpense::APPROVED, ClinicExpense::PAID, ClinicExpense::REJECTED], true) ? $request->query('status') : null,
            'category_id' => $request->integer('category_id') ?: null,
            'vendor_id' => $request->integer('vendor_id') ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 80),
        ];
        $scoped = function () use ($clinic, $reach, $filters, $from, $to) {
            $query = ClinicExpense::query()->where('clinic_id', $clinic->id)
                ->when($from, fn (Builder $q) => $q->where('spent_on', '>=', $from))
                ->when($to, fn (Builder $q) => $q->where('spent_on', '<', CarbonImmutable::parse($to)->addDay()->toDateString()))
                ->when($filters['category_id'], fn (Builder $q, $id) => $q->where('category_id', $id))
                ->when($filters['vendor_id'], fn (Builder $q, $id) => $q->where('vendor_id', $id))
                ->when($filters['q'] !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                    ->where('title', 'like', '%'.addcslashes($filters['q'], '%_\\').'%')->orWhere('reference', 'like', addcslashes($filters['q'], '%_\\').'%')));
            $reach->apply($query, 'clinic_expenses.branch_id', $filters['branch_id']);

            return $query;
        };
        $membership = $this->clinicContext->membership();
        $isOwner = (bool) $membership?->is_primary_owner;
        // Per row, what the server would allow: the permission at the expense's branch, never
        // deciding or paying one's own (except the owner's explicit, re-verified direct path).
        $branchesById = Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->get()->keyBy('id');
        $mayAt = function (ClinicPermission $permission, ?int $branchId) use ($user, $branchesById, $reach, $membership): bool {
            if ($branchId === null) {
                return $reach->includesClinicWide && ($membership?->is_primary_owner || $membership?->has_all_branches_access) && $user->hasClinicPermission($permission);
            }
            $branch = $branchesById->get($branchId);

            return $branch !== null && $user->hasClinicPermissionAt($permission, $branch);
        };
        $byStatus = $scoped()->toBase()->selectRaw('status, sum(amount) as total, count(*) as n')->groupBy('status')->get()->keyBy('status');
        $byCategory = $scoped()->whereIn('status', [ClinicExpense::APPROVED, ClinicExpense::PAID])->toBase()
            ->selectRaw('category_id, sum(amount) as total')->groupBy('category_id')->orderByDesc('total')->limit(8)->pluck('total', 'category_id');
        $categories = $this->expenses->categories($clinic);

        return Inertia::render('ClinicPortal/Expenses/Index', [
            'filters' => $filters,
            'summary' => [
                'spent' => round((float) ($byStatus[ClinicExpense::APPROVED]->total ?? 0) + (float) ($byStatus[ClinicExpense::PAID]->total ?? 0), 2),
                'paid' => round((float) ($byStatus[ClinicExpense::PAID]->total ?? 0), 2),
                'unpaid' => round((float) ($byStatus[ClinicExpense::APPROVED]->total ?? 0), 2),
                'pending' => ['amount' => round((float) ($byStatus[ClinicExpense::PENDING]->total ?? 0), 2), 'count' => (int) ($byStatus[ClinicExpense::PENDING]->n ?? 0)],
                'by_category' => $byCategory->map(fn ($total, $id) => ['name' => $categories->firstWhere('id', $id)?->name ?? 'Other', 'total' => round((float) $total, 2)])->values(),
            ],
            'expenses' => $scoped()->when($filters['status'], fn (Builder $q, $s) => $q->where('status', $s))
                ->with(['category:id,name', 'vendor:id,name', 'branch:id,name', 'creator:id,name', 'decider:id,name'])
                ->orderByDesc('spent_on')->orderByDesc('id')->paginate(self::PAGE)->withQueryString()
                ->through(function (ClinicExpense $e) use ($user, $isOwner, $mayAt) {
                    $own = $e->created_by !== null && (int) $e->created_by === (int) $user->id;

                    return [
                        'id' => $e->id,
                        'title' => $e->title,
                        'amount' => (float) $e->amount,
                        'spent_on' => $e->spent_on->toDateString(),
                        'category' => $e->category?->name,
                        'vendor' => $e->vendor?->name,
                        'branch' => $e->branch?->name,
                        'status' => $e->status,
                        'method' => $e->payment_method,
                        'reference' => $e->reference,
                        'notes' => $e->notes,
                        'recurring' => $e->recurring_expense_id !== null,
                        'rejection_reason' => $e->rejection_reason,
                        'recorded_by' => $e->creator?->name,
                        'recorded_at' => $e->created_at?->toIso8601String(),
                        'decided_by' => $e->decider?->name,
                        'decided_at' => $e->decided_at?->toIso8601String(),
                        'paid_at' => $e->paid_at?->toIso8601String(),
                        'own' => $own,
                        'receipt' => $e->attachment_path ? route('clinic.expenses.receipt', $e->id, false) : null,
                        'can' => [
                            'decide' => $e->status === ClinicExpense::PENDING && ! $own && $mayAt(ClinicPermission::ExpenseApprove, $e->branch_id),
                            // The owner's own expense: only the explicit, re-verified direct path, and the server checks no one else could.
                            'decide_direct' => $e->status === ClinicExpense::PENDING && $own && $isOwner,
                            'pay' => $e->status === ClinicExpense::APPROVED && ! $own && $mayAt(ClinicPermission::ExpenseManage, $e->branch_id),
                            'pay_direct' => $e->status === ClinicExpense::APPROVED && $own && $isOwner && (int) $e->decided_by === (int) $user->id,
                        ],
                    ];
                }),
            'options' => [
                'categories' => $categories->where('is_active', true)->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
                'vendors' => ClinicVendor::query()->where('clinic_id', $clinic->id)->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name']),
                // A new expense may name only a branch within reach.
                'branches' => Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->orderBy('name')->get(['id', 'name'])
                    ->filter(fn (Branch $branch) => $reach->covers($branch->id))->values(),
                'departments' => ClinicDepartment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'methods' => ClinicExpenses::METHODS,
                'frequencies' => ClinicRecurringExpense::FREQUENCIES,
            ],
            'recurring' => ClinicRecurringExpense::query()->where('clinic_id', $clinic->id)->with(['category:id,name', 'vendor:id,name'])->orderBy('next_due_on')->limit(100)->get()
                ->map(fn (ClinicRecurringExpense $r) => ['id' => $r->id, 'title' => $r->title, 'amount' => (float) $r->amount, 'frequency' => $r->frequency, 'next_due_on' => $r->next_due_on->toDateString(), 'category' => $r->category?->name, 'vendor' => $r->vendor?->name, 'active' => $r->is_active]),
            'can' => ['approve' => $user->hasClinicPermission(ClinicPermission::ExpenseApprove), 'record_clinic_wide' => $reach->includesClinicWide],
            'tabs' => BillingTabs::for($access, array_filter(['clinic' => $request->route('clinic')], fn ($value) => is_string($value))),
            'links' => [
                'store' => route('clinic.expenses.store', [], false),
                'approve' => route('clinic.expenses.approve', ['expense' => '__ID__'], false),
                'reject' => route('clinic.expenses.reject', ['expense' => '__ID__'], false),
                'pay' => route('clinic.expenses.pay', ['expense' => '__ID__'], false),
                'recurring_active' => route('clinic.expenses.recurring.active', ['recurring' => '__ID__'], false),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules() + ['receipt' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp']]);

        return $this->attempt(fn () => $this->expenses->record($this->clinicContext->clinic(), $data, $request->user(), $request->file('receipt')), 'Expense recorded.');
    }

    public function approve(Request $request, int $expense): RedirectResponse
    {
        // `sole_owner_direct`: the owner explicitly deciding their own expense (`ClinicExpenses::decide()`).
        return $this->attempt(fn () => $this->expenses->approve($this->find($expense), $request->user(), $request->boolean('sole_owner_direct')), 'Expense approved.');
    }

    public function reject(Request $request, int $expense): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->attempt(fn () => $this->expenses->reject($this->find($expense), $reason, $request->user(), $request->boolean('sole_owner_direct')), 'Expense rejected.');
    }

    public function pay(Request $request, int $expense): RedirectResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(ClinicExpenses::METHODS)], 'reference' => ['nullable', 'string', 'max:100']]);

        // `sole_owner_direct`: the sole owner explicitly paying their own directly approved expense (`ClinicExpenses::markPaid()`).
        return $this->attempt(fn () => $this->expenses->markPaid($this->find($expense), $data['method'], $data['reference'] ?? null, $request->user(), $request->boolean('sole_owner_direct')), 'Expense marked paid.');
    }

    public function receipt(int $expense): StreamedResponse
    {
        $record = $this->find($expense);
        abort_unless($record->attachment_path && Storage::disk('private')->exists($record->attachment_path), 404);

        return Storage::disk('private')->download($record->attachment_path, $record->attachment_name ?? 'receipt');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:80']])['name'];

        return $this->attempt(fn () => $this->expenses->addCategory($this->clinicContext->clinic(), $name, $request->user()), 'Category added.');
    }

    public function storeVendor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:120'],
            'tax_number' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(fn () => $this->expenses->saveVendor($this->clinicContext->clinic(), $data, $request->user()), 'Vendor saved.');
    }

    public function storeRecurring(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'], 'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'category_id' => ['required', 'integer'], 'vendor_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(ClinicExpenses::METHODS)], 'frequency' => ['required', Rule::in(ClinicRecurringExpense::FREQUENCIES)],
            'next_due_on' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->attempt(fn () => $this->expenses->saveRecurring($this->clinicContext->clinic(), $data, $request->user()), 'Recurring expense saved. Each period is recorded for approval when it falls due.');
    }

    public function toggleRecurring(Request $request, int $recurring): RedirectResponse
    {
        $record = ClinicRecurringExpense::query()->where('clinic_id', $this->clinicContext->clinic()?->id)->findOrFail($recurring);
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $this->expenses->setRecurringActive($record, $active, $request->user());

        return back()->with('success', $active ? 'Recurring expense resumed.' : 'Recurring expense paused.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.ClinicClock::now()->addDay()->toDateString()],
            'category_id' => ['required', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(ClinicExpenses::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function find(int $id): ClinicExpense
    {
        return ClinicExpense::query()->where('clinic_id', $this->clinicContext->clinic()?->id)->findOrFail($id);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException|UploadRejectedException $e) {
            return back()->withErrors(['expense' => $e->getMessage()]);
        }

        return back()->with('success', $success);
    }
}
