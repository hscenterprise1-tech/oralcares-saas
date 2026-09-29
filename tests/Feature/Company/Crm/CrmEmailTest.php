<?php

use App\ActivityType;
use App\Mail\LeadEmailMessage;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadEmailThread;
use App\Models\MarketingContact;
use App\Models\Organization;
use App\Models\User;
use App\Services\CRM\DealPageService;
use App\Services\CrmEmailService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Slice C — CRM email channel.
 *
 * `lead_email_threads` existed with no send path. These tests pin the whole
 * flow: validate, respect consent, persist before sending, record the outcome
 * on both success and failure, and allow a retry of what failed.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->org = Organization::factory()->create();

    $this->rep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
        'email' => 'rep@oralcares.in',
        'name' => 'Priya Rep',
    ]);
    $this->rep->assignRole('Sales Representative');

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'email' => 'client@apexdental.in',
        'company_name' => 'Apex Dental',
    ]);
});

it('sends an email and records the thread as sent', function () {
    Mail::fake();

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'Your OralCares proposal',
            'body' => 'Hello, please find our proposal attached.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    Mail::assertSent(LeadEmailMessage::class, fn ($mail) => $mail->hasTo('client@apexdental.in')
        && $mail->subjectLine === 'Your OralCares proposal');

    $thread = LeadEmailThread::firstOrFail();

    expect($thread->status)->toBe(LeadEmailThread::STATUS_SENT)
        ->and($thread->direction)->toBe('outbound')
        ->and($thread->to_email)->toBe('client@apexdental.in')
        ->and($thread->sent_by_user_id)->toBe($this->rep->id)
        ->and($thread->sent_at)->not->toBeNull()
        ->and($thread->attempts)->toBe(1);
});

it('writes an activity onto the lead timeline', function () {
    Mail::fake();

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'Following up',
            'body' => 'Just checking in.',
        ]);

    $activity = Activity::where('subject_id', $this->lead->id)
        ->where('type', ActivityType::Email)
        ->firstOrFail();

    expect($activity->description)->toContain('Email sent: Following up')
        ->and($activity->user_id)->toBe($this->rep->id);
});

it('sets the reply-to address to the sending rep', function () {
    Mail::fake();

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'Hello',
            'body' => 'Body text.',
        ]);

    Mail::assertSent(LeadEmailMessage::class, fn ($mail) => $mail->replyToAddress === 'rep@oralcares.in'
        && $mail->replyToName === 'Priya Rep');
});

it('rejects an empty subject or body', function () {
    Mail::fake();

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), ['subject' => '', 'body' => ''])
        ->assertSessionHasErrors(['subject', 'body']);

    Mail::assertNothingSent();
    expect(LeadEmailThread::count())->toBe(0);
});

it('rejects a malformed recipient address', function () {
    Mail::fake();

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'Hello',
            'body' => 'Body.',
            'to' => 'not-an-address',
        ])
        ->assertSessionHasErrors('to');

    Mail::assertNothingSent();
});

it('refuses to email a contact who has opted out', function () {
    Mail::fake();

    // Consent is keyed on the normalised phone, which is how the WhatsApp path
    // resolves the same contact.
    $this->lead->update(['normalized_phone' => '9876543210']);

    MarketingContact::create([
        'organization_id' => $this->org->id,
        'name' => 'Apex Dental',
        'phone' => '+919876543210',
        'normalized_phone' => '9876543210',
        'marketing_status' => 'opted_out',
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'Hello',
            'body' => 'Body.',
        ])
        ->assertSessionHasErrors('to');

    Mail::assertNothingSent();
    expect(LeadEmailThread::count())->toBe(0);
});

it('reports why a lead cannot be emailed', function () {
    $emails = app(CrmEmailService::class);

    expect($emails->sendability($this->lead))->toBe(['can_send' => true, 'reason' => null]);

    $noEmailLead = Lead::factory()->create(['email' => null, 'assigned_user_id' => $this->rep->id]);

    expect($emails->sendability($noEmailLead)['can_send'])->toBeFalse()
        ->and($emails->sendability($noEmailLead)['reason'])->toContain('no email address');
});

it('preserves the message and marks it failed when the transport throws', function () {
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('SMTP connection refused'));

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'Will fail',
            'body' => 'Body.',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $thread = LeadEmailThread::firstOrFail();

    expect($thread->status)->toBe(LeadEmailThread::STATUS_FAILED)
        ->and($thread->failure_reason)->toContain('SMTP connection refused')
        ->and($thread->failed_at)->not->toBeNull()
        ->and($thread->subject)->toBe('Will fail')
        ->and($thread->isRetryable())->toBeTrue();

    expect(Activity::where('subject_id', $this->lead->id)->where('type', ActivityType::Email)->first()->description)
        ->toContain('Email failed to send');
});

it('retries a failed message on the same thread rather than duplicating it', function () {
    $thread = LeadEmailThread::create([
        'lead_id' => $this->lead->id,
        'direction' => 'outbound',
        'status' => LeadEmailThread::STATUS_FAILED,
        'to_email' => 'client@apexdental.in',
        'sent_by_user_id' => $this->rep->id,
        'subject' => 'Retry me',
        'body_html' => 'Body.',
        'attempts' => 1,
        'failed_at' => now(),
        'failure_reason' => 'Earlier transport failure',
    ]);

    Mail::fake();

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.retry', [$this->lead, $thread]))
        ->assertRedirect()
        ->assertSessionHas('success');

    Mail::assertSent(LeadEmailMessage::class);

    expect(LeadEmailThread::count())->toBe(1);

    $thread->refresh();
    expect($thread->status)->toBe(LeadEmailThread::STATUS_SENT)
        ->and($thread->attempts)->toBe(2)
        ->and($thread->failure_reason)->toBeNull();
});

