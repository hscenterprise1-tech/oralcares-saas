<?php

namespace App\Http\Controllers\Company\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\User;
use App\Services\Marketing\WhatsAppConversationService;
use App\Services\Marketing\WhatsAppLeadService;
use App\Services\Marketing\WhatsAppMessageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class MarketingWhatsAppConversationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected WhatsAppConversationService $conversationService,
        protected WhatsAppMessageService $messageService,
        protected WhatsAppLeadService $leadService
    ) {}

    /**
     * Scope a conversation query to what the current user is allowed to see:
     * Master Admins see the whole tenant, everyone else sees only conversations
     * assigned to them plus anything still unassigned (so new chats can be claimed).
     */
    /**
     * What this person may see.
     *
     * Assignment on the thread was the only rule, which left a gap: an
     * unclaimed conversation belonging to *someone else's* deal showed to every
     * rep in the organization. A thread is part of a deal, and a deal has an
     * owner, so ownership of the lead carries through to the conversation.
     *
     * Genuinely unclaimed threads — no assignee and no lead behind them — stay
     * visible to everyone on purpose. Somebody has to be able to pick them up,
     * and hiding them would mean an inbound message sits unread until an admin
     * happens to look.
     */
    protected function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->isMasterAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            // Mine, however it got to me.
            $q->where('assigned_user_id', $user->id)
                // Or unclaimed — but only if the deal behind it is mine, or
                // there is no deal yet.
                ->orWhere(function (Builder $unclaimed) use ($user) {
                    $unclaimed->whereNull('assigned_user_id')
                        ->where(function (Builder $lead) use ($user) {
                            $lead->whereDoesntHave('crmLead')
                                ->orWhereHas('crmLead', fn (Builder $l) => $l->where('assigned_user_id', $user->id));
                        });
                });
        });
    }

    /**
     * Display 2-Column Messaging Workspace (Left: Threads, Right: Messages Timeline & Reply Bar).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $search = $request->input('search');
        $selectedId = $request->input('conversation_id');
        $filter = $request->input('filter', 'all');

        /*
         * Which line to look at. With several numbers running, "show me only
         * the marketing conversations" is the difference between an inbox and a
         * pile — a support thread and a campaign reply are different jobs and
         * usually different people.
         */
        $purpose = $request->input('purpose');

        /*
         * Which number. This is the question people actually ask — "show me the
         * sales line" — and purpose alone could not answer it once two lines
         * share a purpose. Purpose filtering stays, because "every support
         * conversation across both support numbers" is a real question too.
         */
        $accountId = $request->input('account');

        // Fetch Conversations for tenant organization, scoped to what this user may see
        $query = MarketingWhatsAppConversation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
        $query = $this->visibleTo($query, $user);
        $query = $query
            ->with(['contact.tags', 'whatsappAccount', 'crmLead', 'assignedUser'])
            ->search($search);

        if ($accountId) {
            $query->where('whatsapp_account_id', $accountId);
        }

        if ($purpose) {
            $query->whereHas('whatsappAccount', fn ($q) => $q->where('purpose', $purpose));
        }

        if ($filter === 'unread') {
            $query->where('unread_count', '>', 0);
        } elseif ($filter === 'needs_reply') {
            $query->needsReply();
        }

        $query = $query->latest('last_message_at');

        $conversations = $query->paginate(20)->withQueryString();

        /*
         * Which campaign (if any) put this contact in front of this number —
         * one query for the whole page, not one per row. Scoped per
         * (contact, account) pair, because the same contact reachable
         * through two different numbers must never borrow the wrong one's
         * campaign history.
         */
        $campaignByContactAccount = $this->campaignContextForPage($conversations->getCollection());
        $conversations->getCollection()->transform(function (MarketingWhatsAppConversation $conversation) use ($campaignByContactAccount) {
            $conversation->setAttribute(
                'campaign_context',
                $campaignByContactAccount["{$conversation->contact_id}:{$conversation->whatsapp_account_id}"] ?? null
            );

            return $conversation;
        });

        // Selected Conversation Thread details
        $activeConversation = null;
        $messages = null;
        $activeLead = null;

        if ($selectedId) {
            $activeConversationQuery = MarketingWhatsAppConversation::query()
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
            $activeConversation = $this->visibleTo($activeConversationQuery, $user)
                ->with(['contact.tags', 'whatsappAccount', 'crmLead', 'assignedUser'])
                ->find($selectedId);
        }

        if (! $activeConversation && $conversations->count() > 0) {
            $activeConversation = $conversations->getCollection()->first();
        }

        /*
         * Computed before the auto-mark-read below runs, not after: opening
         * the inbox with no conversation picked auto-selects the top thread
         * and marks it read, and that thread is often the very row the
         * "Unread" tab's own count is about. Counting first keeps the tab's
         * number matching the rows it shows for this page load — counting
         * after would make the tab under-report itself on every visit.
         */
        $stats = $this->visibilityStats($orgId, $user);

        if ($activeConversation) {
            // Auto mark read on view
            if ($activeConversation->unread_count > 0) {
                $this->conversationService->markAsRead($activeConversation);
                $activeConversation->unread_count = 0;
            }

            $activeConversation->setAttribute(
                'campaign_context',
                $campaignByContactAccount["{$activeConversation->contact_id}:{$activeConversation->whatsapp_account_id}"]
                    ?? $this->campaignContextForPage(collect([$activeConversation]))["{$activeConversation->contact_id}:{$activeConversation->whatsapp_account_id}"]
                    ?? null
            );

            /*
             * Newest 50 first (page 1), oldest-first within that window for
             * natural reading order. "Load earlier" fetches the next page —
             * older messages, not lost, just not pulled into memory and the
             * browser until asked for.
             */
            $messages = $activeConversation->messages()
                ->orderBy('created_at', 'desc')
                ->paginate(50, ['*'], 'message_page');
            $messages->setCollection($messages->getCollection()->reverse()->values());

            // Resolve associated CRM Lead
            $activeLead = $this->leadService->findLeadForConversation($activeConversation);
        }

        return Inertia::render('Company/Marketing/Conversations/Index', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'messages' => $messages,
            'activeLead' => $activeLead,
            'currentUserId' => $user?->id,
            'isMasterAdmin' => $user?->isMasterAdmin() ?? false,
            'assignableUsers' => $user?->isMasterAdmin()
                ? User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name'])
                : [],
            'stats' => $stats,
            /*
             * Only the purposes this organization actually has a line for.
             * Offering "Support" as a filter when no support number exists
             * gives a person a control that can only ever return nothing.
             */
            /*
             * Every line this organization has, named the way a person would
             * name it, with what it is for alongside. Two numbers both doing
             * marketing are still two different numbers, so this lists lines
             * rather than purposes.
             */
            'lineOptions' => MarketingWhatsAppAccount::query()
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->orderBy('id')
                ->get()
                ->map(fn (MarketingWhatsAppAccount $account) => [
                    'value' => (string) $account->id,
                    'label' => $account->display_name,
                    'purpose' => $account->purpose?->value,
                    'purpose_label' => $account->purpose?->label(),
                ])
                ->values(),
            'filters' => [
                'search' => $search ?? '',
                'filter' => $filter,
                'purpose' => $purpose,
                'account' => $accountId,
                'conversation_id' => $activeConversation?->id ?? null,
            ],
        ]);
    }

    /**
     * Load an older page of one conversation's messages — the "load
     * earlier" action. Same shape as the initial page, just a later one.
     */
    public function messages(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $conversationQuery = MarketingWhatsAppConversation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
        $conversation = $this->visibleTo($conversationQuery, $user)->findOrFail($id);
        $this->authorize('view', $conversation);

        $messages = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->paginate(50, ['*'], 'message_page');
        $messages->setCollection($messages->getCollection()->reverse()->values());

        return response()->json($messages);
    }

    /**
     * The true unread/mine/unclaimed/needs-reply counts across every
     * conversation this user can see — not just the current page. A rep
     * trusting a number that silently means "the first 20" is worse than
     * not showing one.
     *
     * `unread` and `needs_reply` here use the exact same conditions as the
     * `index()` list filter (the `unread_count > 0` clause and the
     * `needsReply()` scope respectively), so a tab's count can never
     * disagree with what selecting that tab actually shows.
     */
    protected function visibilityStats(?int $orgId, User $user): array
    {
        $base = fn () => $this->visibleTo(
            MarketingWhatsAppConversation::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId)),
            $user
        );

        return [
            'total' => (int) $base()->count(),
            'unread' => (int) $base()->where('unread_count', '>', 0)->count(),
            'needs_reply' => (int) $base()->needsReply()->count(),
            'mine' => (int) $base()->where('assigned_user_id', $user->id)->count(),
            'unclaimed' => (int) $base()->whereNull('assigned_user_id')->count(),
        ];
    }

    /**
     * Campaign context for a batch of conversations, in one query.
     *
     * @param  Collection<int, MarketingWhatsAppConversation>  $conversations
     * @return array<string, array{id: int, name: string}>
     */
    protected function campaignContextForPage($conversations): array
    {
        $pairs = $conversations
            ->filter(fn ($c) => $c->contact_id && $c->whatsapp_account_id)
            ->map(fn ($c) => ['contact_id' => $c->contact_id, 'account_id' => $c->whatsapp_account_id])
            ->unique(fn ($p) => "{$p['contact_id']}:{$p['account_id']}");

        if ($pairs->isEmpty()) {
            return [];
        }

        $contactIds = $pairs->pluck('contact_id')->unique()->values();
        $accountIds = $pairs->pluck('account_id')->unique()->values();

        return MarketingCampaignRecipient::query()
            ->whereIn('contact_id', $contactIds)
            ->whereHas('campaign', fn ($q) => $q->whereIn('whatsapp_account_id', $accountIds))
            ->with('campaign:id,name,whatsapp_account_id')
            ->latest()
            ->get()
            ->filter(fn ($r) => $r->campaign)
            ->unique(fn ($r) => "{$r->contact_id}:{$r->campaign->whatsapp_account_id}")
            ->mapWithKeys(fn ($r) => ["{$r->contact_id}:{$r->campaign->whatsapp_account_id}" => [
                'id' => $r->campaign->id,
                'name' => $r->campaign->name,
            ]])
            ->all();
    }

    /**
     * Send single outbound reply in an active conversation thread.
     *
     * Opted-out and suppressed contacts are blocked here the same way
     * SendCampaignMessageJob blocks them for a campaign send — both read
     * MarketingContact::COMPLIANCE_STATUSES, the one place that compliance
     * rule lives. There is no separate check to keep in sync and no
     * override here: the only way past this is the existing suppression
     * workflow (Settings → Suppression), which this error links to.
     */
    public function reply(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $validated = $request->validate([
            'message' => 'required|string|max:4000',
        ]);

        $conversationQuery = MarketingWhatsAppConversation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
        $conversation = $this->visibleTo($conversationQuery, $user)
            ->with('contact')
            ->findOrFail($id);
        $this->authorize('reply', $conversation);

        $contact = $conversation->contact;
        if ($contact && in_array($contact->marketing_status, MarketingContact::COMPLIANCE_STATUSES, true)) {
            $reason = $contact->marketing_status === 'opted_out'
                ? 'this contact opted out'
                : 'this contact is suppressed';

            return redirect()->route('company.marketing.conversations.index', ['conversation_id' => $conversation->id])
                ->with('error', "Message not sent — {$reason}. Reverse this from the Suppression register if that's correct.");
        }

        $account = $conversation->whatsappAccount;
        if (! $account || $account->status !== 'connected') {
            return redirect()->back()->with('error', 'Connected WhatsApp account required to send replies.');
        }

        $result = $this->messageService->sendOutboundMessage($account, $conversation->phone, trim($validated['message']));

        if (! $result['success']) {
            return redirect()->back()->with('error', $result['error'] ?? 'Failed to send message.');
        }

        return redirect()->route('company.marketing.conversations.index', [
            'conversation_id' => $conversation->id,
        ])->with('success', 'Message sent successfully.');
    }

    /**
     * Mark active conversation as read.
     */
    public function markRead(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $conversationQuery = MarketingWhatsAppConversation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
        $conversation = $this->visibleTo($conversationQuery, $user)->findOrFail($id);
        $this->authorize('view', $conversation);

        $this->conversationService->markAsRead($conversation);

        return redirect()->back();
    }

    /**
     * Create CRM Lead directly from a WhatsApp Conversation.
     */
    public function createLead(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $conversationQuery = MarketingWhatsAppConversation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
        $conversation = $this->visibleTo($conversationQuery, $user)->findOrFail($id);
        $this->authorize('view', $conversation);

        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'status' => 'nullable|string',
            'priority' => 'nullable|string',
            'marketing_campaign_id' => 'nullable|exists:marketing_campaigns,id',
        ]);

        $lead = $this->leadService->createLeadFromConversation($conversation, $validated, $user);

        return redirect()->route('company.marketing.conversations.index', [
            'conversation_id' => $conversation->id,
        ])->with('success', "CRM Lead #{$lead->id} created successfully.");
    }

    /**
     * Link an existing CRM Lead to a WhatsApp Conversation.
     */
    public function linkLead(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $conversationQuery = MarketingWhatsAppConversation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
        $conversation = $this->visibleTo($conversationQuery, $user)->findOrFail($id);
        $this->authorize('view', $conversation);

        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
        ]);

        $lead = Lead::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($validated['lead_id']);

        $this->leadService->linkExistingLead($conversation, $lead, $user);

        return redirect()->route('company.marketing.conversations.index', [
            'conversation_id' => $conversation->id,
        ])->with('success', "WhatsApp conversation linked to Lead #{$lead->id}.");
    }

    /**
     * Assign (or claim, or unassign) a conversation to a rep.
     *
     * Master Admins can assign to any user in the tenant, or unassign.
     * Everyone else may only claim an unassigned/self-owned conversation for themselves.
     */
    public function assign(Request $request, int $id)
    {
        $user = $request->user();
        $orgId = $user?->organization_id;

        $conversationQuery = MarketingWhatsAppConversation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
        $conversation = $this->visibleTo($conversationQuery, $user)->findOrFail($id);
        $this->authorize('assign', $conversation);

        $validated = $request->validate([
            'assigned_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $targetUserId = $validated['assigned_user_id'] ?? null;

        if (! $user->isMasterAdmin() && $targetUserId !== null && $targetUserId !== $user->id) {
            abort(403, 'You can only assign this conversation to yourself.');
        }

        if ($targetUserId !== null && $orgId) {
            $targetBelongsToOrg = User::where('id', $targetUserId)->where('organization_id', $orgId)->exists();
            if (! $targetBelongsToOrg) {
                abort(422, 'Selected user does not belong to this organization.');
            }
        }

        $conversation->update(['assigned_user_id' => $targetUserId]);

        return redirect()->route('company.marketing.conversations.index', [
            'conversation_id' => $conversation->id,
        ])->with('success', $targetUserId ? 'Conversation assigned.' : 'Conversation unassigned.');
    }

    /**
     * Search CRM Leads for linking modal.
     */
    public function searchLeads(Request $request)
    {
        $term = $request->input('q', '');
        $user = $request->user();

        $results = $this->leadService->searchLeads($term, $user?->organization_id);

        return response()->json(['data' => $results]);
    }
}
