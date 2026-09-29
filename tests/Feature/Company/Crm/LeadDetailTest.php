<?php

use App\ActivityType;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\CRM\DealPageService;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->adminUser = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    $this->repUser = User::factory()->companyUser()->create([
        'organization_id' => $this->organization->id,
    ]);
});

it('renders the canonical lead detail workspace with complete relationship DTO', function () {
    actingAs($this->adminUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->repUser->id,
        'company_name' => 'Smile Craft Dental',
        'contact_name' => 'Dr. Sameer Khan',
        'expected_mrr' => 20000,
    ]);

    // Create activity
    $lead->activities()->create([
        'user_id' => $this->repUser->id,
        'type' => ActivityType::Call,
        'description' => 'Initial discovery call completed.',
    ]);

    // Create task
    Task::create([
        'title' => 'Send Product Brochure',
        'type' => TaskType::FollowUp,
        'status' => TaskStatus::Pending,
        'priority' => TaskPriority::High,
        'due_at' => now()->addDays(2),
        'assigned_user_id' => $this->repUser->id,
        'taskable_type' => $lead->getMorphClass(),
        'taskable_id' => $lead->id,
    ]);

    $response = get(route('company.crm.leads.show', $lead));

    /*
     * The payload is now built by one service and organised by the zone that
     * renders each part, with everything past the command bar deferred. The
     * deal itself arrives with the document; the history and the task list
     * follow. What is asserted is unchanged — identity up front, a timeline,
     * and the tasks on the deal — against the contract that now exists.
     */
    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Leads/Show')
            ->has('deal')
            ->where('deal.id', $lead->id)
            ->where('deal.company_name', 'Smile Craft Dental')
            ->has('can')
            ->where('can.update', true)
        );

    /*
     * Only the audit tail is deferred now.
     *
     * `now` and `commercial` used to be deferred with four other groups, which
     * is what made this page settle six times before it could be read. They
     * arrive with the document; the history and the attachment list do not,
     * because they are long and nobody opens a deal to read them.
     */
    $deferred = $response->getOriginalContent()->getData()['page']['deferredProps'] ?? [];

    expect($deferred)->toHaveKeys(['history'])
        ->and($deferred)->not->toHaveKey('now')
        ->and($deferred)->not->toHaveKey('commercial')
        ->and($deferred)->not->toHaveKey('evaluation');

    $page = app(DealPageService::class);

    expect($page->now($lead)['tasks'])->toHaveCount(1)
        ->and($page->history($lead)['events'])->not->toBeEmpty();
});

it('surfaces address completeness on the lead detail workspace', function () {
    actingAs($this->adminUser);

    $incompleteLead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'address' => null,
        'city' => 'Pune',
        'state' => null,
    ]);

    /*
     * Address completeness belongs to the profile zone, which is where the
     * address is now shown and edited.
     */
    $page = app(DealPageService::class);

    get(route('company.crm.leads.show', $incompleteLead))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('deal.city', 'Pune'));

    expect($page->profile($incompleteLead)['location']['has_complete_address'])->toBeFalse();

    $completeLead = Lead::factory()->withAddress()->create([
        'organization_id' => $this->organization->id,
    ]);

    expect($page->profile($completeLead)['location']['has_complete_address'])->toBeTrue();
});

it('allows posting an internal note to the lead activity timeline', function () {
    actingAs($this->adminUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    $response = post(route('company.crm.leads.activities.store', $lead), [
        'type' => 'note',
        'description' => 'Doctor requested special multi-clinic discount.',
    ]);

    $response->assertSessionHasNoErrors();

    $this->assertDatabaseHas('activities', [
        'subject_id' => $lead->id,
        'description' => 'Doctor requested special multi-clinic discount.',
    ]);
});

it('allows logging call disposition with next follow-up scheduling', function () {
    actingAs($this->adminUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->repUser->id,
    ]);

    $response = post(route('company.crm.leads.call-disposition', $lead), [
        'duration_seconds' => 180,
        'disposition' => 'connected',
        'notes' => 'Product walkthrough discussed. Scheduling demo for Friday.',
        'next_followup_at' => now()->addDays(2)->toDateTimeString(),
    ]);

    $response->assertSessionHasNoErrors();

    $this->assertDatabaseHas('lead_call_logs', [
        'lead_id' => $lead->id,
        'disposition' => 'interested',
    ]);

    $lead->refresh();
    expect($lead->next_followup_at)->not->toBeNull();
});

it('allows creating and completing tasks for a lead', function () {
    actingAs($this->adminUser);

    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    // Create task
    $response = post(route('company.crm.leads.tasks.store', $lead), [
        'title' => 'Prepare custom SLA terms',
        'priority' => 'high',
        'due_at' => now()->addDays(3)->toDateString(),
    ]);

    $response->assertSessionHasNoErrors();

    $task = Task::where('taskable_id', $lead->id)->first();
    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Prepare custom SLA terms');

    // Complete task
    $completeResponse = post(route('company.crm.leads.tasks.complete', [$lead, $task]));
    $completeResponse->assertSessionHasNoErrors();

    $task->refresh();
    expect($task->status->value ?? $task->status)->toBe(TaskStatus::Completed->value);
});
