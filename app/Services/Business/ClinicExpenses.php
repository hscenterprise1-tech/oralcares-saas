<?php

namespace App\Services\Business;

use App\ClinicPermission;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicDepartment;
use App\Models\ClinicExpense;
use App\Models\ClinicExpenseCategory;
use App\Models\ClinicRecurringExpense;
use App\Models\ClinicVendor;
use App\Models\User;
use App\Services\Security\Malware\ScannedUploadIntake;
use App\Services\Security\SecurityAudit;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of a clinic's expenses.
 *
 * An expense is recorded `pending`; someone with `expense.approve` approves
 * or rejects it (recorded by such a person, it is approved at once). An
 * approved expense is then paid. Receipts pass the malware scan into private
 * storage. A recurring expense raises each period's expense once, when due.
 * Every change is audited.
 */
class ClinicExpenses
{
    public const DEFAULT_CATEGORIES = ['Rent', 'Utilities', 'Clinical consumables', 'Dental lab', 'Equipment', 'Maintenance', 'Marketing', 'Software', 'Travel', 'Other'];

    public const METHODS = ['cash', 'upi', 'card', 'bank', 'cheque'];

    public function __construct(
        protected ScannedUploadIntake $intake,
        protected SecurityAudit $audit,
    ) {}

    /** @return Collection<int, ClinicExpenseCategory> */
    public function categories(Clinic $clinic): Collection
    {
        $existing = ClinicExpenseCategory::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->orderBy('name')->get();
        if ($existing->isNotEmpty()) {
            return $existing;
        }
        foreach (self::DEFAULT_CATEGORIES as $name) {
            ClinicExpenseCategory::query()->withoutGlobalScopes()->firstOrCreate(['clinic_id' => $clinic->id, 'name' => $name]);
        }

        return ClinicExpenseCategory::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->orderBy('name')->get();
    }

