<?php

use App\Http\Controllers\Company\Marketing\MarketingContactController;
use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingContactImport;
use App\Models\MarketingContactImportRow;
use App\Models\MarketingTag;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\AudienceQueryService;
use App\Services\Marketing\CampaignPreparationService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Segments as dynamic targeting definitions, verified against the exact
 * scenarios the rebuild was asked to prove: one rule at a time, several
 * combined, a large matching set, the same contact under two Segments, and —
 * the one that actually matters for data integrity — a Segment changing
 * after a campaign already launched must never touch that campaign's
 * recipient history.
 */
uses(RefreshDatabase::class);

function segmentContact(array $overrides = []): MarketingContact
{
    static $seq = 0;
    $seq++;

    return MarketingContact::create(array_merge([
        'name' => 'Dr. Segment Test '.$seq,
        'phone' => '+91 90000'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
        'normalized_phone' => '90000'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
        'marketing_status' => 'active',
    ], $overrides));
}

test('a state-only segment matches every contact in that state and no other', function () {
    segmentContact(['state' => 'Gujarat']);
    segmentContact(['state' => 'Gujarat']);
    segmentContact(['state' => 'Rajasthan']);

    $service = app(AudienceQueryService::class);

    expect($service->matchCount(['state' => 'Gujarat']))->toBe(2);
});

test('state, city and area narrow together as a hierarchy', function () {
    segmentContact(['state' => 'Gujarat', 'city' => 'Vadodara', 'area' => 'Alkapuri']);
    segmentContact(['state' => 'Gujarat', 'city' => 'Vadodara', 'area' => 'Karelibaug']);
    segmentContact(['state' => 'Gujarat', 'city' => 'Ahmedabad', 'area' => 'Alkapuri']);

    $service = app(AudienceQueryService::class);

    expect($service->matchCount(['state' => 'Gujarat']))->toBe(3)
        ->and($service->matchCount(['state' => 'Gujarat', 'city' => 'Vadodara']))->toBe(2)
        ->and($service->matchCount(['state' => 'Gujarat', 'city' => 'Vadodara', 'area' => 'Alkapuri']))->toBe(1);
});

test('a tag-based segment matches contacts carrying the tag', function () {
    $clinic = MarketingTag::create(['name' => 'Clinic', 'slug' => 'clinic']);
    $hospital = MarketingTag::create(['name' => 'Hospital', 'slug' => 'hospital']);

    $tagged = segmentContact();
    $tagged->tags()->attach($clinic);

    $other = segmentContact();
    $other->tags()->attach($hospital);

    $service = app(AudienceQueryService::class);

    expect($service->matchCount(['tags' => ['Clinic']]))->toBe(1);
});

test('an import batch segment matches only contacts that batch brought in', function () {
    $batch = MarketingContactImport::create([
        'batch_name' => 'August Gujarat', 'original_filename' => 'aug.csv',
        'status' => 'completed', 'total_rows' => 1, 'imported_count' => 1,
    ]);

    $inBatch = segmentContact(['import_id' => $batch->id]);
    MarketingContactImportRow::create([
        'import_id' => $batch->id, 'contact_id' => $inBatch->id, 'outcome' => 'created',
        'name' => $inBatch->name, 'phone' => $inBatch->phone, 'normalized_phone' => $inBatch->normalized_phone,
    ]);

    segmentContact(); // not in the batch

    $service = app(AudienceQueryService::class);

    expect($service->matchCount(['import_batch' => (string) $batch->id]))->toBe(1);
});

test('a lifecycle-based segment reuses the exact bucket Contacts filters by', function () {
    $account = MarketingWhatsAppAccount::create([
        'provider' => 'meta', 'business_account_id' => '1', 'phone_number_id' => '2',
        'display_phone_number' => '+91 90000 00000', 'verified_name' => 'Test',
        'access_token_encrypted' => Crypt::encryptString('t'), 'status' => 'connected',
    ]);
    $template = MarketingWhatsAppTemplate::create([
        'whatsapp_account_id' => $account->id, 'name' => 'demo', 'language' => 'en',
        'category' => 'MARKETING', 'status' => 'approved', 'body' => 'Hi', 'variables' => [],
    ]);
    $audience = MarketingAudience::create(['name' => 'A', 'rules' => []]);
    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $account->id, 'audience_id' => $audience->id,
        'template_id' => $template->id, 'name' => 'C', 'status' => 'draft',
    ]);

    $replied = segmentContact();
    MarketingCampaignRecipient::create([
        'campaign_id' => $campaign->id, 'contact_id' => $replied->id,
        'phone' => $replied->phone, 'normalized_phone' => $replied->normalized_phone, 'status' => 'replied',
    ]);

    $neverContacted = segmentContact();

    $service = app(AudienceQueryService::class);

    expect($service->matchCount(['status' => 'replied']))->toBe(1)
        ->and($service->matchCount(['status' => 'never_contacted']))->toBe(1);
});

