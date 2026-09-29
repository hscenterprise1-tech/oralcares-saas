<?php

use App\Models\MarketingWhatsAppAccount;
use App\Models\Organization;
use App\WhatsAppNumberPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Several numbers were always supported; nothing recorded what any of them was
 * for. With four or five lines running at once, a phone number on its own does
 * not tell a reader whether a reply is a marketing enquiry or a clinic asking
 * for help — and nothing could route an outbound message to the right line.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();
});

function line(array $attributes = []): MarketingWhatsAppAccount
{
    return MarketingWhatsAppAccount::create(array_merge([
        'organization_id' => test()->org->id,
        'provider' => 'meta',
        'phone_number_id' => (string) random_int(100000, 999999),
        'display_phone_number' => '99900'.random_int(10000, 99999),
        'status' => 'connected',
    ], $attributes));
}

it('treats an existing number as the marketing line', function () {
    // Every number that existed before purposes did was in fact used for
    // marketing, so the default is the only honest backfill.
    expect(line()->purpose)->toBe(WhatsAppNumberPurpose::Marketing);
});

it('sends from the line matching the purpose', function () {
    line(['purpose' => 'marketing', 'label' => 'Campaign line']);
    $onboarding = line(['purpose' => 'onboarding', 'label' => 'Go-live line']);

    expect(MarketingWhatsAppAccount::forPurpose($this->org->id, WhatsAppNumberPurpose::Onboarding)?->id)
        ->toBe($onboarding->id);
});

it('falls back to marketing when no line has the purpose asked for', function () {
    $marketing = line(['purpose' => 'marketing']);

    expect(MarketingWhatsAppAccount::forPurpose($this->org->id, WhatsAppNumberPurpose::Support)?->id)
        ->toBe($marketing->id);
});

it('falls back to any connected line rather than sending nothing', function () {
    // A message that cannot resolve a sender never goes, and silently not
    // sending is the worst of the outcomes.
    $support = line(['purpose' => 'support']);

    expect(MarketingWhatsAppAccount::forPurpose($this->org->id, WhatsAppNumberPurpose::Sales)?->id)
        ->toBe($support->id);
});

it('never resolves a line belonging to another organization', function () {
    $other = Organization::factory()->create();
    MarketingWhatsAppAccount::create([
        'organization_id' => $other->id,
        'provider' => 'meta',
        'phone_number_id' => '777777',
        'display_phone_number' => '9990077777',
        'status' => 'connected',
        'purpose' => 'sales',
    ]);

    expect(MarketingWhatsAppAccount::forPurpose($this->org->id, WhatsAppNumberPurpose::Sales))->toBeNull();
});

it('ignores a line that is not connected', function () {
    line(['purpose' => 'sales', 'status' => 'disconnected']);

    expect(MarketingWhatsAppAccount::forPurpose($this->org->id, WhatsAppNumberPurpose::Sales))->toBeNull();
});

it('names a line by its label, then Meta, then the number', function () {
    expect(line(['label' => 'Main sales line', 'verified_name' => 'OralCares'])->display_name)
        ->toBe('Main sales line')
        ->and(line(['verified_name' => 'OralCares'])->display_name)->toBe('OralCares');

    $bare = line(['verified_name' => null, 'display_phone_number' => '9990012345']);

    // Never an empty string where a line name belongs.
    expect($bare->display_name)->toBe('9990012345');
});

it('gives every purpose a label, a description and a tone', function (string $value) {
    $purpose = WhatsAppNumberPurpose::from($value);

    expect($purpose->label())->not->toBeEmpty()
        ->and($purpose->description())->not->toBeEmpty()
        ->and($purpose->tone())->not->toBeEmpty();
})->with(array_column(WhatsAppNumberPurpose::cases(), 'value'));
