<?php

use App\LeadStatus;
use App\Models\CompanyQuotation;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\CRM\DealPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Deal 360's first paint.
 *
 * The page carried 91% of its bytes in six deferred groups. Each one landed at
 * its own moment and replaced a fixed-height placeholder with content of an
 * unrelated height, so the page visibly settled six times before it could be
 * read — and the stage stepper in the header rendered empty until one of those
 * waves arrived.
 *
 * These tests hold the shape that fixed it: the things a rep reads and acts on
 * are in the document, the audit tail is not, and the commercial block asks the
 * database for its quotations exactly once.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create(['organization_id' => $this->org->id]);
});

function cockpitLead(LeadStatus $status): Lead
{
    return Lead::factory()->create([
        'organization_id' => test()->org->id,
        'status' => $status,
        'stage_entered_at' => now(),
    ]);
}

it('carries the whole workspace in the document', function () {
    $lead = cockpitLead(LeadStatus::Contacted);

    $this->actingAs($this->admin)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Leads/Show')
            /* Identity and capacity. */
            ->has('deal.company_name')
            ->has('deal.specialties')
            ->has('deal.branches')
            ->has('deal.facility')
            /* The stage stepper's own data — it rendered empty without this. */
            ->has('now.stage_control.transitions')
            ->has('now.stage_control.lost_reasons')
            /* The focal card, whichever one this stage opens. */
            ->has('evaluation.demos')
            ->has('evaluation.trials')
            ->has('commercial.lifecycle.steps')
            ->has('commercial.conversion')
            /* The modals, which can be opened before anything else loads. */
            ->has('assignable_users')
            ->has('commercial.packages')
            ->has('outreach.wa_number')
        );
});

it('defers the audit tail and nothing else', function () {
    $lead = cockpitLead(LeadStatus::Contacted);

    $response = $this->actingAs($this->admin)->get(route('company.crm.leads.show', $lead));

    $deferred = $response->getOriginalContent()->getData()['page']['deferredProps'] ?? [];

    expect($deferred)->toHaveKeys(['history'])
        /* One group, so one follow-up request and one settling moment. */
        ->and($deferred)->toHaveCount(1)
        ->and($deferred['history'])->toContain('history')->toContain('files');
});

/*
 * The payload that used to be discarded.
 *
 * `customer_account` was deferred, cost a query on every view, and the page
 * rendered it as `{customer_account && null}`. `conversation` rendered a
 * WhatsApp thread, an email list and a call log beside a history panel that
 * already held all three in one chronological order.
 */
/*
 * `conversation` used to belong on this list too — the WhatsApp panel it fed
 * was retired in favour of the activity stream. It is back: a one-line
 * "WhatsApp sent — '...'" activity entry is not a substitute for reading the
 * actual exchange, so the dedicated thread renders again, and the payload
 * that feeds it is real.
 */
it('no longer ships the payloads nothing rendered', function () {
    $lead = cockpitLead(LeadStatus::Contacted);

    $this->actingAs($this->admin)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('customer_account')
            ->has('conversation.whatsapp')
            ->missing('profile')
        );
});

it('sends a joinable demo with the document', function () {
    $lead = cockpitLead(LeadStatus::DemoScheduled);

    Demo::factory()->create([
        'lead_id' => $lead->id,
        'scheduled_at' => now()->addHours(3),
        'meeting_link' => 'https://meet.google.com/abc-defg-hij',
        'meeting_platform' => 'google_meet',
    ]);

    $this->actingAs($this->admin)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(function ($page) {
            $demo = $page->toArray()['props']['evaluation']['demos'][0];

            expect($demo['meeting_link'])->toBe('https://meet.google.com/abc-defg-hij')
                ->and($demo['meeting_platform_label'])->not->toBeNull()
                /* The reminder trail the demo card renders. */
                ->and($demo['reminders'])->toHaveKeys(['rep_notified', 'customer_24h', 'customer_2h']);
        });
});

/*
 * `wa.me` takes an international number and nothing else. The header's WhatsApp
 * control used to POST an empty body to an endpoint requiring a message, so it
 * answered 422 on every press; it is an anchor now, and this is the number it
 * points at.
 */
it('composes a wa.me number from a ten-digit phone', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::Contacted,
        'phone' => '98332 23344',
    ]);

    $this->actingAs($this->admin)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('outreach.wa_number', '919833223344'));
});

it('leaves wa_number null when there is no usable phone', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'status' => LeadStatus::Contacted,
        'phone' => null,
    ]);

    $this->actingAs($this->admin)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('outreach.wa_number', null));
});

/*
 * The commercial block ran over the same rows four times: `lifecycle()` loaded
 * and reconciled them, this method loaded and reconciled them again, the invoice
 * lookup re-plucked their ids, and `conversion()` called
 * `hasApprovedPaidQuotation()`, which is a fourth load and a fourth
 * reconciliation. One load, one reconciliation, everything downstream taking
 * what it is given.
 */
it('reads the deal quotations once, however many there are', function () {
    $lead = cockpitLead(LeadStatus::QuotationSent);

    $count = function (int $quotations) use ($lead) {
        CompanyQuotation::factory()->count($quotations)->create([
            'lead_id' => $lead->id,
            'organization_id' => $this->org->id,
        ]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        app(DealPageService::class)->commercial($lead->fresh());

        return $queries;
    };

    $withOne = $count(1);
    $withEight = $count(7);

    /*
     * Constant, not merely small. A per-quotation cost would show up as a gap
     * here long before it showed up as a slow page.
     */
    expect($withEight)->toBe($withOne);
});
