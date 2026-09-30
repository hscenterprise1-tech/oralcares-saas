<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\WorkflowRun;
use App\Services\WorkflowDefinitionService;
use App\Services\WorkflowEngineService;
use App\Services\WorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The workflow engine's guards.
 *
 * The engine is the authoritative one and is reused, not replaced. What was
 * missing was every check around it: it wrote a tenant id from a column that no
 * longer exists, accepted another tenant's definition, ran definitions marked
 * inactive, and completed runs that were already finished.
 */
function workflowFixture(): array
{
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create([
        'clinic_id' => $clinic->id,
        'organization_id' => $clinic->organization_id,
    ]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $encounter = ClinicalEncounter::create([
        'organization_id' => $clinic->organization_id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'encounter_number' => 'ENC-'.fake()->unique()->numerify('####'),
        'status' => 'checked_in',
    ]);

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Dr. House',
        'email' => fake()->unique()->safeEmail(),
    ]);

    return compact('clinic', 'branch', 'patient', 'encounter', 'employee');
}

/** A definition with two stages and the transition between them. */
function workflowWithStages(Clinic $clinic, ?Branch $branch, string $name = 'Standard Flow'): array
{
    $definitions = app(WorkflowDefinitionService::class);
    $transitions = app(WorkflowTransitionService::class);

    $definition = $definitions->createDefinition($clinic, $branch, $name);
    $reception = $definitions->addStage($definition, 'Reception', 'reception', 'Floor 1', 'Reception', 10);
    $doctor = $definitions->addStage($definition, 'Doctor', 'doctor', 'Floor 2', 'Dental', 20);
    $transitions->createTransition($definition, $reception, $doctor);

    return [$definition, $reception, $doctor];
}

/* ═══════════════ the engine could not start a run at all ═══════════════════ */

it('starts a run against the current encounter schema', function () {
    /*
     * `clinical_encounters.clinic_id` was dropped when encounters became
     * organisation-scoped, but the engine still wrote the run's `clinic_id`
     * from it. That column read null against a NOT NULL constraint, so every
     * call failed on the integrity constraint — the engine could not start a
     * single run.
     *
     * The run's clinic now comes from the definition, which is the only place
     * that still records one and is the right answer: a run belongs to the
     * workflow it is running.
     */
    ['clinic' => $clinic, 'branch' => $branch, 'encounter' => $encounter, 'employee' => $employee] = workflowFixture();

    [$definition, $reception] = workflowWithStages($clinic, $branch);

    $run = app(WorkflowEngineService::class)->startWorkflow($encounter, $definition, $employee);

    expect($run->clinic_id)->toBe($clinic->id)
        ->and($run->branch_id)->toBe($branch->id)
        ->and($run->current_stage_id)->toBe($reception->id)
        ->and($run->status)->toBe('active')
        ->and($run->histories)->toHaveCount(1);
});

/* ═════════════════════════ tenant boundaries ═══════════════════════════════ */

it('refuses to run one clinic workflow on another clinic encounter', function () {
    ['encounter' => $encounter, 'employee' => $employee] = workflowFixture();

    $other = Clinic::factory()->create();
    $otherBranch = Branch::factory()->create([
        'clinic_id' => $other->id,
        'organization_id' => $other->organization_id,
    ]);

    [$definition] = workflowWithStages($other, $otherBranch);

    expect(fn () => app(WorkflowEngineService::class)->startWorkflow($encounter, $definition, $employee))
        ->toThrow(InvalidArgumentException::class);

    expect(WorkflowRun::count())->toBe(0);
});

