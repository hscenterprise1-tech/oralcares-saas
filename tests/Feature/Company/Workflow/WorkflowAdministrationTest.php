<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Services\Workflow\WorkflowAdministrationService;
use App\Services\WorkflowDefinitionService;
use App\Services\WorkflowEngineService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Company → Workflow administration.
 *
 * Read-only oversight of the engine that already exists. What it is for is the
 * configuration faults: a workflow can be saved, look finished, and be unable to
 * start or unable to move, and nothing anywhere said so.
 */
function workflowAdminUser(array $permissions = []): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['user_type' => UserType::CompanyUser]);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

function adminClinic(): array
{
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create([
        'clinic_id' => $clinic->id,
        'organization_id' => $clinic->organization_id,
    ]);

    return [$clinic, $branch];
}

/** Rows written directly, so the surface is tested against what the table can hold. */
function adminDefinition(Clinic $clinic, ?Branch $branch, string $name, bool $active = true): WorkflowDefinition
{
    return WorkflowDefinition::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch?->id,
        'name' => $name,
        'is_active' => $active,
    ]);
}

function adminStage(WorkflowDefinition $definition, string $name, int $position): WorkflowStage
{
    return WorkflowStage::create([
        'workflow_definition_id' => $definition->id,
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'position' => $position,
    ]);
}

beforeEach(function () {
    setPermissionsTeamId(0);

    Permission::findOrCreate('view-platform', 'web');

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
});

/* ═══════════════════════════ the surface ═══════════════════════════════════ */

it('refuses workflow administration to a company user without the permission', function () {
    $this->actingAs(workflowAdminUser())->get('/company/workflows')->assertForbidden();
    $this->actingAs(workflowAdminUser(['view-platform']))->get('/company/workflows')->assertOk();
});

it('lets the super admin open workflow administration holding nothing', function () {
    expect($this->admin->getAllPermissions())->toBeEmpty();

    $this->actingAs($this->admin)->get('/company/workflows')->assertSuccessful();
});

