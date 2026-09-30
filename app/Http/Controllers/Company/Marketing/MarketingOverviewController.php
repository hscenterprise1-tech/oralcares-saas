<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingContactImport;
use App\Models\MarketingInstagramAccount;
use App\Models\MarketingInstagramPost;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\MarketingWhatsAppMessage;
use App\Services\Marketing\MarketingSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MarketingOverviewController extends Controller
{
    public function __construct(
        protected MarketingSettingsService $settingsService
    ) {}

    /**
     * Display the Real-time Marketing Command Center Overview.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        // ═════════════════════════════════════════════════════════════════════
        // 1. TOP OPERATIONAL METRICS
        // ═════════════════════════════════════════════════════════════════════
        $totalContacts = MarketingContact::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count();
        $todayNewContacts = MarketingContact::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->whereDate('created_at', today())
            ->count();

        // Untouched Contacts: Active contacts that have never been included in any campaign dispatch
        $untouchedContacts = MarketingContact::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('marketing_status', 'active')
            ->whereDoesntHave('campaignRecipients')
            ->count();

        // Active Campaigns Breakdown
        $activeSendingCampaigns = MarketingCampaign::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'sending')
            ->count();
        $activeScheduledCampaigns = MarketingCampaign::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'scheduled')
            ->count();
        $totalActiveCampaigns = $activeSendingCampaigns + $activeScheduledCampaigns;

        // Messages Dispatched Today (Campaign recipients + direct messages)
        $campaignSentToday = MarketingCampaignRecipient::whereHas('campaign', fn ($q) => $q->when($orgId, fn ($sq) => $sq->where('organization_id', $orgId)))
            ->whereDate('sent_at', today())
            ->count();
        $directSentToday = MarketingWhatsAppMessage::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('direction', 'outbound')
            ->whereDate('created_at', today())
            ->count();
        $totalMessagesSentToday = $campaignSentToday + $directSentToday;

        $campaignDeliveredToday = MarketingCampaignRecipient::whereHas('campaign', fn ($q) => $q->when($orgId, fn ($sq) => $sq->where('organization_id', $orgId)))
            ->whereDate('delivered_at', today())
            ->count();
        $directDeliveredToday = MarketingWhatsAppMessage::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('direction', 'outbound')
            ->where('status', 'delivered')
            ->whereDate('updated_at', today())
            ->count();
        $totalDeliveredToday = $campaignDeliveredToday + $directDeliveredToday;

        $campaignFailedToday = MarketingCampaignRecipient::whereHas('campaign', fn ($q) => $q->when($orgId, fn ($sq) => $sq->where('organization_id', $orgId)))
            ->whereDate('failed_at', today())
            ->count();
        $directFailedToday = MarketingWhatsAppMessage::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('direction', 'outbound')
            ->where('status', 'failed')
            ->whereDate('updated_at', today())
            ->count();
        $totalFailedToday = $campaignFailedToday + $directFailedToday;

        // Replies Awaiting Action
        $unreadConversationsCount = MarketingWhatsAppConversation::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('unread_count', '>', 0)
            ->count();

        // Compliance & Opt-Out Metrics
        $optedOutContactsCount = MarketingContact::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('marketing_status', 'opted_out')
            ->count();
        $suppressedContactsCount = MarketingContact::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('marketing_status', 'suppressed')
            ->count();

        // ═════════════════════════════════════════════════════════════════════
        // 2. TODAY'S MARKETING ACTIVITY (CHRONOLOGICAL OPERATIONAL FEED)
        // ═════════════════════════════════════════════════════════════════════
        $activityEvents = collect();

        // A. Recent contact imports
        MarketingContactImport::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->latest('created_at')
            ->take(3)
            ->get()
            ->each(function ($imp) use (&$activityEvents) {
                $activityEvents->push([
                    'id' => 'import-'.$imp->id,
                    'type' => 'import',
                    'title' => 'Contacts imported from '.($imp->original_filename ?: 'CSV File'),
                    'detail' => "{$imp->imported_count} contacts added · {$imp->duplicate_count} duplicates skipped",
                    'timestamp' => $imp->created_at?->toIso8601String(),
                    'status' => $imp->status,
                    'action_url' => '/company/marketing/contacts',
                ]);
            });

        // B. Active & completed campaigns today
        MarketingCampaign::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where(function ($q) {
                $q->whereDate('started_at', today())
                    ->orWhereDate('completed_at', today())
                    ->orWhere('status', 'sending');
            })
            ->latest('updated_at')
            ->take(4)
            ->get()
            ->each(function ($camp) use (&$activityEvents) {
                $statusLabel = match ($camp->status) {
                    'completed' => 'Campaign completed',
                    'sending' => 'Campaign is currently sending',
                    default => 'Campaign started',
                };
                $activityEvents->push([
                    'id' => 'campaign-'.$camp->id,
                    'type' => 'campaign',
                    'title' => "{$statusLabel}: {$camp->name}",
                    'detail' => "{$camp->sent_count} of {$camp->recipients_count} messages dispatched ({$camp->delivered_count} delivered)",
                    'timestamp' => ($camp->completed_at ?? $camp->started_at ?? $camp->updated_at)?->toIso8601String(),
                    'status' => $camp->status,
                    'action_url' => "/company/marketing/campaigns/{$camp->id}",
                ]);
            });

        // C. New inbound WhatsApp replies
        MarketingWhatsAppMessage::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('direction', 'inbound')
            ->with('conversation.contact:id,name')
            ->latest('created_at')
            ->take(4)
            ->get()
            ->each(function ($msg) use (&$activityEvents) {
                $senderName = $msg->conversation?->contact?->name ?: ($msg->conversation?->phone ?: 'Customer');
                $activityEvents->push([
                    'id' => 'reply-'.$msg->id,
                    'type' => 'reply',
                    'title' => "New WhatsApp reply from {$senderName}",
                    'detail' => Str::limit($msg->body ?? 'Incoming WhatsApp message', 75),
                    'timestamp' => $msg->created_at?->toIso8601String(),
                    'status' => 'received',
                    'action_url' => $msg->conversation_id ? "/company/marketing/conversations?conversation_id={$msg->conversation_id}" : '/company/marketing/conversations',
                ]);
            });

        // D. Failed message dispatches
        MarketingCampaignRecipient::whereHas('campaign', fn ($q) => $q->when($orgId, fn ($sq) => $sq->where('organization_id', $orgId)))
            ->where('status', 'failed')
            ->with(['campaign:id,name', 'contact:id,name'])
            ->latest('failed_at')
            ->take(3)
            ->get()
            ->each(function ($rec) use (&$activityEvents) {
                $recipientName = $rec->contact?->name ?: $rec->phone;
                $activityEvents->push([
                    'id' => 'failed-'.$rec->id,
                    'type' => 'failed',
                    'title' => "Message dispatch failed for {$recipientName}",
                    'detail' => $rec->failure_reason ?: "Failed in campaign '{$rec->campaign?->name}'",
                    'timestamp' => ($rec->failed_at ?? $rec->updated_at)?->toIso8601String(),
                    'status' => 'failed',
                    'action_url' => $rec->campaign_id ? "/company/marketing/campaigns/{$rec->campaign_id}" : '/company/marketing/campaigns',
                ]);
            });

        // E. Opt-outs
        MarketingContact::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('marketing_status', 'opted_out')
            ->latest('status_changed_at')
            ->take(2)
            ->get()
            ->each(function ($cnt) use (&$activityEvents) {
                $activityEvents->push([
                    'id' => 'optout-'.$cnt->id,
                    'type' => 'opt_out',
                    'title' => "{$cnt->name} opted out of marketing",
                    'detail' => $cnt->suppression_reason ?: 'Requested opt-out via keyword',
                    'timestamp' => ($cnt->status_changed_at ?? $cnt->updated_at)?->toIso8601String(),
                    'status' => 'opted_out',
                    'action_url' => '/company/marketing/contacts',
                ]);
            });

        // Sort unified activities chronologically descending
        $todayActivity = $activityEvents
            ->sortByDesc(fn ($item) => $item['timestamp'] ?? '')
            ->values()
            ->take(8)
            ->all();

        // ═════════════════════════════════════════════════════════════════════
        // 3. ACTIVE CAMPAIGNS (OPERATIONAL LIST/TABLE)
        // ═════════════════════════════════════════════════════════════════════
        $activeCampaigns = MarketingCampaign::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with(['audience:id,name', 'whatsappAccount:id,verified_name,display_phone_number'])
            ->whereIn('status', ['sending', 'scheduled', 'preparing', 'draft', 'paused'])
            ->latest('updated_at')
            ->take(5)
            ->get()
            ->map(function ($c) {
                $recCount = (int) $c->recipients_count;
                $sentCount = (int) $c->sent_count;
                $progressPercent = $recCount > 0 ? (int) round(($sentCount / $recCount) * 100) : 0;

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'audience_name' => $c->audience?->name ?? 'All Contacts',
                    'sender_number' => $c->whatsappAccount?->display_phone_number ?: ($c->whatsappAccount?->verified_name ?: 'Default WhatsApp Line'),
                    'status' => $c->status,
                    'recipients_count' => $recCount,
                    'sent_count' => $sentCount,
                    'delivered_count' => (int) $c->delivered_count,
                    'failed_count' => (int) $c->failed_count,
                    'replied_count' => (int) $c->replied_count,
                    'progress_percent' => $progressPercent,
                    'scheduled_at' => $c->scheduled_at?->toIso8601String(),
                    'updated_at' => $c->updated_at?->toIso8601String(),
                ];
            });

        // ═════════════════════════════════════════════════════════════════════
        // 4. NEEDS ATTENTION (ACTIONABLE HIGH-PRIORITY ITEMS)
        // ═════════════════════════════════════════════════════════════════════
        $settings = $this->settingsService->getSettings($orgId ?? 1);
        $needsAttention = [];

        // Critical: Emergency Freeze is Active
        if ($settings->emergency_freeze_enabled) {
            $needsAttention[] = [
                'id' => 'emergency-freeze',
                'level' => 'critical',
                'title' => 'Emergency Broadcast Freeze is Active',
                'description' => 'All outbound marketing sends are frozen across the organization. Lifting the freeze will restore pending queues.',
                'action_label' => 'Review Guardrails',
                'action_url' => '/company/marketing/settings',
                'badge' => 'FROZEN',
                'tone' => 'bad',
            ];
        }

        // High: Replies Awaiting Response
        if ($unreadConversationsCount > 0) {
            $needsAttention[] = [
                'id' => 'unread-replies',
                'level' => 'high',
                'title' => "{$unreadConversationsCount} Conversation".($unreadConversationsCount === 1 ? '' : 's').' Waiting for Reply',
                'description' => 'Prospects or contacts responded to your marketing dispatches and are awaiting response.',
                'action_label' => 'Open Inbox',
                'action_url' => '/company/marketing/conversations',
                'badge' => "{$unreadConversationsCount} Unread",
                'tone' => 'warn',
            ];
        }

        // WhatsApp Account Health / Disconnection
        $whatsappAccounts = MarketingWhatsAppAccount::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->get();
        $hasDisconnectedAccount = $whatsappAccounts->contains(fn ($acc) => $acc->status !== 'connected');

        if ($hasDisconnectedAccount || $whatsappAccounts->isEmpty()) {
            $needsAttention[] = [
                'id' => 'whatsapp-health',
                'level' => 'high',
                'title' => $whatsappAccounts->isEmpty() ? 'No WhatsApp Line Connected' : 'WhatsApp Line Disconnected / Error',
                'description' => $whatsappAccounts->isEmpty()
                    ? 'Connect a WhatsApp Business API account to begin sending broadcasts and receiving replies.'
                    : 'A connected WhatsApp line has lost authentication or returned an API error.',
                'action_label' => 'Check WhatsApp Settings',
                'action_url' => '/company/marketing/whatsapp',
                'badge' => 'Connection Needed',
                'tone' => 'bad',
            ];
        }

        // Failed Message Dispatches in recent 3 days
        $recentFailedCount = MarketingCampaignRecipient::whereHas('campaign', fn ($q) => $q->when($orgId, fn ($sq) => $sq->where('organization_id', $orgId)))
            ->where('status', 'failed')
            ->whereDate('failed_at', '>=', now()->subDays(3))
            ->count();

        if ($recentFailedCount > 0) {
            $needsAttention[] = [
                'id' => 'failed-messages',
                'level' => 'medium',
                'title' => "{$recentFailedCount} Message".($recentFailedCount === 1 ? '' : 's').' Failed to Deliver',
                'description' => 'Dispatches failed due to invalid numbers or WhatsApp template delivery rejections in recent campaigns.',
                'action_label' => 'Inspect Campaigns',
                'action_url' => '/company/marketing/campaigns',
                'badge' => "{$recentFailedCount} Failed",
                'tone' => 'bad',
            ];
        }

        // Campaigns Scheduled for Today
        $campaignsScheduledTodayCount = MarketingCampaign::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'scheduled')
            ->whereDate('scheduled_at', today())
            ->count();

        if ($campaignsScheduledTodayCount > 0) {
            $needsAttention[] = [
                'id' => 'scheduled-today',
                'level' => 'medium',
                'title' => "{$campaignsScheduledTodayCount} Campaign".($campaignsScheduledTodayCount === 1 ? '' : 's').' Scheduled for Launch Today',
                'description' => 'Automated broadcasts are queued for dispatch today. Ensure template variables and recipient audiences are verified.',
                'action_label' => 'View Schedule',
                'action_url' => '/company/marketing/campaigns',
                'badge' => 'Scheduled',
                'tone' => 'neutral',
            ];
        }

        // Instagram Posts Pending Approval
        $pendingInstagramPostsCount = MarketingInstagramPost::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'pending_approval')
            ->count();

        if ($pendingInstagramPostsCount > 0) {
            $needsAttention[] = [
                'id' => 'instagram-pending',
                'level' => 'medium',
                'title' => "{$pendingInstagramPostsCount} AI Instagram Post".($pendingInstagramPostsCount === 1 ? '' : 's').' Awaiting Approval',
                'description' => 'AI-drafted visual posts and captions require review and approval before publishing to Instagram.',
                'action_label' => 'Review Posts',
                'action_url' => '/company/marketing/instagram',
                'badge' => "{$pendingInstagramPostsCount} Pending",
                'tone' => 'warn',
            ];
        }

        // ═════════════════════════════════════════════════════════════════════
        // 5. RECENT IMPORTS (LATEST 5 BATCHES)
        // ═════════════════════════════════════════════════════════════════════
        $recentImports = MarketingContactImport::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with('creator:id,name')
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(fn ($imp) => [
                'id' => $imp->id,
                'original_filename' => $imp->original_filename ?: "Batch #{$imp->id}",
                'created_at' => $imp->created_at?->toIso8601String(),
                'status' => $imp->status,
                'total_rows' => (int) $imp->total_rows,
                'imported_count' => (int) $imp->imported_count,
                'duplicate_count' => (int) $imp->duplicate_count,
                'invalid_count' => (int) $imp->invalid_count,
                'created_by' => $imp->creator?->name ?? 'Admin',
            ]);

        // ═════════════════════════════════════════════════════════════════════
        // 6. INSTAGRAM CONTENT STATUS
        // ═════════════════════════════════════════════════════════════════════
        $instagramAccount = MarketingInstagramAccount::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->first();
        $instagramSummary = [
            'has_connected_account' => $instagramAccount?->status === 'connected',
            'pending_approval' => MarketingInstagramPost::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('status', 'pending_approval')->count(),
            'scheduled' => MarketingInstagramPost::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('status', 'scheduled')->count(),
            'publishing_today' => MarketingInstagramPost::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->whereIn('status', ['publishing', 'published'])->whereDate('published_at', today())->count(),
            'failed' => MarketingInstagramPost::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('status', 'failed')->count(),
        ];

        $nextScheduledPost = MarketingInstagramPost::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at', 'asc')
            ->first(['id', 'topic', 'scheduled_at', 'caption', 'image_url']);

        // ═════════════════════════════════════════════════════════════════════
        // RENDER COMMAND CENTER
        // ═════════════════════════════════════════════════════════════════════
        return Inertia::render('Company/Marketing/Overview/Index', [
            'operationalMetrics' => [
                'contacts' => [
                    'total' => $totalContacts,
                    'today_new' => $todayNewContacts,
                    'untouched' => $untouchedContacts,
                ],
                'campaigns' => [
                    'total_active' => $totalActiveCampaigns,
                    'sending' => $activeSendingCampaigns,
                    'scheduled' => $activeScheduledCampaigns,
                ],
                'messages_today' => [
                    'sent' => $totalMessagesSentToday,
                    'delivered' => $totalDeliveredToday,
                    'failed' => $totalFailedToday,
                ],
                'replies' => [
                    'awaiting_action' => $unreadConversationsCount,
                    'action_url' => '/company/marketing/conversations',
                ],
                'compliance' => [
                    'opted_out' => $optedOutContactsCount,
                    'suppressed' => $suppressedContactsCount,
                ],
            ],
            // Backward-compatible kpis object for any existing test assertions
            'kpis' => [
                'contacts' => [
                    'total' => $totalContacts,
                    'active' => $totalContacts - ($optedOutContactsCount + $suppressedContactsCount),
                ],
                'audiences' => [
                    'total' => $untouchedContacts,
                    'active' => $totalActiveCampaigns,
                ],
                'campaigns' => [
                    'active_sending' => $totalActiveCampaigns,
                    'draft' => 0,
                ],
                'whatsapp' => [
                    'connected_accounts' => $whatsappAccounts->where('status', 'connected')->count(),
                    'unread_conversations' => $unreadConversationsCount,
                ],
                'leads' => [
                    'marketing_generated' => 0,
                    'won' => 0,
                ],
                'safety' => [
                    'suppressed' => $suppressedContactsCount,
                    'opted_out' => $optedOutContactsCount,
                ],
            ],
            'todayActivity' => $todayActivity,
            'activeCampaigns' => $activeCampaigns,
            'needsAttention' => $needsAttention,
            'recentImports' => $recentImports,
            'instagramStatus' => [
                'summary' => $instagramSummary,
                'next_scheduled_post' => $nextScheduledPost ? [
                    'id' => $nextScheduledPost->id,
                    'topic' => $nextScheduledPost->topic ?: 'AI Marketing Post',
                    'caption' => Str::limit($nextScheduledPost->caption ?? '', 100),
                    'scheduled_at' => $nextScheduledPost->scheduled_at?->toIso8601String(),
                    'image_url' => $nextScheduledPost->image_url,
                ] : null,
            ],
            'safetyGovernance' => [
                'emergency_freeze_enabled' => (bool) $settings->emergency_freeze_enabled,
                'quiet_hours_enabled' => (bool) $settings->quiet_hours_enabled,
                'quiet_hours_start' => $settings->quiet_hours_start ?? '21:00',
                'quiet_hours_end' => $settings->quiet_hours_end ?? '09:00',
                'suppressed_contacts' => $suppressedContactsCount,
                'opted_out_contacts' => $optedOutContactsCount,
                'auto_suppress_on_failures' => (bool) $settings->auto_suppress_on_failures,
                'max_consecutive_failures' => (int) $settings->max_consecutive_failures,
            ],
        ]);
    }
}
