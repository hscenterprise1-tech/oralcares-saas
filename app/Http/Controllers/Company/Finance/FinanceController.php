<?php

namespace App\Http\Controllers\Company\Finance;

use App\ExpenseStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanyExpense;
use App\Services\Finance\ExpenseService;
use App\Services\FinanceWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The vendor's finance desk.
 *
 * **HQ-global by design.** Nothing here is tenant-scoped: this is the company's
 * own position across every customer, which is what a head-office finance desk
 * reads. Per-tenant money lives in Account 360, which scopes on its own.
 *
 * Reading takes the module's `view-*` permissions. Changing an expense takes
 * `manage-expenses`; approving one takes `approve-expenses`. That separation is
 * the point — `POST /company/finance/expenses` used to be gated on
 * `view-expenses` and wrote the row `approved` in the same request.
 */
class FinanceController extends Controller
{
    public function __construct(
        protected FinanceWorkspaceService $workspaceService,
        protected ExpenseService $expenses,
    ) {}

    /**
     * The whole position: money in, money out, what is owed and how late.
     */
    public function overview()
    {
        return Inertia::render(
            'Company/Finance/FinanceOverview',
            $this->workspaceService->getOverviewPayload() + [
                'can' => [
                    'viewExpenses' => Gate::allows('view-expenses'),
                    'viewPayroll' => Gate::allows('view-payroll'),
                    'viewInvoices' => Gate::allows('view-invoices'),
                ],
            ],
        );
    }

    /**
     * Display MRR/ARR Revenue Analytics Workspace.
     */
    public function revenue()
    {
        $payload = $this->workspaceService->getRevenuePayload();

        return Inertia::render('Company/Finance/RevenueWorkspace', $payload);
    }

    /**
     * Display Commercial Invoices Workspace.
     */
    public function invoices(Request $request)
    {
        $filters = $request->only(['search', 'status']);
        $payload = $this->workspaceService->getInvoicesPayload($filters);

        return Inertia::render('Company/Finance/InvoicesWorkspace', $payload);
    }

    /**
     * Display Commercial Payments Workspace.
     */
    public function payments(Request $request)
    {
        $filters = $request->only(['search', 'payment_method', 'month']);
        $payload = $this->workspaceService->getPaymentsPayload($filters);

        return Inertia::render('Company/Finance/PaymentsWorkspace', $payload);
    }

    /**
     * Display Company Expenses Workspace.
     */
    public function expensesIndex(Request $request)
    {
        $filters = $request->only(['search', 'category', 'status']);

        return Inertia::render(
            'Company/Finance/ExpensesWorkspace',
            $this->workspaceService->getExpensesPayload($filters) + [
                'can' => [
                    'manage' => Gate::allows('manage-expenses'),
                    'approve' => Gate::allows('approve-expenses'),
                ],
            ],
        );
    }

    /**
     * Submit a claim. It is submitted, not approved.
     */
    public function expensesStore(Request $request)
    {
        Gate::authorize('manage-expenses');

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'category' => 'required|string|in:software,infrastructure,marketing,office,travel,miscellaneous',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $expense = $this->expenses->submit($validated, $request->user());

        return back()->with('success', "Expense {$expense->expense_number} submitted for approval.");
    }

    /**
     * Move an expense along its workflow.
     */
    public function expensesTransition(Request $request, CompanyExpense $expense)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(ExpenseStatus::class)],
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $target = ExpenseStatus::from($validated['status']);

        /*
         * Approving is its own authority. Everything else on an expense is
         * `manage-expenses`, so the person who claims one does not
         * automatically get to sign it off.
         */
        Gate::authorize($target === ExpenseStatus::Approved ? 'approve-expenses' : 'manage-expenses');

        try {
            $this->expenses->transition($expense, $target, $request->user(), $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', "Expense {$expense->expense_number} is now {$target->label()}.");
    }

    /*
     * `payrollIndex()` is gone, and with it the last reader of the legacy
     * `company_payrolls` table.
     *
     * It rendered `Company/Finance/PayrollWorkspace`, a page that was deleted
     * once its route was retired — so the method could only ever have thrown.
     * Finance still reports what payroll paid out; it reads
     * `company_payroll_periods` through `payrollRunsPayload()`, which is the
     * live system People writes. The `viewPayroll` flag on the overview stays:
     * it gates the card's link to `/company/people/payroll`, which is the one
     * payroll door.
     *
     * The table and its rows are untouched. Deleting them is not a navigation
     * decision.
     */
}
