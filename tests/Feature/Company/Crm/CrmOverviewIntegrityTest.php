<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\CRM\DealStageService;
use App\Services\CrmMetricsService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CRM Overview — the figures it prints have to be answerable.
 *
 * Three defects this pins down, all found on real data:
 *
 *  1. `probability_to_close` was fillable but nothing ever wrote it, so every
 *     open deal sat at 0 and the weighted forecast was structurally ₹0 no
 *     matter how much pipeline existed.
 *  2. The bottleneck stage was picked across *all* stages, so a closed-lost
 *     deal's 27 days made "Lost" the pipeline's bottleneck — a finished deal
 *     is not a deal that is stuck.
 *  3. The attention cards deep-linked to a legacy redirect and to the
 *     operations page Phase 3 retired, rather than to the Deals cut that
 *     shows exactly the rows the figure counted.
 */
function overviewAdmin(): User
{
    return User::factory()->create(['user_type' => UserType::MasterAdmin]);
}

function overviewLead(string $status, float $mrr = 100000): Lead
{
    return Lead::factory()->create([
        'status' => $status,
        'expected_mrr' => $mrr,
        'expected_arr' => $mrr * 12,
    ]);
}

it('derives close probability from the stage when a deal is created', function () {
    $lead = overviewLead(LeadStatus::Contacted->value);

    expect($lead->fresh()->probability_to_close)
        ->toBe(DealStageService::PROBABILITY['contacted']);
});

it('moves close probability with the stage', function () {
    $lead = overviewLead(LeadStatus::New->value);

    expect($lead->fresh()->probability_to_close)->toBe(DealStageService::PROBABILITY['new']);

    $lead->update(['status' => LeadStatus::QuotationSent->value]);

    expect($lead->fresh()->probability_to_close)
        ->toBe(DealStageService::PROBABILITY['quotation_sent']);
});

it('treats the terminal stages as certainties rather than forecasts', function () {
    expect(DealStageService::PROBABILITY['won'])->toBe(100)
        ->and(DealStageService::PROBABILITY['lost'])->toBe(0);
});

it('produces a weighted forecast that is neither zero nor the raw pipeline', function () {
    $admin = overviewAdmin();

    overviewLead(LeadStatus::Contacted->value, 100000);
    overviewLead(LeadStatus::QuotationSent->value, 100000);

    $kpis = app(CrmMetricsService::class)->kpis($admin);

    expect($kpis['active_pipeline_value'])->toBeGreaterThan(0)
        ->and($kpis['weighted_pipeline_value'])->toBeGreaterThan(0)
        ->and($kpis['weighted_pipeline_value'])->toBeLessThan($kpis['active_pipeline_value']);
});

it('weights each stage by exactly that stage probability', function () {
    $admin = overviewAdmin();

    overviewLead(LeadStatus::QuotationSent->value, 100000);

    $stage = collect(app(CrmMetricsService::class)->forecast($admin)['by_stage'])
        ->firstWhere('stage', 'quotation_sent');

    $expectedShare = DealStageService::PROBABILITY['quotation_sent'] / 100;

    expect(round($stage['weighted_value'], 2))
        ->toBe(round($stage['expected_value'] * $expectedShare, 2));
});

it('never reports a terminal stage as the pipeline bottleneck', function () {
    $admin = overviewAdmin();

    // A lost deal that sat far longer than anything still open.
    overviewLead(LeadStatus::Lost->value)->update([
        'stage_entered_at' => now()->subDays(400),
        'stage_durations' => ['lost' => 400],
    ]);
    overviewLead(LeadStatus::Contacted->value)->update([
        'stage_entered_at' => now()->subDays(5),
        'stage_durations' => ['contacted' => 5],
    ]);

    $bottleneck = app(CrmMetricsService::class)->stageVelocity($admin)['bottleneck_stage'];

    if ($bottleneck !== null) {
        expect($bottleneck['stage'])->not->toBeIn([
            LeadStatus::Won->value,
            LeadStatus::Lost->value,
        ]);
    }
});

it('sends each attention card to the Deals cut that shows the rows it counted', function () {
    $admin = overviewAdmin();

    $items = collect(app(CrmMetricsService::class)->attention($admin)['items'])
        ->keyBy('key');

    expect($items['unassigned_leads']['link'])->toContain('/company/crm/deals')
        ->and($items['unassigned_leads']['link'])->toContain('unassigned_only')
        ->and($items['stale_leads']['link'])->toContain('/company/crm/deals')
        ->and($items['stale_leads']['link'])->toContain('stalled_only');
});

it('no longer points the Overview at the retired operations page', function () {
    $admin = overviewAdmin();

    $links = collect(app(CrmMetricsService::class)->attention($admin)['items'])
        ->pluck('link')
        ->implode(' ');

    expect($links)->not->toContain('/company/crm/operations');
});

it('lets an explicitly supplied probability win over the stage default', function () {
    $lead = Lead::factory()->create([
        'status' => LeadStatus::Negotiation->value,
        'expected_mrr' => 50000,
        'expected_arr' => 600000,
        'probability_to_close' => 40,
    ]);

    // 40 was a judgement the rep made; 'negotiation' would otherwise say 65.
    expect($lead->fresh()->probability_to_close)->toBe(40)
        ->and(DealStageService::PROBABILITY['negotiation'])->not->toBe(40);
});

it('resumes deriving from the stage once the deal moves again', function () {
    $lead = Lead::factory()->create([
        'status' => LeadStatus::Negotiation->value,
        'probability_to_close' => 40,
    ]);

    $lead->update(['status' => LeadStatus::QuotationApproved->value]);

    expect($lead->fresh()->probability_to_close)
        ->toBe(DealStageService::PROBABILITY['quotation_approved']);
});