test('multiple rules combine with AND, narrowing further than any rule alone', function () {
    $clinic = MarketingTag::create(['name' => 'Clinic', 'slug' => 'clinic']);

    $matchesBoth = segmentContact(['state' => 'Gujarat']);
    $matchesBoth->tags()->attach($clinic);

    $stateOnly = segmentContact(['state' => 'Gujarat']);

    $tagOnly = segmentContact(['state' => 'Rajasthan']);
    $tagOnly->tags()->attach($clinic);

    $service = app(AudienceQueryService::class);

    expect($service->matchCount(['state' => 'Gujarat', 'tags' => ['Clinic']]))->toBe(1);
});

test('a contact added or changed after a segment is defined is picked up immediately', function () {
    $service = app(AudienceQueryService::class);
    $rules = ['state' => 'Gujarat'];

    expect($service->matchCount($rules))->toBe(0);

    $contact = segmentContact(['state' => 'Rajasthan']);
    expect($service->matchCount($rules))->toBe(0);

    // The contact moves state — nothing about the Segment changed, only the data did.
    $contact->update(['state' => 'Gujarat']);
    expect($service->matchCount($rules))->toBe(1);

    segmentContact(['state' => 'Gujarat']);
    expect($service->matchCount($rules))->toBe(2);
});

test('the list page count and the detail page count agree', function () {
    segmentContact(['state' => 'Gujarat']);
    segmentContact(['state' => 'Gujarat']);
    segmentContact(['state' => 'Rajasthan']);

    $audience = MarketingAudience::create(['name' => 'Gujarat Segment', 'rules' => ['state' => 'Gujarat']]);
    $user = User::factory()->create(['user_type' => UserType::CompanyUser]);
    grantMarketingPermissions($user);

    $indexCount = null;
    $showCount = null;

    $this->actingAs($user)->get(route('company.marketing.audiences.index'))
        ->assertInertia(function ($page) use (&$indexCount) {
            $indexCount = $page->toArray()['props']['audiences']['data'][0]['match_count'];
        });

    $this->actingAs($user)->get(route('company.marketing.audiences.show', $audience->id))
        ->assertInertia(function ($page) use (&$showCount) {
            $showCount = $page->toArray()['props']['audience']['match_count'];
        });

    expect($indexCount)->toBe(2)->and($showCount)->toBe(2);
});

test('one contact can match more than one segment at once', function () {
    $clinic = MarketingTag::create(['name' => 'Clinic', 'slug' => 'clinic']);

    $contact = segmentContact(['state' => 'Gujarat']);
    $contact->tags()->attach($clinic);

    $service = app(AudienceQueryService::class);

    expect($service->matchCount(['state' => 'Gujarat']))->toBe(1)
        ->and($service->matchCount(['tags' => ['Clinic']]))->toBe(1);
});

test('a large matching set is counted without loading contacts into PHP', function () {
    for ($i = 0; $i < 250; $i++) {
        segmentContact(['state' => 'Gujarat']);
    }

    $service = app(AudienceQueryService::class);

    DB::enableQueryLog();
    $count = $service->matchCount(['state' => 'Gujarat']);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($count)->toBe(250)
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->not->toContain('select * from');
});

test('an opted-out contact still matches the segment but is excluded at campaign eligibility', function () {
    $optedOut = segmentContact(['state' => 'Gujarat', 'marketing_status' => 'opted_out']);
    segmentContact(['state' => 'Gujarat']);

    $audience = MarketingAudience::create(['name' => 'Gujarat', 'rules' => ['state' => 'Gujarat']]);
    $service = app(AudienceQueryService::class);

    // The Segment's own count is a targeting question — it does not hide the opted-out contact.
    expect($service->matchCount($audience->rules))->toBe(2);

    // Campaign eligibility is a different question, and excludes them.
    $eligible = $service->resolveEligibleContactsQuery($audience)->pluck('id');
    expect($eligible)->not->toContain($optedOut->id)->toHaveCount(1);
});

