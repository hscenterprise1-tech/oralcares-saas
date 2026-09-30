<?php

use App\ContactRole;
use App\Models\CompanyPlan;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\MyWorkspaceService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 0 regression suite for CRM authorization.
 *
 * Before Phase 0 the CRM route group carried no `can:` middleware and 12 of 20
 * controllers made no authorization call, so any authenticated company-portal
 * user reached every CRM endpoint. These tests pin the corrected behaviour:
 * holding a CompanyUser account is not by itself CRM access.
 */

/** A company employee with no CRM permissions — e.g. Finance or HR. */
function nonCrmEmployee(): User
{
    return User::factory()->create(['user_type' => UserType::CompanyUser]);
}

it('denies CRM read surfaces to a company user without CRM permissions', function (string $routeName) {
    $this->actingAs(nonCrmEmployee())
        ->get(route($routeName))
        ->assertForbidden();
})->with([
    'company.crm.dashboard',
    'company.crm.overview',
    'company.crm.leads.index',
    'company.crm.pipeline',
    'company.crm.my-workspace',
    'company.crm.operations.index',
    'company.crm.tasks.index',
    'company.crm.demos.index',
    'company.crm.teams.index',
    'company.admin.packages.index',
    'company.crm.organizations.index',
    'company.crm.subscriptions.index',
    'company.crm.renewals.index',
    'company.crm.leads.export',
]);

it('allows CRM read surfaces to a company user holding CRM permissions', function (string $routeName) {
    $this->actingAs(crmUser())
        ->get(route($routeName))
        ->assertSuccessful();
})->with([
    'company.crm.dashboard',
    'company.crm.deals.index',
    'company.crm.my-workspace',
]);

/*
 * The retired surfaces still authorize before they redirect.
 *
 * A bare Route::redirect carries no middleware, which would turn a 403 into a
 * redirect for somebody with no CRM access — the dataset above is what pins
 * that. This pins the other half: for somebody who *is* allowed, the old URL
 * lands on whatever absorbed it rather than 404ing a bookmark.
 */
it('sends a retired CRM surface to the one that absorbed it', function (string $routeName, string $destination) {
    $this->actingAs(crmUser())
        ->get(route($routeName))
        ->assertRedirect($destination);
})->with([
    ['company.crm.operations.index', '/company/crm/deals?view=lost'],
    ['company.crm.tasks.index', '/company/crm/my-workspace'],
    ['company.crm.demos.index', '/company/crm/my-workspace'],
    ['company.crm.leads.index', '/company/crm/deals'],
    ['company.crm.pipeline', '/company/crm/deals'],
]);

it('always allows a master admin through', function (string $routeName) {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->actingAs($admin)
        ->get(route($routeName))
        ->assertSuccessful();
})->with([
    'company.crm.dashboard',
    'company.crm.deals.index',
    'company.crm.teams.index',
    'company.admin.packages.index',
]);

it('denies package catalog mutation to a user without plan permissions', function () {
    $plan = CompanyPlan::factory()->create();

    $this->actingAs(nonCrmEmployee())
        ->delete(route('company.admin.packages.destroy', $plan))
        ->assertForbidden();

    $this->assertDatabaseHas('company_plans', ['id' => $plan->id]);
});

it('denies sales team mutation to a user without team permissions', function () {
    // A standard CRM rep can read teams but must not reconfigure them.
    $this->actingAs(crmUser())
        ->post(route('company.crm.teams.store'), [
            'name' => 'Rogue Team',
            'assignment_strategy' => 'round_robin',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('teams', ['name' => 'Rogue Team']);
});

it('denies contact PII to a company user without CRM permissions', function () {
    $contact = Contact::create([
        'organization_id' => Organization::factory()->create()->id,
        'first_name' => 'Private',
        'last_name' => 'Person',
        'email' => 'private@example.com',
        'phone' => '+919876511111',
        'decision_role' => ContactRole::Owner,
    ]);

    /*
     * The deal-scoped endpoint, which is the only one there is now. The
     * standalone contacts directory and its write endpoints were a second
     * people system that the deal never used; deleting a contact is authorized
     * against the deal it belongs to.
     */
    $lead = Lead::factory()->create();
    $contact->update(['lead_id' => $lead->id]);

    $this->actingAs(nonCrmEmployee())
        ->delete(route('company.crm.leads.contacts.destroy', [$lead, $contact]))
        ->assertForbidden();

    $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
});

it('denies lead mutation endpoints to a user without CRM permissions', function () {
    $lead = Lead::factory()->create();

    $this->actingAs(nonCrmEmployee())
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'won'])
        ->assertForbidden();

    $this->actingAs(nonCrmEmployee())
        ->post(route('company.crm.leads.convert', $lead))
        ->assertForbidden();
});

it('denies onboarding go-live to a user without onboarding permissions', function () {
    /*
     * Against the Customer Success endpoint, which is the one that exists. The
     * CRM equivalent was retired with the rest of that surface — Account 360
     * owns onboarding now and posts to its own actions.
     */
    $org = Organization::factory()->create();
    $onboarding = OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => 'password_setup',
        'status' => 'pending',
        'completed_steps' => [],
    ]);

    $this->actingAs(nonCrmEmployee())
        ->post(route('company.customer-success.accounts.onboarding.mark-go-live', $org))
        ->assertForbidden();

    expect($onboarding->fresh()->status)->toBe('pending');
});

it('scopes the global task list to the viewer instead of returning every task', function () {
    $mine = crmUser();
    $stranger = crmUser(['email' => 'stranger@example.com']);

    $ownTask = Task::create([
        'title' => 'My own task',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::Medium,
        'due_at' => now()->addDay(),
        'assigned_user_id' => $mine->id,
        'creator_id' => $mine->id,
    ]);

    $foreignTask = Task::create([
        'title' => 'Somebody elses task',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::Medium,
        'due_at' => now()->addDay(),
        'assigned_user_id' => $stranger->id,
        'creator_id' => $stranger->id,
    ]);

    /*
     * My Work is the task list — the standalone tasks page was a third view of
     * these same rows. The scoping guard moves with it: "my tasks" must mean
     * mine, not everybody's.
     */
    $this->actingAs($mine)
        ->get(route('company.crm.my-workspace'))
        ->assertSuccessful()
        ->assertInertia(function ($page) use ($ownTask, $foreignTask) {
            $ids = collect($page->toArray()['props']['tasks'])->pluck('id')->all();

            expect($ids)->toContain($ownTask->id)
                ->and($ids)->not->toContain($foreignTask->id);
        });
});

it('caps the task list rather than returning every row', function () {
    $user = crmUser();

    /*
     * Regression guard, carried over from the paginated tasks page: the old
     * endpoint used ->get() with no limit and returned every task in the
     * database. My Work's list is capped at LIST_LIMIT instead.
     */
    Task::factory()->count(MyWorkspaceService::LIST_LIMIT + 5)->create([
        'assigned_user_id' => $user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
    ]);

    $this->actingAs($user)
        ->get(route('company.crm.my-workspace'))
        ->assertSuccessful()
        ->assertInertia(function ($page) {
            expect($page->toArray()['props']['tasks'])
                ->toHaveCount(MyWorkspaceService::LIST_LIMIT);
        });
});

it('keeps clinic tenant users out of CRM entirely', function () {
    $clinicUser = User::factory()->create(['user_type' => UserType::ClinicUser]);

    $this->actingAs($clinicUser)
        ->get(route('company.crm.leads.index'))
        ->assertForbidden();
});
