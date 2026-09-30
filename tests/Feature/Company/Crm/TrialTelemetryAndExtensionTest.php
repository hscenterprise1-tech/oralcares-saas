<?php

use App\LeadStatus;
use App\LostReason;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\User;
use App\Services\CRM\TrialPageService;
use App\Services\CRM\TrialService;
use App\Services\Provisioning\TenantTelemetryService;
use App\TrialOutcome;
use App\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * What a trial is worth, how long it runs, and why it died.
 *
 * Three gaps this covers. A trial could not say which package was being
 * evaluated in a way the list could badge; live activity could only be read
 * from last night's snapshot, one query per row; and a lost evaluation was
 * indistinguishable from a completed one, so the only genuinely useful thing
 * about a failure — the reason, and who won instead — was never recorded.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->rep = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->plan = CompanyPlan::factory()->create([
        'key' => 'growth',
        'name' => 'Growth',
        'trial_duration_days' => 14,
    ]);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Sundar Dental',
        'status' => LeadStatus::TrialActive->value,
        'assigned_user_id' => $this->rep->id,
    ]);

    $this->trial = Trial::factory()->create([
        'lead_id' => $this->lead->id,
        'assigned_user_id' => $this->rep->id,
        'company_plan_id' => $this->plan->id,
        'status' => TrialStatus::Active->value,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(9)->toDateString(),
        'duration_days' => 14,
    ]);
});

/* ── Package mapping ─────────────────────────────────────────────────────── */

it('carries the evaluated package through to the list as a badge', function () {
    $row = collect(app(TrialPageService::class)->listPayload($this->rep)['rows'])
        ->firstWhere('id', $this->trial->id);

    expect($row['package'])->not->toBeNull()
        ->and($row['package']['key'])->toBe('growth')
        ->and($row['package']['name'])->toBe('Growth')
        ->and($row['planName'])->toBe('Growth');
});

it('falls back to the free-text plan when no catalogue package is linked', function () {
    // A trial started before packages existed still has to say what it was sold
    // as, rather than showing nothing at all.
    $this->trial->forceFill(['company_plan_id' => null, 'plan' => 'Legacy Pilot'])->save();

    $row = collect(app(TrialPageService::class)->listPayload($this->rep)['rows'])
        ->firstWhere('id', $this->trial->id);

    expect($row['package'])->toBeNull()
        ->and($row['planName'])->toBe('Legacy Pilot');
});

/* ── Extension ───────────────────────────────────────────────────────────── */

it('extends the window, the access, and the audit trail together', function () {
    $originalEnd = $this->trial->end_date->copy();

    app(TrialService::class)->extend($this->trial, 7, 'Still evaluating the EMR module.', $this->rep);

    $this->trial->refresh();

    expect($this->trial->end_date->toDateString())->toBe($originalEnd->copy()->addDays(7)->toDateString())
        ->and($this->trial->extension_count)->toBe(1)
        ->and($this->trial->extension_reason)->toBe('Still evaluating the EMR module.')
        ->and($this->trial->extended_at)->not->toBeNull()
        // The pre-extension date is preserved, or "extended twice" loses its
        // baseline and the renewal conversation has no anchor.
        ->and($this->trial->original_end_date->toDateString())->toBe($originalEnd->toDateString());

    $this->assertDatabaseHas('activities', [
        'subject_id' => $this->lead->id,
        'type' => 'trial',
    ]);
});

it('counts repeat extensions rather than overwriting the first', function () {
    $service = app(TrialService::class);

    $service->extend($this->trial, 3, 'First.', $this->rep);
    $service->extend($this->trial->fresh(), 7, 'Second.', $this->rep);

    expect($this->trial->fresh()->extension_count)->toBe(2);
});

it('refuses to extend a trial that is already closed', function () {
    $this->trial->forceFill(['status' => TrialStatus::Cancelled])->save();

    expect(fn () => app(TrialService::class)->extend($this->trial, 7, 'Too late.', $this->rep))
        ->toThrow(InvalidArgumentException::class);
});