it('exposes only GET on workflow administration', function () {
    /*
     * Deliberate: a definition belongs to a clinic and a history entry names a
     * clinic employee as its performer, so an HQ write has nowhere to record
     * who made it. Oversight, not authorship.
     */
    $verbs = collect(app('router')->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'company/workflows'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->sort()
        ->values();

    expect($verbs->all())->toBe(['GET', 'HEAD']);
});

it('mutates nothing when workflow administration is opened', function () {
    [$clinic, $branch] = adminClinic();
    $definition = adminDefinition($clinic, $branch, 'Standard');
    adminStage($definition, 'Reception', 10);

    $before = [
        'definitions' => WorkflowDefinition::count(),
        'stages' => WorkflowStage::count(),
        'transitions' => WorkflowTransition::count(),
        'runs' => WorkflowRun::count(),
    ];

    $this->actingAs($this->admin)->get('/company/workflows')->assertOk();

    expect(WorkflowDefinition::count())->toBe($before['definitions'])
        ->and(WorkflowStage::count())->toBe($before['stages'])
        ->and(WorkflowTransition::count())->toBe($before['transitions'])
        ->and(WorkflowRun::count())->toBe($before['runs']);
});

it('creates no new permission for this surface', function () {
    /* `view-platform` was already seeded and, until now, gated nothing. */
    $source = file_get_contents(app_path('Http/Controllers/Company/Workflow/WorkflowController.php'));

    expect($source)->toContain("Gate::authorize('view-platform')")
        ->and(Permission::where('name', 'view-workflows')->exists())->toBeFalse()
        ->and(Permission::where('name', 'manage-workflows')->exists())->toBeFalse();
});

it('reads across tenants rather than one organisation', function () {
    [$first] = adminClinic();
    [$second] = adminClinic();

    adminDefinition($first, null, 'First clinic flow');
    adminDefinition($second, null, 'Second clinic flow');

    $definitions = app(WorkflowAdministrationService::class)->definitions();

    expect(collect($definitions)->pluck('name')->all())
        ->toContain('First clinic flow')
        ->toContain('Second clinic flow');
});

/* ════════════════════════ configuration faults ═════════════════════════════ */

it('names a workflow that has no stages to start from', function () {
    /* `startWorkflow` refuses these; nothing said so before opening one. */
    [$clinic, $branch] = adminClinic();
    adminDefinition($clinic, $branch, 'Empty flow');

    $integrity = app(WorkflowAdministrationService::class)->integrity();

    expect(collect($integrity['no_stages'])->pluck('name')->all())->toBe(['Empty flow']);
});

it('names a workflow whose stages nothing connects', function () {
    [$clinic, $branch] = adminClinic();
    $definition = adminDefinition($clinic, $branch, 'Unconnected');
    adminStage($definition, 'Reception', 10);
    adminStage($definition, 'Doctor', 20);

    $integrity = app(WorkflowAdministrationService::class)->integrity();

    expect(collect($integrity['no_transitions'])->pluck('name')->all())->toBe(['Unconnected'])
        ->and($integrity['no_stages'])->toBe([])
        /*
         * Counted once. With no transitions at all every stage is both
         * unreachable and a dead end, so listing them would report the same
         * single break three times over.
         */
        ->and($integrity['unreachable_stages'])->toBe([])
        ->and($integrity['dead_end_stages'])->toBe([])
        ->and($integrity['total'])->toBe(1);
});

it('names a stage no case can reach and one no case can leave', function () {
    [$clinic, $branch] = adminClinic();
    $definition = adminDefinition($clinic, $branch, 'Three stage');

    $reception = adminStage($definition, 'Reception', 10);
    adminStage($definition, 'Isolated', 20);
    $discharge = adminStage($definition, 'Discharge', 30);

    /* Reception → Discharge, skipping the middle stage entirely. */
    WorkflowTransition::create([
        'workflow_definition_id' => $definition->id,
        'from_stage_id' => $reception->id,
        'to_stage_id' => $discharge->id,
    ]);

    $integrity = app(WorkflowAdministrationService::class)->integrity();

    expect(collect($integrity['unreachable_stages'])->pluck('stage')->all())->toBe(['Isolated'])
        ->and(collect($integrity['dead_end_stages'])->pluck('stage')->all())->toBe(['Isolated']);
});

it('names validation rules the engine never evaluates', function () {
    /*
     * `createTransition` accepts `validation_rules` and `canTransition` reads
     * `rules` only, so anyone who set these believing they gate a movement is
     * wrong and nothing has told them.
     */
    [$clinic, $branch] = adminClinic();
    $definition = adminDefinition($clinic, $branch, 'Guarded');
    $from = adminStage($definition, 'Reception', 10);
    $to = adminStage($definition, 'Doctor', 20);

    WorkflowTransition::create([
        'workflow_definition_id' => $definition->id,
        'from_stage_id' => $from->id,
        'to_stage_id' => $to->id,
        'validation_rules' => ['consent_signed' => true],
    ]);

    $integrity = app(WorkflowAdministrationService::class)->integrity();

    expect($integrity['unenforced_validation_rules'])->toHaveCount(1)
        ->and($integrity['unenforced_validation_rules'][0]['rules'])->toBe(['consent_signed' => true]);
});

it('names two workflows left switched on for the same branch', function () {
    [$clinic, $branch] = adminClinic();
    adminDefinition($clinic, $branch, 'Old flow');
    adminDefinition($clinic, $branch, 'New flow');

    $integrity = app(WorkflowAdministrationService::class)->integrity();

    expect($integrity['duplicate_active'])->toHaveCount(1)
        ->and($integrity['duplicate_active'][0]['names'])->toBe(['Old flow', 'New flow'])
        /* Counted in the total the page shows, so the headline and the list agree. */
        ->and($integrity['total'])->toBeGreaterThanOrEqual(1);
});

it('names a definition filed under one clinic and pointing at another', function () {
    [$clinic] = adminClinic();
    [$other, $otherBranch] = adminClinic();

    adminDefinition($clinic, $otherBranch, 'Smuggled');

    $integrity = app(WorkflowAdministrationService::class)->integrity();

    expect($integrity['foreign_branch'])->toHaveCount(1)
        ->and($integrity['foreign_branch'][0]['branch_clinic_id'])->toBe($other->id);
});

it('reports a clean configuration as clean', function () {
    [$clinic, $branch] = adminClinic();
    $definition = adminDefinition($clinic, $branch, 'Sound');
    $from = adminStage($definition, 'Reception', 10);
    $to = adminStage($definition, 'Doctor', 20);

    WorkflowTransition::create([
        'workflow_definition_id' => $definition->id,
        'from_stage_id' => $from->id,
        'to_stage_id' => $to->id,
    ]);

    expect(app(WorkflowAdministrationService::class)->integrity()['total'])->toBe(0);
});

/* ═══════════════════ what the engine does not support ══════════════════════ */

it('reports the absent failure state rather than implying one', function () {
    /*
     * The engine writes active, completed and cancelled and carries no error
     * column or attempt count, so nothing can be marked failed and nothing can
     * be retried. Inventing either would be inventing clinical policy.
     */
    $unsupported = app(WorkflowAdministrationService::class)->unsupported();

    expect($unsupported['has_failure_state'])->toBeFalse()
        ->and($unsupported['performer_scope'])->toBe('clinic_employees');
});

it('counts the engine event listeners at runtime instead of asserting there are none', function () {
    /* So the page stops saying it the moment somebody subscribes. */
    $unsupported = app(WorkflowAdministrationService::class)->unsupported();

    expect($unsupported['event_listeners'])->toHaveKeys([
        'WorkflowStarted',
        'WorkflowStageChanged',
        'WorkflowCompleted',
        'WorkflowCancelled',
    ]);
});

it('orders cases by how long they have been still, applying no threshold', function () {
    /*
     * "Stuck" is a clinical judgement — how long a case may sit differs per
     * stage and per clinic, and no threshold exists anywhere in this
     * application. The reader is given the order and decides.
     */
    $source = file_get_contents(app_path('Services/Workflow/WorkflowAdministrationService.php'));

    expect($source)->toContain('still_for_hours')
        ->and($source)->not->toContain('STUCK_AFTER')
        ->and($source)->not->toContain('is_stuck');
});

it('lists a live case with the stage it is sitting in', function () {
    /*
     * Exercised through the engine rather than by writing run rows, so the
     * reporting and the engine cannot drift apart without this failing.
     */
    [$clinic, $branch] = adminClinic();

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $encounter = ClinicalEncounter::create([
        'organization_id' => $clinic->organization_id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'encounter_number' => 'ENC-ADMIN-1',
        'status' => 'checked_in',
    ]);

    $definitions = app(WorkflowDefinitionService::class);
    $definition = $definitions->createDefinition($clinic, $branch, 'Live flow');
    $definitions->addStage($definition, 'Reception', 'reception', null, null, 10);

    app(WorkflowEngineService::class)->startWorkflow($encounter, $definition);

    $runs = app(WorkflowAdministrationService::class)->runs();

    expect($runs['by_status']['active'])->toBe(1)
        ->and($runs['longest_still'])->toHaveCount(1)
        ->and($runs['longest_still'][0]['stage'])->toBe('Reception')
        ->and($runs['longest_still'][0]['definition'])->toBe('Live flow')
        ->and($runs['longest_still'][0]['clinic'])->toBe($clinic->name);

    $history = app(WorkflowAdministrationService::class)->history();

    expect($history)->toHaveCount(1)
        ->and($history[0]['action'])->toBe('move')
        ->and($history[0]['to'])->toBe('Reception');
});
