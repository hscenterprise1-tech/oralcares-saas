<?php

namespace App\Policies;

use App\Models\MarketingWhatsAppConversation;
use App\Models\User;

class MarketingWhatsAppConversationPolicy
{
    /**
     * Whether the conversation is visible to this user at all: same tenant,
     * and either unassigned (anyone can pick it up) or assigned to them.
     * Master Admins bypass this entirely via the global Gate::before hook.
     */
    public function view(User $user, MarketingWhatsAppConversation $conversation): bool
    {
        if ($user->organization_id && $conversation->organization_id !== $user->organization_id) {
            return false;
        }

        return $conversation->assigned_user_id === null || $conversation->assigned_user_id === $user->id;
    }

    public function reply(User $user, MarketingWhatsAppConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    /**
     * Whether this user may change the conversation's assignment at all.
     * The controller enforces the finer rule that a non-Master-Admin may only
     * assign the conversation to themselves.
     */
    public function assign(User $user, MarketingWhatsAppConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
