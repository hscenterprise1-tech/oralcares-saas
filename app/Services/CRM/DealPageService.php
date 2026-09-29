<?php

namespace App\Services\CRM;

use App\BillingCycle;
use App\ContactRole;
use App\HealthcareSpecialty;
use App\LostReason;
use App\Models\CommercialInvoice;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\Contact;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\OrganizationType;
use App\Services\CommercialLedgerService;
use App\Services\CrmEmailService;
use App\Support\CrmPermissions;
use App\Support\CrmScope;
use App\Support\DealDomainPresenter;
use App\UserType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The deal page's payload. One builder, one contract.
 *
 * There were two — `LeadWorkspaceService::getLeadDetailPayload()` and
 * `DealDetailService::payload()` — merged with `array_merge`, which meant any
 * key they disagreed about was silently resolved by ordering. They already
 * disagreed about contacts and about WhatsApp, and in both cases one of the
 * two was dead weight travelling over the wire on every request.
 *
 * Everything here is organised by the zone that renders it, and every zone
 * past the first is deferred. The page used to send its entire history, every
 * quotation, every message and six option lists on every request — including
 * after a save, because a section save is a full visit. Now the command bar
 * and the Now zone arrive with the document and the rest follows.
 *
 * Zones, matching the page:
 *
 *   immediate  identity, stage, value, owner, permissions
 *   now        next action, stage control, tasks
 *   commercial quotations, agreements, payments, the lifecycle, conversion
 *   evaluation demos and trials
 *   profile    organisation, location, branches, contacts, qualification
 *   history    the timeline
 *   files      attachments
 */
class DealPageService
{
    public function __construct(
        protected DealStageService $stages,
        protected DealDetailService $detail,
        protected DealCommercialService $commercial,
        protected DealConversationService $conversation,
    ) {}

    /**
     * What arrives with the document: enough to render the command bar and the
     * first zone, and nothing that needs a second query per row.
     *
     * @return array<string, mixed>
     */
    public function immediate(Lead $lead, ?User $user): array
    {
        $lead->loadMissing([
            'assignedUser:id,name,email',
            'assignedTeam:id,name',
            'selectedPlan:id,name,price,billing_period',
            // The estate, for the header's footprint summary and the branches panel.
            'branches',
        ]);

        $status = $this->value($lead->status);

        return [
            'deal' => [
                'id' => $lead->id,
                'company_name' => $lead->company_name,
                'contact_name' => $lead->contact_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'status' => $status,
                'status_label' => $lead->status?->label() ?? Str::headline((string) $status),
                'priority' => $this->value($lead->priority),
                'source' => $lead->source,
                'source_label' => Lead::sources()[$lead->source] ?? Str::headline((string) $lead->source),
                'city' => $lead->city,
                'state' => $lead->state,
                'postal_code' => $lead->postal_code,
                'area' => $lead->area,
                /*
                 * What they practise and what kind of place it is, resolved
                 * through the same presenter the Deals list uses — a card that
                 * says "Polyclinic — 8 doctors" and a page that says something
                 * else is the divergence this module keeps pulling apart.
                 */
                'specialties' => DealDomainPresenter::specialties($lead),
                'facility' => DealDomainPresenter::facility($lead),
                'branches' => DealDomainPresenter::branches($lead),
                'expected_mrr' => (float) ($lead->expected_mrr ?? 0),
                'expected_arr' => (float) ($lead->expected_arr ?: ($lead->expected_mrr ? $lead->expected_mrr * 12 : 0)),
                'probability_to_close' => (int) ($lead->probability_to_close ?? 0),
                'lead_score' => (int) ($lead->lead_score ?? $lead->automated_score ?? 0),
                'weighted_value' => round(
                    (float) ($lead->expected_arr ?: ($lead->expected_mrr ? $lead->expected_mrr * 12 : 0))
                    * ((int) ($lead->probability_to_close ?? 0) / 100)
                ),
                'assigned_user' => $lead->assignedUser?->only(['id', 'name', 'email']),
                'assigned_team' => $lead->assignedTeam?->only(['id', 'name']),
                'selected_plan' => $lead->selectedPlan?->only(['id', 'name', 'price', 'billing_period']),
                'stage_entered_at' => $lead->stage_entered_at?->toISOString(),
                'days_in_stage' => $lead->stage_entered_at
                    ? (int) $lead->stage_entered_at->diffInDays(now())
                    : null,
                'created_at' => $lead->created_at?->toISOString(),

                /* Why it was lost. Captured by the Lost form and displayed nowhere until now. */
                'lost_reason' => $this->value($lead->lost_reason),
                'lost_competitor' => $lead->lost_competitor,
                'lost_at' => $lead->lost_at?->format('d M Y'),
                'winback_scheduled_at' => $lead->winback_scheduled_at?->format('d M Y'),

                'customer_organization_id' => $lead->customer_organization_id,
            ],

            /*
             * What this person may do, decided on the server.
             *
             * The page had no idea: `canEdit` defaulted to true in the section
             * component, so a read-only user was shown six Edit buttons and
             * discovered the truth as a 403.
             */
            'can' => $this->permissions($lead, $user),
        ];
    }