it('refuses to retry a message that already sent', function () {
    $thread = LeadEmailThread::create([
        'lead_id' => $this->lead->id,
        'direction' => 'outbound',
        'status' => LeadEmailThread::STATUS_SENT,
        'to_email' => 'client@apexdental.in',
        'subject' => 'Already sent',
        'body_html' => 'Body.',
        'sent_at' => now(),
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.retry', [$this->lead, $thread]))
        ->assertSessionHasErrors('email');
});

it('refuses to retry a thread belonging to a different lead', function () {
    $otherLead = Lead::factory()->create(['assigned_user_id' => $this->rep->id]);
    $thread = LeadEmailThread::create([
        'lead_id' => $otherLead->id,
        'direction' => 'outbound',
        'status' => LeadEmailThread::STATUS_FAILED,
        'to_email' => 'someone@else.in',
        'subject' => 'Not yours',
        'body_html' => 'Body.',
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.retry', [$this->lead, $thread]))
        ->assertNotFound();
});

it('refuses a user with no CRM permission on the lead to retry it', function () {
    $thread = LeadEmailThread::create([
        'lead_id' => $this->lead->id,
        'direction' => 'outbound',
        'status' => LeadEmailThread::STATUS_FAILED,
        'to_email' => 'client@apexdental.in',
        'subject' => 'Original',
        'body_html' => 'Body.',
        'failure_reason' => 'SMTP timeout',
        'attempts' => 1,
    ]);

    $outsider = User::factory()->create(['user_type' => UserType::CompanyUser]);

    $this->actingAs($outsider)
        ->post(route('company.crm.leads.email.retry', [$this->lead, $thread]))
        ->assertForbidden();

    expect($thread->fresh()->status)->toBe(LeadEmailThread::STATUS_FAILED)
        ->and($thread->fresh()->attempts)->toBe(1);
});

it('exposes is_retryable as false on a thread that sent successfully', function () {
    Mail::fake();

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'Will succeed',
            'body' => 'Body.',
        ]);

    $thread = app(DealPageService::class)->conversation($this->lead)['email']['threads'][0];

    expect($thread['status'])->toBe('sent')
        ->and($thread['is_retryable'])->toBeFalse();
});

it('exposes is_retryable, the failure reason and the status on a failed thread', function () {
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('SMTP timeout'));

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'Will fail',
            'body' => 'Body.',
        ]);

    $thread = app(DealPageService::class)->conversation($this->lead)['email']['threads'][0];

    expect($thread['status'])->toBe('failed')
        ->and($thread['is_retryable'])->toBeTrue()
        ->and($thread['failure_reason'])->toContain('SMTP timeout');
});

it('the deal page response carries the email thread the retry UI reads', function () {
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('SMTP timeout'));

    $this->actingAs($this->rep)->post(route('company.crm.leads.email.store', $this->lead), [
        'subject' => 'Rendered on the page',
        'body' => 'Body.',
    ]);

    $this->actingAs($this->rep)
        ->get(route('company.crm.leads.show', $this->lead))
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Leads/Show')
            ->where('conversation.email.threads.0.subject', 'Rendered on the page')
            ->where('conversation.email.threads.0.status', 'failed')
            ->where('conversation.email.threads.0.is_retryable', true));
});

it('denies sending to a user without permission on the lead', function () {
    Mail::fake();
    $outsider = User::factory()->create(['user_type' => UserType::CompanyUser]);

    $this->actingAs($outsider)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'Hello',
            'body' => 'Body.',
        ])
        ->assertForbidden();

    Mail::assertNothingSent();
});

it('escapes composed text but preserves simple inline formatting', function () {
    Mail::fake();

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'Formatting',
            'body' => "Hello <b>Doctor</b>\nRegards <script>alert(1)</script>",
        ]);

    $body = LeadEmailThread::firstOrFail()->body_html;

    expect($body)->toContain('<b>Doctor</b>')
        ->and($body)->toContain('<br>')
        ->and($body)->not->toContain('<script>')
        ->and($body)->toContain('&lt;script&gt;');
});

it('exposes email history and sendability on the lead detail page', function () {
    Mail::fake();

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.email.store', $this->lead), [
            'subject' => 'History check',
            'body' => 'Body.',
        ]);

    /*
     * Email is one channel of the conversation zone now, beside WhatsApp and
     * calls, rather than a payload key of its own behind its own disclosure.
     * The zone is deferred, so it is read from the builder.
     */
    $this->actingAs($this->rep)
        ->get(route('company.crm.leads.show', $this->lead))
        ->assertSuccessful();

    $email = app(DealPageService::class)->conversation($this->lead)['email'];

    expect($email['can_send'])->toBeTrue()
        ->and($email['default_to'])->toBe('client@apexdental.in')
        ->and($email['threads'][0]['subject'])->toBe('History check')
        ->and($email['threads'][0]['status'])->toBe('sent');
});