it('refuses a branch-scoped definition on an encounter in another branch', function () {
    ['clinic' => $clinic, 'encounter' => $encounter, 'employee' => $employee] = workflowFixture();

    $otherBranch = Branch::factory()->create([
        'clinic_id' => $clinic->id,
        'organization_id' => $clinic->organization_id,
    ]);

    [$definition] = workflowWithStages($clinic, $otherBranch);

    expect(fn () => app(WorkflowEngineService::class)->startWorkflow($encounter, $definition, $employee))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to attach a definition to a branch of a different clinic', function () {
    /*
     * `createDefinition` took a clinic and a branch and never checked they
     * matched, so a definition could be filed under one tenant while pointing
     * at another tenant's branch — and the deactivation sweep that runs with it
     * would switch off that other tenant's active workflow.
     */
    $clinic = Clinic::factory()->create();
    $other = Clinic::factory()->create();
    $foreignBranch = Branch::factory()->create([
        'clinic_id' => $other->id,
        'organization_id' => $other->organization_id,
    ]);

    expect(fn () => app(WorkflowDefinitionService::class)->createDefinition($clinic, $foreignBranch, 'Smuggled'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a transition between stages of another definition', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create([
        'clinic_id' => $clinic->id,
        'organization_id' => $clinic->organization_id,
    ]);

    [$first, $firstReception] = workflowWithStages($clinic, $branch, 'First');
    [$second, , $secondDoctor] = workflowWithStages($clinic, $branch, 'Second');

    expect(fn () => app(WorkflowTransitionService::class)->createTransition($second, $firstReception, $secondDoctor))
        ->toThrow(InvalidArgumentException::class);
});

/* ═══════════════════════ state the engine ignored ══════════════════════════ */

it('refuses to start a definition that is switched off', function () {
    /*
     * `is_active` was written by the definition service and read by nothing,
     * so switching a workflow off changed nothing about what could run.
     */
    ['clinic' => $clinic, 'branch' => $branch, 'encounter' => $encounter, 'employee' => $employee] = workflowFixture();

    [$definition] = workflowWithStages($clinic, $branch);
    $definition->update(['is_active' => false]);

    expect(fn () => app(WorkflowEngineService::class)->startWorkflow($encounter, $definition->fresh(), $employee))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to start a definition that has no stages', function () {
    /* `firstOrFail` raised a missing-model error naming no workflow. */
    ['clinic' => $clinic, 'branch' => $branch, 'encounter' => $encounter, 'employee' => $employee] = workflowFixture();

    $definition = app(WorkflowDefinitionService::class)->createDefinition($clinic, $branch, 'Empty');

    expect(fn () => app(WorkflowEngineService::class)->startWorkflow($encounter, $definition, $employee))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a second run for an encounter that already has one', function () {
    /*
     * `workflow_runs.encounter_id` is unique, so this surfaced as a raw
     * integrity constraint violation rather than a refusal anything could
     * catch.
     */
    ['clinic' => $clinic, 'branch' => $branch, 'encounter' => $encounter, 'employee' => $employee] = workflowFixture();

    [$definition] = workflowWithStages($clinic, $branch);

    app(WorkflowEngineService::class)->startWorkflow($encounter, $definition, $employee);

    expect(fn () => app(WorkflowEngineService::class)->startWorkflow($encounter, $definition, $employee))
        ->toThrow(InvalidArgumentException::class);

    expect(WorkflowRun::count())->toBe(1);
});

/* ═════════════════════ finishing a run twice ═══════════════════════════════ */

it('refuses to complete a run that is already finished', function () {
    /*
     * `transitionTo` checked the status; completing and cancelling did not. A
     * finished run could be finished again, writing another history row and
     * firing the event a second time.
     */
    ['clinic' => $clinic, 'branch' => $branch, 'encounter' => $encounter, 'employee' => $employee] = workflowFixture();

    [$definition] = workflowWithStages($clinic, $branch);
    $engine = app(WorkflowEngineService::class);

    $run = $engine->startWorkflow($encounter, $definition, $employee);
    $engine->completeWorkflow($run, $employee);

    expect(fn () => $engine->completeWorkflow($run->fresh(), $employee))
        ->toThrow(InvalidArgumentException::class);

    expect($run->fresh()->histories)->toHaveCount(2);
});

it('refuses to cancel a run that is already completed', function () {
    ['clinic' => $clinic, 'branch' => $branch, 'encounter' => $encounter, 'employee' => $employee] = workflowFixture();

    [$definition] = workflowWithStages($clinic, $branch);
    $engine = app(WorkflowEngineService::class);

    $run = $engine->startWorkflow($encounter, $definition, $employee);
    $engine->completeWorkflow($run, $employee);

    expect(fn () => $engine->cancelWorkflow($run->fresh(), $employee, 'too late'))
        ->toThrow(InvalidArgumentException::class);

    expect($run->fresh()->status)->toBe('completed');
});
