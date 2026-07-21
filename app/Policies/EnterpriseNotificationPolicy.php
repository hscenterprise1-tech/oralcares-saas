<?php

namespace App\Policies;

use App\Models\User;
use App\Shared\Notification\Models\EnterpriseNotification;
use Illuminate\Auth\Access\HandlesAuthorization;

class EnterpriseNotificationPolicy
{
    use HandlesAuthorization;

    public function view(User $user, EnterpriseNotification $notification): bool
    {
        return $notification->notifiable_type === $user->getMorphClass() 
            && $notification->notifiable_id === $user->getKey();
    }

    public function update(User $user, EnterpriseNotification $notification): bool
    {
        return $this->view($user, $notification);
    }
}
