<?php

use App\LeadActivityType;
use App\LeadStatus;
use App\Models\Clinic;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\LogLeadActivityService;
use App\TaskPriority;
use App\TaskStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('internal team creation and relationship to users', function () {
    $team = Team::factory()->create([
        'name' => 'Sales Team',
        'key' => 'sales-team',
    ]);

    $admin = User::factory()->masterAdmin()->create();
    $employee = User::factory()->companyUser()->create();

    $team->addUser($admin);
    $team->addUser($employee);

    expect($team->users)->toHaveCount(2)
        ->and($team->users->contains($admin))->toBeTrue()
        ->and($team->users->contains($employee))->toBeTrue()
        ->and($admin->teams->contains($team))->toBeTrue()
        ->and($employee->teams->contains($team))->toBeTrue();
});

test('clinic user cannot be added to platform operational teams', function () {
    $team = Team::factory()->create();
    $clinicUser = User::factory()->create(); // default is clinic_user

    expect(fn () => $team->addUser($clinicUser))
        ->toThrow(InvalidArgumentException::class, 'Clinic users cannot be added to platform operational teams.');
});

test('lead creation with enum status and default source', function () {
    $lead = Lead::factory()->create([
        'company_name' => 'Smile Dental Care',
        'slug' => 'smile-dental',
        'status' => LeadStatus::New,
        'source' => 'whatsapp',
    ]);

    expect($lead->company_name)->toBe('Smile Dental Care')
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->source)->toBe('whatsapp')
        ->and($lead->assigned_user_id)->toBeNull()
        ->and($lead->assigned_team_id)->toBeNull();
});

test('lead can be assigned directly to an internal user', function () {
    $lead = Lead::factory()->create();
    $employee = User::factory()->companyUser()->create();

    $lead->update(['assigned_user_id' => $employee->id]);

    expect($lead->assignedUser->id)->toBe($employee->id)
        ->and($employee->assignedLeads->contains($lead))->toBeTrue();
});

test('lead can be assigned to a team queue', function () {
    $lead = Lead::factory()->create();
    $team = Team::factory()->create();

    $lead->update(['assigned_team_id' => $team->id]);

    expect($lead->assignedTeam->id)->toBe($team->id);
});

test('lead cannot be assigned to both user and team simultaneously', function () {
    $employee = User::factory()->companyUser()->create();
    $team = Team::factory()->create();

    expect(fn () => Lead::factory()->create([
        'assigned_user_id' => $employee->id,
        'assigned_team_id' => $team->id,
    ]))->toThrow(InvalidArgumentException::class, 'A lead cannot be assigned to both a user and a team simultaneously.');
});

test('lead cannot be assigned to a clinic user', function () {
    $clinicUser = User::factory()->create(); // default is clinic_user

    expect(fn () => Lead::factory()->create([
        'assigned_user_id' => $clinicUser->id,
    ]))->toThrow(InvalidArgumentException::class, 'CRM records cannot be assigned to clinic users.');
});

test('lead activity logging via service preserves actor and payload', function () {
    $lead = Lead::factory()->create();
    $employee = User::factory()->companyUser()->create();
    $service = app(LogLeadActivityService::class);

    $activity = $service->execute(
        lead: $lead,
        type: LeadActivityType::StatusChanged,
        description: 'Lead marked as demo scheduled',
        actor: $employee,
        properties: ['old_status' => 'new', 'new_status' => 'demo_scheduled']
    );

    expect($activity->lead_id)->toBe($lead->id)
        ->and($activity->user_id)->toBe($employee->id)
        ->and($activity->type)->toBe(LeadActivityType::StatusChanged)
        ->and($activity->description)->toBe('Lead marked as demo scheduled')
        ->and($activity->properties)->toBe(['old_status' => 'new', 'new_status' => 'demo_scheduled']);

    expect($lead->activities)->toHaveCount(1)
        ->and($lead->activities->first()->id)->toBe($activity->id);
});

test('task creation and assignment constraints', function () {
    $employee = User::factory()->companyUser()->create();
    $task = Task::factory()->create([
        'title' => 'Follow up phone call',
        'assigned_user_id' => $employee->id,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => now()->addDay(),
    ]);

    expect($task->title)->toBe('Follow up phone call')
        ->and($task->assignedUser->id)->toBe($employee->id)
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($employee->assignedTasks->contains($task))->toBeTrue();
});

test('task cannot be assigned to both user and team simultaneously', function () {
    $employee = User::factory()->companyUser()->create();
    $team = Team::factory()->create();

    expect(fn () => Task::factory()->create([
        'assigned_user_id' => $employee->id,
        'assigned_team_id' => $team->id,
        'due_at' => now()->addDay(),
    ]))->toThrow(InvalidArgumentException::class, 'A task cannot be assigned to both a user and a team simultaneously.');
});

test('task polymorphic taskable relationship works', function () {
    $lead = Lead::factory()->create();

    $task = Task::factory()->create([
        'title' => 'Prepare Demo Clinic',
        'taskable_type' => 'lead',
        'taskable_id' => $lead->id,
        'due_at' => now()->addDay(),
    ]);

    expect($task->taskable)->toBeInstanceOf(Lead::class)
        ->and($task->taskable->id)->toBe($lead->id)
        ->and($lead->tasks->contains($task))->toBeTrue();
});

