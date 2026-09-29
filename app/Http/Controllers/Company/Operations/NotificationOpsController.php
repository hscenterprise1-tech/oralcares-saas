<?php

namespace App\Http\Controllers\Company\Operations;

use App\Http\Controllers\Controller;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Notification\Models\NotificationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the system has been sending, and whether it arrived.
 *
 * Explicitly **not** a second notification architecture. `app/Shared/Notification`
 * already has channels, enums, jobs, a listener, an engine, preferences and an
 * inbox at `/notifications`; none of it is duplicated, replaced or written to
 * here. What was missing is the operational view: the inbox answers "what did I
 * get", and nothing answered "what has this company been sending, and what
 * failed".
 *
 * Read-only. Delivery outcomes are a record of what happened, and this surface
 * does not get to revise them.
 */
class NotificationOpsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('view-notifications');

        $filters = $request->only(['search', 'category', 'channel', 'status']);

        $logs = NotificationLog::query()
            ->when($filters['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $notifications = EnterpriseNotification::query()
            ->when($filters['search'] ?? null, function ($query, $search) {
                /* Grouped, so a search cannot escape the filters beside it. */
                $query->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%");
                });
            })
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return Inertia::render('Company/Operations/NotificationsWorkspace', [
            'notifications' => $notifications->map(fn (EnterpriseNotification $row) => [
                'id' => $row->id,
                'title' => $row->title,
                'type' => $row->type,
                'category' => $row->category?->value ?? $row->category,
                'priority' => $row->priority?->value ?? $row->priority,
                'read' => $row->read_at !== null,
                'archived' => $row->archived_at !== null,
                'at' => $row->created_at?->format('d M Y, H:i'),
            ])->values(),
            'logs' => $logs->map(fn (NotificationLog $row) => [
                'id' => $row->id,
                'channel' => $row->channel?->value ?? $row->channel,
                'status' => $row->status?->value ?? $row->status,
                'at' => $row->created_at?->format('d M Y, H:i'),
            ])->values(),
            'filters' => $filters,
            'summary' => [
                'total' => EnterpriseNotification::query()->count(),
                'unread' => EnterpriseNotification::query()->whereNull('read_at')->count(),
                'archived' => EnterpriseNotification::query()->whereNotNull('archived_at')->count(),
                'delivered' => $logs->filter(fn ($row) => in_array((string) ($row->status?->value ?? $row->status), ['sent', 'delivered'], true))->count(),
                'failed' => $logs->filter(fn ($row) => (string) ($row->status?->value ?? $row->status) === 'failed')->count(),
                'log_total' => NotificationLog::query()->count(),
            ],
            'byCategory' => EnterpriseNotification::query()
                ->selectRaw('category, COUNT(*) as total')
                ->groupBy('category')
                ->pluck('total', 'category'),
            'byChannel' => NotificationLog::query()
                ->selectRaw('channel, COUNT(*) as total')
                ->groupBy('channel')
                ->pluck('total', 'channel'),
            /* The existing inbox, linked rather than rebuilt. */
            'inboxUrl' => '/notifications',
            'preferencesUrl' => '/notifications/preferences',
        ]);
    }
}
