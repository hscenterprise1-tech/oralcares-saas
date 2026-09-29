<?php

use App\ActivityType;
use App\LeadStatus;
use App\Models\CommercialDocument;
use App\Models\CompanyQuotation;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadEmailThread;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CRM\DealDetailService;
use App\Services\CRM\DealPageService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Phase 2 — the Deal Detail as the one operational surface.
 *
 * Follow-up completion, notes, email, agreements, contacts and files all had
 * working backends and no UI. These pin the payload and the endpoints the
 * rebuilt page is built on.
 */
function workspaceAdmin(): User
{
    return User::factory()->create(['user_type' => UserType::MasterAdmin]);
}

function workspaceDeal(array $attributes = []): Lead
{
    return Lead::factory()->create(array_merge([
        'organization_id' => Organization::factory(),
        'status' => LeadStatus::Contacted,
        'company_name' => 'Workspace Dental',
        'contact_name' => 'Primary Buyer',
        'email' => 'buyer@workspace.test',
        'phone' => '+919876500321',
    ], $attributes));
}

function pendingFollowup(Lead $lead, $dueAt, ?User $owner = null): Task
{
    return Task::create([
        'title' => 'Call the practice manager',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::Medium,
        'due_at' => $dueAt,
        'assigned_user_id' => $owner?->id ?? $lead->assigned_user_id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Next action
// ─────────────────────────────────────────────────────────────────────────────

it('surfaces the next action from the task table, not the cached column', function () {
    $owner = User::factory()->create(['user_type' => UserType::CompanyUser]);
    $lead = workspaceDeal(['assigned_user_id' => $owner->id]);
    pendingFollowup($lead, now()->addDays(2), $owner);

    $next = app(DealDetailService::class)->nextAction($lead->fresh());

    expect($next['has_action'])->toBeTrue()
        ->and($next['title'])->toBe('Call the practice manager')
        ->and($next['owner'])->toBe($owner->name)
        ->and($next['is_overdue'])->toBeFalse();
});

it('flags an overdue next action', function () {
    $lead = workspaceDeal();
    pendingFollowup($lead, now()->subDays(3));

    expect(app(DealDetailService::class)->nextAction($lead->fresh())['is_overdue'])->toBeTrue();
});

it('reports no action when nothing is scheduled', function () {
    $next = app(DealDetailService::class)->nextAction(workspaceDeal());

    expect($next['has_action'])->toBeFalse();
});

it('completes a follow-up from the deal and keeps the task in sync', function () {
    $lead = workspaceDeal();
    $task = pendingFollowup($lead, now()->addDay());

    $this->actingAs(workspaceAdmin())
        ->post(route('company.crm.leads.followup.complete', $lead), [
            'notes' => 'Spoke to the owner.',
            'next_followup_at' => now()->addDays(7)->toDateTimeString(),
            'next_followup_notes' => 'Send the quote',
        ])
        ->assertRedirect();

    $lead->refresh();

    expect($task->fresh()->status->value)->toBe('completed')
        ->and($lead->next_followup_at->toDateString())->toBe(now()->addDays(7)->toDateString())
        ->and(Task::where('taskable_id', $lead->id)->where('status', TaskStatus::Pending)->count())->toBe(1);
});

it('clears the next action when a follow-up completes with nothing after it', function () {
    $lead = workspaceDeal();
    pendingFollowup($lead, now()->addDay());

    $this->actingAs(workspaceAdmin())
        ->post(route('company.crm.leads.followup.complete', $lead), ['notes' => 'Closed out.']);

    expect($lead->fresh()->next_followup_at)->toBeNull()
        ->and(app(DealDetailService::class)->nextAction($lead->fresh())['has_action'])->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// Timeline and notes
// ─────────────────────────────────────────────────────────────────────────────

it('separates meaningful events from system noise', function () {
    $lead = workspaceDeal();

    $lead->activities()->create(['type' => 'note', 'description' => 'Wants a multi-clinic discount.']);
    $lead->activities()->create(['type' => 'call', 'description' => 'Called, no answer.']);
    $lead->activities()->create(['type' => 'system_update', 'description' => 'Lead details updated — City: "a" → "b"']);
    $lead->activities()->create(['type' => 'system_update', 'description' => 'Lead details updated — State: "x" → "y"']);

    $timeline = app(DealDetailService::class)->timeline($lead->fresh());

    expect(collect($timeline['events'])->pluck('type'))->toContain('note', 'call')
        ->and($timeline['system_count'])->toBeGreaterThanOrEqual(2)
        ->and(collect($timeline['events'])->pluck('description'))
        ->not->toContain('Lead details updated — City: "a" → "b"');
});

it('keeps stage changes in the main timeline despite their system type', function () {
    $lead = workspaceDeal();
    $lead->activities()->create(['type' => 'system_update', 'description' => 'Stage changed from new to contacted']);

    $timeline = app(DealDetailService::class)->timeline($lead->fresh());

    expect(collect($timeline['events'])->pluck('description'))->toContain('Stage changed from new to contacted');
});

it('does not delete the noisy history, only demotes it', function () {
    $lead = workspaceDeal();
    $lead->activities()->create(['type' => 'system_update', 'description' => 'Lead details updated — noise']);

    $timeline = app(DealDetailService::class)->timeline($lead->fresh());

    expect(collect($timeline['system_events'])->pluck('description'))->toContain('Lead details updated — noise');
});

it('adds a note as a first-class timeline event', function () {
    $lead = workspaceDeal();

    $this->actingAs(workspaceAdmin())
        ->post(route('company.crm.leads.activities.store', $lead), [
            'type' => 'note',
            'description' => 'Decision expected after the board meeting.',
        ])
        ->assertRedirect();

    $timeline = app(DealDetailService::class)->timeline($lead->fresh());

    expect(collect($timeline['events'])->pluck('description'))
        ->toContain('Decision expected after the board meeting.');
});

// ─────────────────────────────────────────────────────────────────────────────
// Email — a real backend, not a WhatsApp alias
// ─────────────────────────────────────────────────────────────────────────────

it('sends an email through the real crm email path', function () {
    Mail::fake();
    $lead = workspaceDeal();

    $this->actingAs(workspaceAdmin())
        ->post(route('company.crm.leads.email.store', $lead), [
            'subject' => 'Your OralCares proposal',
            'body' => 'Attaching the quotation we discussed.',
        ])
        ->assertRedirect();

    $thread = LeadEmailThread::where('lead_id', $lead->id)->first();

    expect($thread)->not->toBeNull()
        ->and($thread->subject)->toBe('Your OralCares proposal')
        ->and($thread->to_email)->toBe('buyer@workspace.test');
});

it('refuses to email a lead with no address', function () {
    $lead = workspaceDeal(['email' => null]);

    $this->actingAs(workspaceAdmin())
        ->from(route('company.crm.leads.show', $lead))
        ->post(route('company.crm.leads.email.store', $lead), [
            'subject' => 'Hello', 'body' => 'Body',
        ])
        ->assertSessionHasErrors();
});

it('reports email sendability on the deal payload', function () {
    $lead = workspaceDeal();

    /* The email channel is part of the conversation zone, which is deferred. */
    $this->actingAs(workspaceAdmin())
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('deal'));

    $email = app(DealPageService::class)->conversation($lead)['email'];

    expect($email['can_send'])->toBeTrue()
        ->and($email['default_to'])->toBe('buyer@workspace.test');
});

it('keeps email and whatsapp as separate actions', function () {
    Mail::fake();
    $lead = workspaceDeal();
    $admin = workspaceAdmin();

    $this->actingAs($admin)->post(route('company.crm.leads.email.store', $lead), [
        'subject' => 'S', 'body' => 'B',
    ]);

    // Sending an email must not also register as a WhatsApp send. Read off the
    // timeline now that `lead_whatsapp_messages` is gone.
    expect(LeadEmailThread::where('lead_id', $lead->id)->count())->toBe(1)
        ->and($lead->activities()->where('type', ActivityType::WhatsApp)->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Invoices — what a settled quotation produces, on the deal
// ─────────────────────────────────────────────────────────────────────────────

it('lists the invoices raised from this deal quotations', function () {
    $lead = workspaceDeal();

    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'organization_id' => $lead->organization_id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 10000, 'tax' => 0, 'discount' => 0, 'grand_total' => 10000,
    ]);

    Payment::factory()->create([
        'lead_id' => $lead->id,
        'company_quotation_id' => $quotation->id,
        'amount' => 10000,
        'status' => 'successful',
    ]);

    $invoices = app(DealDetailService::class)->invoices($lead);

    expect($invoices['has_invoice'])->toBeTrue()
        ->and($invoices['items'])->toHaveCount(1)
        ->and((float) $invoices['items'][0]['final_amount'])->toBe(10000.0);
});

it('reports no invoice when nothing has settled', function () {
    $lead = workspaceDeal();

    CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'organization_id' => $lead->organization_id,
        'status' => QuotationStatus::Approved,
        'subtotal' => 10000, 'tax' => 0, 'discount' => 0, 'grand_total' => 10000,
    ]);

    expect(app(DealDetailService::class)->invoices($lead)['has_invoice'])->toBeFalse();
});

it('does not list a historical agreement beside live invoices', function () {
    /*
     * The agreement documents are still real and still readable — the Won gate
     * consults them so deals closed under the old flow keep winning. They are
     * simply not offered as part of a flow that no longer has that step.
     */
    $lead = workspaceDeal();
    CommercialDocument::factory()->create(['lead_id' => $lead->id, 'type' => 'agreement', 'status' => 'accepted']);

    expect($lead->fresh()->hasAcceptedAgreement())->toBeTrue()
        ->and(app(DealDetailService::class)->invoices($lead)['items'])->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────────────
// Contacts — real records, tenant-scoped
// ─────────────────────────────────────────────────────────────────────────────

it('returns the deal real contacts rather than a synthesised one', function () {
    $lead = workspaceDeal();

    Contact::create([
        'lead_id' => $lead->id, 'organization_id' => $lead->organization_id,
        'first_name' => 'Asha', 'last_name' => 'Menon', 'email' => 'asha@workspace.test', 'is_primary' => true,
    ]);
    Contact::create([
        'lead_id' => $lead->id, 'organization_id' => $lead->organization_id,
        'first_name' => 'Ravi', 'last_name' => 'Kumar', 'email' => 'ravi@workspace.test',
    ]);

    $contacts = app(DealDetailService::class)->contacts($lead);

    expect($contacts)->toHaveCount(2)
        ->and($contacts[0]['name'])->toBe('Asha Menon')
        ->and($contacts[0]['is_primary'])->toBeTrue();
});

it('does not return another deal contacts', function () {
    $lead = workspaceDeal();
    $other = workspaceDeal(['company_name' => 'Other Deal']);

    Contact::create([
        'lead_id' => $other->id,
        'organization_id' => $other->organization_id,
        'first_name' => 'Not',
        'last_name' => 'Mine',
    ]);

    expect(app(DealDetailService::class)->contacts($lead))->toHaveCount(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// The composed payload
// ─────────────────────────────────────────────────────────────────────────────

it('ships every zone of the deal detail payload', function () {
    /*
     * The page is built from one service now, organised by the zone that
     * renders each part. The command bar arrives with the document; every
     * other zone is deferred, which is why they are asserted as deferred keys
     * rather than as props on the first response.
     */
    $lead = workspaceDeal();

    $response = $this->actingAs(workspaceAdmin())
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('Company/Crm/Leads/Show')
        ->has('deal')
        ->has('can')
    );

    $deferred = $response->getOriginalContent()->getData()['page']['deferredProps'] ?? [];

    /*
     * One payload, two deferred tails.
     *
     * This used to assert six defer groups — `now`, `commercial`, `evaluation`,
     * `conversation`, `history`, `files` — which is exactly the architecture
     * that made the page settle six times before it could be read. Everything a
     * rep reads or acts on now arrives with the document; only the activity
     * history and the attachment list are fetched afterwards, and both render in
     * a column sized independently of the workspace, so neither can move it.
     *
     * `profile` is still deliberately absent — the organisation profile is its
     * own route, which is where `DealPageService::profile()` is read.
     * `customer_account` is gone too: it was deferred, cost a query, and the
     * page rendered it as `{customer_account && null}`.
     *
     * `conversation` was gone for the same reason, on the theory that the
     * activity stream already held every event a WhatsApp panel would have
     * shown — true for a rep skimming what happened, false for a rep who
     * needs to read an actual exchange in order. A one-line "WhatsApp sent —
     * '...'" activity entry is not a substitute for the thread itself, so
     * the dedicated panel is back — immediate, not deferred, because a chat
     * that pops in half a second after the page does not read as live.
     */
    expect($deferred)->toHaveKeys(['history'])
        /* One group, so one follow-up request and one settling moment. */
        ->and($deferred)->toHaveCount(1)
        ->and($deferred['history'])->toContain('history')->toContain('files')
        ->and($deferred)->not->toHaveKey('profile');

    $response->assertInertia(fn ($page) => $page
        /* The workspace, whole, at first paint. */
        ->has('now.stage_control.transitions')
        ->has('evaluation.demos')
        ->has('evaluation.trials')
        ->has('commercial.lifecycle')
        ->has('commercial.packages')
        ->has('assignable_users')
        ->has('outreach.wa_number')
        ->has('conversation.whatsapp')
        ->missing('customer_account')
        ->missing('profile')
    );
    $page = app(DealPageService::class);

    expect($page->now($lead))->toHaveKeys(['next_action', 'stage_control', 'tasks'])
        ->and($page->commercial($lead))->toHaveKeys(['lifecycle', 'conversion', 'quotations', 'invoices', 'payments'])
        ->and($page->evaluation($lead))->toHaveKeys(['demos', 'trials'])
        ->and($page->outreach($lead))->toHaveKeys(['phone', 'wa_number', 'email'])
        ->and($page->profile($lead))->toHaveKeys(['organisation', 'location', 'branches', 'contacts', 'qualification', 'commercial', 'attribution'])
        ->and($page->history($lead))->toHaveKeys(['events', 'total', 'has_more', 'kinds']);
});
