<?php

namespace App\Services\CRM;

use App\LeadStatus;
use App\Models\CommercialInvoice;
use App\Models\Contact;
use App\Models\CrmDocument;
use App\Models\Lead;
use App\Models\Task;
use App\TaskStatus;
use Illuminate\Support\Collection;

/**
 * The sections the rebuilt Deal Detail adds.
 *
 * Deliberately separate from LeadWorkspaceService::getLeadDetailPayload(),
 * which is already 4,000 lines of shared surface. Nothing here duplicates it:
 * this composes the next-action block, the real contacts, invoices, files
 * and the classified timeline — none of which the old payload carried.
 */
class DealDetailService
{
    /**
     * Timeline events a rep needs to understand the deal.
     *
     * Everything else still exists and is still queryable; it is folded into a
     * collapsed "system" group rather than deleted, so history is intact but
     * `system_update` rows — 42 of 77 activities in the live database — stop
     * burying the calls and notes.
     *
     * @var list<string>
     */
    public const SIGNIFICANT_TYPES = [
        'call', 'whatsapp', 'email', 'meeting', 'demo', 'trial',
        'follow_up', 'note', 'document', 'quotation', 'agreement', 'provisioning',

        /*
         * What somebody changed on the deal. These used to be written as
         * `system_update` and folded away with the machine noise, so a rep
         * correcting a price left a record nobody would find. They now carry a
         * field-level diff and read as what they are: a person's edit.
         */
        'record_created', 'record_updated', 'record_deleted',
    ];

    /**
     * System events that are still worth reading, despite their type.
     *
     * A stage change and an ownership change are the spine of a deal's
     * history; they happen to be written as `system_update`.
     *
     * @var list<string>
     */
    public const SIGNIFICANT_SYSTEM_PATTERNS = ['Stage changed', 'assigned to', 'reassigned to', 'SLA breach', 'escalated'];

    public function __construct(protected DealStageService $stages) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(Lead $lead): array
    {
        return [
            'next_action' => $this->nextAction($lead),
            'deal_contacts' => $this->contacts($lead),
            'invoices' => $this->invoices($lead),
            'files' => $this->files($lead),
            'deal_timeline' => $this->timeline($lead),
            'conversion' => $this->conversion($lead),
            'whatsapp' => $this->whatsApp($lead),
        ];
    }

    /**
     * The actual WhatsApp conversation with this person.
     *
     * Not a list of "a WhatsApp message was sent" rows — the messages
     * themselves, in order, the way they were said. A rep opening a deal wants
     * to know what was actually discussed, and that lived only in the Marketing
     * inbox, on a different page, under a different module.
     *
     * The link already existed (`leads.whatsAppConversation`); nothing had ever
     * read it from here.
     *
     * @return array{
     *     conversation_id: ?int,
     *     phone: ?string,
     *     unread: int,
     *     inbox_url: ?string,
     *     messages: array<int, array{id:int, direction:string, body:?string, status:?string, at:?string, at_label:string}>
     * }
     */
    public function whatsApp(Lead $lead): array
    {
        $conversation = $lead->whatsAppConversation()->with(['messages' => fn ($q) => $q->latest()->limit(30)])->first();

        if (! $conversation) {
            return [
                'conversation_id' => null,
                'phone' => $lead->phone,
                'unread' => 0,
                'inbox_url' => null,
                'messages' => [],
            ];
        }

        return [
            'conversation_id' => $conversation->id,
            'phone' => $conversation->phone,
            'unread' => (int) ($conversation->unread_count ?? 0),
            'inbox_url' => "/company/marketing/conversations?conversation_id={$conversation->id}",
            /*
             * Oldest first, because a conversation is read downward. The query
             * takes the newest 30 so a long history does not have to be loaded
             * to show the recent part of it.
             */
            'messages' => $conversation->messages
                ->sortBy('created_at')
                ->values()
                ->map(fn ($message) => [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'body' => $message->body,
                    'status' => $message->status,
                    'at' => $message->created_at?->toISOString(),
                    'at_label' => $message->created_at?->diffForHumans() ?? '',
                ])
                ->all(),
        ];
    }

