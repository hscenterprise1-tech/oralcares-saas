<?php

namespace App;

/**
 * Where an expense is in its life.
 *
 * Submitted → Approved → Paid. Submitting no longer approves: the old
 * `expensesStore` wrote `approved` in the same request that created the row,
 * behind a *read* permission, so anybody who could look at the expense ledger
 * could enter and approve any amount.
 *
 * `Pending` is legacy. One row in this database carries it, written before any
 * of this existed. Nothing produces it any more; it is accepted here so that
 * row stays readable and can still be approved, exactly as it stands.
 */
enum ExpenseStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
        };
    }

    /**
     * Where this expense may go next.
     *
     * Legacy `pending` behaves as `submitted`, because that is what it always
     * meant. Nothing moves backwards, and `paid` is the end.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending, self::Submitted => [self::Approved],
            self::Approved => [self::Paid],
            self::Paid => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /** Whether the amount and description may still be corrected. */
    public function isMutable(): bool
    {
        return $this === self::Pending || $this === self::Submitted;
    }

    /** Whether this expense counts as money the business has committed. */
    public function isCommitted(): bool
    {
        return $this === self::Approved || $this === self::Paid;
    }
}