it('extends through the endpoint with the preset day counts the modal offers', function () {
    foreach ([3, 7, 14] as $days) {
        $trial = Trial::factory()->create([
            'lead_id' => $this->lead->id,
            'assigned_user_id' => $this->rep->id,
            'status' => TrialStatus::Active->value,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $endBefore = $trial->end_date->copy();

        $this->actingAs($this->rep)
            ->post(route('company.crm.trials.extend', $trial), [
                'additional_days' => $days,
                'extension_reason' => "Extending by {$days}.",
            ])
            ->assertSessionHasNoErrors();

        expect($trial->fresh()->end_date->toDateString())
            ->toBe($endBefore->copy()->addDays($days)->toDateString());
    }
});

/* ── Telemetry ───────────────────────────────────────────────────────────── */

it('writes nothing for a trial with no environment to measure', function () {
    // Zeros would say the customer did nothing. "We never built it" and "they
    // never used it" must not be the same row.
    expect(app(TrialService::class)->syncTrialUsage($this->trial))->toBeNull()
        ->and($this->trial->fresh()->usage_telemetry)->toBeNull();
});

it('caches live tenant activity on the trial row', function () {
    $clinic = Clinic::factory()->create(['organization_id' => $this->organization->id]);

    $this->trial->forceFill([
        'clinic_id' => $clinic->id,
        'provisioned_at' => now()->subDays(3),
    ])->save();

    $this->mock(TenantTelemetryService::class, function ($mock) {
        $mock->shouldReceive('capture')->once()->andReturn([
            'patients_count' => 42,
            'prescriptions_count' => 17,
            'invoices_count' => 9,
            'appointments_count' => 63,
            'users_total' => 4,
            'logins_7d' => 11,
            'last_login_at' => now()->toISOString(),
        ]);
    });

    $telemetry = app(TrialService::class)->syncTrialUsage($this->trial);

    expect($telemetry['patients'])->toBe(42)
        ->and($telemetry['prescriptions'])->toBe(17)
        ->and($telemetry['invoices'])->toBe(9)
        ->and($telemetry['is_active_today'])->toBeTrue()
        ->and($telemetry['is_inactive'])->toBeFalse()
        // Written to the row, which is what lets the list render it for free.
        ->and($this->trial->fresh()->usage_telemetry['patients'])->toBe(42);
});

it('calls a tenant inactive once it has been quiet for two days', function () {
    $clinic = Clinic::factory()->create(['organization_id' => $this->organization->id]);

    $this->trial->forceFill([
        'clinic_id' => $clinic->id,
        'provisioned_at' => now()->subDays(10),
    ])->save();

    $this->mock(TenantTelemetryService::class, function ($mock) {
        $mock->shouldReceive('capture')->once()->andReturn([
            'patients_count' => 3,
            'users_total' => 1,
            'logins_7d' => 1,
            'last_login_at' => now()->subDays(3)->toISOString(),
        ]);
    });

    $telemetry = app(TrialService::class)->syncTrialUsage($this->trial);

    expect($telemetry['is_active_today'])->toBeFalse()
        ->and($telemetry['is_inactive'])->toBeTrue()
        ->and($telemetry['hours_since_login'])->toBeGreaterThanOrEqual(TrialService::INACTIVE_AFTER_HOURS);
});

it('distinguishes never signed in from gone quiet', function () {
    $clinic = Clinic::factory()->create(['organization_id' => $this->organization->id]);

    $this->trial->forceFill([
        'clinic_id' => $clinic->id,
        'provisioned_at' => now()->subDays(4),
    ])->save();

    $this->mock(TenantTelemetryService::class, function ($mock) {
        $mock->shouldReceive('capture')->once()->andReturn([
            'patients_count' => 0,
            'users_total' => 2,
            'logins_7d' => 0,
            'last_login_at' => null,
        ]);
    });

    $telemetry = app(TrialService::class)->syncTrialUsage($this->trial);

    // Both are "inactive", but they need different calls, so the hour count
    // stays null rather than being flattened to a number.
    expect($telemetry['is_inactive'])->toBeTrue()
        ->and($telemetry['hours_since_login'])->toBeNull();
});

/* ── Loss ────────────────────────────────────────────────────────────────── */

it('records why a trial was lost and closes the deal with it', function () {
    app(TrialService::class)->markLost($this->trial, LostReason::Competitor, 'Practo', $this->rep);

    $this->trial->refresh();
    $this->lead->refresh();

    expect($this->trial->lost_at)->not->toBeNull()
        ->and($this->trial->lost_reason)->toBe(LostReason::Competitor->value)
        ->and($this->trial->lost_competitor)->toBe('Practo')
        ->and($this->trial->outcome)->toBe(TrialOutcome::Lost)
        // Completed, not cancelled — the evaluation ran and produced an answer.
        ->and($this->trial->status)->toBe(TrialStatus::Completed)
        // A lost trial under an open deal is how a pipeline reports revenue
        // nobody is going to close.
        ->and($this->lead->status)->toBe(LeadStatus::Lost)
        ->and($this->lead->lost_reason)->toBe(LostReason::Competitor)
        ->and($this->lead->lost_competitor)->toBe('Practo');
});

it('is idempotent — marking a lost trial lost again changes nothing', function () {
    $service = app(TrialService::class);

    $service->markLost($this->trial, LostReason::Price, null, $this->rep);
    $firstLostAt = $this->trial->fresh()->lost_at;

    $service->markLost($this->trial->fresh(), LostReason::Competitor, 'Someone else', $this->rep);

    $this->trial->refresh();

    expect($this->trial->lost_at->toIso8601String())->toBe($firstLostAt->toIso8601String())
        ->and($this->trial->lost_reason)->toBe(LostReason::Price->value)
        ->and($this->trial->lost_competitor)->toBeNull();
});

it('leaves a won deal closed when a trial on it is lost', function () {
    $this->lead->forceFill(['status' => LeadStatus::Won->value])->save();

    app(TrialService::class)->markLost($this->trial, LostReason::Timing, null, $this->rep);

    expect($this->trial->fresh()->lost_at)->not->toBeNull()
        // Losing a second trial on an account we already sold to is a fact about
        // the trial, not a reason to reopen the sale.
        ->and($this->lead->fresh()->status)->toBe(LeadStatus::Won);
});

it('refuses a loss reason outside the shared vocabulary', function () {
    $this->actingAs($this->rep)
        ->post(route('company.crm.trials.mark-lost', $this->trial), [
            'lost_reason' => 'they_ghosted_us',
        ])
        ->assertSessionHasErrors('lost_reason');

    expect($this->trial->fresh()->lost_at)->toBeNull();
});

it('marks a trial lost through the endpoint', function () {
    $this->actingAs($this->rep)
        ->post(route('company.crm.trials.mark-lost', $this->trial), [
            'lost_reason' => LostReason::Price->value,
            'lost_competitor' => 'In-house build',
        ])
        ->assertSessionHasNoErrors();

    expect($this->trial->fresh()->lost_reason)->toBe(LostReason::Price->value);
});

it('stops offering the mark-lost action once a trial is already lost', function () {
    $page = app(TrialPageService::class);

    expect($page->payload($this->trial, $this->rep)['can']['markLost'])->toBeTrue();

    app(TrialService::class)->markLost($this->trial, LostReason::Price, null, $this->rep);

    expect($page->payload($this->trial->fresh(), $this->rep)['can']['markLost'])->toBeFalse();
});

it('counts lost trials apart from closed ones on the list', function () {
    app(TrialService::class)->markLost($this->trial, LostReason::Unresponsive, null, $this->rep);

    $payload = app(TrialPageService::class)->listPayload($this->rep);

    // Every lost trial is closed; the interesting figure is how many we lost.
    expect($payload['counts']['lost'])->toBe(1)
        ->and($payload['counts']['closed'])->toBeGreaterThanOrEqual(1);
});

it('offers the same loss vocabulary the deal uses', function () {
    $reasons = collect(app(TrialPageService::class)->listPayload($this->rep)['lostReasons'])
        ->pluck('value')
        ->all();

    expect($reasons)->toBe(array_column(LostReason::cases(), 'value'));
});
