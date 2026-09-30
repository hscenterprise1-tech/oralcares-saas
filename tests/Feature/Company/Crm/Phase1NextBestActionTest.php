<?php

use App\ActivityType;
use App\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\OrganizationType;
use App\Services\CRM\NextBestActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 1 item 9 — the next action must mean something.
 *
 * A data-completeness prompt outranked every stage-aware branch and fired
 * whenever `healthcare_specialty` was blank, so twelve of fourteen leads in
 * the running app showed the identical sentence. Stage now drives the
 * recommendation, optional fields never produce one, and null is a real
 * "no priority action" state.
 */
function nba(Lead $lead): ?string
{
    return app(NextBestActionService::class)->determineNextAction($lead);
}

function sizedLead(array $attributes = []): Lead
{
    return Lead::factory()->create(array_merge([
        'expected_mrr' => 30000,
        'expected_users' => 8,
        'healthcare_specialty' => null,
        'organization_type' => null,
    ], $attributes));
}

it('gives different advice at different stages', function () {
    $recommendations = collect([
        LeadStatus::Contacted,
        LeadStatus::DemoScheduled,
        LeadStatus::DemoCompleted,
        LeadStatus::TrialActive,
        LeadStatus::Negotiation,
        LeadStatus::QuotationSent,
        LeadStatus::QuotationApproved,
        LeadStatus::PaymentReceived,
    ])->map(function (LeadStatus $status) {
        $lead = sizedLead(['status' => $status]);

        return nba($lead);
    });

    expect($recommendations->unique())->toHaveCount($recommendations->count())
        ->and($recommendations->filter())->toHaveCount($recommendations->count());
});

it('does not prompt for optional profile fields', function () {
    // The whole defect: a blank speciality used to override every stage.
    $lead = sizedLead([
        'status' => LeadStatus::Negotiation,
        'healthcare_specialty' => null,
        'organization_type' => null,
    ]);

    expect(nba($lead))->toBe('Agree terms and send the quotation.')
        ->and(nba($lead))->not->toContain('Organization Profile');
});

it('gives the same advice whether or not optional fields are filled', function () {
    $bare = sizedLead(['status' => LeadStatus::Contacted]);
    $enriched = sizedLead([
        'status' => LeadStatus::Contacted,
        'healthcare_specialty' => 'orthodontics',
        'organization_type' => OrganizationType::DentalClinic,
    ]);

    expect(nba($bare))->toBe(nba($enriched));
});

it('asks for size only while the lead is being qualified', function () {
    $new = Lead::factory()->create([
        'status' => LeadStatus::New, 'expected_mrr' => null, 'expected_users' => 0,
    ]);
    $negotiating = Lead::factory()->create([
        'status' => LeadStatus::Negotiation, 'expected_mrr' => null, 'expected_users' => 0,
    ]);

    expect(nba($new))->toContain('Qualify')
        ->and(nba($negotiating))->not->toContain('Qualify');
});

it('accepts either size signal as qualification', function () {
    $withUsers = Lead::factory()->create([
        'status' => LeadStatus::New, 'expected_mrr' => null, 'expected_users' => 12,
    ]);

    expect(nba($withUsers))->not->toContain('Qualify');
});

it('prioritises re-engagement over stage advice on a stale lead', function () {
    $lead = sizedLead(['status' => LeadStatus::Negotiation]);
    $lead->activities()->create([
        'type' => ActivityType::Note,
        'description' => 'old note',
    ]);
    Activity::query()->update(['created_at' => now()->subDays(30)]);

    expect(nba($lead->fresh()))->toContain('re-engage');
});

it('returns no action for a lost lead that already has a win-back', function () {
    $lead = sizedLead([
        'status' => LeadStatus::Lost,
        'winback_scheduled_at' => now()->addMonth()->toDateString(),
    ]);

    expect(nba($lead))->toBeNull();
});

it('asks for a win-back on a lost lead that has none', function () {
    $lead = sizedLead(['status' => LeadStatus::Lost, 'winback_scheduled_at' => null]);

    expect(nba($lead))->toContain('win-back');
});

it('never throws for any status', function () {
    $lead = sizedLead([
        'healthcare_specialty' => 'general', 'organization_type' => OrganizationType::Hospital,
    ]);

    foreach (LeadStatus::cases() as $status) {
        $lead->status = $status;

        expect(fn () => nba($lead))->not->toThrow(Error::class);
    }
});

it('does not fabricate a lead score in the pipeline payload', function () {
    $source = file_get_contents(app_path('Services/LeadWorkspaceService.php'));

    // A lead with no score used to render as 75 — a measured-looking number
    // nobody measured.
    expect($source)->not->toContain('automated_score ?? 75')
        ->and($source)->not->toContain("next_best_action ?? 'Schedule Follow-up'")
        ->and($source)->not->toContain("next_best_action ?? 'Call Today'");
});
