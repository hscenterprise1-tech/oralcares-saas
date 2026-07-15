<?php

use App\ClinicStatus;
use App\Data\ClinicOnboardingData;
use App\Models\Clinic;
use App\Models\User;
use App\Services\OnboardClinicService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('guest cannot access master dashboard', function () {
    $response = $this->get('/master/dashboard');
    $response->assertRedirect('/login');
});

test('master_admin can access dashboard', function () {
    $user = User::factory()->masterAdmin()->create();
    $response = $this->actingAs($user)->get('/master/dashboard');
    $response->assertStatus(200)->assertSee('Master Dashboard');
});

test('clinic_user cannot access master dashboard', function () {
    $user = User::factory()->create(); // default is clinic_user
    $response = $this->actingAs($user)->get('/master/dashboard');
    $response->assertStatus(403);
});

test('company_user cannot access master dashboard', function () {
    $user = User::factory()->companyUser()->create();
    $response = $this->actingAs($user)->get('/master/dashboard');
    $response->assertStatus(403);
});

test('master_admin login redirects to master dashboard', function () {
    $user = User::factory()->masterAdmin()->create([
        'password' => Hash::make('password123'),
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/master/dashboard');
});

test('dashboard displays real clinic counts', function () {
    $admin = User::factory()->masterAdmin()->create();

    // Create some clinics
    Clinic::factory()->create(['status' => ClinicStatus::Active]);
    Clinic::factory()->create(['status' => ClinicStatus::Inactive]);

    $response = $this->actingAs($admin)->get('/master/dashboard');

    $response->assertStatus(200)
        ->assertSee('2')
        ->assertSee('1');
});

test('dashboard displays recent clinics', function () {
    $admin = User::factory()->masterAdmin()->create();
    $clinic = Clinic::factory()->create(['name' => 'Dental Care Inc.']);

    $response = $this->actingAs($admin)->get('/master/dashboard');

    $response->assertStatus(200)
        ->assertSee('Dental Care Inc.');
});

test('master_admin can access clinic index', function () {
    $admin = User::factory()->masterAdmin()->create();
    $response = $this->actingAs($admin)->get('/master/clinics');
    $response->assertStatus(200)->assertSee('Clinics Management');
});

test('clinic index search works', function () {
    $admin = User::factory()->masterAdmin()->create();
    Clinic::factory()->create(['name' => 'Unique Searchable Clinic']);
    Clinic::factory()->create(['name' => 'Other Clinic']);

    $response = $this->actingAs($admin)->get('/master/clinics?search=Unique');

    $response->assertStatus(200)
        ->assertSee('Unique Searchable Clinic')
        ->assertDontSee('Other Clinic');
});

test('clinic index status filter works', function () {
    $admin = User::factory()->masterAdmin()->create();
    Clinic::factory()->create(['name' => 'Active Clinic', 'status' => ClinicStatus::Active]);
    Clinic::factory()->create(['name' => 'Inactive Clinic', 'status' => ClinicStatus::Inactive]);

    $response = $this->actingAs($admin)->get('/master/clinics?status=active');

    $response->assertStatus(200)
        ->assertSee('Active Clinic')
        ->assertDontSee('Inactive Clinic');
});

test('clinic index pagination is used', function () {
    $admin = User::factory()->masterAdmin()->create();
    Clinic::factory(12)->create();

    $response = $this->actingAs($admin)->get('/master/clinics');

    $response->assertStatus(200)
        ->assertSee('Next');
});

test('master_admin can access create clinic page', function () {
    $admin = User::factory()->masterAdmin()->create();
    $response = $this->actingAs($admin)->get('/master/clinics/create');
    $response->assertStatus(200)->assertSee('Onboard New Clinic');
});

test('create page lists only eligible clinic_user owners', function () {
    $admin = User::factory()->masterAdmin()->create();
    $eligible = User::factory()->create(['name' => 'Eligible Doctor']);
    $ineligibleMaster = User::factory()->masterAdmin()->create(['name' => 'Ineligible Master']);
    $ineligibleCompany = User::factory()->companyUser()->create(['name' => 'Ineligible Company']);

    $response = $this->actingAs($admin)->get('/master/clinics/create');

    $response->assertStatus(200)
        ->assertSee('Eligible Doctor')
        ->assertDontSee('Ineligible Master')
        ->assertDontSee('Ineligible Company');
});

test('master_admin can create a clinic successfully', function () {
    $admin = User::factory()->masterAdmin()->create();
    $owner = User::factory()->create();

    $response = $this->actingAs($admin)->post('/master/clinics', [
        'clinic_name' => 'Perfect Smile Dental',
        'clinic_slug' => 'perfect-smile',
        'owner_id' => $owner->id,
        'primary_branch_name' => 'Perfect Smile Main',
    ]);

    $clinic = Clinic::where('slug', 'perfect-smile')->first();
    expect($clinic)->not->toBeNull();

    $response->assertRedirect(route('master.clinics.show', $clinic));

    expect($clinic->primaryBranch->name)->toBe('Perfect Smile Main')
        ->and($clinic->primaryOwner->id)->toBe($owner->id)
        ->and($clinic->roles)->toHaveCount(4);
});

test('invalid owner type is rejected during clinic store', function () {
    $admin = User::factory()->masterAdmin()->create();
    $ineligible = User::factory()->masterAdmin()->create();

    $response = $this->actingAs($admin)->post('/master/clinics', [
        'clinic_name' => 'Perfect Smile Dental',
        'clinic_slug' => 'perfect-smile',
        'owner_id' => $ineligible->id,
        'primary_branch_name' => 'Perfect Smile Main',
    ]);

    expect(Clinic::where('slug', 'perfect-smile')->exists())->toBeFalse();
    $response->assertSessionHas('error');
});

test('duplicate slug is rejected during clinic store', function () {
    $admin = User::factory()->masterAdmin()->create();
    $owner = User::factory()->create();

    Clinic::factory()->create(['slug' => 'duplicate-slug']);

    $response = $this->actingAs($admin)->post('/master/clinics', [
        'clinic_name' => 'Perfect Smile Dental',
        'clinic_slug' => 'duplicate-slug',
        'owner_id' => $owner->id,
        'primary_branch_name' => 'Perfect Smile Main',
    ]);

    $response->assertSessionHasErrors(['clinic_slug']);
});

test('clinic details display correct owner, main branch, and roles', function () {
    $admin = User::factory()->masterAdmin()->create();

    $owner = User::factory()->create();
    $onboardService = app(OnboardClinicService::class);
    $onboardResult = $onboardService->execute(new ClinicOnboardingData(
        clinicName: 'Grace Dental Clinic',
        clinicSlug: 'grace-dental',
        owner: $owner,
        primaryBranchName: 'Grace Branch'
    ));

    $response = $this->actingAs($admin)->get(route('master.clinics.show', $onboardResult->clinic));

    $response->assertStatus(200)
        ->assertSee('Grace Dental Clinic')
        ->assertSee('grace-dental.oralcares.in')
        ->assertSee($owner->name)
        ->assertSee('Grace Branch')
        ->assertSee('clinic_admin');
});

test('non-master users cannot access clinic create/store/show routes', function () {
    $clinicUser = User::factory()->create();
    $clinic = Clinic::factory()->create();

    $this->actingAs($clinicUser)->get('/master/clinics/create')->assertStatus(403);
    $this->actingAs($clinicUser)->post('/master/clinics', [])->assertStatus(403);
    $this->actingAs($clinicUser)->get(route('master.clinics.show', $clinic))->assertStatus(403);
});

test('empty states render safely', function () {
    $admin = User::factory()->masterAdmin()->create();

    $response = $this->actingAs($admin)->get('/master/dashboard');
    $response->assertStatus(200)->assertSee('No Clinics Onboarded');

    $response2 = $this->actingAs($admin)->get('/master/clinics');
    $response2->assertStatus(200)->assertSee('No Clinics Found');

    $response3 = $this->actingAs($admin)->get('/master/clinics/create');
    $response3->assertStatus(200)->assertSee('No Eligible Clinic Users Found');
});
