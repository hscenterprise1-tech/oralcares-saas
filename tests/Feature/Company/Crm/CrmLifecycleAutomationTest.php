<?php

use App\DemoStatus;
use App\Jobs\ExpireOverdueQuotationsJob;
use App\Jobs\ProcessDueWinBacksJob;
use App\LeadStatus;
use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\QuotationStatus;
use App\Services\LeadWorkspaceService;
use App\Services\QuotationActivityService;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Notification\Services\NotificationEngineService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 1 — CRM lifecycle automation.
 *
 * Covers the three workflows that previously stored data but never acted on it:
 * win-back scheduling, quotation validity, and presenter double-booking.
 */

// ─────────────────────────── Win-back ───────────────────────────

it('registers the win-back and quotation expiry jobs on the scheduler', function () {
    $summaries = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->getSummaryForDisplay())
        ->implode(' | ');

    expect($summaries)->toContain(ProcessDueWinBacksJob::class)
        ->and($summaries)->toContain(ExpireOverdueQuotationsJob::class);
});

it('notifies the owner when a scheduled win-back falls due', function () {
    $org = Organization::factory()->create();
    $rep = User::factory()->companyUser()->create(['organization_id' => $org->id]);

    $lead = Lead::factory()->create([
        'organization_id' => $org->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::Lost,
        'winback_scheduled_at' => now()->subDay(),
    ]);

    app(ProcessDueWinBacksJob::class)->handle(app(LeadWorkspaceService::class));

    expect(EnterpriseNotification::where('notifiable_id', $rep->id)->count())->toBeGreaterThan(0)
        ->and($lead->activities()->where('description', 'like', 'Win-back re-engagement due%')->exists())->toBeTrue();
});

it('does not notify for a win-back scheduled in the future', function () {
    $org = Organization::factory()->create();
    $rep = User::factory()->companyUser()->create(['organization_id' => $org->id]);

    Lead::factory()->create([
        'organization_id' => $org->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::Lost,
        'winback_scheduled_at' => now()->addWeek(),
    ]);

    app(ProcessDueWinBacksJob::class)->handle(app(LeadWorkspaceService::class));

    expect(EnterpriseNotification::where('notifiable_id', $rep->id)->count())->toBe(0);
});

it('is idempotent across repeated win-back runs', function () {
    $org = Organization::factory()->create();
    $rep = User::factory()->companyUser()->create(['organization_id' => $org->id]);

    Lead::factory()->create([
        'organization_id' => $org->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::Lost,
        'winback_scheduled_at' => now()->subDay(),
    ]);

    $service = app(LeadWorkspaceService::class);
    app(ProcessDueWinBacksJob::class)->handle($service);
    $afterFirst = EnterpriseNotification::count();

    app(ProcessDueWinBacksJob::class)->handle($service);

    expect(EnterpriseNotification::count())->toBe($afterFirst);
});

it('reschedules an existing win-back task instead of stacking a second one', function () {
    $org = Organization::factory()->create();
    $rep = User::factory()->companyUser()->create(['organization_id' => $org->id]);
    $lead = Lead::factory()->create([
        'organization_id' => $org->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::Lost,
    ]);

    $service = app(LeadWorkspaceService::class);
    $service->scheduleWinBack($lead, now()->addDays(7)->toDateString(), 'first', $rep);
    $service->scheduleWinBack($lead->fresh(), now()->addDays(21)->toDateString(), 'revised', $rep);

    $winbackTasks = Task::where('taskable_id', $lead->id)
        ->where('title', 'like', 'Win-Back Follow-up:%')
        ->get();

    expect($winbackTasks)->toHaveCount(1)
        ->and($winbackTasks->first()->due_at->toDateString())->toBe(now()->addDays(21)->toDateString());
});

// ─────────────────────── Quotation expiry ───────────────────────

function quotationWithValidity(string $status, ?Carbon $validUntil): CompanyQuotation
{
    $lead = Lead::factory()->create();
    $org = Organization::factory()->create();
    $preparedBy = CompanyEmployee::create([
        'organization_id' => $org->id,
        'user_id' => User::factory()->companyUser()->create(['organization_id' => $org->id])->id,
        'employee_code' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
        'employment_status' => 'active',
    ]);

    return CompanyQuotation::create([
        'lead_id' => $lead->id,
        'prepared_by_id' => $preparedBy->id,
        'quotation_number' => 'QT-'.fake()->unique()->numberBetween(10000, 99999),
        'status' => $status,
        'valid_until' => $validUntil,
        'subtotal' => 10000,
        'tax_amount' => 1800,
        'discount_amount' => 0,
        'grand_total' => 11800,
    ]);
}

it('expires a sent quotation whose validity has passed', function () {
    $quotation = quotationWithValidity(QuotationStatus::Sent->value, now()->subDays(2));

    app(ExpireOverdueQuotationsJob::class)->handle(app(QuotationActivityService::class), app(NotificationEngineService::class));

    expect($quotation->fresh()->status->value ?? $quotation->fresh()->status)
        ->toBe(QuotationStatus::Expired->value);
});

it('leaves a still-valid quotation alone', function () {
    $quotation = quotationWithValidity(QuotationStatus::Sent->value, now()->addWeek());

    app(ExpireOverdueQuotationsJob::class)->handle(app(QuotationActivityService::class), app(NotificationEngineService::class));

    expect($quotation->fresh()->status->value ?? $quotation->fresh()->status)
        ->toBe(QuotationStatus::Sent->value);
});

