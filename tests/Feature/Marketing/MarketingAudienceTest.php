<?php

use App\Models\MarketingAudience;
use App\Models\MarketingContact;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Segments as reusable targeting definitions — the CRUD surface.
 *
 * A Segment's `rules` are the same named fields Contacts filters by (state,
 * city, area, organization, tags, import_batch, imported_from/to, status),
 * not a free-form field/operator/value language. See
 * `SegmentMatchingTest` for whether those rules actually resolve the right
 * contacts; this file is about the pages and the record around them.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
    ]);
    grantMarketingPermissions($this->user);
});

test('user can view the segments list page', function () {
    MarketingAudience::create([
        'name' => 'Vadodara Dentists',
        'description' => 'Target dentists in Vadodara',
        'rules' => ['city' => 'Vadodara'],
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->get(route('company.marketing.audiences.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Marketing/Audiences/Index')
            ->has('audiences.data', 1)
            ->where('audiences.data.0.name', 'Vadodara Dentists')
            ->has('audiences.data.0.match_count')
        );
});

test('user can create a new segment with rules', function () {
    $response = $this->actingAs($this->user)->post(route('company.marketing.audiences.store'), [
        'name' => 'Gujarat Clinics',
        'description' => 'All dental clinics in Gujarat',
        'status' => 'active',
        'rules' => [
            'state' => 'Gujarat',
            'tags' => ['Dentist'],
        ],
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('marketing_audiences', [
        'name' => 'Gujarat Clinics',
        'description' => 'All dental clinics in Gujarat',
        'status' => 'active',
    ]);

    $audience = MarketingAudience::where('name', 'Gujarat Clinics')->firstOrFail();
    expect($audience->rules)->toBe(['state' => 'Gujarat', 'tags' => ['Dentist']]);
});

test('saving a rule set strips empty and "all" values before storing', function () {
    $this->actingAs($this->user)->post(route('company.marketing.audiences.store'), [
        'name' => 'Loosely Filled',
        'status' => 'active',
        'rules' => [
            'state' => 'Gujarat',
            'city' => 'all',
            'area' => '',
            'tags' => [],
        ],
    ]);

    $audience = MarketingAudience::where('name', 'Loosely Filled')->firstOrFail();

    expect($audience->rules)->toBe(['state' => 'Gujarat']);
});

test('the segment detail page shows the current matching count and usage', function () {
    MarketingContact::create([
        'name' => 'Dr. Rahul Patel', 'phone' => '9876500001', 'normalized_phone' => '9876500001',
        'state' => 'Gujarat', 'marketing_status' => 'active',
    ]);
    MarketingContact::create([
        'name' => 'Dr. Amit Shah', 'phone' => '9876500002', 'normalized_phone' => '9876500002',
        'state' => 'Gujarat', 'marketing_status' => 'active',
    ]);
    MarketingContact::create([
        'name' => 'Dr. Priya Mehta', 'phone' => '9876500003', 'normalized_phone' => '9876500003',
        'state' => 'Rajasthan', 'marketing_status' => 'active',
    ]);

    $audience = MarketingAudience::create([
        'name' => 'Gujarat Segment',
        'rules' => ['state' => 'Gujarat'],
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->get(route('company.marketing.audiences.show', $audience->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Marketing/Audiences/Show')
            ->where('audience.match_count', 2)
            ->where('audience.campaigns_count', 0)
            ->has('campaigns', 0)
            ->has('preview', 2)
        );
});

test('user can duplicate a segment without duplicating contacts', function () {
    $audience = MarketingAudience::create([
        'name' => 'Original Segment',
        'rules' => ['city' => 'Ahmedabad'],
        'status' => 'active',
    ]);

    $contactCountBefore = MarketingContact::count();

    $response = $this->actingAs($this->user)->post(route('company.marketing.audiences.duplicate', $audience->id));

    $response->assertRedirect(route('company.marketing.audiences.index'));

    $this->assertDatabaseHas('marketing_audiences', [
        'name' => 'Original Segment (Copy)',
        'status' => 'paused',
    ]);

    expect(MarketingContact::count())->toBe($contactCountBefore);
});

test('deleting a segment soft-deletes the definition without touching contacts', function () {
    $contact = MarketingContact::create([
        'name' => 'Contact To Preserve', 'phone' => '9876543210', 'normalized_phone' => '9876543210',
    ]);

    $audience = MarketingAudience::create([
        'name' => 'Segment To Delete',
        'rules' => ['organization' => 'Preserve'],
    ]);

    $response = $this->actingAs($this->user)->delete(route('company.marketing.audiences.destroy', $audience->id));

    $response->assertRedirect(route('company.marketing.audiences.index'));

    $this->assertSoftDeleted('marketing_audiences', ['id' => $audience->id]);
    $this->assertDatabaseHas('marketing_contacts', ['id' => $contact->id]);
});

test('an unknown rule key is silently dropped rather than stored or erroring', function () {
    $response = $this->actingAs($this->user)->post(route('company.marketing.audiences.store'), [
        'name' => 'Defensive Segment',
        'status' => 'active',
        'rules' => [
            'state' => 'Gujarat',
            'sql_injection_attempt' => 'DROP TABLE marketing_contacts;--',
        ],
    ]);

    $response->assertRedirect();

    $audience = MarketingAudience::where('name', 'Defensive Segment')->firstOrFail();
    expect($audience->rules)->toBe(['state' => 'Gujarat']);
});

test('an invalid lifecycle status value is rejected by validation', function () {
    $response = $this->actingAs($this->user)->post(route('company.marketing.audiences.store'), [
        'name' => 'Bad Status Segment',
        'status' => 'active',
        'rules' => ['status' => 'not_a_real_status'],
    ]);

    $response->assertSessionHasErrors(['rules.status']);
});
