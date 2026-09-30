<?php

use App\ActivityType;
use App\LeadStatus;
use App\Models\Clinic;
use App\Models\Lead;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Services\Marketing\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->adminUser = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->employeeUser = User::factory()->companyUser()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->clinic = Clinic::firstOrCreate(
        ['organization_id' => $this->organization->id],
        ['name' => 'Test Clinic', 'slug' => 'test-clinic-'.Str::random(6)]
    );

    MarketingWhatsAppAccount::create([
        'organization_id' => $this->organization->id,
        'account_name' => 'Tenant WA Account',
        'phone_number' => '+919876543210',
        'whatsapp_business_account_id' => 'waba_123',
        'phone_number_id' => 'phone_123',
        'access_token' => 'token_123',
        'status' => 'connected',
    ]);
});

it('can create a 7-day or 15-day basic trial plan for a lead', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'phone' => '9876543210',
    ]);

    $service = app(LeadWorkspaceService::class);

    $trial = $service->createTrial($lead, [
        'plan' => 'Basic Trial Plan',
        'duration_days' => 15,
    ], $this->adminUser);

    expect($trial)->not->toBeNull();
    expect($trial->duration_days)->toBe(15);
    expect($trial->plan)->toBe('Basic Trial Plan');
    expect($trial->status->value)->toBe('preparing');

    $this->assertDatabaseHas('trials', [
        'id' => $trial->id,
        'lead_id' => $lead->id,
        'duration_days' => 15,
        'plan' => 'Basic Trial Plan',
    ]);
});

it('blocks duplicate active trial creation for the same lead', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    $service = app(LeadWorkspaceService::class);

    $trial1 = $service->createTrial($lead, ['duration_days' => 7], $this->adminUser);
    $trial2 = $service->createTrial($lead, ['duration_days' => 15], $this->adminUser);

    expect($trial2->id)->toBe($trial1->id);
    expect(Trial::where('lead_id', $lead->id)->count())->toBe(1);
});

it('activates trial and transitions lead stage to TrialActive', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::DemoCompleted->value,
    ]);

    $service = app(LeadWorkspaceService::class);
    $trial = $service->createTrial($lead, ['duration_days' => 15], $this->adminUser);

    $activatedTrial = $service->activateTrial($trial, $this->employeeUser);

    expect($activatedTrial->status->value)->toBe('active');

    $lead->refresh();
    $statusVal = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
    expect($statusVal)->toBe(LeadStatus::TrialActive->value);
});

it('allows updating onboarding checklist', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);
    $service = app(LeadWorkspaceService::class);
    $trial = $service->createTrial($lead, [], $this->adminUser);

    $checklist = [
        ['id' => '1', 'task' => 'Confirm client details', 'completed' => true],
        ['id' => '2', 'task' => 'Confirm primary contact', 'completed' => true],
    ];

    $updatedTrial = $service->updateTrialChecklist($trial, $checklist, $this->employeeUser);

    expect(count($updatedTrial->onboarding_checklist))->toBe(2);
    expect($updatedTrial->onboarding_checklist[0]['completed'])->toBeTrue();
});

it('allows up to the governance cap of trial extensions, preserving the original end date, then blocks further ones', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);
    $service = app(LeadWorkspaceService::class);
    $trial = $service->createTrial($lead, ['duration_days' => 7], $this->adminUser);
    $trial = $service->activateTrial($trial, $this->adminUser);

    $originalEndDate = $trial->end_date->toDateString();

    $extendedTrial = $service->extendTrial($trial, [
        'additional_days' => 7,
        'extension_reason' => 'Client requested extra time for EMR evaluation.',
    ], $this->employeeUser);

    expect($extendedTrial->extension_count)->toBe(1);
    expect($extendedTrial->status->value)->toBe('extended');
    expect($extendedTrial->original_end_date->toDateString())->toBe($originalEndDate);

    // Extensions 2 and 3 (up to the cap) succeed and keep the original end date pinned.
    $extendedTrial = $service->extendTrial($extendedTrial, ['additional_days' => 5, 'extension_reason' => 'Second ask.'], $this->employeeUser);
    expect($extendedTrial->extension_count)->toBe(2);

    $extendedTrial = $service->extendTrial($extendedTrial, ['additional_days' => 10, 'extension_reason' => 'Third ask.'], $this->employeeUser);
    expect($extendedTrial->extension_count)->toBe(3);
    expect($extendedTrial->original_end_date->toDateString())->toBe($originalEndDate);

    // A 4th extension attempt beyond the cap must throw.
    expect(fn () => $service->extendTrial($extendedTrial, ['additional_days' => 7, 'extension_reason' => 'Fourth ask.'], $this->employeeUser))
        ->toThrow(InvalidArgumentException::class);
});

