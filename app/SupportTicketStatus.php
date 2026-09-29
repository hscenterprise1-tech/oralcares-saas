<?php

namespace App;

/**
 * Where a support ticket stands.
 *
 * Open → Pending (waiting on the customer) → Resolved → Closed, with Resolved
 * reopenable because customers disagree about whether something was fixed and
 * a closed ticket they cannot reopen just becomes a second ticket.
 */
enum SupportTicketStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Pending => 'Waiting on customer',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Pending, self::Resolved, self::Closed],
            self::Pending => [self::Open, self::Resolved, self::Closed],
            self::Resolved => [self::Open, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /** Whether the SLA clock is still running. */
    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::Pending;
    }
}
