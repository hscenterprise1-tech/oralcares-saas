<?php

use App\Models\Lead;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    actingAs($this->admin);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'phone' => '9998887766',
    ]);
});

function trialTestApprovedTemplate(Organization $org, string $name): MarketingWhatsAppTemplate
{
    $account = MarketingWhatsAppAccount::create([
        'organization_id' => $org->id,
        'provider' => 'meta',
        'phone_number_id' => '9990001111',
        'status' => 'connected',
    ]);

    return MarketingWhatsAppTemplate::create([
        'organization_id' => $org->id,
        'whatsapp_account_id' => $account->id,
        'name' => $name,
        'language' => 'en',
        'category' => 'utility',
        'status' => 'approved',
        'body' => 'Trial reminder body.',
    ]);
}

it('sends a real trial-created WhatsApp reminder when an approved template exists', function () {
    trialTestApprovedTemplate($this->organization, 'trial_created');

    $trial = app(LeadWorkspaceService::class)->createTrial($this->lead, ['duration_days' => 15], $this->admin);

    $message = $this->lead->activities()->where('type', 'whatsapp')->latest('id')->first();

    expect($message)->not->toBeNull()
        ->and($message->description)->not->toContain('NOT sent');
});

it('logs a not-sent activity for the trial-extended reminder when no template is approved', function () {
    $trial = app(LeadWorkspaceService::class)->createTrial($this->lead, ['duration_days' => 10], $this->admin);

    app(LeadWorkspaceService::class)->extendTrial($trial, [
        'additional_days' => 5,
        'extension_reason' => 'More time needed.',
    ], $this->admin);

    $message = $this->lead->activities()->where('type', 'whatsapp')->latest('id')->first();

    expect($message)->not->toBeNull()
        ->and($message->description)->toContain('NOT sent');
});
