<?php

use App\Models\Lead;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->provider()->create();

    $this->account = MarketingWhatsAppAccount::create([
        'organization_id' => $this->org->id,
        'provider' => 'meta',
        'status' => 'connected',
    ]);

    $this->repA = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    grantMarketingPermissions($this->repA);

    $this->repB = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    grantMarketingPermissions($this->repB);

    $this->masterAdmin = User::factory()->masterAdmin()->create([
        'organization_id' => $this->org->id,
    ]);

    $this->assignedToA = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'phone' => '9111111111',
        'normalized_phone' => '9111111111',
        'assigned_user_id' => $this->repA->id,
        'status' => 'open',
    ]);

    $this->assignedToB = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'phone' => '9222222222',
        'normalized_phone' => '9222222222',
        'assigned_user_id' => $this->repB->id,
        'status' => 'open',
    ]);

    $this->unassigned = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'phone' => '9333333333',
        'normalized_phone' => '9333333333',
        'assigned_user_id' => null,
        'status' => 'open',
    ]);
});

test('a rep sees only their own assigned conversations plus unassigned ones', function () {
    $response = $this->actingAs($this->repA)->get(route('company.marketing.conversations.index'));

    $ids = collect($response->inertiaProps('conversations.data'))->pluck('id');

    expect($ids)->toContain($this->assignedToA->id)
        ->toContain($this->unassigned->id)
        ->not->toContain($this->assignedToB->id);
});

test('master admin sees every conversation regardless of assignment', function () {
    $response = $this->actingAs($this->masterAdmin)->get(route('company.marketing.conversations.index'));

    $ids = collect($response->inertiaProps('conversations.data'))->pluck('id');

    expect($ids)->toContain($this->assignedToA->id)
        ->toContain($this->assignedToB->id)
        ->toContain($this->unassigned->id);
});

test('a rep cannot open a conversation assigned to someone else via reply', function () {
    $response = $this->actingAs($this->repA)->post(
        route('company.marketing.conversations.reply', $this->assignedToB->id),
        ['message' => 'hello']
    );

    $response->assertNotFound();
});

test('a rep can claim an unassigned conversation for themselves', function () {
    $response = $this->actingAs($this->repA)->post(
        route('company.marketing.conversations.assign', $this->unassigned->id),
        ['assigned_user_id' => $this->repA->id]
    );

    $response->assertRedirect();
    expect($this->unassigned->fresh()->assigned_user_id)->toBe($this->repA->id);
});

test('a rep cannot assign a conversation to a colleague', function () {
    $response = $this->actingAs($this->repA)->post(
        route('company.marketing.conversations.assign', $this->unassigned->id),
        ['assigned_user_id' => $this->repB->id]
    );

    $response->assertForbidden();
    expect($this->unassigned->fresh()->assigned_user_id)->toBeNull();
});

test('master admin can assign a conversation to any rep', function () {
    $response = $this->actingAs($this->masterAdmin)->post(
        route('company.marketing.conversations.assign', $this->unassigned->id),
        ['assigned_user_id' => $this->repB->id]
    );

    $response->assertRedirect();
    expect($this->unassigned->fresh()->assigned_user_id)->toBe($this->repB->id);
});

test('master admin can unassign a conversation', function () {
    $response = $this->actingAs($this->masterAdmin)->post(
        route('company.marketing.conversations.assign', $this->assignedToA->id),
        ['assigned_user_id' => null]
    );

    $response->assertRedirect();
    expect($this->assignedToA->fresh()->assigned_user_id)->toBeNull();
});

/*
 * Visibility follows the deal, not only the thread.
 *
 * Assignment on the conversation was the only rule, so an unclaimed thread
 * belonging to somebody else's deal was visible to every rep in the company.
 */