    /**
     * Whether this deal can become a customer, and what is missing if not.
     *
     * The endpoint has always enforced two conditions — an accepted agreement
     * and a settled payment — by refusing the request and flashing an error.
     * That tells somebody they were wrong only after they have acted, and does
     * not say what to do about it.
     *
     * Sending the conditions themselves lets the page answer the question
     * before it is asked: what is left before this deal closes. The server
     * still enforces them; this only stops the interface being a guess.
     *
     * @return array{
     *     is_converted: bool,
     *     customer_organization_id: ?int,
     *     can_convert: bool,
     *     requirements: array<int, array{key: string, label: string, met: bool, hint: string}>
     * }
     */
    public function conversion(Lead $lead, ?bool $hasSettledQuotation = null, ?bool $hasPayment = null): array
    {
        /*
         * Same gate DealStageService uses for Won: any approved quotation
         * settled in full, or — for deals closed under the old flow — an
         * accepted agreement plus a settled payment.
         *
         * Both facts can be handed in. The deal page has already loaded and
         * reconciled this deal's quotations and payments by the time it asks;
         * recomputing them here meant a third reconciliation and a second
         * payments query for answers already sitting in memory.
         */
        $hasPayment ??= $lead->hasSuccessfulPayment();

        $hasApprovedSettledQuotation = $hasSettledQuotation
            ?? ($lead->hasApprovedPaidQuotation()
                || ($lead->hasAcceptedAgreement() && $hasPayment));

        /*
         * Only worth showing once closing is actually in view.
         *
         * A deal at Demo Scheduled was being told it had "2 things left" before
         * the demo had even happened, which reads as an instruction rather than
         * a status — and a checklist you cannot act on yet is noise on the
         * busiest page in the product. From Negotiation onward it is the next
         * real thing; before that it is a distraction.
         */
        $status = $lead->status instanceof LeadStatus ? $lead->status : LeadStatus::tryFrom((string) $lead->status);

        $closing = in_array($status, [
            LeadStatus::Negotiation,
            LeadStatus::QuotationSent,
            LeadStatus::QuotationApproved,
            LeadStatus::PaymentReceived,
            LeadStatus::Won,
        ], true);

        $requirements = [
            [
                'key' => 'quotation_settled',
                'label' => 'Quotation approved & settled',
                'met' => $hasApprovedSettledQuotation,
                'hint' => 'Approve a quotation and record payment against it in Commercial.',
            ],
            [
                'key' => 'payment',
                'label' => 'Payment settled',
                'met' => $hasPayment,
                'hint' => 'Record the first payment against this deal.',
            ],
        ];

        return [
            'is_converted' => $lead->customer_organization_id !== null,
            'customer_organization_id' => $lead->customer_organization_id,
            'can_convert' => $hasApprovedSettledQuotation && $hasPayment && $lead->customer_organization_id === null,
            'requirements' => $requirements,
            /*
             * A converted deal always shows its account link, whatever stage
             * the row happens to carry — the fact outlives the pipeline.
             */
            'is_relevant' => $closing || $lead->customer_organization_id !== null,
        ];
    }

    /**
     * The one next-action block.
     *
     * Derived from the task table — the Phase 1 source of truth — never from
     * `leads.next_followup_at` directly, so the page cannot show a date the
     * tasks disagree with.
     *
     * @return array<string, mixed>
     */
    public function nextAction(Lead $lead): array
    {
        $task = Task::query()
            ->where('taskable_type', $lead->getMorphClass())
            ->where('taskable_id', $lead->id)
            ->where('status', TaskStatus::Pending->value)
            ->whereIn('type', array_map(fn ($t) => $t->value, LeadFollowupSyncService::CONTACT_TYPES))
            ->with('assignedUser:id,name')
            ->orderBy('due_at')
            ->first();

        if (! $task) {
            return [
                'has_action' => false,
                'recommendation' => $lead->next_best_action,
            ];
        }

        return [
            'has_action' => true,
            'task_id' => $task->id,
            'title' => $task->title,
            'type' => $task->type instanceof \BackedEnum ? $task->type->value : (string) $task->type,
            'due_at' => $task->due_at?->toISOString(),
            'due_label' => $task->due_at?->format('d M Y, h:i A'),
            'is_overdue' => $task->due_at ? $task->due_at->isPast() : false,
            'owner' => $task->assignedUser?->name,
            'owner_id' => $task->assigned_user_id,
            'recommendation' => $lead->next_best_action,
        ];
    }

