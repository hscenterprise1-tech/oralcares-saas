<?php

namespace App\Shared\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Notification\Repositories\NotificationRepository;
use App\Shared\Notification\Requests\BulkNotificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationRepository $repository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['clinic_id', 'is_read', 'category', 'priority', 'is_archived']);
        $perPage = $request->input('per_page', 15);

        $notifications = $this->repository->getForUser($request->user(), $filters, $perPage);

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->repository->getUnreadCount($request->user(), $request->input('clinic_id'));

        return response()->json(['count' => $count]);
    }

    public function markAsRead(string $id, Request $request): JsonResponse
    {
        $notification = \App\Shared\Notification\Models\EnterpriseNotification::findOrFail($id);
        Gate::authorize('update', $notification);

        $success = $this->repository->markAsRead($id, $request->user());

        return response()->json(['success' => $success]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $count = $this->repository->markAllAsRead($request->user(), $request->input('clinic_id'));

        return response()->json(['success' => true, 'count' => $count]);
    }

    public function archive(string $id, Request $request): JsonResponse
    {
        $notification = \App\Shared\Notification\Models\EnterpriseNotification::findOrFail($id);
        Gate::authorize('update', $notification);

        $success = $this->repository->archive($id, $request->user());

        return response()->json(['success' => $success]);
    }

    public function bulkAction(BulkNotificationRequest $request): JsonResponse
    {
        $action = $request->validated('action');
        $ids = $request->validated('ids');
        $user = $request->user();

        // Normally we'd use a repository method for this bulk operation
        $query = \App\Shared\Notification\Models\EnterpriseNotification::whereIn('id', $ids)
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());

        $count = match ($action) {
            'read' => $query->update(['read_at' => now()]),
            'archive' => $query->update(['archived_at' => now()]),
            'delete' => $query->delete(),
        };

        return response()->json(['success' => true, 'count' => $count]);
    }
}
