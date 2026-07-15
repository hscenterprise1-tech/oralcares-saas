<?php

use App\LeadActivityType;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest cannot access crm routes', function () {
    $this->get('/master/leads')->assertRedirect('/login');
    $this->get('/master/leads/create')->assertRedirect('/login');
    $this->post('/master/leads', [])->assertRedirect('/login');
});

test('clinic user cannot access crm routes', function () {
    $clinicUser = User::factory()->create(); // default is clinic_user

    $this->actingAs($clinicUser)->get('/master/leads')->assertStatus(403);
    $this->actingAs($clinicUser)->get('/master/leads/create')->assertStatus(403);
    $this->actingAs($clinicUser)->post('/master/leads', [])->assertStatus(403);
});

test('master admin can access leads index and see page elements', function () {
    $admin = User::factory()->masterAdmin()->create();
    $response = $this->actingAs($admin)->get('/master/leads');

    $response->assertStatus(200)
        ->assertSee('Leads')
        ->assertSee('Add Lead');
});

test('leads index pagination preserves query string parameters', function () {
    $admin = User::factory()->masterAdmin()->create();
    Lead::factory(15)->create(); // 15 leads

    $response = $this->actingAs($admin)->get('/master/leads?search=Dental&status=new');

    $response->assertStatus(200);
    // Asserts query string is present in pagination links
    expect($response->getContent())->toContain('search=Dental')
        ->and($response->getContent())->toContain('status=new');
});

test('leads index search works correctly', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead1 = Lead::factory()->create(['company_name' => 'Searchable Dental Clinic']);
    $lead2 = Lead::factory()->create(['company_name' => 'Other Clinic']);

    $response = $this->actingAs($admin)->get('/master/leads?search=Searchable');

    $response->assertStatus(200)
        ->assertSee('Searchable Dental Clinic')
        ->assertDontSee('Other Clinic');
});

test('leads index status filter works correctly', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead1 = Lead::factory()->create(['status' => LeadStatus::New, 'company_name' => 'New Lead']);
    $lead2 = Lead::factory()->create(['status' => LeadStatus::Won, 'company_name' => 'Won Lead']);

    $response = $this->actingAs($admin)->get('/master/leads?status=won');

    $response->assertStatus(200)
        ->assertSee('Won Lead')
        ->assertDontSee('New Lead');
});

test('leads index source filter works correctly', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead1 = Lead::factory()->create(['source' => 'whatsapp', 'company_name' => 'Whatsapp Lead']);
    $lead2 = Lead::factory()->create(['source' => 'instagram', 'company_name' => 'Instagram Lead']);

    $response = $this->actingAs($admin)->get('/master/leads?source=instagram');

    $response->assertStatus(200)
        ->assertSee('Instagram Lead')
        ->assertDontSee('Whatsapp Lead');
});

test('master admin can onboard a lead successfully and verifies timeline logging', function () {
    $admin = User::factory()->masterAdmin()->create();

    $response = $this->actingAs($admin)->post('/master/leads', [
        'company_name' => 'Alpha Dental Care',
        'contact_name' => 'Dr. Alpha',
        'phone' => '+1234567890',
        'email' => 'alpha@dental.com',
        'source' => 'whatsapp',
        'status' => LeadStatus::New->value,
    ]);

    $lead = Lead::where('slug', 'alpha-dental-care')->first();
    expect($lead)->not->toBeNull();

    $response->assertRedirect(route('master.leads.show', $lead));

    // Verify activity logging
    $activity = LeadActivity::where('lead_id', $lead->id)->first();
    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe(LeadActivityType::LeadCreated)
        ->and($activity->user_id)->toBe($admin->id);
});

test('validation errors are rendered properly on lead creation', function () {
    $admin = User::factory()->masterAdmin()->create();

    $response = $this->actingAs($admin)->post('/master/leads', [
        'company_name' => '', // blank required name
        'contact_name' => 'Dr. Alpha',
        'phone' => '+1234567890',
        'source' => 'whatsapp',
        'status' => LeadStatus::New->value,
    ]);

    $response->assertSessionHasErrors(['company_name']);
});

