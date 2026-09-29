<?php

namespace App;

/**
 * Where a payroll run stands.
 *
 * Draft → Calculated → PendingApproval → Approved → Locked → Paid, and a run
 * may fall back from Calculated to Draft while somebody is still working on it.
 * Nothing else is legal: `canTransitionTo()` is the only place the order is
 * stated, so a controller cannot quietly invent a shortcut past approval.
 *
 * Locked is the point of no return for money. From there the figures are
 * history, and a correction is a new run rather than an edit.
 */
enum PayrollPeriodStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Locked = 'locked';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Calculated => 'Calculated',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Locked => 'Locked',
            self::Paid => 'Paid',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Calculated],
            /* Recalculating is normal while a run is still being prepared. */
            self::Calculated => [self::Draft, self::PendingApproval],
            self::PendingApproval => [self::Approved, self::Draft],
            self::Approved => [self::Locked],
            self::Locked => [self::Paid],
            self::Paid => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /**
     * Whether the run's money may still change.
     *
     * Locked and Paid runs are historical records. Editing one would rewrite
     * what the business has already committed to paying.
     */
    public function isMutable(): bool
    {
        return ! in_array($this, [self::Locked, self::Paid], true);
    }
}
