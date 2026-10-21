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

    public function index(Request $request): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $today = ClinicClock::now();
        $from = $this->date($request->query('from')) ?? $today->startOfMonth()->toDateString();
        $to = $this->date($request->query('to')) ?? $today->toDateString();
        $filters = [
            'from' => $from, 'to' => $to,
            'status' => in_array($request->query('status'), [ClinicExpense::PENDING, ClinicExpense::APPROVED, ClinicExpense::PAID, ClinicExpense::REJECTED], true) ? $request->query('status') : null,
            'category_id' => $request->integer('category_id') ?: null,
            'vendor_id' => $request->integer('vendor_id') ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
        ];
        $scoped = fn () => ClinicExpense::query()->where('clinic_id', $clinic->id)
            ->where('spent_on', '>=', $from)->where('spent_on', '<', CarbonImmutable::parse($to)->addDay()->toDateString())
            ->when($filters['category_id'], fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['vendor_id'], fn (Builder $q, $id) => $q->where('vendor_id', $id))
            ->when($filters['branch_id'], fn (Builder $q, $id) => $q->where('branch_id', $id));
        $byStatus = $scoped()->toBase()->selectRaw('status, sum(amount) as total, count(*) as n')->groupBy('status')->get()->keyBy('status');
        $byCategory = $scoped()->whereIn('status', [ClinicExpense::APPROVED, ClinicExpense::PAID])->toBase()
            ->selectRaw('category_id, sum(amount) as total')->groupBy('category_id')->orderByDesc('total')->limit(8)->pluck('total', 'category_id');
        $categories = $this->expenses->categories($clinic);
        $user = $request->user();

        return Inertia::render('ClinicPortal/Expenses/Index', [
            'filters' => $filters,
            'summary' => [
                'spent' => round((float) ($byStatus[ClinicExpense::APPROVED]->total ?? 0) + (float) ($byStatus[ClinicExpense::PAID]->total ?? 0), 2),
                'paid' => round((float) ($byStatus[ClinicExpense::PAID]->total ?? 0), 2),
                'unpaid' => round((float) ($byStatus[ClinicExpense::APPROVED]->total ?? 0), 2),
                'pending' => ['amount' => round((float) ($byStatus[ClinicExpense::PENDING]->total ?? 0), 2), 'count' => (int) ($byStatus[ClinicExpense::PENDING]->n ?? 0)],
                'by_category' => $byCategory->map(fn ($total, $id) => ['name' => $categories->firstWhere('id', $id)?->name ?? 'Other', 'total' => round((float) $total, 2)])->values(),
            ],
            'expenses' => Inertia::scroll(fn () => $scoped()->when($filters['status'], fn (Builder $q, $s) => $q->where('status', $s))
                ->with(['category:id,name', 'vendor:id,name', 'branch:id,name', 'creator:id,name'])
                ->orderByDesc('spent_on')->orderByDesc('id')->cursorPaginate(self::PAGE)
                ->through(fn (ClinicExpense $e) => [
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
                    'receipt' => $e->attachment_path ? route('clinic.expenses.receipt', $e->id, false) : null,
                ])),
            'options' => [
                'categories' => $categories->where('is_active', true)->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
                'vendors' => ClinicVendor::query()->where('clinic_id', $clinic->id)->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name']),
                'branches' => Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->orderBy('name')->get(['id', 'name']),
                'departments' => ClinicDepartment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'methods' => ClinicExpenses::METHODS,
                'frequencies' => ClinicRecurringExpense::FREQUENCIES,
            ],
            'recurring' => ClinicRecurringExpense::query()->where('clinic_id', $clinic->id)->with(['category:id,name', 'vendor:id,name'])->orderBy('next_due_on')->limit(100)->get()
                ->map(fn (ClinicRecurringExpense $r) => ['id' => $r->id, 'title' => $r->title, 'amount' => (float) $r->amount, 'frequency' => $r->frequency, 'next_due_on' => $r->next_due_on->toDateString(), 'category' => $r->category?->name, 'vendor' => $r->vendor?->name, 'active' => $r->is_active]),
            'can' => ['approve' => $user->hasClinicPermission(ClinicPermission::ExpenseApprove)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules() + ['receipt' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp']]);

        return $this->attempt(fn () => $this->expenses->record($this->clinicContext->clinic(), $data, $request->user(), $request->file('receipt')), 'Expense recorded.');
    }

    public function approve(Request $request, int $expense): RedirectResponse
    {
        return $this->attempt(fn () => $this->expenses->approve($this->find($expense), $request->user()), 'Expense approved.');
    }

    public function reject(Request $request, int $expense): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->attempt(fn () => $this->expenses->reject($this->find($expense), $reason, $request->user()), 'Expense rejected.');
    }

    public function pay(Request $request, int $expense): RedirectResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(ClinicExpenses::METHODS)], 'reference' => ['nullable', 'string', 'max:100']]);

        return $this->attempt(fn () => $this->expenses->markPaid($this->find($expense), $data['method'], $data['reference'] ?? null, $request->user()), 'Expense marked paid.');
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
