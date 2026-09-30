<?php

use App\DemoStatus;
use App\Jobs\SendCustomerDemoRemindersJob;
use App\Jobs\SendDailyRepAgendaJob;
use App\LeadStatus;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * The demo engine: how somebody joins, and proof that they were told.
 *
 * Two things had no record at all before this. Which tool a demo is on — a
 * reminder has to say "join on Zoom" or "join on Meet", and `meeting_link`
 * alone could not tell you which — and whether a reminder was ever actually
 * sent, without which the reminder jobs cannot be idempotent and a redeploy
 * sends everything twice.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->rep = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
        'phone' => '919600000001',
    ]);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Kamat Dental',
        'contact_name' => 'Asha Kamat',
        'phone' => '919600000002',
        'status' => LeadStatus::Contacted->value,
        'assigned_user_id' => $this->rep->id,
    ]);
});

/* ── Booking: the platform, and what it implies ──────────────────────────── */

it('records the platform a demo is held on, not merely that it is online', function () {
    $this->actingAs($this->rep)->post(route('company.crm.leads.schedule-demo', $this->lead), [
        'lead_id' => $this->lead->id,
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'meeting_platform' => Demo::PLATFORM_ZOOM,
        'meeting_link' => 'https://zoom.us/j/1234567890',
        'meeting_id' => '123 4567 890',
        'passcode' => '881122',
    ])->assertSessionHasNoErrors();

    $demo = Demo::where('lead_id', $this->lead->id)->firstOrFail();

    expect($demo->meeting_platform)->toBe(Demo::PLATFORM_ZOOM)
        ->and($demo->meeting_link)->toBe('https://zoom.us/j/1234567890')
        ->and($demo->meeting_id)->toBe('123 4567 890')
        ->and($demo->passcode)->toBe('881122')
        // The coarse mode is derived, never taken from the request, so the two
        // columns cannot end up contradicting each other.
        ->and($demo->demo_mode)->toBe('online')
        ->and($demo->isRemote())->toBeTrue();
});

it('derives in-person mode from the platform rather than trusting both fields', function () {
    $this->actingAs($this->rep)->post(route('company.crm.leads.schedule-demo', $this->lead), [
        'lead_id' => $this->lead->id,
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        // Deliberately contradictory: a caller claiming an online demo held in
        // person. The finer-grained field wins.
        'demo_mode' => 'online',
        'meeting_platform' => Demo::PLATFORM_IN_PERSON,
        'location' => '4th Cross, Indiranagar',
    ])->assertSessionHasNoErrors();

    $demo = Demo::where('lead_id', $this->lead->id)->firstOrFail();

    expect($demo->meeting_platform)->toBe(Demo::PLATFORM_IN_PERSON)
        ->and($demo->demo_mode)->toBe('in_person')
        ->and($demo->isRemote())->toBeFalse()
        ->and($demo->joiningInstruction())->toBe('4th Cross, Indiranagar');
});

it('defaults a demo booked without a platform to google meet', function () {
    app(LeadWorkspaceService::class)->scheduleDemo($this->lead, [
        'scheduled_at' => now()->addDay()->toDateTimeString(),
    ], $this->rep);

    expect(Demo::where('lead_id', $this->lead->id)->value('meeting_platform'))
        ->toBe(Demo::PLATFORM_GOOGLE_MEET);
});

it('rejects a platform outside the supported list', function () {
    $this->actingAs($this->rep)->post(route('company.crm.leads.schedule-demo', $this->lead), [
        'lead_id' => $this->lead->id,
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'meeting_platform' => 'skype',
    ])->assertSessionHasErrors('meeting_platform');
});

/* ── Customer reminders: the two windows, claimed once each ──────────────── */

it('claims the 24 hour window for a demo starting tomorrow', function () {
    Notification::fake();

    $demo = Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'presenter_id' => $this->rep->id,
        'scheduled_at' => now()->addHours(20),
        'status' => DemoStatus::Scheduled,
    ]);

    app(SendCustomerDemoRemindersJob::class)->handle(app(LeadWorkspaceService::class));

    $demo->refresh();

    expect($demo->customer_reminder_24h_at)->not->toBeNull()
        // Still twenty hours out, so the two-hour window has not opened.
        ->and($demo->customer_reminder_2h_at)->toBeNull();
});

it('claims both windows for a demo starting within the hour', function () {
    Notification::fake();

    $demo = Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'presenter_id' => $this->rep->id,
        'scheduled_at' => now()->addMinutes(45),
        'status' => DemoStatus::Confirmed,
    ]);

    app(SendCustomerDemoRemindersJob::class)->handle(app(LeadWorkspaceService::class));

    $demo->refresh();

    expect($demo->customer_reminder_24h_at)->not->toBeNull()
        ->and($demo->customer_reminder_2h_at)->not->toBeNull();
});

