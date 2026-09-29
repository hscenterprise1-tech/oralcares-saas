<?php

use App\Models\MarketingAutomation;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\MarketingAutomationEventService;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * "A representative will contact you" — the automation that makes it true.
 *
 * Before this action the engine could tag, suppress, create a lead and create a
 * task, and none of those put a conversation in front of a person or told them
 * it was waiting. A task in a list nobody watches is not a handover.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();

    $this->admin = User::factory()->create([
        'user_type' => UserType::MasterAdmin,
        'organization_id' => $this->org->id,
    ]);

    $this->rep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    grantMarketingPermissions($this->rep);

    $this->account = MarketingWhatsAppAccount::create([
        'organization_id' => $this->org->id,
        'provider' => 'meta',
        'phone_number_id' => '9990001111',
        'status' => 'connected',
    ]);

    $this->contact = MarketingContact::create([
        'organization_id' => $this->org->id,
        'name' => 'Dr. Rahul Patel',
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
        'organization' => 'Patel Dental Clinic',
        'marketing_status' => 'active',
    ]);
});

function handoverConversation(array $overrides = []): MarketingWhatsAppConversation
{
    return MarketingWhatsAppConversation::create(array_merge([
        'organization_id' => test()->org->id,
        'whatsapp_account_id' => test()->account->id,
        'contact_id' => test()->contact->id,
        'phone' => '9876543210',
        'normalized_phone' => '9876543210',
    ], $overrides));
}

function handoverAutomation(array $step = []): MarketingAutomation
{
    return MarketingAutomation::create([
        'organization_id' => test()->org->id,
        'name' => 'Ask for a human -> handover',
        'status' => 'active',
        'trigger_type' => 'whatsapp.button_reply',
        'definition' => [
            'version' => 1,
            'trigger' => ['type' => 'whatsapp.button_reply'],
            'steps' => [array_merge(['id' => 's1', 'type' => 'handover_to_human'], $step)],
        ],
    ]);
}

function fireHandover(MarketingWhatsAppConversation $conversation): void
{
    app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
        organizationId: test()->org->id,
        eventType: 'whatsapp.button_reply',
        eventKey: 'whatsapp:wamid.Handover'.uniqid().':button',
        payload: ['button_id' => 'talk_to_human', 'button_title' => 'Talk to someone'],
        contact: test()->contact,
        conversation: $conversation
    );
}

it('assigns an unowned conversation and notifies the person it went to', function () {
    $conversation = handoverConversation();
    handoverAutomation(['assign_to_user_id' => $this->rep->id]);

    fireHandover($conversation);

    expect($conversation->fresh()->assigned_user_id)->toBe($this->rep->id);

    // Assignment without a notification produces a conversation that is
    // technically owned and actually abandoned — the exact failure this action
    // exists to prevent, so the two are one step.
    expect(EnterpriseNotification::where('notifiable_id', $this->rep->id)
        ->where('data->metadata->type', 'conversation_handover')
        ->count())->toBe(1);
});

it('leaves an already-owned conversation with its owner and tells them instead', function () {
    // Reassigning a thread out from under whoever is mid-reply would be worse
    // than not firing at all.
    $conversation = handoverConversation(['assigned_user_id' => $this->rep->id]);
    handoverAutomation(['assign_to_user_id' => $this->admin->id]);

    fireHandover($conversation);

    expect($conversation->fresh()->assigned_user_id)->toBe($this->rep->id)
        ->and(EnterpriseNotification::where('notifiable_id', $this->rep->id)
            ->where('data->metadata->type', 'conversation_handover')
            ->exists())->toBeTrue()
        ->and(EnterpriseNotification::where('notifiable_id', $this->admin->id)->exists())->toBeFalse();
});

it('falls back to a master admin when the rule names nobody', function () {
    $conversation = handoverConversation();
    handoverAutomation();

    fireHandover($conversation);

    expect($conversation->fresh()->assigned_user_id)->toBe($this->admin->id);
});

it('carries the reason through to the notification', function () {
    $conversation = handoverConversation();
    handoverAutomation([
        'assign_to_user_id' => $this->rep->id,
        'reason' => 'Asked about pricing for three branches.',
    ]);

    fireHandover($conversation);

    $notification = EnterpriseNotification::where('notifiable_id', $this->rep->id)->firstOrFail();

    expect($notification->data['message'])->toContain('Asked about pricing for three branches.')
        ->and($notification->data['message'])->toContain('Dr. Rahul Patel');
});

it('completes the run rather than failing it', function () {
    $conversation = handoverConversation();
    $automation = handoverAutomation(['assign_to_user_id' => $this->rep->id]);

    fireHandover($conversation);

    expect($automation->fresh()->executions()->first()->status)->toBe('completed');
});