it('triggers client WhatsApp reminder respecting M-8 opt-out and suppression safety rules', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'phone' => '9876543210',
    ]);

    $contact = MarketingContact::create([
        'organization_id' => $this->organization->id,
        'name' => $lead->contact_name,
        'phone' => $lead->phone,
        'normalized_phone' => PhoneNormalizer::normalize($lead->phone),
        'marketing_status' => 'opted_out',
    ]);

    $lead->update(['marketing_contact_id' => $contact->id]);

    $service = app(LeadWorkspaceService::class);
    $trial = $service->createTrial($lead, [], $this->employeeUser);

    $sent = $service->sendClientTrialReminder($trial, 'started');
    expect($sent)->toBeFalse();

    /*
     * Asserted against the timeline rather than `lead_whatsapp_messages`, which
     * is gone. A suppressed contact stops the send before any row is written.
     */
    expect($lead->activities()->where('type', ActivityType::WhatsApp)->count())->toBe(0);
});

it('completes trial with outcome advancing lead stage to Negotiation', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::TrialActive->value,
    ]);

    $service = app(LeadWorkspaceService::class);
    $trial = $service->createTrial($lead, [], $this->adminUser);
    $trial = $service->activateTrial($trial, $this->adminUser);

    $completedTrial = $service->completeTrial($trial, [
        'outcome' => 'quotation_requested',
        'feedback' => 'Loved the IVF Module!',
    ], $this->employeeUser);

    /*
     * `converted` is now `quotation_requested` — the status is set when the
     * customer asks for a price, which is not a sale. The behaviour under test
     * is unchanged: this outcome advances the deal to Negotiation.
     */
    expect($completedTrial->status->value)->toBe('quotation_requested');
    expect($completedTrial->feedback)->toBe('Loved the IVF Module!');

    $lead->refresh();
    $statusVal = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
    expect($statusVal)->toBe(LeadStatus::Negotiation->value);
});

it('marks trial expired when end date is past without marking lead lost', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::TrialActive->value,
    ]);

    $trial = Trial::create([
        'lead_id' => $lead->id,
        'plan' => 'Basic Trial Plan',
        'duration_days' => 7,
        'start_date' => now()->subDays(10),
        'end_date' => now()->subDays(3),
        'status' => 'active',
        'assigned_user_id' => $this->employeeUser->id,
    ]);

    $service = app(LeadWorkspaceService::class);
    $res = $service->checkAndGenerateTrialReminders($this->organization->id);

    $trial->refresh();
    expect($trial->status->value)->toBe('expired');

    $lead->refresh();
    $statusVal = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
    expect($statusVal)->not->toBe(LeadStatus::Lost->value);
});

it('enforces tenant isolation preventing cross tenant trial updates', function () {
    $otherOrg = Organization::factory()->create();
    $otherLead = Lead::factory()->create(['organization_id' => $otherOrg->id]);

    $trial = Trial::create([
        'lead_id' => $otherLead->id,
        'plan' => 'Basic Trial Plan',
        'duration_days' => 15,
        'start_date' => now(),
        'end_date' => now()->addDays(15),
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->employeeUser)
        ->post(route('company.crm.trials.retry', $trial));

    $response->assertStatus(403);
});

it('stops trial reminders and tasks when lead status transitions to Won or Lost', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::Won->value,
    ]);

    $service = app(LeadWorkspaceService::class);
    $trial = Trial::create([
        'lead_id' => $lead->id,
        'plan' => 'Basic Trial Plan',
        'duration_days' => 15,
        'start_date' => now(),
        'end_date' => now()->addDays(15),
        'status' => 'active',
    ]);

    $sent = $service->sendClientTrialReminder($trial, 'ending_tomorrow');
    expect($sent)->toBeFalse();
});
