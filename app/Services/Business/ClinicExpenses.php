<?php

namespace App\Services\Business;

use App\ClinicMembershipStatus;
use App\ClinicPermission;
use App\EmploymentStatus;
use App\Exceptions\OwnerModeRequired;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicDepartment;
use App\Models\ClinicExpense;
use App\Models\ClinicExpenseCategory;
use App\Models\ClinicMembership;
use App\Models\ClinicRecurringExpense;
use App\Models\ClinicVendor;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Security\Malware\ScannedUploadIntake;
use App\Services\Security\SecurityAudit;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of a clinic's expenses.
 *
 * An expense is always recorded `pending`; someone else with `expense.approve`
 * approves or rejects it, never the person who recorded it. An approved
 * expense is then paid. Deciding and paying need the permission at the
 * expense's own branch; an expense with no branch needs someone who works
 * across the whole clinic. Receipts pass the malware scan into private
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
        protected ClinicContext $clinicContext,
        protected OwnerMode $ownerMode,
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
            // Pending even when the recorder may approve: deciding is someone else's.
            'status' => ClinicExpense::PENDING,
            'attachment_path' => $admitted['path'] ?? null,
            'attachment_name' => $receipt?->getClientOriginalName(),
            'attachment_sha256' => $admitted['sha256'] ?? null,
            'created_by' => $by->id,
        ]);
        $this->audit->record('expense.recorded', $expense, ['amount' => (float) $expense->amount, 'category_id' => $expense->category_id, 'status' => $expense->status], ['clinic_id' => $clinic->id]);

        return $expense;
    }

    /**
     * `$soleOwnerDirect` asks for the sole-owner direct path on the owner's own
     * expense (see `decide()`); it changes nothing for anyone else's.
     */
    public function approve(ClinicExpense $expense, User $by, bool $soleOwnerDirect = false): ClinicExpense
    {
        return $this->decide($expense, $by, ClinicExpense::APPROVED, null, $soleOwnerDirect);
    }

    public function reject(ClinicExpense $expense, string $reason, User $by, bool $soleOwnerDirect = false): ClinicExpense
    {
        if (trim($reason) === '') {
            throw new DomainException('Say why the expense is rejected.');
        }

        return $this->decide($expense, $by, ClinicExpense::REJECTED, mb_substr(trim($reason), 0, 500), $soleOwnerDirect);
    }

    /**
     * Record that an approved expense was paid, how, and with what reference.
     * It records what the payer says happened; it moves no money and proves no
     * transfer. The recorder never pays their own expense, except through the
     * sole-owner direct path (`$soleOwnerDirect`): the owner paying the expense
     * they recorded and approved directly, with a fresh confirmation, while no
     * one else could pay it. That is audited as its own event.
     */
    public function markPaid(ClinicExpense $expense, string $method, ?string $reference, User $by, bool $soleOwnerDirect = false): ClinicExpense
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new DomainException('Choose how it was paid.');
        }

        // Checked before the transaction, so a refusal's audit entry survives it.
        $direct = $soleOwnerDirect && $this->recordedBy($expense, $by);
        if (! $direct && $this->recordedBy($expense, $by)) {
            $this->audit->record('expense.pay_refused', $expense, ['reason' => 'recorder'], ['clinic_id' => $expense->clinic_id]);

            throw new AuthorizationException('An expense is paid by someone other than the person who recorded it.');
        }
        if ($direct) {
            $this->assertSoleOwnerDirect($expense, $by, paying: true);
        } else {
            $this->ownerMode->requireForSensitiveAction($by, 'expense.pay', OwnerMode::STANDARD, ['expense_id' => $expense->id, 'branch_id' => $expense->branch_id]);
        }

        return DB::transaction(function () use ($expense, $method, $reference, $by, $direct) {
            $locked = ClinicExpense::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($expense->id);
            $this->assertMayActOn($locked, $by, ClinicPermission::ExpenseManage);
            if ($this->recordedBy($locked, $by) && ! $direct) {
                throw new AuthorizationException('An expense is paid by someone other than the person who recorded it.');
            }
            if ($locked->status !== ClinicExpense::APPROVED) {
                throw new DomainException('Only an approved expense can be marked paid.');
            }
            if ($direct) {
                $this->assertSoleOwnerDirect($locked, $by, paying: true, audited: false);
            }
            $locked->update(['status' => ClinicExpense::PAID, 'paid_at' => now(), 'payment_method' => $method, 'reference' => $reference ?? $locked->reference]);
            $this->audit->record($direct ? 'expense.paid_sole_owner_direct' : 'expense.paid', $locked, ['method' => $method, 'amount' => (float) $locked->amount, 'path' => $direct ? 'sole_owner_direct' : 'payer'], ['clinic_id' => $locked->clinic_id]);

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

    /**
     * Someone other than the recorder decides. The one exception is the
     * sole-owner direct path: the clinic's primary owner deciding their own
     * expense, asked for explicitly, with their password confirmed within the
     * fresh-verification window, and only while no one else could decide it.
     * It is recorded as such in the audit trail.
     */
    private function decide(ClinicExpense $expense, User $by, string $status, ?string $reason, bool $soleOwnerDirect = false): ClinicExpense
    {
        if (! $by->hasClinicPermission(ClinicPermission::ExpenseApprove)) {
            throw new DomainException('You cannot approve expenses.');
        }

        // The step-up checks run before the transaction, so a refusal's audit
        // entry is not rolled back with it; they run again under the lock.
        $direct = $soleOwnerDirect && $this->recordedBy($expense, $by);
        if (! $direct && $this->recordedBy($expense, $by)) {
            $this->audit->record('expense.self_decision_refused', $expense, ['decision' => $status], ['clinic_id' => $expense->clinic_id]);

            throw new AuthorizationException('An expense is decided by someone other than the person who recorded it.');
        }
        if ($direct) {
            $this->assertSoleOwnerDirect($expense, $by);
        } else {
            $this->ownerMode->requireForSensitiveAction($by, 'expense.decide', OwnerMode::STANDARD, ['expense_id' => $expense->id, 'branch_id' => $expense->branch_id]);
        }

        return DB::transaction(function () use ($expense, $by, $status, $reason, $direct) {
            $locked = ClinicExpense::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($expense->id);
            $this->assertMayActOn($locked, $by, ClinicPermission::ExpenseApprove);
            if ($this->recordedBy($locked, $by) && ! $direct) {
                throw new AuthorizationException('An expense is decided by someone other than the person who recorded it.');
            }
            if ($direct) {
                $this->assertSoleOwnerDirect($locked, $by, audited: false);
            }
            if ($locked->status !== ClinicExpense::PENDING) {
                throw new DomainException('This expense has already been decided.');
            }
            $locked->update(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'rejection_reason' => $reason]);
            $this->audit->record("expense.{$status}", $locked, ['reason' => $reason, 'path' => $direct ? 'sole_owner_direct' : 'approver'], ['clinic_id' => $locked->clinic_id]);

            return $locked;
        });
    }

    /**
     * The sole-owner direct path's conditions, each checked on the server:
     * the actor is this clinic's primary owner, in Owner Mode with a fresh
     * verification (never skipped, even outside a request), and no other
     * member could decide this expense.
     *
     * @throws AuthorizationException
     */
    private function assertSoleOwnerDirect(ClinicExpense $expense, User $by, bool $paying = false, bool $audited = true): void
    {
        $membership = $this->clinicContext->membership();
        // `$stepUp`: the owner may confirm their password and send again (`OwnerModeRequired`).
        $refuse = function (string $reason, string $message, ?string $stepUp = null) use ($expense, $audited, $paying): never {
            if ($audited) {
                $this->audit->record($paying ? 'expense.sole_owner_direct_pay_refused' : 'expense.sole_owner_direct_refused', $expense, ['reason' => $reason], ['clinic_id' => $expense->clinic_id]);
            }

            throw $stepUp !== null ? new OwnerModeRequired($message, $stepUp) : new AuthorizationException($message);
        };

        if (! $this->recordedBy($expense, $by)) {
            $refuse('not_recorder', 'The direct path is only for the owner\'s own expense.');
        }
        if (! $membership || ! $membership->is_primary_owner || $membership->user_id !== $by->id || $membership->clinic_id !== $expense->clinic_id) {
            $refuse('not_primary_owner', 'Only the clinic owner may act on their own expense, and only when no one else can.');
        }
        // Paying directly follows only the owner's own direct decision: it never
        // approves anything, and never pays what someone else approved.
        if ($paying && ($expense->decided_by === null || (int) $expense->decided_by !== (int) $by->id)) {
            $refuse('not_directly_approved', 'Only an expense you approved through the direct path can be paid through it.');
        }
        if (auth()->id() !== $by->id) {
            $refuse('actor_mismatch', 'This action can only be taken by the signed-in user.');
        }
        if (! $this->ownerMode->freshlyVerified()) {
            $refuse('verification_stale', OwnerMode::FRESH_REFUSAL, $this->ownerMode->authorized() ? OwnerModeRequired::STALE : OwnerModeRequired::INACTIVE);
        }
        if ($this->independentMemberHolds($paying ? ClinicPermission::ExpenseManage : ClinicPermission::ExpenseApprove, $expense, $by)) {
            $refuse($paying ? 'payer_available' : 'approver_available', $paying
                ? 'Someone else in the clinic can pay this expense. Ask them to.'
                : 'Someone else in the clinic can decide this expense. Ask them to.');
        }
    }

    private function recordedBy(ClinicExpense $expense, User $user): bool
    {
        return $expense->created_by !== null && (int) $expense->created_by === (int) $user->id;
    }

    /**
     * Whether another active member could act on this expense in the owner's
     * place: someone other than the recorder, signed in to this clinic, not on
     * their way out, with `$permission` (`expense.approve` to decide,
     * `expense.manage` to pay) held where the expense is (at its branch, with
     * access to it; for a clinic-wide expense, at any branch while working
     * across every branch). Counted generously: any doubt means one exists.
     */
    private function independentMemberHolds(ClinicPermission $permission, ClinicExpense $expense, User $recorder): bool
    {
        $tables = config('permission.table_names');
        $permissionId = DB::table($tables['permissions'])->where('name', $permission->value)->value('id');
        if ($permissionId === null) {
            return false;
        }

        $branchIds = $expense->branch_id !== null
            ? [$expense->branch_id]
            : Branch::query()->withoutGlobalScopes()->where('clinic_id', $expense->clinic_id)->pluck('id')->all();
        $viaRoles = DB::table($tables['model_has_roles'])->where('model_type', User::class)->whereIn('branch_id', $branchIds)
            ->whereIn('role_id', DB::table($tables['role_has_permissions'])->where('permission_id', $permissionId)->select('role_id'))
            ->select('model_id');
        $direct = DB::table($tables['model_has_permissions'])->where('model_type', User::class)->whereIn('branch_id', $branchIds)
            ->where('permission_id', $permissionId)->select('model_id');

        return ClinicMembership::query()->withoutGlobalScopes()
            ->where('clinic_memberships.clinic_id', $expense->clinic_id)
            ->where('clinic_memberships.status', ClinicMembershipStatus::Active)
            ->where('clinic_memberships.user_id', '!=', $recorder->id)
            ->whereNotExists(fn ($query) => $query->from('clinic_employees')
                ->whereColumn('clinic_employees.clinic_id', 'clinic_memberships.clinic_id')
                ->whereColumn('clinic_employees.user_id', 'clinic_memberships.user_id')
                ->whereIn('clinic_employees.status', EmploymentStatus::accessEnding()))
            ->where(fn ($query) => $query->whereIn('clinic_memberships.user_id', $viaRoles)->orWhereIn('clinic_memberships.user_id', $direct))
            ->when(
                $expense->branch_id !== null,
                fn ($query) => $query->where(fn ($access) => $access->where('clinic_memberships.has_all_branches_access', true)
                    ->orWhereExists(fn ($pivot) => $pivot->from('branch_clinic_membership')
                        ->whereColumn('branch_clinic_membership.clinic_membership_id', 'clinic_memberships.id')
                        ->where('branch_clinic_membership.branch_id', $expense->branch_id))),
                fn ($query) => $query->where('clinic_memberships.has_all_branches_access', true),
            )
            ->exists();
    }

    /**
     * The permission must be held where the expense is: at its branch, from the
     * persisted record. An expense with no branch belongs to the whole clinic,
     * so only the owner or a member working across every branch may act on it.
     *
     * @throws AuthorizationException
     */
    private function assertMayActOn(ClinicExpense $expense, User $by, ClinicPermission $permission): void
    {
        $membership = $this->clinicContext->membership();
        $inClinic = $membership !== null && $membership->user_id === $by->id && $membership->clinic_id === $expense->clinic_id;

        if ($expense->branch_id !== null) {
            $branch = Branch::query()->withoutGlobalScopes()->where('clinic_id', $expense->clinic_id)->find($expense->branch_id);
            $allowed = $inClinic && $branch !== null && $by->hasClinicPermissionAt($permission, $branch);
        } else {
            $allowed = $inClinic && ($membership->is_primary_owner || $membership->has_all_branches_access) && $by->hasClinicPermission($permission);
        }

        if (! $allowed) {
            throw new AuthorizationException('You may not do this for that branch\'s expenses.');
        }
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
