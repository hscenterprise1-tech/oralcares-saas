<?php

use App\ContactRole;
use App\HealthcareSpecialty;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\CRM\DealPageService;
use App\TaskStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Create Deal → Deal Detail → edit → Deal Detail.
 *
 * The rule this pins: a field the create form captures must be visible on the
 * deal, changeable there, and changed in the record rather than in the page's
 * head. A field that can only be right on the day it was entered is worse than
 * no field at all, because somebody will trust it.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));

    $this->org = Organization::factory()->create();
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->user->assignRole('CRM Manager');

    $this->plan = CompanyPlan::factory()->create();
    $this->team = Team::factory()->create(['organization_id' => $this->org->id]);
});

function everyField(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Sunrise Multi-Specialty',
        'organization_type' => 'multi_speciality_hospital',
        'healthcare_specialties' => [
            HealthcareSpecialty::MultiSpecialty->value,
            HealthcareSpecialty::Dental->value,
            HealthcareSpecialty::ENT->value,
        ],
        'branch_count' => 2,

        'country' => 'India',
        'state' => 'Maharashtra',
        'city' => 'Mumbai',
        'area' => 'Andheri West',
        'address' => '3rd Floor, Sunrise Tower',
        'postal_code' => '400053',

        'contact_name' => 'Dr Anita Rao',
        'email' => 'anita@sunrise.test',
        'phone' => '+91 98765 43210',
        'contact' => [
            'job_title' => 'Managing Director',
            'whatsapp' => '+91 90000 11111',
            'decision_role' => ContactRole::Owner->value,
        ],

        'current_software' => 'Dentrix',
        'required_modules' => ['Appointments', 'Billing'],
        'required_integrations' => ['Tally', 'WhatsApp'],
        'competitors' => 'Practo, Dentrix',
        'deployment_preference' => 'cloud',
        'expected_go_live_date' => '2026-11-01',
        'expected_users' => 25,

        'source' => 'referral',
        'expected_mrr' => 45000,
        'expected_arr' => 540000,
        'probability_to_close' => 40,
        'notes' => 'Wants a demo for the whole clinical team.',

        'branches' => [
            ['name' => 'Andheri', 'city' => 'Mumbai', 'postal_code' => '400053', 'is_main' => true],
            ['name' => 'Bandra', 'city' => 'Mumbai', 'phone' => '+91 22 1234 5678'],
        ],
    ], $overrides);
}

it('shows on the deal every field the create form captured', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField([
        'assigned_user_id' => $this->user->id,
        'selected_plan_id' => $this->plan->id,
    ]))->assertRedirect();

    $lead = Lead::sole();

    $this->actingAs($this->user)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertOk();

    /*
     * Every field the create form captured, read back from the zone that owns
     * it. The deal itself carries identity and value; the whole reference
     * record — organisation, location, branches, contacts, qualification,
     * attribution — is the profile zone, which is what the profile editor
     * edits as one form.
     */
    $page = app(DealPageService::class);
    $profile = $page->profile($lead);
    $deal = $page->immediate($lead, $this->user)['deal'];

    expect($profile['organisation'])->toMatchArray([
        'company_name' => 'Sunrise Multi-Specialty',
        'organization_type' => 'multi_speciality_hospital',
        'healthcare_specialties' => ['multi_specialty', 'dental', 'ent'],
        'branch_count' => 2,
    ]);

    expect($profile['location'])->toMatchArray([
        'country' => 'India',
        'state' => 'Maharashtra',
        'city' => 'Mumbai',
        'area' => 'Andheri West',
        'address' => '3rd Floor, Sunrise Tower',
        'postal_code' => '400053',
    ]);

    expect($profile['branches'])->toHaveCount(2)
        ->and($profile['branches'][0]['name'])->toBe('Andheri')
        ->and($profile['branches'][0]['is_main'])->toBeTrue()
        ->and($profile['branches'][1]['phone'])->toBe('+91 22 1234 5678');

    expect($profile['contacts'])->toHaveCount(1)
        ->and($profile['contacts'][0])->toMatchArray([
            'job_title' => 'Managing Director',
            'whatsapp' => '+91 90000 11111',
            'decision_role' => ContactRole::Owner->value,
            'is_primary' => true,
        ]);

    expect($profile['qualification'])->toMatchArray([
        'current_software' => 'Dentrix',
        'required_modules' => ['Appointments', 'Billing'],
        'required_integrations' => ['Tally', 'WhatsApp'],
        'competitors' => 'Practo, Dentrix',
        'deployment_preference' => 'cloud',
        'expected_go_live_date' => '2026-11-01',
        'expected_users' => 25,
    ]);

    expect($profile['commercial'])->toMatchArray([
        'expected_mrr' => 45000.0,
        'expected_arr' => 540000.0,
        'probability_to_close' => 40,
        'source' => 'referral',
        'selected_plan_id' => $this->plan->id,
    ]);

    expect($deal['company_name'])->toBe('Sunrise Multi-Specialty')
        ->and($deal['expected_arr'])->toBe(540000.0);
});

