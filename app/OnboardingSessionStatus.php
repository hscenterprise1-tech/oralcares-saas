<?php

namespace App;

/**
 * Where an onboarding session stands.
 *
 * pending → in_progress → completed is the normal path. A session can be
 * paused by its owner and resumed, and blocked by the system (for example
 * when the clinic's subscription window is closed) and unblocked back to
 * the state it had. Completed is terminal.
 */
enum OnboardingSessionStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';
    case Blocked = 'blocked';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress, self::Paused, self::Blocked, self::Completed],
            self::InProgress => [self::Paused, self::Blocked, self::Completed],
            self::Paused => [self::InProgress, self::Blocked],
            self::Blocked => [self::Pending, self::InProgress, self::Paused],
            self::Completed => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function acceptsChanges(): bool
    {
        return in_array($this, [self::Pending, self::InProgress, self::Paused], true);
    }
}