test('invalid clinic user assignment is rejected by validator', function () {
    $admin = User::factory()->masterAdmin()->create();
    $clinicUser = User::factory()->create(); // default is clinic_user

    $response = $this->actingAs($admin)->post('/master/leads', [
        'company_name' => 'Alpha Dental Care',
        'contact_name' => 'Dr. Alpha',
        'phone' => '+1234567890',
        'source' => 'whatsapp',
        'status' => LeadStatus::New->value,
        'assigned_user_id' => $clinicUser->id,
    ]);

    $response->assertSessionHasErrors(['assigned_user_id']);
});

test('dual assignment to user and team is rejected by validator', function () {
    $admin = User::factory()->masterAdmin()->create();
    $employee = User::factory()->companyUser()->create();
    $team = Team::factory()->create();

    $response = $this->actingAs($admin)->post('/master/leads', [
        'company_name' => 'Alpha Dental Care',
        'contact_name' => 'Dr. Alpha',
        'phone' => '+1234567890',
        'source' => 'whatsapp',
        'status' => LeadStatus::New->value,
        'assigned_user_id' => $employee->id,
        'assigned_team_id' => $team->id,
    ]);

    $response->assertSessionHasErrors(['assigned_user_id']);
});

test('lead detail workspace displays correct metadata and timeline events', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create([
        'company_name' => 'Beta Dental Care',
        'contact_name' => 'Dr. Beta',
    ]);

    // Create note activity
    LeadActivity::create([
        'lead_id' => $lead->id,
        'user_id' => $admin->id,
        'type' => LeadActivityType::NoteAdded,
        'description' => 'Dr. Beta preferred afternoon calls.',
    ]);

    $response = $this->actingAs($admin)->get(route('master.leads.show', $lead));

    $response->assertStatus(200)
        ->assertSee('Beta Dental Care')
        ->assertSee('Dr. Beta')
        ->assertSee('Dr. Beta preferred afternoon calls.');
});

test('updating lead status logs status change activity', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create([
        'status' => LeadStatus::New,
    ]);

    $response = $this->actingAs($admin)->put(route('master.leads.update', $lead), [
        'company_name' => $lead->company_name,
        'contact_name' => $lead->contact_name,
        'phone' => $lead->phone,
        'source' => $lead->source,
        'status' => LeadStatus::Contacted->value,
    ]);

    $response->assertRedirect(route('master.leads.show', $lead));

    // Verify activity logged
    $activity = LeadActivity::where('lead_id', $lead->id)
        ->where('type', LeadActivityType::StatusChanged)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['old_status'])->toBe(LeadStatus::New->value)
        ->and($activity->properties['new_status'])->toBe(LeadStatus::Contacted->value);
});

test('updating lead status to won logs lead_won activity', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create([
        'status' => LeadStatus::New,
    ]);

    $this->actingAs($admin)->put(route('master.leads.update', $lead), [
        'company_name' => $lead->company_name,
        'contact_name' => $lead->contact_name,
        'phone' => $lead->phone,
        'source' => $lead->source,
        'status' => LeadStatus::Won->value,
    ]);

    // Verify activity logged
    $activity = LeadActivity::where('lead_id', $lead->id)
        ->where('type', LeadActivityType::LeadWon)
        ->first();

    expect($activity)->not->toBeNull();
});

test('updating lead status to lost logs lead_lost activity', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create([
        'status' => LeadStatus::New,
    ]);

    $this->actingAs($admin)->put(route('master.leads.update', $lead), [
        'company_name' => $lead->company_name,
        'contact_name' => $lead->contact_name,
        'phone' => $lead->phone,
        'source' => $lead->source,
        'status' => LeadStatus::Lost->value,
    ]);

    // Verify activity logged
    $activity = LeadActivity::where('lead_id', $lead->id)
        ->where('type', LeadActivityType::LeadLost)
        ->first();

    expect($activity)->not->toBeNull();
});