it('refuses a deal addressed to a person and a team at once', function () {
    /*
     * The model throws on this combination. Before the rule was mirrored into
     * the request, a rep who filled both dropdowns got a 500 rather than a
     * sentence telling them to pick one.
     */
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField([
        'assigned_user_id' => $this->user->id,
        'assigned_team_id' => $this->team->id,
    ]))->assertSessionHasErrors('assigned_team_id');

    expect(Lead::count())->toBe(0);
});

it('accepts a deal addressed to a team instead of a person', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField([
        'assigned_team_id' => $this->team->id,
    ]))->assertRedirect();

    $this->actingAs($this->user)
        ->get(route('company.crm.leads.show', Lead::sole()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('deal.assigned_team.id', $this->team->id));
});

it('changes the record, not just the screen, when a section is edited', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField());
    $lead = Lead::sole();

    $this->actingAs($this->user)->put(route('company.crm.leads.update', $lead), [
        'company_name' => 'Sunrise Health Group',
        'contact_name' => 'Dr Anita Rao',
        'healthcare_specialties' => [HealthcareSpecialty::Dermatology->value],
        'current_software' => 'Practo',
        'required_modules' => ['Pharmacy'],
        'deployment_preference' => 'on_premise',
        'expected_users' => 60,
        'probability_to_close' => 75,
        'selected_plan_id' => $this->plan->id,
        'contact' => [
            'job_title' => 'Chief Executive',
            'whatsapp' => '+91 91111 22222',
            'decision_role' => ContactRole::Administrator->value,
        ],
        'branches' => [
            ['name' => 'Powai', 'city' => 'Mumbai', 'is_main' => true],
        ],
    ])->assertRedirect();

    $lead->refresh();

    expect($lead->company_name)->toBe('Sunrise Health Group')
        ->and($lead->healthcare_specialties)->toBe(['dermatology'])
        // The legacy singular follows the plural rather than going stale.
        ->and($lead->healthcare_specialty)->toBe('dermatology')
        ->and($lead->current_software)->toBe('Practo')
        ->and($lead->required_modules)->toBe(['Pharmacy'])
        ->and($lead->deployment_preference)->toBe('on_premise')
        ->and($lead->expected_users)->toBe(60)
        ->and($lead->probability_to_close)->toBe(75)
        ->and($lead->selected_plan_id)->toBe($this->plan->id);

    $contact = $lead->contacts()->where('is_primary', true)->sole();

    expect($contact->job_title)->toBe('Chief Executive')
        ->and($contact->whatsapp)->toBe('+91 91111 22222')
        ->and($contact->decision_role)->toBe(ContactRole::Administrator);

    // Branches are edited as one list, so the new list replaces the old.
    expect($lead->branches()->count())->toBe(1)
        ->and($lead->mainBranch->name)->toBe('Powai')
        ->and($lead->fresh()->branch_count)->toBe(1);
});