test('campaign recipients stay exactly as they were even after the segment changes', function () {
    Queue::fake();

    $account = MarketingWhatsAppAccount::create([
        'provider' => 'meta', 'business_account_id' => '1', 'phone_number_id' => '2',
        'display_phone_number' => '+91 90000 00000', 'verified_name' => 'Test',
        'access_token_encrypted' => Crypt::encryptString('t'), 'status' => 'connected',
    ]);
    $template = MarketingWhatsAppTemplate::create([
        'whatsapp_account_id' => $account->id, 'name' => 'demo', 'language' => 'en',
        'category' => 'MARKETING', 'status' => 'approved', 'body' => 'Hi', 'variables' => [],
    ]);

    $audience = MarketingAudience::create(['name' => 'Gujarat', 'rules' => ['state' => 'Gujarat']]);
    $original = segmentContact(['state' => 'Gujarat']);

    $campaign = MarketingCampaign::create([
        'whatsapp_account_id' => $account->id, 'audience_id' => $audience->id,
        'template_id' => $template->id, 'name' => 'Gujarat Outreach', 'status' => 'draft',
    ]);

    app(CampaignPreparationService::class)->prepareAndLaunchCampaign($campaign);

    expect(MarketingCampaignRecipient::where('campaign_id', $campaign->id)->count())->toBe(1);
    $this->assertDatabaseHas('marketing_campaign_recipients', [
        'campaign_id' => $campaign->id, 'contact_id' => $original->id,
    ]);

    /*
     * Now the Segment's definition changes entirely — a new contact matches
     * it, the original contact no longer would. Neither must move the
     * already-launched campaign's recipient snapshot at all.
     */
    $newlyMatching = segmentContact(['state' => 'Gujarat']);
    $audience->update(['rules' => ['state' => 'Rajasthan']]);

    expect(MarketingCampaignRecipient::where('campaign_id', $campaign->id)->count())->toBe(1);
    $this->assertDatabaseHas('marketing_campaign_recipients', [
        'campaign_id' => $campaign->id, 'contact_id' => $original->id,
    ]);
    $this->assertDatabaseMissing('marketing_campaign_recipients', [
        'campaign_id' => $campaign->id, 'contact_id' => $newlyMatching->id,
    ]);
});

test('a date-range segment agrees with Contacts for an organisation on a non-default timezone', function () {
    $org = Organization::factory()->create(['timezone' => 'America/New_York']);
    $user = User::factory()->create(['organization_id' => $org->id, 'user_type' => UserType::CompanyUser]);
    grantMarketingPermissions($user);

    /*
     * 23:30 UTC on the 24th is still the 24th in New York but already the
     * 25th in the Asia/Kolkata default — exactly the boundary a silently
     * unresolved timezone would get wrong.
     */
    $contact = segmentContact(['organization_id' => $org->id]);
    $contact->forceFill(['created_at' => Carbon::parse('2026-08-24 23:30:00', 'UTC')])->saveQuietly();

    $audience = MarketingAudience::create([
        'organization_id' => $org->id,
        'name' => 'Added on the 24th',
        'rules' => ['imported_from' => '2026-08-24', 'imported_to' => '2026-08-24'],
    ]);

    $contactsRequest = Request::create('/company/marketing/contacts', 'GET', [
        'imported_from' => '2026-08-24', 'imported_to' => '2026-08-24',
    ]);
    $contactsRequest->setUserResolver(fn () => $user);
    $contactsTotal = app(MarketingContactController::class)->index($contactsRequest)
        ->toResponse($contactsRequest)
        ->getOriginalContent()['page']['props']['contacts']['total'];

    $service = app(AudienceQueryService::class);
    $timezone = MarketingContact::resolveBusinessTimezone($org);

    expect($timezone)->toBe('America/New_York')
        ->and($contactsTotal)->toBe(1)
        ->and($service->matchCount($audience->rules, $org->id, $timezone))->toBe($contactsTotal)
        ->and($service->resolveEligibleContactsQuery($audience->fresh())->pluck('id'))->toContain($contact->id);
});
