<?php

namespace App\Shared\Notification\Repositories;

use App\Models\User;
use App\Shared\Notification\Models\EnterpriseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationRepository
{
    /**
     * Get paginated notifications for a user.
     */
    public function getForUser(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = EnterpriseNotification::where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());

        if (isset($filters['clinic_id'])) {
            $query->where('clinic_id', $filters['clinic_id']);
        }

        if (isset($filters['is_read'])) {
            if ($filters['is_read']) {
                $query->whereNotNull('read_at');
            } else {
                $query->whereNull('read_at');
            }
        }

        if (isset($filters['is_archived'])) {
            if ($filters['is_archived']) {
                $query->whereNotNull('archived_at');
            } else {
                $query->whereNull('archived_at');
            }
        } else {
            // By default hide archived
            $query->whereNull('archived_at');
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Get unread notification count.
     */
    public function getUnreadCount(User $user, ?int $clinicId = null): int
    {
        $query = EnterpriseNotification::where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->whereNull('read_at')
            ->whereNull('archived_at');

        if ($clinicId) {
            $query->where('clinic_id', $clinicId);
        }

        return $query->count();
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(string $id, User $user): bool
    {
        return (bool) EnterpriseNotification::where('id', $id)
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->update(['read_at' => now()]);
    }

    /**
     * Mark all notifications as read for a user (optionally scoped by clinic).
     */
    public function markAllAsRead(User $user, ?int $clinicId = null): int
    {
        $query = EnterpriseNotification::where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->whereNull('read_at');

        if ($clinicId) {
            $query->where('clinic_id', $clinicId);
        }

        return $query->update(['read_at' => now()]);
    }

    /**
     * Archive a single notification.
     */
    public function archive(string $id, User $user): bool
    {
        return (bool) EnterpriseNotification::where('id', $id)
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->update(['archived_at' => now()]);
    }
}