test('soft delete behavior on lead, team, and task', function () {
    $lead = Lead::factory()->create();
    $team = Team::factory()->create();
    $task = Task::factory()->create([
        'due_at' => now()->addDay(),
    ]);

    $lead->delete();
    $team->delete();
    $task->delete();

    expect(Lead::find($lead->id))->toBeNull()
        ->and(Team::find($team->id))->toBeNull()
        ->and(Task::find($task->id))->toBeNull();

    expect(Lead::withTrashed()->find($lead->id))->not->toBeNull()
        ->and(Team::withTrashed()->find($team->id))->not->toBeNull()
        ->and(Task::withTrashed()->find($task->id))->not->toBeNull();
});

test('lead to clinic relationship future readiness', function () {
    $lead = Lead::factory()->create();
    $clinic = Clinic::factory()->create();

    $lead->update(['clinic_id' => $clinic->id]);

    expect($lead->clinic->id)->toBe($clinic->id);
});

test('lead activities are strictly append-only in Eloquent', function () {
    $lead = Lead::factory()->create();
    $activity = LeadActivity::create([
        'lead_id' => $lead->id,
        'type' => LeadActivityType::LeadCreated,
        'description' => 'Initial ingestion',
    ]);

    expect($activity->exists)->toBeTrue();

    expect(fn () => $activity->update(['description' => 'Updated Description']))
        ->toThrow(LogicException::class, 'Lead activities are append-only and cannot be modified.');

    $activity->description = 'Direct modification';
    expect(fn () => $activity->save())
        ->toThrow(LogicException::class, 'Lead activities are append-only and cannot be modified.');

    $activity = $activity->fresh();
    expect(fn () => $activity->delete())
        ->toThrow(LogicException::class, 'Lead activities are append-only and cannot be deleted.');
});

test('soft deleting a Lead preserves activity timeline', function () {
    $lead = Lead::factory()->create();
    $activity = LeadActivity::create([
        'lead_id' => $lead->id,
        'type' => LeadActivityType::LeadCreated,
        'description' => 'Initial ingestion',
    ]);

    $lead->delete();

    $dbRecord = DB::table('lead_activities')->where('id', $activity->id)->first();
    expect($dbRecord)->not->toBeNull();
});

test('task polymorphic relationship records stable lowercase domain morph aliases in db', function () {
    $lead = Lead::factory()->create();

    $task = Task::factory()->create([
        'title' => 'Prepare Demo Clinic',
        'taskable_type' => 'lead',
        'taskable_id' => $lead->id,
        'due_at' => now()->addDay(),
    ]);

    $dbRecord = DB::table('tasks')->where('id', $task->id)->first();
    expect($dbRecord->taskable_type)->toBe('lead');
});

test('leads unique slug generation handles duplicates and updates stably', function () {
    $lead1 = Lead::factory()->create(['company_name' => 'Grand Central Dental']);
    expect($lead1->slug)->toBe('grand-central-dental');

    $lead2 = Lead::factory()->create(['company_name' => 'Grand Central Dental']);
    $lead3 = Lead::factory()->create(['company_name' => 'Grand Central Dental']);

    expect($lead2->slug)->toStartWith('grand-central-dental-')
        ->and($lead3->slug)->toStartWith('grand-central-dental-')
        ->and($lead2->slug)->not->toBe($lead3->slug);

    $lead4 = Lead::factory()->create([
        'company_name' => 'Other Dental',
        'slug' => 'grand-central-dental',
    ]);
    expect($lead4->slug)->toStartWith('grand-central-dental-');

    expect(fn () => DB::table('leads')->insert([
        'company_name' => 'Forced Duplicate',
        'slug' => 'grand-central-dental',
        'contact_name' => 'Tester',
        'email' => 'test@test.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $originalSlug = $lead2->slug;
    $lead2->update(['contact_name' => 'New Contact Name']);
    expect($lead2->slug)->toBe($originalSlug);
});

test('task completion timestamp is managed automatically based on status', function () {
    $task1 = Task::factory()->create([
        'status' => TaskStatus::Completed,
        'due_at' => now()->addDay(),
    ]);
    expect($task1->completed_at)->not->toBeNull();

    $task2 = Task::factory()->create([
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
    ]);
    expect($task2->completed_at)->toBeNull();

    $task2->update(['status' => TaskStatus::Completed]);
    $task2->refresh();
    expect($task2->completed_at)->not->toBeNull();

    $task2->update(['status' => TaskStatus::Pending]);
    $task2->refresh();
    expect($task2->completed_at)->toBeNull();

    $task2->update(['status' => TaskStatus::Completed]);
    $task2->refresh();
    expect($task2->completed_at)->not->toBeNull();

    $previousTime = $task2->completed_at;
    $task2->update(['title' => 'Updated Task Title']);
    $task2->refresh();
    expect($task2->completed_at->timestamp)->toBe($previousTime->timestamp);

    $task2->update(['status' => TaskStatus::Cancelled]);
    $task2->refresh();
    expect($task2->completed_at)->toBeNull();
});
