<?php

use App\Jobs\SendDemoDayRemindersJob;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    actingAs($this->admin);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'phone' => '9998887766',
    ]);
});

function crmMakeApprovedTemplate(Organization $org, string $name): MarketingWhatsAppTemplate
{
    $account = MarketingWhatsAppAccount::create([
        'organization_id' => $org->id,
        'provider' => 'meta',
        'phone_number_id' => '9990001111',
        'status' => 'connected',
    ]);

    return MarketingWhatsAppTemplate::create([
        'organization_id' => $org->id,
        'whatsapp_account_id' => $account->id,
        'name' => $name,
        'language' => 'en',
        'category' => 'utility',
        'status' => 'approved',
        'body' => 'Reminder message body.',
    ]);
}

it('does not send a WhatsApp reminder immediately when a demo is scheduled', function () {
    crmMakeApprovedTemplate($this->organization, 'demo_reminder_client');

    post(route('company.crm.leads.schedule-demo', $this->lead), [
        'lead_id' => $this->lead->id,
        'scheduled_at' => now()->addDays(3)->toDateTimeString(),
        'demo_mode' => 'online',
        'presenter_id' => $this->admin->id,
    ])->assertSessionHasNoErrors();

    expect($this->lead->activities()->where('type', 'whatsapp')->count())->toBe(0);
});

it('sends a real client demo reminder when an approved template is configured', function () {
    crmMakeApprovedTemplate($this->organization, 'demo_reminder_client');

    $demo = Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'presenter_id' => $this->admin->id,
        'scheduled_at' => today()->setTime(14, 0),
        'status' => 'scheduled',
    ]);

    $sent = app(LeadWorkspaceService::class)->sendClientDemoReminder($demo);

    expect($sent)->toBeTrue();

    /* The deal's own history is the record now — the mirror table is retired. */
    $activity = $this->lead->activities()->where('type', 'whatsapp')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->description)->not->toContain('NOT sent');
});

it('logs a not-sent activity instead of a fake send when no approved template exists', function () {
    $demo = Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'presenter_id' => $this->admin->id,
        'scheduled_at' => today()->setTime(14, 0),
        'status' => 'scheduled',
    ]);

    $sent = app(LeadWorkspaceService::class)->sendClientDemoReminder($demo);

    expect($sent)->toBeFalse();

    $activity = $this->lead->activities()->latest('id')->first();
    expect($activity->description)->toContain('NOT sent');
});

it('sends a demo feedback request WhatsApp when a demo is completed', function () {
    crmMakeApprovedTemplate($this->organization, 'demo_feedback_request');

    $demo = Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'presenter_id' => $this->admin->id,
        'status' => 'scheduled',
    ]);

    post(route('company.crm.demos.complete', $demo), [
        'outcome' => 'interested',
        'feedback' => 'Went well.',
    ])->assertSessionHasNoErrors();

    /* An activity carries the message, not a template column. */
    $feedbackMessage = $this->lead->activities()
        ->where('type', 'whatsapp')
        ->latest('id')
        ->first();

    expect($feedbackMessage)->not->toBeNull()
        ->and($feedbackMessage->description)->not->toContain('NOT sent');
});

it('SendDemoDayRemindersJob sends client reminders and a rep digest for todays demos only', function () {
    crmMakeApprovedTemplate($this->organization, 'demo_reminder_client');
    crmMakeApprovedTemplate($this->organization, 'demo_reminder_rep');

    $rep = User::factory()->companyUser()->create([
        'organization_id' => $this->organization->id,
        'phone' => '9991112222',
    ]);

    $todayDemo = Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'presenter_id' => $rep->id,
        'scheduled_at' => today()->setTime(11, 0),
        'status' => 'scheduled',
    ]);

    $otherLead = Lead::factory()->create(['organization_id' => $this->organization->id, 'phone' => '9998887767']);
    $futureDemo = Demo::factory()->create([
        'lead_id' => $otherLead->id,
        'presenter_id' => $rep->id,
        'scheduled_at' => now()->addWeek(),
        'status' => 'scheduled',
    ]);

    (new SendDemoDayRemindersJob)->handle(app(LeadWorkspaceService::class));

    expect($this->lead->activities()->where('type', 'whatsapp')->count())->toBe(1);
    expect($otherLead->activities()->where('type', 'whatsapp')->count())->toBe(0);

    $this->assertDatabaseHas('ent_notifications', [
        'notifiable_id' => $rep->id,
        'notifiable_type' => User::class,
    ]);
});
