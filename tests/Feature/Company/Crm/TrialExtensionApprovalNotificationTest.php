<?php

use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\TrialExtensionRequest;
use App\Models\User;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\TrialStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Phase 3 — Trial extension approval notifications.
 *
 * The approval workflow itself (request/approve/reject, self-approval for a
 * manager's own ask) already existed and was fully tested before this suite —
 * see TrialEnterpriseOperationsTest. What it never did was tell anyone: an
 * approver only found a pending request by opening My Work, and a requester
 * only learned the outcome by reloading the trial. These tests pin the
 * notification wiring on top of that unchanged workflow.
 */
beforeEach(function () {
    setPermissionsTeamId(0);

    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->org = Organization::factory()->create();

    $this->manager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->manager->assignRole('CRM Manager');

    $this->rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->rep->assignRole('Sales Representative');
});

function notificationExtensionTrial(): Trial
{
    $lead = Lead::factory()->create([
        'organization_id' => test()->org->id,
        'assigned_user_id' => test()->rep->id,
    ]);

    return Trial::factory()->create([
        'lead_id' => $lead->id,
        'end_date' => now()->addDays(5),
    ]);
}

it('notifies the manager when a rep requests a trial extension', function () {
    $trial = notificationExtensionTrial();

    $this->actingAs($this->rep)->post(route('company.crm.trials.extension-requests.store', $trial), [
        'additional_days' => 7,
        'reason' => 'Clinic asked for two more weeks to onboard staff.',
    ]);

    $request = TrialExtensionRequest::where('trial_id', $trial->id)->firstOrFail();

    $notification = EnterpriseNotification::where('notifiable_id', $this->manager->id)
        ->where('category', NotificationCategory::APPROVAL->value)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['metadata']['trial_id'] ?? null)->toBe($trial->id)
        ->and($notification->data['metadata']['extension_request_id'] ?? null)->toBe($request->id)
        ->and($notification->actions)->not->toBeEmpty();
});

it('does not notify anyone when a manager approves their own extension request', function () {
    $trial = notificationExtensionTrial();

    $this->actingAs($this->manager)->post(route('company.crm.trials.extension-requests.store', $trial), [
        'additional_days' => 5,
        'reason' => 'Extending my own pilot clinic.',
    ]);

    expect(EnterpriseNotification::where('category', NotificationCategory::APPROVAL->value)->count())->toBe(0);
});

it('notifies the requester when a manager approves their extension request', function () {
    $trial = notificationExtensionTrial();

    $this->actingAs($this->rep)->post(route('company.crm.trials.extension-requests.store', $trial), [
        'additional_days' => 7,
        'reason' => 'Clinic asked for two more weeks to onboard staff.',
    ]);

    $request = TrialExtensionRequest::where('trial_id', $trial->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->post(route('company.crm.trials.extension-requests.approve', [$trial, $request]));

    $notification = EnterpriseNotification::where('notifiable_id', $this->rep->id)
        ->where('category', NotificationCategory::APPROVAL->value)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->title)->toContain('approved')
        ->and($notification->data['metadata']['decision'] ?? null)->toBe('approved');
});

it('notifies the requester when a manager rejects their extension request', function () {
    $trial = notificationExtensionTrial();

    $this->actingAs($this->rep)->post(route('company.crm.trials.extension-requests.store', $trial), [
        'additional_days' => 10,
        'reason' => 'Wants a month to decide.',
    ]);

    $request = TrialExtensionRequest::where('trial_id', $trial->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->post(route('company.crm.trials.extension-requests.reject', [$trial, $request]), [
            'rejection_reason' => 'A month is longer than policy allows without a signed quotation.',
        ]);

    $notification = EnterpriseNotification::where('notifiable_id', $this->rep->id)
        ->where('category', NotificationCategory::APPROVAL->value)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->title)->toContain('rejected')
        ->and($notification->data['metadata']['decision'] ?? null)->toBe('rejected');
});

it('never notifies a manager in a different tenant about an extension request', function () {
    $trial = notificationExtensionTrial();

    $otherOrg = Organization::factory()->create();
    $stranger = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $otherOrg->id]);
    $stranger->assignRole('CRM Manager');

    $this->actingAs($this->rep)->post(route('company.crm.trials.extension-requests.store', $trial), [
        'additional_days' => 7,
        'reason' => 'Clinic asked for two more weeks to onboard staff.',
    ]);

    expect(EnterpriseNotification::where('notifiable_id', $stranger->id)->count())->toBe(0);
});

it('does not notify anyone when a self-approving request fails against an already-closed trial', function () {
    $trial = notificationExtensionTrial();
    $trial->update(['status' => TrialStatus::Cancelled]);

    // The manager self-approves in the same transaction the request is
    // created in, so this is the path that actually reaches
    // TrialLifecycleService::extend()'s closed-trial guard and rolls back —
    // a rep's request would simply queue as pending regardless of status.
    $this->actingAs($this->manager)
        ->post(route('company.crm.trials.extension-requests.store', $trial), [
            'additional_days' => 7,
            'reason' => 'Trying anyway.',
        ]);

    expect(TrialExtensionRequest::where('trial_id', $trial->id)->count())->toBe(0)
        ->and(EnterpriseNotification::where('category', NotificationCategory::APPROVAL->value)->count())->toBe(0);
});