    /**
     * People who can be named as a demo presenter.
     *
     * Scoped through `CrmScope::visibleUserIds()` like every other read in this
     * module, so a rep is offered their own team rather than the platform's
     * whole staff list. Clinic users are excluded: they belong to a customer,
     * not to the company presenting to them.
     *
     * @return list<array{id: int, name: string}>
     */
    public function assignableUsers(?User $user): array
    {
        $ids = CrmScope::visibleUserIds($user);

        return User::query()
            ->where('user_type', '!=', UserType::ClinicUser->value)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    public function permissions(Lead $lead, ?User $user): array
    {
        $update = $user !== null && Gate::forUser($user)->allows('update', $lead);

        return [
            'update' => $update,
            'convert' => $update && CrmPermissions::check($user, 'convert leads'),
            'approve_quotation' => $update && ($user?->can('crm.quotations.approve') ?? false),
            'approve_trial_extension' => $user !== null && ($user->can('crm.trials.approve-extension') ?? false),
            'delete' => $user !== null && Gate::forUser($user)->allows('delete', $lead),
        ];
    }

    /**
     * Zone 1 — what moves this deal next.
     *
     * @return array<string, mixed>
     */
    public function now(Lead $lead): array
    {
        return [
            'next_action' => $this->detail->nextAction($lead),
            'stage_control' => [
                'current' => $this->value($lead->status),
                'current_label' => $lead->status?->label(),
                'suggested' => DealStageService::NEXT_STAGE[$this->value($lead->status)] ?? null,
                'transitions' => $this->stages->availableTransitions($lead),
                'lost_reasons' => $this->lostReasons(),
            ],
            'tasks' => $lead->tasks()
                ->with('assignedUser:id,name')
                ->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
                ->orderBy('due_at')
                ->get()
                ->map(fn ($task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'type' => $this->value($task->type),
                    'priority' => Str::headline((string) $this->value($task->priority)),
                    'due_at' => $task->due_at?->format('d M Y, h:i A'),
                    'is_overdue' => $task->due_at
                        ? $task->due_at->isPast() && $this->value($task->status) !== 'completed'
                        : false,
                    'is_completed' => $this->value($task->status) === 'completed',
                    'assigned_to' => $task->assignedUser?->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Zone 2 — what it is worth and how close it is to closing.
     *
     * @return array<string, mixed>
     */
    public function commercial(Lead $lead): array
    {
        /*
         * The deal's quotations, loaded once and reconciled once — for the
         * whole zone, the lifecycle strip and the conversion gate together.
         *
         * This block used to run four times over the same rows. `lifecycle()`
         * loaded the quotations and reconciled them; this method loaded them
         * again (with `invoice`) and reconciled them again; the invoice lookup
         * below re-queried their ids with a third `pluck`; and `conversion()`
         * called `Lead::hasApprovedPaidQuotation()`, which is a fourth load and
         * a fourth reconciliation. Everything downstream now takes the set it
         * is given.
         *
         * `invoice` is eager-loaded because the row mapper reads
         * `$q->invoice?->invoice_number`, and a lazy `hasOne` there is one more
         * query per quotation.
         */
        $quotations = $lead->quotations()
            ->with(['invoice:id,company_quotation_id,invoice_number', 'preparedBy.user:id,name', 'companyPlan:id,name'])
            ->latest('id')
            ->get();
        $ledgerStates = app(CommercialLedgerService::class)->reconcileMany($quotations);

        $lifecycle = $this->commercial->lifecycle($lead, $quotations, $ledgerStates);

        /*
         * The two facts the conversion gate needs, read off the set already in
         * memory rather than asked for again.
         */
        $hasSettledQuotation = $quotations->contains(
            fn (CompanyQuotation $q) => $this->value($q->status) === 'approved'
                && ($ledgerStates[$q->id]['state'] ?? null) === CommercialLedgerService::PAID
        );

        $payments = $lead->payments()->latest('id')->get();
        $hasSuccessfulPayment = $payments->contains(fn ($p) => $p->status === 'successful');

        return [
            'lifecycle' => $lifecycle,

            /*
             * The packages a quotation can be raised against. They belong to
             * this zone because this is the only place on the page that offers
             * a choice of one — the page used to ship the list on every
             * request whether or not anybody was quoting.
             */
            'packages' => CompanyPlan::query()
                ->where('is_active', true)
                ->orderBy('price')
                ->get([
                    'id', 'name', 'price', 'billing_period',
                    /* The trial form prices and sizes a trial from these. */
                    'is_trial_available', 'trial_duration_days', 'max_doctors', 'max_users', 'max_branches',
                ])
                ->map(fn (CompanyPlan $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'price' => (float) $p->price,
                    'billing_period' => $p->billing_period,
                    'is_trial_available' => (bool) $p->is_trial_available,
                    'trial_duration_days' => (int) ($p->trial_duration_days ?? 0),
                    'max_doctors' => (int) ($p->max_doctors ?? 0),
                    'max_users' => (int) ($p->max_users ?? 0),
                    'max_branches' => (int) ($p->max_branches ?? 0),
                ])
                ->values()
                ->all(),
            'conversion' => $this->detail->conversion(
                $lead,
                $hasSettledQuotation || ($lead->hasAcceptedAgreement() && $hasSuccessfulPayment),
                $hasSuccessfulPayment
            ),
            'quotations' => $quotations
                ->map(fn (CompanyQuotation $q) => [
                    'id' => $q->id,
                    'quotation_number' => $q->quotation_number,
                    'version' => (int) ($q->version ?? 1),
                    'status' => $this->value($q->status),
                    'is_active' => $q->isActive(),
                    'plan_name' => $q->companyPlan?->name,
                    'subtotal' => (float) ($q->subtotal ?? 0),
                    'discount' => (float) ($q->discount ?? 0),
                    'grand_total' => (float) ($q->grand_total ?? 0),
                    'billing_cycle' => $this->value($q->billing_cycle) ?: 'monthly',
                    'billing_cycle_label' => ($q->billing_cycle ?? BillingCycle::Monthly)->shortLabel(),
                    'normalized_arr' => $q->normalizedArr(),
                    'valid_until' => $q->valid_until?->format('d M Y'),
                    'sent_at' => $q->sent_at?->format('d M Y'),
                    'approved_at' => $q->approved_at?->format('d M Y'),
                    'rejection_reason' => $q->rejection_reason,
                    'created_at' => $q->created_at?->format('d M Y'),
                    'prepared_by_name' => $q->preparedBy?->user?->name,
                    /*
                     * Settlement, not an agreement. `is_settled` is what decides
                     * whether this quotation still takes money and whether its
                     * terms are fixed.
                     */
                    'is_settled' => ($ledgerStates[$q->id]['state'] ?? null) === CommercialLedgerService::PAID,
                    'invoice_number' => $q->invoice?->invoice_number,
                ])
                ->values()
                ->all(),
            /*
             * Invoices, not agreements. An invoice is raised from a settled
             * quotation; the agreement documents that used to sit here were a
             * step this product never actually performed.
             *
             * Historical agreements are deliberately not listed. They exist and
             * are still readable, but offering them beside live invoices would
             * present a retired document type as part of the current flow.
             */
            'invoices' => CommercialInvoice::query()
                /* The ids are already here — a `pluck` would be a third trip. */
                ->whereIn('company_quotation_id', $quotations->pluck('id'))
                ->latest('id')
                ->get()
                ->map(fn (CommercialInvoice $i) => [
                    'id' => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'status' => $i->status,
                    'final_amount' => (float) $i->final_amount,
                    'issued_at' => $i->issued_at?->format('d M Y'),
                    'paid_at' => $i->paid_at?->format('d M Y'),
                ])
                ->values()
                ->all(),
            'payments' => $payments
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => (float) $p->amount,
                    'currency' => $p->currency,
                    'status' => $p->status,
                    'gateway' => $p->gateway,
                    'transaction_id' => $p->transaction_id,
                    'recorded_at' => $p->created_at?->format('d M Y'),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Zone 3 — demos and trials, as one evaluation record.
     *
     * @return array<string, mixed>
     */
    public function evaluation(Lead $lead): array
    {
        return [
            'demos' => $lead->demos()
                ->with('presenter:id,name')
                ->latest('scheduled_at')
                ->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'scheduled_at' => $d->scheduled_at?->format('d M Y, h:i A'),
                    /* The raw instant, for a client-side countdown — the formatted string above is for reading, not for arithmetic. */
                    'scheduled_at_iso' => $d->scheduled_at?->toIso8601String(),
                    'status' => $this->value($d->status),
                    /*
                     * `demo_mode`, not `demo_method` — there has never been a
                     * `demo_method` column, so this key fell through to its
                     * default and every demo on the page claimed to be online,
                     * including the ones held at the practice.
                     */
                    'demo_mode' => $d->demo_mode ?? 'online',
                    'meeting_platform' => $d->meeting_platform,
                    'meeting_platform_label' => Demo::PLATFORMS[$d->meeting_platform] ?? null,
                    'meeting_link' => $d->meeting_link,
                    'meeting_id' => $d->meeting_id,
                    'passcode' => $d->passcode,
                    'location' => $d->location,
                    /*
                     * What the customer has actually been told, so the rep can
                     * see at a glance whether a reminder went out rather than
                     * asking and finding out on the call.
                     */
                    'reminders' => [
                        'rep_notified' => $d->rep_notified_at !== null,
                        'customer_24h' => $d->customer_reminder_24h_at !== null,
                        'customer_2h' => $d->customer_reminder_2h_at !== null,
                    ],
                    'presenter_name' => $d->presenter?->name,
                    'notes' => $d->notes,
                    'outcome' => $this->value($d->outcome),
                ])
                ->values()
                ->all(),
            'trials' => $lead->trials()
                /*
                 * The package comes from `company_plans`, never from the free
                 * text on the trial — the tier the deal page shows is the tier
                 * the catalogue defines, which is the whole point of holding
                 * packages centrally.
                 */
                ->with([
                    'companyPlan:id,key,name',
                    'clinic:id,slug',
                    'lead:id,email',
                    'pendingExtensionRequest.requestedBy:id,name',
                ])
                ->latest('id')
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'status' => $this->value($t->status),
                    'package' => $t->companyPlan ? [
                        'id' => $t->companyPlan->id,
                        'key' => $t->companyPlan->key,
                        'name' => $t->companyPlan->name,
                    ] : null,
                    /*
                     * Live usage, so the deal page answers "are they actually
                     * using it" without sending the reader to the Trials board.
                     * Null means not measured — never render it as zeros.
                     */
                    'telemetry' => $t->usage_telemetry ?: null,
                    'extension_count' => (int) $t->extension_count,
                    'lost' => $t->lost_at ? [
                        'at' => $t->lost_at->toDateString(),
                        'reason' => $t->lost_reason,
                        'competitor' => $t->lost_competitor,
                    ] : null,
                    'plan' => $t->plan,
                    'start_date' => $t->start_date?->format('d M Y'),
                    'end_date' => $t->end_date?->format('d M Y'),
                    'days_remaining' => $t->end_date ? max(0, (int) now()->diffInDays($t->end_date, false)) : null,
                    'is_ending_soon' => $t->end_date ? now()->diffInDays($t->end_date, false) <= 3 : false,
                    'notes' => $t->notes,
                    /*
                     * The provisioned tenant, once one exists. `tenant_url` is
                     * display-only — `clinics.slug` is a database-unique
                     * string, not a claim that DNS resolves it, so this is
                     * never rendered as a link. The admin identity is the
                     * lead's own email, which is exactly what
                     * `CustomerProvisioningEngine` keys the clinic's primary
                     * admin user against. No password is ever surfaced here:
                     * `CustomerProvisioningEngine` generates a random one and
                     * hands it over through `AdminHandoverService`, and it is
                     * never persisted anywhere this payload could read it
                     * back from — only whether that handover went out.
                     */
                    'provisioning' => $t->clinic ? [
                        'clinic_slug' => $t->clinic->slug,
                        'tenant_url' => $t->clinic->slug.'.'.config('crm.tenant_base_domain'),
                        'admin_email' => $t->lead?->email,
                        'credentials_sent' => $t->provisioned_at !== null,
                        'provisioned_at' => $t->provisioned_at?->format('d M Y, h:i A'),
                    ] : null,
                    /*
                     * At most one pending request per trial — a second ask
                     * before the first is decided would just be a duplicate
                     * banner saying the same thing twice.
                     */
                    'pending_extension_request' => $t->pendingExtensionRequest ? [
                        'id' => $t->pendingExtensionRequest->id,
                        'additional_days' => $t->pendingExtensionRequest->additional_days,
                        'reason' => $t->pendingExtensionRequest->reason,
                        'requested_by' => $t->pendingExtensionRequest->requestedBy?->name,
                    ] : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Zone 6 — everything the create form captured.
     *
     * This is the whole reference record, in one place, so the profile editor
     * can be one form rather than six inline editors that each resend the
     * company name to satisfy a validation rule none of them needed.
     *
     * @return array<string, mixed>
     */
    public function profile(Lead $lead): array
    {
        $lead->loadMissing(['branches', 'marketingContact:id,name,phone', 'marketingCampaign:id,name']);

        return [
            'organisation' => [
                'company_name' => $lead->company_name,
                'organization_type' => $this->value($lead->organization_type) ?: null,
                'healthcare_specialties' => $lead->healthcare_specialties ?? [],
                'branch_count' => $lead->branch_count !== null ? (int) $lead->branch_count : null,
                'organization_size' => $lead->organization_size,
            ],
            'location' => [
                'address' => $lead->address,
                'area' => $lead->area,
                'city' => $lead->city,
                'state' => $lead->state,
                'country' => $lead->country,
                'postal_code' => $lead->postal_code,
                'has_complete_address' => $lead->has_complete_address,
            ],
            'branches' => $lead->branches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'is_main' => (bool) $b->is_main,
                'address' => $b->address,
                'area' => $b->area,
                'city' => $b->city,
                'state' => $b->state,
                'country' => $b->country,
                'postal_code' => $b->postal_code,
                'contact_name' => $b->contact_name,
                'phone' => $b->phone,
                'email' => $b->email,
            ])->values()->all(),
            'contacts' => Contact::query()
                ->where('lead_id', $lead->id)
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
                    'whatsapp' => $c->whatsapp,
                    'job_title' => $c->job_title,
                    'decision_role' => $this->value($c->decision_role),
                    'is_primary' => (bool) $c->is_primary,
                ])
                ->values()
                ->all(),
            'qualification' => [
                'current_software' => $lead->current_software,
                'migration_required' => (bool) $lead->migration_required,
                'competitors' => $lead->competitors,
                'deployment_preference' => $lead->deployment_preference,
                'expected_go_live_date' => $lead->expected_go_live_date?->format('Y-m-d'),
                'expected_users' => $lead->expected_users !== null ? (int) $lead->expected_users : null,
                'required_modules' => $lead->required_modules ?? [],
                'required_integrations' => $lead->required_integrations ?? [],
                'product_interest' => $lead->product_interest ?? [],
            ],
            'commercial' => [
                'assigned_user_id' => $lead->assigned_user_id,
                'assigned_team_id' => $lead->assigned_team_id,
                'source' => $lead->source,
                'selected_plan_id' => $lead->selected_plan_id,
                'expected_mrr' => $lead->expected_mrr !== null ? (float) $lead->expected_mrr : null,
                'expected_arr' => $lead->expected_arr !== null ? (float) $lead->expected_arr : null,
                'probability_to_close' => $lead->probability_to_close !== null ? (int) $lead->probability_to_close : null,
                'priority' => $this->value($lead->priority),
                'notes' => $lead->followup_notes,
            ],
            /* Read-only. A record somebody can rewrite is not a record. */
            'attribution' => [
                'marketing_contact' => $lead->marketingContact?->only(['id', 'name', 'phone']),
                'marketing_campaign' => $lead->marketingCampaign?->only(['id', 'name']),
                'created_at' => $lead->created_at?->format('d M Y, h:i A'),
                'qualified_at' => $lead->qualified_at?->format('d M Y'),
            ],
        ];
    }

    /**
     * The option lists the profile editor needs. Only sent with the profile
     * zone — the page itself has no use for them.
     *
     * @return array<string, mixed>
     */
    public function profileOptions(?User $user): array
    {
        return [
            'lead_sources' => Lead::sources(),
            'organization_types' => $this->organizationTypes(),
            'specialties' => HealthcareSpecialty::options(),

            /*
             * From the enum, not from a map written beside the form.
             * `ContactRole` stores display strings — "Medical Director", not
             * `medical_director` — so a hand-written snake_case list matched
             * none of them and the field rendered empty on every deal that had
             * one set.
             */
            'decision_roles' => collect(ContactRole::cases())
                ->mapWithKeys(fn ($role) => [$role->value => $role->value])
                ->all(),
            'assignable_users' => User::query()
                ->when($user && ! $user->isMasterAdmin(), fn ($q) => $q->where('organization_id', $user->resolveOrganizationId()))
                ->where('user_type', '!=', UserType::ClinicUser)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->values()
                ->all(),
            'assignable_teams' => Team::query()
                ->when($user?->resolveOrganizationId(), fn ($q, $id) => $q->where('organization_id', $id))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Team $t) => ['id' => $t->id, 'name' => $t->name])
                ->values()
                ->all(),
            'packages' => CompanyPlan::query()
                ->where('is_active', true)
                ->orderBy('price')
                ->get([
                    'id', 'name', 'price', 'billing_period',
                    /* The trial form prices and sizes a trial from these. */
                    'is_trial_available', 'trial_duration_days', 'max_doctors', 'max_users', 'max_branches',
                ])
                ->map(fn (CompanyPlan $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'price' => (float) $p->price,
                    'billing_period' => $p->billing_period,
                    'is_trial_available' => (bool) $p->is_trial_available,
                    'trial_duration_days' => (int) ($p->trial_duration_days ?? 0),
                    'max_doctors' => (int) ($p->max_doctors ?? 0),
                    'max_users' => (int) ($p->max_users ?? 0),
                    'max_branches' => (int) ($p->max_branches ?? 0),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Zone 4 — everything that was said, in one thread.
     *
     * @return array<string, mixed>
     */
    public function conversation(Lead $lead): array
    {
        $emails = app(CrmEmailService::class);

        return [
            'whatsapp' => $this->detail->whatsApp($lead),
            'email' => array_merge($emails->sendability($lead), [
                'default_to' => $lead->email,
                'threads' => $emails->history($lead),
            ]),
            'calls' => $lead->callLogs()
                ->with('user:id,name')
                ->latest('id')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'duration_seconds' => (int) ($c->call_duration_seconds ?? 0),
                    'disposition' => $c->disposition,
                    'notes' => $c->notes,
                    'user_name' => $c->user?->name,
                    'at' => $c->created_at?->toISOString(),
                    'at_label' => $c->created_at?->format('d M Y, h:i A'),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * How this deal can be reached, for the activity feed's composer.
     *
     * This is what survives of the conversation zone. That zone rendered a
     * WhatsApp thread, an email list and a call log as three separate panels
     * beside a fourth panel — the history — that already held every one of
     * those events in one chronological order. The stream stayed; what was
     * needed from the panels is three facts about how to reach somebody.
     *
     * `wa_number` is composed here rather than in the page: `wa.me` takes an
     * international number with no punctuation, and an Indian ten-digit number
     * needs the country code that this book's numbers are stored without.
     *
     * @return array<string, mixed>
     */
    public function outreach(Lead $lead): array
    {
        $digits = preg_replace('/\D/', '', (string) $lead->phone);

        return [
            'phone' => $lead->phone,
            'wa_number' => match (true) {
                strlen((string) $digits) === 10 => '91'.$digits,
                strlen((string) $digits) >= 11 => $digits,
                default => null,
            },
            'email' => array_merge(
                app(CrmEmailService::class)->sendability($lead),
                ['default_to' => $lead->email]
            ),
        ];
    }

    /**
     * Zone 5 — the whole history, paginated.
     *
     * The timeline was unbounded: every activity on the deal, fetched with no
     * limit, on every request and after every save.
     *
     * @return array<string, mixed>
     */
    public function history(Lead $lead, int $perPage = 25, int $page = 1): array
    {
        $query = $lead->activities()->with('user:id,name')->latest('id');
        $total = (clone $query)->count();

        $events = $query
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'type' => $this->value($a->type),
                'type_label' => DealDetailService::label((string) $this->value($a->type)),
                'description' => $a->description,
                'actor' => $a->user?->name ?? 'System',
                'at' => $a->created_at?->toISOString(),
                'at_label' => $a->created_at?->diffForHumans(),
                'properties' => $a->properties,
            ])
            ->values()
            ->all();

        return [
            'events' => $events,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $total > $page * $perPage,
            /* `reorder()` because the relation carries an ordering MySQL will not accept beside DISTINCT. */
            'kinds' => collect($lead->activities()->reorder()->distinct()->pluck('type'))
                ->map(fn ($t) => [
                    'value' => (string) $this->value($t),
                    'label' => DealDetailService::label((string) $this->value($t)),
                ])
                ->values()
                ->all(),
        ];
    }

    /** Zone 7 — attachments. */
    public function files(Lead $lead): array
    {
        return $this->detail->files($lead);
    }

    /** @return list<array{value: string, label: string}> */
    protected function lostReasons(): array
    {
        return collect(LostReason::cases())
            ->map(fn ($r) => ['value' => $r->value, 'label' => Str::headline($r->value)])
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    protected function organizationTypes(): array
    {
        return collect(OrganizationType::cases())
            ->mapWithKeys(fn ($t) => [$t->value => Str::headline($t->value)])
            ->all();
    }

    /** Enums reach the page as their values, once, under one name. */
    protected function value(mixed $enum): ?string
    {
        if ($enum === null) {
            return null;
        }

        return $enum instanceof \BackedEnum ? (string) $enum->value : (string) $enum;
    }
}
