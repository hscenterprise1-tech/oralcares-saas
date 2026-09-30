<?php

use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\UserType;

beforeEach(function () {
    $this->user = grantCrmPermissions(
        User::factory()->create(['user_type' => UserType::CompanyUser])
    );
    $this->lead = Lead::factory()->create();
});

it('can create a task for a lead', function () {
    $response = $this->actingAs($this->user)
        ->post(route('company.crm.leads.tasks.store', $this->lead), [
            'title' => 'Test Task',
            'type' => TaskType::Call->value,
            'priority' => TaskPriority::High->value,
            'due_at' => now()->addDay()->toDateTimeString(),
        ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('tasks', [
        'title' => 'Test Task',
        'taskable_id' => $this->lead->id,
        'taskable_type' => $this->lead->getMorphClass(),
        'status' => TaskStatus::Pending->value,
    ]);
});

it('can complete a task for a lead', function () {
    $task = Task::factory()->create([
        'taskable_id' => $this->lead->id,
        'taskable_type' => $this->lead->getMorphClass(),
        'status' => TaskStatus::Pending->value,
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('company.crm.leads.tasks.complete', [$this->lead, $task]));

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => TaskStatus::Completed->value,
    ]);
});

it('can delete a task for a lead', function () {
    $task = Task::factory()->create([
        'taskable_id' => $this->lead->id,
        'taskable_type' => $this->lead->getMorphClass(),
    ]);

    $response = $this->actingAs($this->user)
        ->delete(route('company.crm.leads.tasks.destroy', [$this->lead, $task]));

    $response->assertSessionHas('success');

    $this->assertSoftDeleted('tasks', [
        'id' => $task->id,
    ]);
});