it('does not send the same reminder twice when the job runs again', function () {
    Notification::fake();

    $demo = Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'presenter_id' => $this->rep->id,
        'scheduled_at' => now()->addMinutes(90),
        'status' => DemoStatus::Scheduled,
    ]);

    app(SendCustomerDemoRemindersJob::class)->handle(app(LeadWorkspaceService::class));
    $firstPass = $demo->fresh()->customer_reminder_2h_at;

    // An hour of wall-clock later, the hourly job runs again over the same demo.
    $this->travel(1)->hours();
    app(SendCustomerDemoRemindersJob::class)->handle(app(LeadWorkspaceService::class));

    expect($demo->fresh()->customer_reminder_2h_at->toIso8601String())
        ->toBe($firstPass->toIso8601String());
});

it('leaves a demo that has already happened alone', function () {
    Notification::fake();

    $demo = Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'presenter_id' => $this->rep->id,
        // Well outside the grace window — reminding somebody about yesterday is
        // worse than saying nothing.
        'scheduled_at' => now()->subDays(2),
        'status' => DemoStatus::Scheduled,
    ]);

    app(SendCustomerDemoRemindersJob::class)->handle(app(LeadWorkspaceService::class));

    $demo->refresh();

    expect($demo->customer_reminder_24h_at)->toBeNull()
        ->and($demo->customer_reminder_2h_at)->toBeNull();
});

it('ignores a cancelled demo', function () {
    Notification::fake();

    $demo = Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'presenter_id' => $this->rep->id,
        'scheduled_at' => now()->addHour(),
        'status' => DemoStatus::Cancelled,
    ]);

    app(SendCustomerDemoRemindersJob::class)->handle(app(LeadWorkspaceService::class));

    expect($demo->fresh()->customer_reminder_2h_at)->toBeNull();
});

/* ── The rep's daily agenda ──────────────────────────────────────────────── */

it('sends a rep one agenda covering demos, trials and overdue follow-ups', function () {
    Notification::fake();

    Demo::factory()->create([
        'lead_id' => $this->lead->id,
        'presenter_id' => $this->rep->id,
        'scheduled_at' => now()->setTime(11, 0),
        'status' => DemoStatus::Scheduled,
    ]);

    Trial::factory()->create([
        'lead_id' => $this->lead->id,
        'assigned_user_id' => $this->rep->id,
        'status' => TrialStatus::EndingSoon->value,
    ]);

    $this->lead->tasks()->create([
        'title' => 'Chase the quotation',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => now()->subDays(2),
        'assigned_user_id' => $this->rep->id,
        'creator_id' => $this->rep->id,
    ]);

    $captured = [];

    /*
     * Asserted through the service rather than by inspecting a WhatsApp
     * payload: the message body is presentation, the three counts are the
     * contract, and this is the seam where the job hands them over.
     */
    $this->partialMock(LeadWorkspaceService::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('sendRepDailyAgenda')
            ->once()
            ->andReturnUsing(function (User $rep, array $agenda) use (&$captured) {
                $captured = $agenda;

                return ['success' => true];
            });
    });

    app(SendDailyRepAgendaJob::class)->handle(app(LeadWorkspaceService::class));

    expect($captured['demos'])->toBe(1)
        ->and($captured['pending_trials'])->toBe(1)
        ->and($captured['overdue_followups'])->toBe(1)
        ->and($captured['first_demo_at'])->toBe('11:00 AM');
});

it('says nothing to a rep with an empty day', function () {
    Notification::fake();

    $result = app(LeadWorkspaceService::class)->sendRepDailyAgenda($this->rep, [
        'demos' => 0,
        'first_demo_at' => null,
        'pending_trials' => 0,
        'overdue_followups' => 0,
    ]);

    // An agenda that says "nothing today" trains people to stop opening it.
    expect($result['error'])->toBe('nothing_to_report');

    Notification::assertNothingSent();
});

it('does not count a lost trial as needing attention', function () {
    Notification::fake();

    Trial::factory()->create([
        'lead_id' => $this->lead->id,
        'assigned_user_id' => $this->rep->id,
        'status' => TrialStatus::EndingSoon->value,
        'lost_at' => now()->subDay(),
        'lost_reason' => 'Competitor',
    ]);

    $called = false;

    $this->partialMock(LeadWorkspaceService::class, function ($mock) use (&$called) {
        $mock->shouldReceive('sendRepDailyAgenda')->andReturnUsing(function () use (&$called) {
            $called = true;

            return ['success' => true];
        });
    });

    app(SendDailyRepAgendaJob::class)->handle(app(LeadWorkspaceService::class));

    expect($called)->toBeFalse();
});
