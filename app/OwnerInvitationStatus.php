<?php

namespace App;

/**
 * The clinic owner's path from a won deal to a live clinic:
 *
 * created → sent → opened → accepted → profile_completed →
 * onboarding_started → onboarding_completed → active
 *
 * Revoked and expired end an invitation that was never accepted. The
 * lifecycle only moves forward; a repeated event is a no-op.
 */
enum OwnerInvitationStatus: string
{
    case Created = 'created';
    case Sent = 'sent';
    case Opened = 'opened';
    case Accepted = 'accepted';
    case ProfileCompleted = 'profile_completed';
    case OnboardingStarted = 'onboarding_started';
    case OnboardingCompleted = 'onboarding_completed';
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';

    /** The forward order; revoked and expired sit outside it. */
    public const ORDER = ['created', 'sent', 'opened', 'accepted', 'profile_completed', 'onboarding_started', 'onboarding_completed', 'active'];

    public function rank(): int
    {
        $index = array_search($this->value, self::ORDER, true);

        return $index === false ? -1 : $index;
    }

    /** Still waiting for the owner to accept, so the link may be used. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Created, self::Sent, self::Opened], true);
    }

    public function isAccepted(): bool
    {
        return $this->rank() >= self::Accepted->rank();
    }
}