test('a rep does not see an unclaimed thread that belongs to another rep\'s deal', function () {
    $leadOfB = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->repB->id,
    ]);

    $unclaimedButBs = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'phone' => '9444444444',
        'normalized_phone' => '9444444444',
        'assigned_user_id' => null,
        'crm_lead_id' => $leadOfB->id,
        'status' => 'open',
    ]);

    $ids = collect($this->actingAs($this->repA)
        ->get(route('company.marketing.conversations.index'))
        ->inertiaProps('conversations.data'))->pluck('id');

    expect($ids)->not->toContain($unclaimedButBs->id);
});

test('a rep does see an unclaimed thread on their own deal', function () {
    $leadOfA = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->repA->id,
    ]);

    $unclaimedButAs = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $this->account->id,
        'phone' => '9555555555',
        'normalized_phone' => '9555555555',
        'assigned_user_id' => null,
        'crm_lead_id' => $leadOfA->id,
        'status' => 'open',
    ]);

    $ids = collect($this->actingAs($this->repA)
        ->get(route('company.marketing.conversations.index'))
        ->inertiaProps('conversations.data'))->pluck('id');

    expect($ids)->toContain($unclaimedButAs->id);
});

test('a genuinely unclaimed thread with no deal stays visible so somebody can pick it up', function () {
    // Hiding these would mean an inbound message sits unread until an admin
    // happens to look, which is worse than a rep seeing one thread too many.
    $ids = collect($this->actingAs($this->repA)
        ->get(route('company.marketing.conversations.index'))
        ->inertiaProps('conversations.data'))->pluck('id');

    expect($ids)->toContain($this->unassigned->id);
});

test('the inbox can be narrowed to one line', function () {
    $supportLine = MarketingWhatsAppAccount::create([
        'organization_id' => $this->org->id,
        'provider' => 'meta',
        'status' => 'connected',
        'purpose' => 'support',
    ]);

    $onSupport = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $supportLine->id,
        'phone' => '9666666666',
        'normalized_phone' => '9666666666',
        'status' => 'open',
    ]);

    $ids = collect($this->actingAs($this->masterAdmin)
        ->get(route('company.marketing.conversations.index', ['purpose' => 'support']))
        ->inertiaProps('conversations.data'))->pluck('id');

    expect($ids)->toContain($onSupport->id)
        ->not->toContain($this->assignedToA->id);
});

test('every line is offered as a filter, named the way a person would name it', function () {
    // The first version of this control listed *purposes*, so two marketing
    // numbers produced one option and the filter hid itself — exactly when
    // somebody would want it. Two numbers are two numbers.
    $second = MarketingWhatsAppAccount::create([
        'organization_id' => $this->org->id,
        'provider' => 'meta',
        'status' => 'connected',
        'purpose' => 'marketing',
        'label' => 'Second campaign line',
    ]);

    $options = collect($this->actingAs($this->masterAdmin)
        ->get(route('company.marketing.conversations.index'))
        ->inertiaProps('lineOptions'));

    expect($options->pluck('value'))
        ->toContain((string) $this->account->id)
        ->toContain((string) $second->id)
        ->and($options->firstWhere('value', (string) $second->id)['label'])
        ->toBe('Second campaign line');
});

test('the inbox can be narrowed to one specific number', function () {
    $otherLine = MarketingWhatsAppAccount::create([
        'organization_id' => $this->org->id,
        'provider' => 'meta',
        'status' => 'connected',
        'purpose' => 'marketing',
        'label' => 'Other line',
    ]);

    $onOtherLine = MarketingWhatsAppConversation::create([
        'organization_id' => $this->org->id,
        'whatsapp_account_id' => $otherLine->id,
        'phone' => '9777777777',
        'normalized_phone' => '9777777777',
        'status' => 'open',
    ]);

    $ids = collect($this->actingAs($this->masterAdmin)
        ->get(route('company.marketing.conversations.index', ['account' => $otherLine->id]))
        ->inertiaProps('conversations.data'))->pluck('id');

    expect($ids)->toContain($onOtherLine->id)
        ->not->toContain($this->assignedToA->id);
});
