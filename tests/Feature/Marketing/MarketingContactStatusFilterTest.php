<?php

use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingContactImport;
use App\Models\MarketingContactImportRow;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

/**
 * The contacts list can be filtered by where a person is in the marketing
 * lifecycle, not just by whether they opted out.
 *
 * Two rules are load-bearing and both are asserted here:
 *
 *  - a contact's campaign status is whatever their *most recent* recipient row
 *    says, never a count of every row they appear in;
 *  - compliance beats campaign. Somebody who opted out after a message was
 *    delivered is opted out, and must not come back under "delivered" — if they
 *    did, this list would contradict the batch summary, which applies exactly
 *    the same precedence.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
    ]);
    grantMarketingPermissions($this->user);

    /*
     * A campaign needs an account, a template and an audience before it can
     * exist, and a recipient row needs a campaign. None of that is the subject
     * of these tests — it is the scaffolding a recipient row stands on.
     */
    $account = MarketingWhatsAppAccount::create([
        'provider' => 'meta',
        'business_account_id' => '1029384756',
        'phone_number_id' => '9876543210123',
        'display_phone_number' => '+91 9876543210',
        'verified_name' => 'OralCares WhatsApp',
        'access_token_encrypted' => Crypt::encryptString('test_token'),
        'status' => 'connected',
    ]);

    $template = MarketingWhatsAppTemplate::create([
        'whatsapp_account_id' => $account->id,
        'name' => 'oralcares_intro_demo',
        'language' => 'en',
        'category' => 'MARKETING',
        'status' => 'approved',
        'body' => 'Hello {{1}}',
        'variables' => ['1'],
    ]);

    $audience = MarketingAudience::create([
        'name' => 'Everyone',
        'rules' => [],
    ]);

    $this->accountId = $account->id;
    $this->templateId = $template->id;
    $this->audienceId = $audience->id;
});

/** One campaign, built on the scaffolding the suite set up. */
function aCampaign(string $name): MarketingCampaign
{
    return MarketingCampaign::create([
        'whatsapp_account_id' => test()->accountId,
        'audience_id' => test()->audienceId,
        'template_id' => test()->templateId,
        'name' => $name,
        'status' => 'completed',
    ]);
}

/** A contact plus, optionally, one recipient row per campaign status given. */
function contactWithHistory(string $name, array $statuses = [], array $attributes = []): MarketingContact
{
    static $seq = 0;
    $seq++;

    $phone = '90000'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

    $contact = MarketingContact::create(array_merge([
        'name' => $name,
        'phone' => '+91 '.$phone,
        'normalized_phone' => $phone,
        'marketing_status' => 'active',
    ], $attributes));

    /*
     * The importer writes a membership row alongside the contact, and the batch
     * summary counts membership rather than `import_id` — so a fixture that
     * only sets `import_id` would describe a state the importer never produces.
     */
    if (! empty($attributes['import_id'])) {
        MarketingContactImportRow::create([
            'import_id' => $attributes['import_id'],
            'contact_id' => $contact->id,
            'outcome' => MarketingContactImportRow::OUTCOME_CREATED,
            'name' => $contact->name,
            'phone' => $contact->phone,
            'normalized_phone' => $contact->normalized_phone,
        ]);
    }

    foreach ($statuses as $index => $status) {
        $recipient = MarketingCampaignRecipient::create([
            'campaign_id' => aCampaign($name.' campaign '.$index)->id,
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'normalized_phone' => $contact->normalized_phone,
            'status' => $status,
        ]);

        /*
         * Written after the insert on purpose: Eloquent stamps `updated_at`
         * itself during `create()`, so passing it in the attributes has no
         * effect and every row would land on the same second — which is the
         * ambiguity the id tiebreaker exists to survive, not one to rely on
         * here. Each step is deliberately a minute newer than the last.
         */
        $recipient->forceFill(['updated_at' => now()->addMinutes($index)])->saveQuietly();
    }

    return $contact;
}

test('a contact never included in a campaign counts as never contacted', function () {
    contactWithHistory('Never Touched');
    contactWithHistory('Was Sent To', ['sent']);

    $never = MarketingContact::query()->filterStatus('never_contacted')->pluck('name');

    expect($never)->toHaveCount(1)
        ->and($never->first())->toBe('Never Touched');
});

test('campaign status comes from the latest recipient row, not every row', function () {
    /*
     * The whole point: this contact appears in three campaigns. Counting rows
     * would place them in three buckets at once. They are one person, and they
     * are currently "replied".
     */
    contactWithHistory('Moved Through', ['sent', 'delivered', 'replied']);

    expect(MarketingContact::query()->filterStatus('replied')->count())->toBe(1)
        ->and(MarketingContact::query()->filterStatus('sent')->count())->toBe(0)
        ->and(MarketingContact::query()->filterStatus('delivered')->count())->toBe(0);
});

test('the annotated column agrees with the filter', function () {
    contactWithHistory('Moved Through', ['sent', 'delivered', 'read']);

    $row = MarketingContact::query()->withLastCampaignStatus()->first();

    expect($row->last_campaign_status)->toBe('read')
        ->and(MarketingContact::query()->filterStatus('read')->count())->toBe(1);
});

test('opting out beats whatever the last campaign said', function () {
    contactWithHistory('Delivered Then Left', ['delivered'], [
        'marketing_status' => 'opted_out',
    ]);

    expect(MarketingContact::query()->filterStatus('delivered')->count())->toBe(0)
        ->and(MarketingContact::query()->filterStatus('opted_out')->count())->toBe(1);
});

