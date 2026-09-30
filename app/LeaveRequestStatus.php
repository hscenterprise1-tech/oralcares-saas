<?php

namespace App;

/**
 * Where a leave request stands.
 *
 * Pending → Approved or Rejected; either of those, and Pending itself, can be
 * Cancelled. There is no path that reaches Approved without a decision, and the
 * decision is always attributed.
 */
enum LeaveRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            /*
             * An approved leave can still be cancelled — plans change, and the
             * balance it consumed is given back by a reversal entry rather than
             * by editing the figure it produced.
             */
            self::Approved => [self::Cancelled],
            self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /** Whether this request is holding balance. */
    public function consumesBalance(): bool
    {
        return $this === self::Approved;
    }
}