it('leaves branches and contact alone when an edit says nothing about them', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField());
    $lead = Lead::sole();

    /*
     * A section that saves only its own fields must not wipe the sections it
     * never showed — the commonest way an "edit one thing" form destroys data.
     */
    $this->actingAs($this->user)->put(route('company.crm.leads.update', $lead), [
        'company_name' => 'Renamed only',
        'contact_name' => 'Dr Anita Rao',
    ])->assertRedirect();

    expect($lead->fresh()->company_name)->toBe('Renamed only')
        ->and($lead->branches()->count())->toBe(2)
        ->and($lead->contacts()->count())->toBe(1)
        ->and($lead->contacts()->sole()->whatsapp)->toBe('+91 90000 11111');
});

it('shows the records the deal produced, not an empty page', function () {
    /*
     * The page let you raise a quotation, book a demo, start a trial and set a
     * task, and then showed you none of them — the payload carried all four and
     * nothing rendered any of them. Apex Dental Care had two quotations and its
     * deal page read zero.
     */
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField());
    $lead = Lead::sole();

    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'quotation_number' => 'QT-2026-0044',
        'grand_total' => 540000,
        'status' => 'sent',
    ]);

    Task::factory()->for($lead, 'taskable')->create([
        'title' => 'Send the revised pricing',
        'assigned_user_id' => $this->user->id,
        'status' => TaskStatus::Pending,
    ]);

    $this->actingAs($this->user)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk();

    $page = app(DealPageService::class);
    $commercial = $page->commercial($lead);
    $now = $page->now($lead);

    expect($commercial['quotations'])->toHaveCount(1)
        ->and($commercial['quotations'][0]['quotation_number'])->toBe('QT-2026-0044')
        ->and($commercial['quotations'][0]['grand_total'])->toBe(540000.0)
        /*
         * Raw enum values, once each. These rows used to arrive under two
         * names apiece — `number` beside `quotation_number`, `createdAt`
         * beside `created_at` — so a component could typecheck against a key
         * the other half of the payload contradicted.
         */
        ->and($commercial['quotations'][0]['status'])->toBe('sent')
        ->and($commercial['quotations'][0])->not->toHaveKeys(['number', 'contractValue', 'createdAt']);

    expect($now['tasks'])->toHaveCount(1)
        ->and($now['tasks'][0]['title'])->toBe('Send the revised pricing')
        ->and($now['tasks'][0]['is_completed'])->toBeFalse()
        ->and($now['tasks'][0]['assigned_to'])->toBe($this->user->name)
        ->and($now['tasks'][0])->not->toHaveKeys(['isCompleted', 'assignedTo', 'dueAt']);

    expect($quotation->fresh()->lead_id)->toBe($lead->id);
});

it('gives the deal page the same option lists the create form has', function () {
    /*
     * Every section here edits a field the create form fills, so it needs the
     * same options to offer. A specialty you can pick on the way in and not
     * correct afterwards is a typo you live with.
     */
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField());

    /*
     * The option lists belong to the profile editor, which is where the fields
     * they fill are edited. The deal page itself has no use for them and used
     * to ship all six on every request.
     */
    $this->actingAs($this->user)
        ->get(route('company.crm.leads.profile', Lead::sole()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Leads/Profile')
            ->has('specialties')
            ->has('assignable_teams')
            ->has('assignable_users')
            ->has('lead_sources')
            ->has('organization_types')
            ->has('packages')
            ->has('profile.organisation')
            ->has('can')
        );
});

it('keeps the contact record in step when the deal is renamed', function () {
    /*
     * The contact row carries the lead's own name, email and phone. An edit
     * that corrects one of those without mentioning the contact used to leave
     * the person behind, so the deal and the person on it disagreed about who
     * to call — and mentioning the contact used to wipe the WhatsApp number
     * the create form had captured.
     */
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField());
    $lead = Lead::sole();

    $this->actingAs($this->user)->put(route('company.crm.leads.update', $lead), [
        'company_name' => 'Sunrise Multi-Specialty',
        'contact_name' => 'Dr Anita Rao-Mehta',
        'phone' => '+91 90000 99999',
    ])->assertRedirect();

    $contact = $lead->contacts()->where('is_primary', true)->sole();

    expect($contact->first_name)->toBe('Dr')
        ->and($contact->last_name)->toBe('Anita Rao-Mehta')
        ->and($contact->phone)->toBe('+91 90000 99999')
        /* Untouched, because this edit said nothing about them. */
        ->and($contact->whatsapp)->toBe('+91 90000 11111')
        ->and($contact->job_title)->toBe('Managing Director');
});