it('never expires an approved or rejected quotation', function (string $status) {
    $quotation = quotationWithValidity($status, now()->subMonth());

    app(ExpireOverdueQuotationsJob::class)->handle(app(QuotationActivityService::class), app(NotificationEngineService::class));

    expect($quotation->fresh()->status->value ?? $quotation->fresh()->status)->toBe($status);
})->with([
    QuotationStatus::Approved->value,
    QuotationStatus::Rejected->value,
    QuotationStatus::Converted->value,
]);

it('tells the owner when their quotation expires', function () {
    // The job changed a commercial state silently for a long time: a live offer
    // became Expired at 00:30 and the person chasing it found out only if they
    // happened to reopen the record.
    $quotation = quotationWithValidity(QuotationStatus::Sent->value, now()->subDays(2));
    $owner = $quotation->preparedBy;
    $quotation->update(['assigned_id' => $owner->id]);

    app(ExpireOverdueQuotationsJob::class)->handle(app(QuotationActivityService::class), app(NotificationEngineService::class));

    expect(EnterpriseNotification::where('notifiable_id', $owner->user_id)
        ->where('data->metadata->type', 'quotation_expired')
        ->where('data->metadata->quotation_id', $quotation->id)
        ->count())->toBe(1);
});

it('still expires an unowned quotation rather than failing on the notification', function () {
    // `assignedTo` is a CompanyEmployee and the engine notifies users, so there
    // are two ways to have nobody to tell. Neither may stop the expiry.
    $quotation = quotationWithValidity(QuotationStatus::Sent->value, now()->subDays(2));

    app(ExpireOverdueQuotationsJob::class)->handle(app(QuotationActivityService::class), app(NotificationEngineService::class));

    expect($quotation->fresh()->status->value ?? $quotation->fresh()->status)
        ->toBe(QuotationStatus::Expired->value)
        ->and(EnterpriseNotification::where('data->metadata->type', 'quotation_expired')->count())->toBe(0);
});

it('writes an audit entry when a quotation expires', function () {
    $quotation = quotationWithValidity(QuotationStatus::Sent->value, now()->subDays(2));

    app(ExpireOverdueQuotationsJob::class)->handle(app(QuotationActivityService::class), app(NotificationEngineService::class));

    $this->assertDatabaseHas('company_quotation_activities', [
        'company_quotation_id' => $quotation->id,
        'event' => 'expired',
    ]);
});

// ─────────────────── Demo conflict detection ────────────────────

it('rejects a demo that overlaps the presenter existing booking', function () {
    $presenter = User::factory()->companyUser()->create();
    $lead = Lead::factory()->create();

    Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $presenter->id,
        'scheduled_at' => Carbon::parse('2027-03-01 10:00'),
        'duration_minutes' => 60,
        'status' => DemoStatus::Scheduled,
    ]);

    app(LeadWorkspaceService::class)->scheduleDemo($lead, [
        'presenter_id' => $presenter->id,
        'scheduled_at' => '2027-03-01 10:30',
        'duration_minutes' => 45,
    ]);
})->throws(InvalidArgumentException::class);

it('allows a back-to-back demo that does not overlap', function () {
    $presenter = User::factory()->companyUser()->create();
    $lead = Lead::factory()->create();

    Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $presenter->id,
        'scheduled_at' => Carbon::parse('2027-03-01 10:00'),
        'duration_minutes' => 60,
        'status' => DemoStatus::Scheduled,
    ]);

    $demo = app(LeadWorkspaceService::class)->scheduleDemo($lead, [
        'presenter_id' => $presenter->id,
        'scheduled_at' => '2027-03-01 11:00',
        'duration_minutes' => 45,
    ]);

    expect($demo)->not->toBeNull();
});

it('allows two presenters to run demos at the same time', function () {
    $presenterA = User::factory()->companyUser()->create();
    $presenterB = User::factory()->companyUser()->create();
    $lead = Lead::factory()->create();

    Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $presenterA->id,
        'scheduled_at' => Carbon::parse('2027-03-01 10:00'),
        'duration_minutes' => 60,
        'status' => DemoStatus::Scheduled,
    ]);

    $demo = app(LeadWorkspaceService::class)->scheduleDemo($lead, [
        'presenter_id' => $presenterB->id,
        'scheduled_at' => '2027-03-01 10:00',
        'duration_minutes' => 60,
    ]);

    expect($demo)->not->toBeNull();
});

it('ignores cancelled demos when checking for conflicts', function () {
    $presenter = User::factory()->companyUser()->create();
    $lead = Lead::factory()->create();

    Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $presenter->id,
        'scheduled_at' => Carbon::parse('2027-03-01 10:00'),
        'duration_minutes' => 60,
        'status' => DemoStatus::Cancelled,
    ]);

    $demo = app(LeadWorkspaceService::class)->scheduleDemo($lead, [
        'presenter_id' => $presenter->id,
        'scheduled_at' => '2027-03-01 10:00',
        'duration_minutes' => 60,
    ]);

    expect($demo)->not->toBeNull();
});

it('surfaces a booking clash as a validation error rather than a server fault', function () {
    $presenter = User::factory()->companyUser()->create();
    $lead = Lead::factory()->create();
    $actor = crmUser();

    Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $presenter->id,
        'scheduled_at' => Carbon::parse('2027-03-01 10:00'),
        'duration_minutes' => 60,
        'status' => DemoStatus::Scheduled,
    ]);

    $this->actingAs($actor)
        ->post(route('company.crm.demos.store'), [
            'lead_id' => $lead->id,
            'presenter_id' => $presenter->id,
            'scheduled_at' => '2027-03-01 10:30',
            'duration_minutes' => 45,
        ])
        ->assertSessionHasErrors('scheduled_at');
});