    public function addCategory(Clinic $clinic, string $name, User $by): ClinicExpenseCategory
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Name the category.');
        }
        $category = ClinicExpenseCategory::query()->withoutGlobalScopes()->firstOrCreate(['clinic_id' => $clinic->id, 'name' => mb_substr($name, 0, 80)], ['is_active' => true]);
        $this->audit->record('expense.category_saved', $category, ['name' => $category->name], ['clinic_id' => $clinic->id]);

        return $category;
    }

    /** @param array{name: string, phone?: ?string, email?: ?string, tax_number?: ?string, notes?: ?string} $data */
    public function saveVendor(Clinic $clinic, array $data, User $by, ?ClinicVendor $vendor = null): ClinicVendor
    {
        $attributes = ['name' => mb_substr(trim($data['name']), 0, 120), 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'tax_number' => $data['tax_number'] ?? null, 'notes' => $data['notes'] ?? null];
        $vendor = $vendor ? tap($vendor)->update($attributes) : ClinicVendor::query()->withoutGlobalScopes()->create(['clinic_id' => $clinic->id, ...$attributes]);
        $this->audit->record('expense.vendor_saved', $vendor, ['name' => $vendor->name], ['clinic_id' => $clinic->id]);

        return $vendor;
    }

    /**
     * @param  array{title: string, amount: float|string, spent_on: string, category_id: int, vendor_id?: ?int, branch_id?: ?int, department_id?: ?int, payment_method?: ?string, reference?: ?string, notes?: ?string}  $data
     */
    public function record(Clinic $clinic, array $data, User $by, ?UploadedFile $receipt = null, ?int $recurringId = null): ClinicExpense
    {
        $this->assertOwn($clinic, $data);
        if ((float) $data['amount'] <= 0) {
            throw new DomainException('An expense must be more than zero.');
        }
        $admitted = $receipt ? $this->intake->admit($receipt, "clinic_{$clinic->id}/expenses") : null;
        $approves = $by->hasClinicPermission(ClinicPermission::ExpenseApprove);

        $expense = ClinicExpense::query()->withoutGlobalScopes()->create([
            'clinic_id' => $clinic->id,
            'branch_id' => $data['branch_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'category_id' => $data['category_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'recurring_expense_id' => $recurringId,
            'title' => mb_substr(trim($data['title']), 0, 160),
            'amount' => round((float) $data['amount'], 2),
            'spent_on' => $data['spent_on'],
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $approves ? ClinicExpense::APPROVED : ClinicExpense::PENDING,
            'decided_by' => $approves ? $by->id : null,
            'decided_at' => $approves ? now() : null,
            'attachment_path' => $admitted['path'] ?? null,
            'attachment_name' => $receipt?->getClientOriginalName(),
            'attachment_sha256' => $admitted['sha256'] ?? null,
            'created_by' => $by->id,
        ]);
        $this->audit->record('expense.recorded', $expense, ['amount' => (float) $expense->amount, 'category_id' => $expense->category_id, 'status' => $expense->status], ['clinic_id' => $clinic->id]);

        return $expense;
    }

    public function approve(ClinicExpense $expense, User $by): ClinicExpense
    {
        return $this->decide($expense, $by, ClinicExpense::APPROVED, null);
    }

    public function reject(ClinicExpense $expense, string $reason, User $by): ClinicExpense
    {
        if (trim($reason) === '') {
            throw new DomainException('Say why the expense is rejected.');
        }

        return $this->decide($expense, $by, ClinicExpense::REJECTED, mb_substr(trim($reason), 0, 500));
    }

    public function markPaid(ClinicExpense $expense, string $method, ?string $reference, User $by): ClinicExpense
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new DomainException('Choose how it was paid.');
        }

        return DB::transaction(function () use ($expense, $method, $reference) {
            $locked = ClinicExpense::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($expense->id);
            if ($locked->status !== ClinicExpense::APPROVED) {
                throw new DomainException('Only an approved expense can be marked paid.');
            }
            $locked->update(['status' => ClinicExpense::PAID, 'paid_at' => now(), 'payment_method' => $method, 'reference' => $reference ?? $locked->reference]);
            $this->audit->record('expense.paid', $locked, ['method' => $method], ['clinic_id' => $locked->clinic_id]);

            return $locked;
        });
    }

    /**
     * @param  array{title: string, amount: float|string, category_id: int, vendor_id?: ?int, branch_id?: ?int, payment_method?: ?string, frequency: string, next_due_on: string}  $data
     */
    public function saveRecurring(Clinic $clinic, array $data, User $by): ClinicRecurringExpense
    {
        $this->assertOwn($clinic, $data);
        if (! in_array($data['frequency'], ClinicRecurringExpense::FREQUENCIES, true)) {
            throw new DomainException('Choose how often it recurs.');
        }
        $recurring = ClinicRecurringExpense::query()->withoutGlobalScopes()->create([
            'clinic_id' => $clinic->id, 'branch_id' => $data['branch_id'] ?? null, 'category_id' => $data['category_id'], 'vendor_id' => $data['vendor_id'] ?? null,
            'title' => mb_substr(trim($data['title']), 0, 160), 'amount' => round((float) $data['amount'], 2), 'payment_method' => $data['payment_method'] ?? null,
            'frequency' => $data['frequency'], 'next_due_on' => $data['next_due_on'], 'is_active' => true, 'created_by' => $by->id,
        ]);
        $this->audit->record('expense.recurring_saved', $recurring, ['frequency' => $recurring->frequency, 'amount' => (float) $recurring->amount], ['clinic_id' => $clinic->id]);

        return $recurring;
    }

    public function setRecurringActive(ClinicRecurringExpense $recurring, bool $active, User $by): ClinicRecurringExpense
    {
        $recurring->update(['is_active' => $active]);
        $this->audit->record($active ? 'expense.recurring_resumed' : 'expense.recurring_paused', $recurring, [], ['clinic_id' => $recurring->clinic_id]);

        return $recurring;
    }

    /**
     * Record every recurring expense that has fallen due, each period once
     * (the unique period key makes a rerun harmless), as pending approval.
     */
    public function raiseDue(CarbonImmutable $today): int
    {
        $raised = 0;
        ClinicRecurringExpense::query()->withoutGlobalScopes()->where('is_active', true)->where('next_due_on', '<=', $today->toDateString())
            ->chunkById(200, function ($due) use ($today, &$raised) {
                foreach ($due as $recurring) {
                    DB::transaction(function () use ($recurring, $today, &$raised) {
                        $locked = ClinicRecurringExpense::query()->withoutGlobalScopes()->lockForUpdate()->find($recurring->id);
                        $next = CarbonImmutable::parse($locked->next_due_on);
                        while ($locked->is_active && $next->lessThanOrEqualTo($today)) {
                            try {
                                ClinicExpense::query()->withoutGlobalScopes()->create([
                                    'clinic_id' => $locked->clinic_id, 'branch_id' => $locked->branch_id, 'category_id' => $locked->category_id, 'vendor_id' => $locked->vendor_id,
                                    'recurring_expense_id' => $locked->id, 'title' => $locked->title, 'amount' => $locked->amount, 'spent_on' => $next->toDateString(),
                                    'payment_method' => $locked->payment_method, 'status' => ClinicExpense::PENDING, 'created_by' => $locked->created_by,
                                ]);
                                $raised++;
                            } catch (UniqueConstraintViolationException) {
                                // That period was already raised.
                            }
                            $next = match ($locked->frequency) {
                                'weekly' => $next->addWeek(),
                                'quarterly' => $next->addMonthsNoOverflow(3),
                                'yearly' => $next->addYearNoOverflow(),
                                default => $next->addMonthNoOverflow(),
                            };
                        }
                        $locked->update(['next_due_on' => $next->toDateString()]);
                    });
                }
            });

        return $raised;
    }

    private function decide(ClinicExpense $expense, User $by, string $status, ?string $reason): ClinicExpense
    {
        if (! $by->hasClinicPermission(ClinicPermission::ExpenseApprove)) {
            throw new DomainException('You cannot approve expenses.');
        }

        return DB::transaction(function () use ($expense, $by, $status, $reason) {
            $locked = ClinicExpense::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($expense->id);
            if ($locked->status !== ClinicExpense::PENDING) {
                throw new DomainException('This expense has already been decided.');
            }
            $locked->update(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'rejection_reason' => $reason]);
            $this->audit->record("expense.{$status}", $locked, ['reason' => $reason], ['clinic_id' => $locked->clinic_id]);

            return $locked;
        });
    }

    /** A category, vendor or branch named must be this clinic's. */
    private function assertOwn(Clinic $clinic, array $data): void
    {
        $own = fn (string $model, ?int $id) => $id === null || $model::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereKey($id)->exists();
        if (! $own(ClinicExpenseCategory::class, (int) $data['category_id'])) {
            throw new DomainException('Choose one of the clinic’s categories.');
        }
        if (! $own(ClinicVendor::class, isset($data['vendor_id']) ? (int) $data['vendor_id'] : null)) {
            throw new DomainException('Choose one of the clinic’s vendors.');
        }
        if (! $own(Branch::class, isset($data['branch_id']) ? (int) $data['branch_id'] : null)) {
            throw new DomainException('Choose one of the clinic’s branches.');
        }
        if (! $own(ClinicDepartment::class, isset($data['department_id']) ? (int) $data['department_id'] : null)) {
            throw new DomainException('Choose one of the clinic’s departments.');
        }
    }
}