    /**
     * The deal's real contacts.
     *
     * The old payload synthesised a single row from the lead's own
     * contact_name/phone/email, so genuine Contact records never appeared and
     * the tab could not be used to manage anyone.
     *
     * @return list<array<string, mixed>>
     */
    public function contacts(Lead $lead): array
    {
        return Contact::where('lead_id', $lead->id)
            ->orderByDesc('is_primary')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Contact $c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'name' => trim($c->first_name.' '.$c->last_name),
                'email' => $c->email,
                'phone' => $c->phone,
                /* The number you can actually message — often not the mobile. */
                'whatsapp' => $c->whatsapp,
                'job_title' => $c->job_title,
                'decision_role' => $c->decision_role instanceof \BackedEnum ? $c->decision_role->value : $c->decision_role,
                'is_primary' => (bool) $c->is_primary,
            ])
            ->values()
            ->all();
    }

    /**
     * Invoices raised on this deal.
     *
     * An invoice is raised automatically once an approved quotation settles
     * in full — there is no drafting or accepting step. Historical agreement
     * documents are deliberately not listed here; they still exist and are
     * still readable (`Lead::hasAcceptedAgreement()`), but this deal has no
     * new-agreement path left to surface.
     *
     * @return array<string, mixed>
     */
    public function invoices(Lead $lead): array
    {
        $invoices = CommercialInvoice::query()
            ->whereIn('company_quotation_id', $lead->quotations()->pluck('id'))
            ->latest('id')
            ->get();

        return [
            'items' => $invoices->map(fn (CommercialInvoice $i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'status' => $i->status,
                'final_amount' => (float) $i->final_amount,
                'issued_at' => $i->issued_at?->format('d M Y'),
                'paid_at' => $i->paid_at?->format('d M Y'),
            ])->values()->all(),
            'has_invoice' => $invoices->isNotEmpty(),
        ];
    }

    /**
     * Files attached to the deal.
     *
     * `crm_documents` and its store/download/destroy routes already exist and
     * work; they simply had no surface.
     *
     * @return list<array<string, mixed>>
     */
    public function files(Lead $lead): array
    {
        return CrmDocument::where('documentable_type', $lead->getMorphClass())
            ->where('documentable_id', $lead->id)
            ->latest()
            ->get()
            ->map(fn (CrmDocument $d) => [
                'id' => $d->id,
                'name' => $d->file_name,
                'size' => $d->file_size_bytes,
                'uploaded_at' => $d->created_at?->format('d M Y'),
                'download_url' => route('company.crm.leads.documents.download', [$lead, $d], absolute: false),
            ])
            ->values()
            ->all();
    }

    /**
     * The timeline, split into what matters and what merely happened.
     *
     * @return array<string, mixed>
     */
    public function timeline(Lead $lead): array
    {
        $activities = $lead->activities()->with('user:id,name')->get();

        [$significant, $system] = $activities->partition(fn ($a) => $this->isSignificant($a));

        return [
            'events' => $this->mapEvents($significant),
            'system_events' => $this->mapEvents($system),
            'system_count' => $system->count(),
        ];
    }

    protected function isSignificant(object $activity): bool
    {
        $type = $activity->type instanceof \BackedEnum ? $activity->type->value : (string) $activity->type;

        if (in_array($type, self::SIGNIFICANT_TYPES, true)) {
            return true;
        }

        foreach (self::SIGNIFICANT_SYSTEM_PATTERNS as $pattern) {
            if (str_contains((string) $activity->description, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function mapEvents(Collection $activities): array
    {
        return $activities
            ->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type instanceof \BackedEnum ? $a->type->value : (string) $a->type,
                'description' => $a->description,
                'actor' => $a->user?->name ?? 'System',
                'at' => $a->created_at?->toISOString(),
                'at_label' => $a->created_at?->diffForHumans(),
                'properties' => $a->properties,
            ])
            ->values()
            ->all();
    }

    /**
     * Human labels for the activity types the timeline renders.
     */
    public static function label(string $type): string
    {
        return match ($type) {
            'call' => 'Call',
            'whatsapp' => 'WhatsApp',
            'email' => 'Email',
            'note' => 'Note',
            'demo' => 'Demo',
            'trial' => 'Trial',
            'quotation' => 'Quotation',
            'agreement' => 'Agreement',
            'follow_up' => 'Follow-up',
            'document' => 'Document',
            'provisioning' => 'Provisioning',
            'system_update' => 'System',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