it('keeps the note the rep typed', function () {
    /*
     * The form field is `notes` and the column is `followup_notes`. There is no
     * `notes` column, so every note written on a deal was validated and then
     * dropped — on the way in and on every edit after it.
     */
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField([
        'notes' => 'Wants the pharmacy module in phase two.',
    ]))->assertRedirect();

    $lead = Lead::sole();

    expect($lead->followup_notes)->toBe('Wants the pharmacy module in phase two.');

    $this->actingAs($this->user)->put(route('company.crm.leads.update', $lead), [
        'company_name' => $lead->company_name,
        'contact_name' => $lead->contact_name,
        'notes' => 'Pharmacy dropped; billing only.',
    ])->assertRedirect();

    expect($lead->fresh()->followup_notes)->toBe('Pharmacy dropped; billing only.');
});

it('raises a quotation from the deal itself', function () {
    /*
     * The deal route carries the lead in the URL and the controller only read
     * `lead_id` from the body, so this failed validation every time — and the
     * modal has no field for `lead_id`, so the button simply did nothing.
     */
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField());
    $lead = Lead::sole();

    $this->actingAs($this->user)->post(route('company.crm.leads.create-quotation', $lead), [
        'company_plan_id' => $this->plan->id,
        'notes' => 'Three chairs, annual billing.',
    ])->assertRedirect();

    expect($lead->quotations()->count())->toBe(1);

    /* And it is on the page the moment it exists. */
    $this->actingAs($this->user)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk();

    expect(app(DealPageService::class)->commercial($lead)['quotations'])
        ->toHaveCount(1);
});

it('carries the two fields the schema had room for and no form ever asked', function () {
    /*
     * `organization_size` feeds the lead score and `migration_required` is the
     * first thing implementation wants to know. Both were real columns, both
     * fillable and cast, and neither had an input on any page — so the score
     * was computed without one and the other was false on every deal by
     * default rather than by answer.
     */
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField([
        'organization_size' => '51-200',
        'migration_required' => true,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $lead = Lead::sole();

    expect($lead->organization_size)->toBe('51-200')
        ->and($lead->migration_required)->toBeTrue();

    $profile = app(DealPageService::class)->profile($lead);

    expect($profile['organisation']['organization_size'])->toBe('51-200')
        ->and($profile['qualification']['migration_required'])->toBeTrue();

    $this->actingAs($this->user)->put(route('company.crm.leads.update', $lead), [
        'organization_size' => '200+',
        'migration_required' => false,
    ])->assertRedirect();

    expect($lead->fresh()->organization_size)->toBe('200+')
        ->and($lead->fresh()->migration_required)->toBeFalse()
        /* And the change is on the record, like every other edited field. */
        ->and($lead->activities()->latest('id')->first()->description)->toContain('Organisation size');
});

it('keeps the branch count following the branch list, and never below one', function () {
    $this->actingAs($this->user)->post(route('company.crm.leads.store'), everyField())->assertRedirect();
    $lead = Lead::sole();

    /* Two branches were given on creation. */
    expect($lead->branch_count)->toBe(2);

    $this->actingAs($this->user)->put(route('company.crm.leads.update', $lead), [
        'branches' => [['name' => 'Only Site', 'city' => 'Mumbai', 'is_main' => true]],
    ])->assertRedirect();

    expect($lead->fresh()->branch_count)->toBe(1);

    /*
     * Clearing the list means the sites have not been captured, not that the
     * practice has none — so the figure floors at one rather than claiming a
     * clinic with no locations.
     */
    $this->actingAs($this->user)->put(route('company.crm.leads.update', $lead), [
        'branches' => [],
    ])->assertRedirect();

    expect($lead->fresh()->branch_count)->toBe(1)
        ->and($lead->fresh()->branches()->count())->toBe(0);
});