test('updating lead assignment logs assignment change activity', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create();
    $employee = User::factory()->companyUser()->create();

    $this->actingAs($admin)->put(route('master.leads.update', $lead), [
        'company_name' => $lead->company_name,
        'contact_name' => $lead->contact_name,
        'phone' => $lead->phone,
        'source' => $lead->source,
        'status' => $lead->status->value,
        'assigned_user_id' => $employee->id,
    ]);

    // Verify activity logged
    $activity = LeadActivity::where('lead_id', $lead->id)
        ->where('type', LeadActivityType::AssignmentChanged)
        ->first();

    expect($activity)->not->toBeNull();
});

test('adding quick internal note logs note_added activity with actor', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create();

    $response = $this->actingAs($admin)->post(route('master.leads.notes.store', $lead), [
        'note' => 'Call back on Friday afternoon.',
    ]);

    $response->assertRedirect(route('master.leads.show', $lead));

    // Verify note is logged
    $activity = LeadActivity::where('lead_id', $lead->id)
        ->where('type', LeadActivityType::NoteAdded)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toBe('Call back on Friday afternoon.')
        ->and($activity->user_id)->toBe($admin->id);
});

test('timeline renders actor details safely when actor user is deleted', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create();

    // Create note activity
    $activity = LeadActivity::create([
        'lead_id' => $lead->id,
        'user_id' => $admin->id,
        'type' => LeadActivityType::NoteAdded,
        'description' => 'Dr. Beta preferred afternoon calls.',
    ]);

    // Delete the actor
    $admin->delete();

    $adminResponse = User::factory()->masterAdmin()->create();
    $response = $this->actingAs($adminResponse)->get(route('master.leads.show', $lead));

    $response->assertStatus(200)
        ->assertSee('Operator: System')
        ->assertSee('Dr. Beta preferred afternoon calls.');
});

test('leads index displays derived next action date from pending task', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create(['company_name' => 'Followup Clinic']);

    // Create pending task
    Task::factory()->create([
        'title' => 'Follow up call',
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDays(3),
        'taskable_type' => 'lead',
        'taskable_id' => $lead->id,
    ]);

    $response = $this->actingAs($admin)->get('/master/leads');

    $response->assertStatus(200)
        ->assertSee('Followup Clinic')
        ->assertSee(now()->addDays(3)->format('Y-m-d H:i'));
});

test('valid internal company user and master admin can be assigned', function () {
    $admin = User::factory()->masterAdmin()->create();
    $employee = User::factory()->companyUser()->create();

    $this->actingAs($admin)->post('/master/leads', [
        'company_name' => 'Alpha Clinic',
        'contact_name' => 'Dr. Alpha',
        'phone' => '1234567890',
        'source' => 'website',
        'status' => LeadStatus::New->value,
        'assigned_user_id' => $employee->id,
    ])->assertRedirect();

    $this->actingAs($admin)->post('/master/leads', [
        'company_name' => 'Beta Clinic',
        'contact_name' => 'Dr. Beta',
        'phone' => '1234567890',
        'source' => 'website',
        'status' => LeadStatus::New->value,
        'assigned_user_id' => $admin->id,
    ])->assertRedirect();
});

test('clinic user is rejected as assignee at form request level', function () {
    $admin = User::factory()->masterAdmin()->create();
    $clinicUser = User::factory()->create(); // default is clinic_user

    $response = $this->actingAs($admin)->post('/master/leads', [
        'company_name' => 'Alpha Clinic',
        'contact_name' => 'Dr. Alpha',
        'phone' => '1234567890',
        'source' => 'website',
        'status' => LeadStatus::New->value,
        'assigned_user_id' => $clinicUser->id,
    ]);

    $response->assertSessionHasErrors(['assigned_user_id']);
});

test('invalid non existent user is rejected', function () {
    $admin = User::factory()->masterAdmin()->create();

    $response = $this->actingAs($admin)->post('/master/leads', [
        'company_name' => 'Alpha Clinic',
        'contact_name' => 'Dr. Alpha',
        'phone' => '1234567890',
        'source' => 'website',
        'status' => LeadStatus::New->value,
        'assigned_user_id' => 999999,
    ]);

    $response->assertSessionHasErrors(['assigned_user_id']);
});

