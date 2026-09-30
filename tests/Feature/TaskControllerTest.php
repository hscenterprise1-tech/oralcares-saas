<?php

use App\Http\Middleware\CompanyPortalMiddleware;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * There is no tasks page. My Work is the task list — it already carried your
 * open tasks, everyone else's for an admin, and the modal these endpoints
 * serve, so the index was a third list of the same rows. The URL redirects
 * rather than 404s because a task notification may still point at it.
 */
test('the tasks url sends you to My Work', function () {
    $this->withoutMiddleware(CompanyPortalMiddleware::class);
    $user = crmUser();
    Task::factory()->count(3)->create(['assigned_user_id' => $user->id]);

    $this->actingAs($user)
        ->get('/company/crm/tasks')
        ->assertRedirect('/company/crm/my-workspace');
});

test('My Work lists the tasks the retired page used to', function () {
    $this->withoutMiddleware(CompanyPortalMiddleware::class);
    $user = crmUser();
    Task::factory()->count(3)->create(['assigned_user_id' => $user->id]);

    $this->actingAs($user)
        ->get('/company/crm/my-workspace')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/MyWorkspace')
            ->has('tasks', 3)
        );
});

test('store creates a new task', function () {
    $this->withoutMiddleware(CompanyPortalMiddleware::class);
    $user = crmUser();
    $lead = Lead::factory()->create();

    $data = [
        'title' => 'Follow up on proposal',
        'description' => 'Detailed description',
        'status' => 'pending',
        'type' => 'follow_up',
        'priority' => 'high',
        'due_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        'taskable_type' => 'App\\Models\\Lead',
        'taskable_id' => $lead->id,
    ];

    $this->actingAs($user)
        ->post('/company/crm/tasks', $data)
        ->assertRedirect();

    $this->assertDatabaseHas('tasks', [
        'title' => 'Follow up on proposal',
        'type' => 'follow_up',
        'priority' => 'high',
        'taskable_type' => 'App\Models\Lead',
        'taskable_id' => $lead->id,
    ]);
});

test('update modifies an existing task', function () {
    $this->withoutMiddleware(CompanyPortalMiddleware::class);
    $user = crmUser();
    $task = Task::factory()->create([
        'title' => 'Old Title',
        'status' => 'pending',
    ]);

    $data = [
        'title' => 'New Title',
        'description' => 'New Description',
        'status' => 'completed',
        'type' => 'to_do',
        'priority' => 'critical',
        'due_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
    ];

    $this->actingAs($user)
        ->put("/company/crm/tasks/{$task->id}", $data)
        ->assertRedirect();

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'title' => 'New Title',
        'status' => 'completed',
    ]);
});

test('destroy removes a task', function () {
    $this->withoutMiddleware(CompanyPortalMiddleware::class);
    $user = crmUser();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->delete("/company/crm/tasks/{$task->id}")
        ->assertRedirect();

    $this->assertSoftDeleted('tasks', [
        'id' => $task->id,
    ]);
});
