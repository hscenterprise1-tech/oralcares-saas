<?php

use App\ActivityType;
use App\LeadPriority;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\MarketingAudience;
use App\Models\MarketingCampaign;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->adminUser = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    $this->employeeUser = grantCrmPermissions(
        User::factory()->companyUser()->create([
            'organization_id' => $this->organization->id,
        ])
    );
});

it('can create a lead with canonical fields, normalized phone, and activity log', function () {
    actingAs($this->adminUser);

    $leadData = [
        'company_name' => 'Dental Care Clinic',
        'contact_name' => 'Dr. Rajesh Sharma',
        'email' => 'rajesh@dentalcare.com',
        'phone' => '9876543210',
        'city' => 'Pune',
        'healthcare_specialties' => ['dental'],
        'status' => 'new',
        'priority' => 'high',
        'source' => 'website',
        'expected_mrr' => 15000,
    ];

    /*
     * A `dd($response->status(), $response->content())` used to sit here for
     * the non-302 case. When it fired it printed a rendered Ignition error page
     * — 1.2 MB of HTML and bundled JavaScript — straight into the runner's
     * stdout and killed the process, so a single broken endpoint destroyed the
     * whole suite's output and left no summary line at all.
     *
     * The assertions below already say what went wrong, and say it in one line.
     */
    $response = post(route('company.crm.leads.store'), $leadData);
    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('company.crm.deals.index'));

    $lead = Lead::where('email', 'rajesh@dentalcare.com')->first();
    expect($lead)->not->toBeNull()
        ->and($lead->normalized_phone)->toBe('9876543210')
        ->and($lead->company_name)->toBe('Dental Care Clinic');

    $this->assertDatabaseHas('activities', [
        'subject_id' => $lead->id,
        'type' => ActivityType::RecordCreated->value,
    ]);
});

it('can update lead and record audit activity entry', function () {
    actingAs($this->adminUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Original Clinic',
        'phone' => '9876543210',
        'status' => LeadStatus::New,
        'priority' => LeadPriority::Medium,
    ]);

    $updateData = [
        'company_name' => 'Updated Dental Clinic',
        'priority' => 'high',
        'status' => 'contacted',
    ];

    $response = put(route('company.crm.leads.update', $lead), $updateData);
    $response->assertSessionHasNoErrors();

    $lead->refresh();
    expect($lead->company_name)->toBe('Updated Dental Clinic')
        ->and($lead->priority->value)->toBe('high');

    $this->assertDatabaseHas('activities', [
        'subject_id' => $lead->id,
        'type' => ActivityType::SystemUpdate->value,
    ]);
});

it('preserves marketing attribution fields during lead creation', function () {
    actingAs($this->adminUser);

    $contact = MarketingContact::create([
        'organization_id' => $this->organization->id,
        'name' => 'Attributed Doctor',
        'first_name' => 'Attributed',
        'last_name' => 'Doctor',
        'phone' => '+919876543299',
        'normalized_phone' => '9876543299',
        'marketing_status' => 'active',
    ]);

    $account = MarketingWhatsAppAccount::create([
        'organization_id' => $this->organization->id,
        'name' => 'Default WhatsApp Account',
        'phone_number_id' => '123456789',
        'waba_id' => '987654321',
        'access_token' => 'token_secret',
        'status' => 'active',
    ]);

    $audience = MarketingAudience::create([
        'organization_id' => $this->organization->id,
        'name' => 'All Doctors',
        'rules' => [],
    ]);

    $template = MarketingWhatsAppTemplate::create([
        'organization_id' => $this->organization->id,
        'whatsapp_account_id' => $account->id,
        'name' => 'welcome_promo',
        'language' => 'en',
        'category' => 'UTILITY',
        'status' => 'APPROVED',
        'body' => 'Hello {{1}}',
        'components' => [],
    ]);

    $campaign = MarketingCampaign::create([
        'organization_id' => $this->organization->id,
        'whatsapp_account_id' => $account->id,
        'audience_id' => $audience->id,
        'template_id' => $template->id,
        'name' => 'Attribution Campaign',
        'status' => 'draft',
    ]);

    $leadData = [
        'company_name' => 'Attributed Clinic',
        'contact_name' => 'Attributed Doctor',
        'email' => 'attributed@clinic.com',
        'phone' => '9876543299',
        'city' => 'Pune',
        'healthcare_specialties' => ['dental'],
        'status' => 'new',
        'priority' => 'medium',
        'source' => 'whatsapp',
        'marketing_contact_id' => $contact->id,
        'marketing_campaign_id' => $campaign->id,
    ];

    $response = post(route('company.crm.leads.store'), $leadData);
    $response->assertSessionHasNoErrors();

    $lead = Lead::where('email', 'attributed@clinic.com')->first();
    expect($lead)->not->toBeNull()
        ->and($lead->marketing_contact_id)->toBe($contact->id)
        ->and($lead->marketing_campaign_id)->toBe($campaign->id);
});

it('detects duplicate lead by phone or email within organization scope', function () {
    actingAs($this->adminUser);

    $existingLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => 'duplicate@clinic.com',
        'phone' => '9876543210',
        'normalized_phone' => '+919876543210',
    ]);

    $service = app(LeadWorkspaceService::class);
    $duplicate = $service->findDuplicateLead($this->organization->id, '9876543210', 'other@clinic.com');

    expect($duplicate)->not->toBeNull()
        ->and($duplicate->id)->toBe($existingLead->id);
});

it('enforces tenant isolation preventing access to leads from another organization', function () {
    $otherOrg = Organization::factory()->create();
    $otherLead = Lead::factory()->create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Org Clinic',
    ]);

    actingAs($this->employeeUser);

    get(route('company.crm.leads.show', $otherLead))
        ->assertForbidden();
});

it('employee persona workspace scopes leads to assigned user', function () {
    $assignedLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
        'company_name' => 'My Assigned Clinic',
    ]);

    $unassignedLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->adminUser->id,
        'company_name' => 'Unassigned Clinic',
    ]);

    actingAs($this->employeeUser);

    get(route('company.crm.my-workspace'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/MyWorkspace')
            ->where('workload.counts.total_assigned_leads', 1)
        );
});

it('lead transition to won triggers marketing M-5 suppression', function () {
    actingAs($this->adminUser);

    $contact = MarketingContact::create([
        'organization_id' => $this->organization->id,
        'name' => 'Won LeadContact',
        'first_name' => 'Won',
        'last_name' => 'LeadContact',
        'phone' => '+919876543210',
        'normalized_phone' => '+919876543210',
        'marketing_status' => 'active',
    ]);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'marketing_contact_id' => $contact->id,
        'phone' => '9876543210',
        'status' => LeadStatus::QuotationSent,
    ]);

    $service = app(LeadWorkspaceService::class);
    $service->transitionStage($lead, 'won', $this->adminUser);

    $contact->refresh();
    expect($contact->marketing_status)->toBe('suppressed');
});