test('a suppressed contact is likewise excluded from campaign statuses', function () {
    contactWithHistory('Delivered Then Suppressed', ['delivered'], [
        'marketing_status' => 'suppressed',
    ]);

    expect(MarketingContact::query()->filterStatus('delivered')->count())->toBe(0)
        ->and(MarketingContact::query()->filterStatus('suppressed')->count())->toBe(1);
});

test('queued covers pending too, because both mean it has not gone out', function () {
    contactWithHistory('Still Pending', ['pending']);
    contactWithHistory('Actually Queued', ['queued']);

    expect(MarketingContact::query()->filterStatus('queued')->count())->toBe(2);
});

test('an unrecognised status narrows nothing rather than returning an empty list', function () {
    contactWithHistory('Someone', ['sent']);

    expect(MarketingContact::query()->filterStatus('not-a-real-status')->count())->toBe(1);
});

test('contacts stay traceable to the batch that created them', function () {
    $batch = MarketingContactImport::create([
        'original_filename' => 'vadodara-clinics.csv',
        'batch_name' => 'Vadodara Dental Clinics — August 2026',
        'status' => 'completed',
        'total_rows' => 2,
        'imported_count' => 2,
    ]);

    contactWithHistory('From The Batch', [], ['import_id' => $batch->id]);
    contactWithHistory('Added By Hand');

    $fromBatch = MarketingContact::query()->filterImportBatch($batch->id)->get();

    expect($fromBatch)->toHaveCount(1)
        ->and($fromBatch->first()->name)->toBe('From The Batch')
        ->and($fromBatch->first()->importBatch->batch_name)->toBe('Vadodara Dental Clinics — August 2026');
});

test('the batch summary counts each contact once, under its current state', function () {
    $batch = MarketingContactImport::create([
        'original_filename' => 'mixed.csv',
        'status' => 'completed',
        'total_rows' => 4,
        'imported_count' => 4,
    ]);

    contactWithHistory('Untouched', [], ['import_id' => $batch->id]);
    contactWithHistory('Progressed', ['sent', 'delivered'], ['import_id' => $batch->id]);
    contactWithHistory('Answered', ['sent', 'replied'], ['import_id' => $batch->id]);
    contactWithHistory('Left', ['delivered'], ['import_id' => $batch->id, 'marketing_status' => 'opted_out']);

    $summary = $batch->fresh()->marketingStatusSummary();

    expect($summary['total'])->toBe(4)
        ->and($summary['never_contacted'])->toBe(1)
        ->and($summary['delivered'])->toBe(1)
        ->and($summary['replied'])->toBe(1)
        ->and($summary['opted_out'])->toBe(1)
        // "Progressed" was sent before it was delivered; it must not be in both.
        ->and($summary['sent'])->toBe(0);

    $counted = $summary['never_contacted'] + $summary['queued'] + $summary['sent']
        + $summary['delivered'] + $summary['read'] + $summary['replied']
        + $summary['failed'] + $summary['opted_out'] + $summary['suppressed'];

    expect($counted)->toBe($summary['total']);
});

test('the lifecycle counts describe the list they sit above', function () {
    /*
     * The guard against the defect this whole arrangement exists to prevent:
     * a headline figure that disagrees with the list under it. With a batch
     * selected, the strip must count only that batch — and the counts must
     * still add up to the number of contacts on screen.
     */
    $batch = MarketingContactImport::create([
        'original_filename' => 'one.csv',
        'status' => 'completed',
        'total_rows' => 3,
        'imported_count' => 3,
    ]);

    contactWithHistory('In Batch, Quiet', [], ['import_id' => $batch->id]);
    contactWithHistory('In Batch, Delivered', ['delivered'], ['import_id' => $batch->id]);
    contactWithHistory('In Batch, Replied', ['replied'], ['import_id' => $batch->id]);

    // Elsewhere in the database, and therefore not this batch's business.
    contactWithHistory('Outside, Delivered', ['delivered']);
    contactWithHistory('Outside, Delivered Too', ['delivered']);

    $this->actingAs($this->user)
        ->get(route('company.marketing.contacts.index', ['import_batch' => $batch->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('contacts.total', 3)
            // The counts are deferred so the list can render first.
            ->missing('lifecycle')
            ->loadDeferredProps(function ($reload) {
                $lifecycle = $reload->toArray()['props']['lifecycle'];

                // `all` is the same population counted once more, not a bucket.
                $buckets = array_diff_key($lifecycle, ['all' => null]);

                expect(array_sum($buckets))->toBe(3)
                    ->and($lifecycle['all'])->toBe(3)
                    // Two more contacts are "delivered" outside this batch; the
                    // strip must not count them.
                    ->and($lifecycle['delivered'])->toBe(1)
                    ->and($lifecycle['never_contacted'])->toBe(1)
                    ->and($lifecycle['replied'])->toBe(1);
            })
        );
});

test('the contacts page can be filtered by campaign status over http', function () {
    contactWithHistory('Replied Person', ['replied']);
    contactWithHistory('Quiet Person', ['sent']);

    $this->actingAs($this->user)
        ->get(route('company.marketing.contacts.index', ['status' => 'replied']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Marketing/Contacts/Index')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', 'Replied Person')
        );
});
