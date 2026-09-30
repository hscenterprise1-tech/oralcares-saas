<?php

use App\Jobs\CheckUnassignedLeadSlaJob;
use App\Jobs\EscalateOverdueFollowupsJob;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Phase 1 item 10 — notifications are written, owned and retrievable.
 *
 * THE CONTRACT
 *
 *   Written by   NotificationEngineService, into `enterprise_notifications`.
 *   Owned by     the recipient — morphed via `notifiable_type`/`notifiable_id`.
 *                Not tenant-scoped as such; a notification belongs to a person.
 *   Retrieved by GET /api/notifications (JSON) and GET /notifications (page),
 *                both of which already existed and both of which filter on the
 *                authenticated user.
 *
 * Two defects: the CRM bell pointed at `/company/notifications`, which was
 * never a registered route, and the two SLA jobs wrote only to the legacy
 * `lead_activities` table — so their escalations reached nobody at all.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));

    $this->org = Organization::factory()->create();

    $this->rep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);

    $this->manager = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->manager->assignRole('CRM Manager');
});

function notificationsFor(User $user): Collection
{
    return EnterpriseNotification::where('notifiable_type', $user->getMorphClass())
        ->where('notifiable_id', $user->getKey())
        ->get();
}

// ─────────────────────────────────────────────────────────────────────────────
// The retrieval path exists and is user-scoped
// ─────────────────────────────────────────────────────────────────────────────

it('serves notifications through the registered api route', function () {
    /*
     * Created unowned on purpose: an auto-assigned lead notifies whoever the
     * rota reached, and this test is about who receives a *manual* assignment.
     */
    app(LeadWorkspaceService::class)->assignLead(
        unownedLead(['organization_id' => $this->org->id]),
        $this->rep,
        $this->manager,
        'manual'
    );

    $this->actingAs($this->rep)
        ->getJson('/api/notifications')
        ->assertOk();

    expect(notificationsFor($this->rep))->not->toBeEmpty();
});

it('does not serve one user notifications to another', function () {
    /*
     * Created unowned on purpose: an auto-assigned lead notifies whoever the
     * rota reached, and this test is about who receives a *manual* assignment.
     */
    app(LeadWorkspaceService::class)->assignLead(
        unownedLead(['organization_id' => $this->org->id]),
        $this->rep,
        $this->manager,
        'manual'
    );

    expect(notificationsFor($this->rep))->not->toBeEmpty()
        ->and(notificationsFor($this->manager))->toBeEmpty();
});

it('serves the notifications page at the route the shell now links to', function () {
    $this->actingAs($this->rep)->get('/notifications')->assertOk();
});

it('has no route at the path the shell used to link to', function () {
    // Pins the 404 that made the bell dead, so the link cannot regress to it.
    expect(Route::has('company.notifications'))->toBeFalse();

    $shell = file_get_contents(resource_path('js/pages/Company/design/CrmShell.tsx'));
    expect($shell)->not->toContain("router.visit('/company/notifications')");
});

// ─────────────────────────────────────────────────────────────────────────────
// Creation — scheduled jobs must produce retrievable notifications
// ─────────────────────────────────────────────────────────────────────────────

it('notifies the owner when a follow-up breaches its escalation window', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Contacted,
    ]);

    Task::create([
        'title' => 'Overdue follow-up',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => now()->subDays(3),
        'assigned_user_id' => $this->rep->id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    (new EscalateOverdueFollowupsJob)->handle(app(LeadWorkspaceService::class));

    $titles = notificationsFor($this->rep)->pluck('title');

    expect($titles)->toContain('Follow-up overdue');
});

it('does not notify anyone when an overdue lead has no owner', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => null,
        'status' => LeadStatus::Contacted,
    ]);

    Task::create([
        'title' => 'Ownerless follow-up',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => now()->subDays(3),
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    (new EscalateOverdueFollowupsJob)->handle(app(LeadWorkspaceService::class));

    expect(notificationsFor($this->rep)->pluck('title'))->not->toContain('Follow-up overdue');
});

it('notifies managers when a lead breaches the unassigned SLA', function () {
    /* A legacy row from before assignment was automatic — see `unownedLead`. */
    unownedLead([
        'company_name' => 'Unowned Dental',
        'organization_id' => $this->org->id,
        'assigned_user_id' => null,
        'status' => LeadStatus::New,
        'created_at' => now()->subHours(5),
    ]);

    (new CheckUnassignedLeadSlaJob)->handle();

    expect(notificationsFor($this->manager)->pluck('title'))
        ->toContain('Unassigned lead breached SLA');
});

it('does not escalate an unassigned lead across tenants', function () {
    $otherOrg = Organization::factory()->create();
    Lead::factory()->create([
        'company_name' => 'Other Tenant Lead',
        'organization_id' => $otherOrg->id,
        'assigned_user_id' => null,
        'status' => LeadStatus::New,
        'created_at' => now()->subHours(5),
    ]);

    (new CheckUnassignedLeadSlaJob)->handle();

    expect(notificationsFor($this->manager))->toBeEmpty();
});

it('does not re-notify on a second run for the same breach', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => null,
        'status' => LeadStatus::New,
        'created_at' => now()->subHours(5),
    ]);

    (new CheckUnassignedLeadSlaJob)->handle();
    (new CheckUnassignedLeadSlaJob)->handle();

    expect(notificationsFor($this->manager))->toHaveCount(1);
});
