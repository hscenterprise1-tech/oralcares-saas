<?php

namespace App\Shared\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ClinicContext;
use App\Shared\Notification\Models\EnterpriseNotification;
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

    /**
     * The most notifications returned per page, whatever `per_page` asks for.
     */
    public const MAX_PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['clinic_id', 'is_read', 'category', 'priority', 'is_archived']);
        $clinicId = $this->clinicScope($request);
        if ($clinicId !== null) {
            $filters['clinic_id'] = $clinicId;
        }

        $perPage = max(1, min(self::MAX_PER_PAGE, (int) $request->input('per_page', 15)));

        $notifications = $this->repository->getForUser($request->user(), $filters, $perPage);

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->repository->getUnreadCount($request->user(), $this->clinicScope($request) ?? $request->integer('clinic_id') ?: null);

        return response()->json(['count' => $count]);
    }

    public function markAsRead(string $id, Request $request): JsonResponse
    {
        $notification = EnterpriseNotification::findOrFail($id);
        Gate::authorize('update', $notification);

        $success = $this->repository->markAsRead($id, $request->user());

        return response()->json(['success' => $success]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $count = $this->repository->markAllAsRead($request->user(), $this->clinicScope($request) ?? $request->integer('clinic_id') ?: null);

        return response()->json(['success' => true, 'count' => $count]);
    }

    /**
     * Inside a clinic session, the clinic the session is bound to, whatever
     * `clinic_id` the request sends: a member of two clinics sees and clears
     * only the notifications of the clinic they are working in.
     */
    private function clinicScope(Request $request): ?int
    {
        $context = app(ClinicContext::class);

        return $context->hasClinic() ? $context->clinic()?->id : null;
    }

    public function archive(string $id, Request $request): JsonResponse
    {
        $notification = EnterpriseNotification::findOrFail($id);
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
        $query = EnterpriseNotification::whereIn('id', $ids)
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