test('active team can be assigned while inactive or soft deleted teams are rejected', function () {
    $admin = User::factory()->masterAdmin()->create();
    $activeTeam = Team::factory()->create(['is_active' => true]);
    $inactiveTeam = Team::factory()->create(['is_active' => false]);
    $softDeletedTeam = Team::factory()->create(['is_active' => true]);
    $softDeletedTeam->delete();

    // Active team
    $this->actingAs($admin)->post('/master/leads', [
        'company_name' => 'Active Team Clinic',
        'contact_name' => 'Dr. Alpha',
        'phone' => '1234567890',
        'source' => 'website',
        'status' => LeadStatus::New->value,
        'assigned_team_id' => $activeTeam->id,
    ])->assertRedirect();

    // Inactive team
    $this->actingAs($admin)->post('/master/leads', [
        'company_name' => 'Inactive Team Clinic',
        'contact_name' => 'Dr. Alpha',
        'phone' => '1234567890',
        'source' => 'website',
        'status' => LeadStatus::New->value,
        'assigned_team_id' => $inactiveTeam->id,
    ])->assertSessionHasErrors(['assigned_team_id']);

    // Soft deleted team
    $this->actingAs($admin)->post('/master/leads', [
        'company_name' => 'Soft Deleted Team Clinic',
        'contact_name' => 'Dr. Alpha',
        'phone' => '1234567890',
        'source' => 'website',
        'status' => LeadStatus::New->value,
        'assigned_team_id' => $softDeletedTeam->id,
    ])->assertSessionHasErrors(['assigned_team_id']);
});

test('assignment transition logging handles all changes uniquely', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create();
    $employee1 = User::factory()->companyUser()->create();
    $employee2 = User::factory()->companyUser()->create();
    $team = Team::factory()->create();

    // 1. Unassigned -> User
    $this->actingAs($admin)->put(route('master.leads.update', $lead), [
        'company_name' => $lead->company_name,
        'contact_name' => $lead->contact_name,
        'phone' => $lead->phone,
        'source' => $lead->source,
        'status' => $lead->status->value,
        'assigned_user_id' => $employee1->id,
    ]);
    expect(LeadActivity::where('lead_id', $lead->id)->where('type', LeadActivityType::AssignmentChanged)->first()->description)
        ->toContain("Lead assignment changed to User: {$employee1->name}.");

    // 2. User -> Different User
    $this->actingAs($admin)->put(route('master.leads.update', $lead), [
        'company_name' => $lead->company_name,
        'contact_name' => $lead->contact_name,
        'phone' => $lead->phone,
        'source' => $lead->source,
        'status' => $lead->status->value,
        'assigned_user_id' => $employee2->id,
    ]);
    expect(LeadActivity::where('lead_id', $lead->id)->where('type', LeadActivityType::AssignmentChanged)->get()->last()->description)
        ->toContain("Lead assignment changed to User: {$employee2->name}.");

    // 3. User -> Team
    $this->actingAs($admin)->put(route('master.leads.update', $lead), [
        'company_name' => $lead->company_name,
        'contact_name' => $lead->contact_name,
        'phone' => $lead->phone,
        'source' => $lead->source,
        'status' => $lead->status->value,
        'assigned_user_id' => null,
        'assigned_team_id' => $team->id,
    ]);
    expect(LeadActivity::where('lead_id', $lead->id)->where('type', LeadActivityType::AssignmentChanged)->get()->last()->description)
        ->toContain("Lead assignment changed to Team: {$team->name}.");

    // 4. Team -> Unassigned
    $this->actingAs($admin)->put(route('master.leads.update', $lead), [
        'company_name' => $lead->company_name,
        'contact_name' => $lead->contact_name,
        'phone' => $lead->phone,
        'source' => $lead->source,
        'status' => $lead->status->value,
        'assigned_user_id' => null,
        'assigned_team_id' => null,
    ]);
    expect(LeadActivity::where('lead_id', $lead->id)->where('type', LeadActivityType::AssignmentChanged)->get()->last()->description)
        ->toContain('Lead assignment changed to Unassigned.');
});

