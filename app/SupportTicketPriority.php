<?php

namespace App;

/**
 * How urgent a ticket is.
 *
 * The four levels are structural — every support desk has them. What each one
 * *promises* is not: the response and resolution intervals live in
 * `company_support_sla_targets`, configured by the business, and are seeded
 * with nothing. A priority with no target simply has no due date.
 */
enum SupportTicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** For ordering a queue: the most urgent first. */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 4,
            self::High => 3,
            self::Normal => 2,
            self::Low => 1,
        };
    }
}