test('overdue pending tasks are visually highlighted with Overdue badge', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create(['company_name' => 'Overdue Clinic']);

    Task::factory()->create([
        'title' => 'Overdue Task',
        'status' => TaskStatus::Pending,
        'due_at' => now()->subDay(),
        'taskable_type' => 'lead',
        'taskable_id' => $lead->id,
    ]);

    $response = $this->actingAs($admin)->get('/master/leads');

    $response->assertStatus(200)
        ->assertSee('Overdue')
        ->assertSee(now()->subDay()->format('Y-m-d H:i'));
});

test('completed and cancelled tasks are excluded from next action', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create(['company_name' => 'Completed Clinic']);

    Task::factory()->create([
        'title' => 'Completed Task',
        'status' => TaskStatus::Completed,
        'due_at' => now()->subDay(),
        'taskable_type' => 'lead',
        'taskable_id' => $lead->id,
    ]);

    $response = $this->actingAs($admin)->get('/master/leads');

    $response->assertStatus(200)
        ->assertSee('No Action Scheduled');
});

test('timeline is paginated and older history is accessible', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create();

    // Create 30 note activities
    for ($i = 1; $i <= 30; $i++) {
        $activity = new LeadActivity;
        $activity->lead_id = $lead->id;
        $activity->user_id = $admin->id;
        $activity->type = LeadActivityType::NoteAdded;
        $activity->description = 'Note number '.sprintf('%02d', $i);
        $activity->created_at = now()->addMinutes($i);
        $activity->save();
    }

    $response = $this->actingAs($admin)->get(route('master.leads.show', $lead));
    $response->assertStatus(200)
        ->assertSee('Note number 30')
        ->assertDontSee('Note number 01'); // page limit is 25

    $responsePage2 = $this->actingAs($admin)->get(route('master.leads.show', $lead).'?activity_page=2');
    $responsePage2->assertStatus(200)
        ->assertSee('Note number 01')
        ->assertDontSee('Note number 30');
});

test('timeline notes validation trims and rejects whitespace only notes', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create();

    // Empty note
    $response = $this->actingAs($admin)->post(route('master.leads.notes.store', $lead), [
        'note' => '',
    ]);
    $response->assertSessionHasErrors(['note']);

    // Whitespace note
    $response2 = $this->actingAs($admin)->post(route('master.leads.notes.store', $lead), [
        'note' => '    ',
    ]);
    $response2->assertSessionHasErrors(['note']);

    // Note > 5000 chars
    $response3 = $this->actingAs($admin)->post(route('master.leads.notes.store', $lead), [
        'note' => str_repeat('a', 5001),
    ]);
    $response3->assertSessionHasErrors(['note']);
});

test('leads details and edit pages return 404 for soft deleted leads', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create();
    $lead->delete(); // soft delete

    $this->actingAs($admin)->get(route('master.leads.show', $lead))->assertStatus(404);
    $this->actingAs($admin)->get(route('master.leads.edit', $lead))->assertStatus(404);
    $this->actingAs($admin)->post(route('master.leads.notes.store', $lead), ['note' => 'test'])->assertStatus(404);
});

test('leads search and multiple filters work together', function () {
    $admin = User::factory()->masterAdmin()->create();
    $lead1 = Lead::factory()->create([
        'company_name' => 'Gamma Dental Care',
        'status' => LeadStatus::New,
        'source' => 'website',
    ]);
    $lead2 = Lead::factory()->create([
        'company_name' => 'Other Dental Care',
        'status' => LeadStatus::Won,
        'source' => 'website',
    ]);

    $response = $this->actingAs($admin)->get('/master/leads?search=Dental&status=new&source=website');
    $response->assertStatus(200)
        ->assertSee('Gamma Dental Care')
        ->assertDontSee('Other Dental Care');
});
