<?php

use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicDepartment;
use App\Models\ClinicMembership;
use App\Models\ClinicOperatingHour;
use App\Models\ClinicRoom;
use App\Models\CompanySubscription;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function setupInfrastructureTest()
{
    // Reusing the helper from OnboardingTest (if defined globally) or recreate here
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true,
        // Admin pages require staff.manage / branch.manage; the owner always holds them.
        'is_primary_owner' => true,
    ]);

    OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => 'completed',
        'status' => 'completed',
    ]);

    // Seat/branch-limit enforcement reads the clinic's active subscription —
    // without one, ResourceLimitService fails closed ("no active
    // subscription") and branch creation is refused.
    CompanySubscription::factory()->create(['clinic_id' => $clinic->id]);

    // Create a default branch so rooms have something to attach to
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $org->id]);

    return [$user, $clinic, $branch];
}

it('can list branches', function () {
    [$user] = setupInfrastructureTest();
    $this->actingAs($user)->get(route('admin.branches.index'))->assertStatus(200);
});

it('can create a branch', function () {
    [$user, $clinic] = setupInfrastructureTest();
    $response = $this->actingAs($user)->post(route('admin.branches.store'), [
        'name' => 'New Branch',
        'slug' => 'new-branch',
        'status' => 'active',
        'is_main' => false,
    ]);
    $response->assertRedirect(route('admin.branches.index'));
    expect(Branch::where('slug', 'new-branch')->exists())->toBeTrue();
});

it('can create a branch with address, contact, capacity and compliance fields', function () {
    [$user] = setupInfrastructureTest();
    $response = $this->actingAs($user)->post(route('admin.branches.store'), [
        'name' => 'Andheri Branch',
        'slug' => 'andheri-branch',
        'status' => 'active',
        'is_main' => false,
        'address_line1' => '12 MIDC Road',
        'address_line2' => 'Andheri East',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'pincode' => '400093',
        'phone' => '02226840000',
        'whatsapp_number' => '9820012345',
        'resource_capacity' => 4,
        'gstin' => '27ABCDE1234F1Z5',
        'clinical_establishment_registration_number' => 'CEA/MH/00123',
    ]);
    $response->assertRedirect(route('admin.branches.index'));

    $branch = Branch::where('slug', 'andheri-branch')->first();
    expect($branch)->not->toBeNull()
        ->and($branch->city)->toBe('Mumbai')
        ->and($branch->pincode)->toBe('400093')
        ->and($branch->whatsapp_number)->toBe('9820012345')
        ->and($branch->resource_capacity)->toBe(4)
        ->and($branch->gstin)->toBe('27ABCDE1234F1Z5')
        ->and($branch->clinical_establishment_registration_number)->toBe('CEA/MH/00123');
});

it('can create a branch with a name/logo override', function () {
    [$user] = setupInfrastructureTest();
    $logo = UploadedFile::fake()->create('branch-logo.png', 50, 'image/png');

    $response = $this->actingAs($user)->post(route('admin.branches.store'), [
        'name' => 'Bandra Branch',
        'slug' => 'bandra-branch',
        'status' => 'active',
        'is_main' => false,
        'display_name' => 'OralCares Bandra',
        'logo' => $logo,
    ]);
    $response->assertRedirect(route('admin.branches.index'));

    $branch = Branch::where('slug', 'bandra-branch')->first();
    expect($branch->display_name)->toBe('OralCares Bandra')
        ->and($branch->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($branch->logo_path);
});

it('can update a branch\'s logo override and replaces the previous one', function () {
    [$user, , $branch] = setupInfrastructureTest();

    $first = UploadedFile::fake()->create('logo.png', 50, 'image/png');
    $this->actingAs($user)->put(route('admin.branches.update', $branch), [
        'name' => $branch->name,
        'slug' => $branch->slug,
        'status' => $branch->status->value,
        'is_main' => $branch->is_main,
        'logo' => $first,
    ])->assertRedirect(route('admin.branches.index'));

    $branch->refresh();
    $firstPath = $branch->logo_path;
    expect($firstPath)->not->toBeNull();
    Storage::disk('public')->assertExists($firstPath);

    $second = UploadedFile::fake()->create('logo2.png', 50, 'image/png');
    $this->actingAs($user)->put(route('admin.branches.update', $branch), [
        'name' => $branch->name,
        'slug' => $branch->slug,
        'status' => $branch->status->value,
        'is_main' => $branch->is_main,
        'logo' => $second,
    ])->assertRedirect(route('admin.branches.index'));

    $branch->refresh();
    expect($branch->logo_path)->not->toBe($firstPath);
    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($branch->logo_path);
});

it('syncs operating hours to clinic_operating_hours on branch create and update', function () {
    [$user] = setupInfrastructureTest();
    $response = $this->actingAs($user)->post(route('admin.branches.store'), [
        'name' => 'Powai Branch',
        'slug' => 'powai-branch',
        'status' => 'active',
        'is_main' => false,
        'operating_hours' => [
            ['day_of_week' => 1, 'open_time' => '09:00', 'close_time' => '18:00', 'is_closed' => false],
            ['day_of_week' => 0, 'open_time' => '09:00', 'close_time' => '18:00', 'is_closed' => true],
        ],
    ]);
    $response->assertRedirect(route('admin.branches.index'));

    $branch = Branch::where('slug', 'powai-branch')->first();
    expect(ClinicOperatingHour::where('branch_id', $branch->id)->count())->toBe(2);

    $monday = ClinicOperatingHour::where('branch_id', $branch->id)->where('day_of_week', 1)->first();
    expect($monday->is_closed)->toBeFalse()
        ->and($monday->open_time)->toBe('09:00');

    $sunday = ClinicOperatingHour::where('branch_id', $branch->id)->where('day_of_week', 0)->first();
    expect($sunday->is_closed)->toBeTrue();

    $this->actingAs($user)->put(route('admin.branches.update', $branch), [
        'name' => 'Powai Branch',
        'slug' => 'powai-branch',
        'status' => 'active',
        'is_main' => false,
        'operating_hours' => [
            ['day_of_week' => 1, 'open_time' => '10:00', 'close_time' => '19:00', 'is_closed' => false],
        ],
    ])->assertRedirect(route('admin.branches.index'));

    $monday->refresh();
    expect($monday->open_time)->toBe('10:00')
        ->and($monday->close_time)->toBe('19:00');
});

it('can list departments', function () {
    [$user] = setupInfrastructureTest();
    $this->actingAs($user)->get(route('admin.departments.index'))->assertStatus(200);
});

it('can create a department', function () {
    [$user, $clinic] = setupInfrastructureTest();
    $response = $this->actingAs($user)->post(route('admin.departments.store'), [
        'name' => 'Cardiology',
        'key' => 'CARD',
        'is_active' => true,
    ]);
    $response->assertRedirect(route('admin.departments.index'));
    expect(ClinicDepartment::where('name', 'Cardiology')->exists())->toBeTrue();
});

it('can list rooms', function () {
    [$user] = setupInfrastructureTest();
    $this->actingAs($user)->get(route('admin.rooms.index'))->assertStatus(200);
});

it('can create a room', function () {
    [$user, $clinic, $branch] = setupInfrastructureTest();
    $response = $this->actingAs($user)->post(route('admin.rooms.store'), [
        'branch_id' => $branch->id,
        'name' => 'Room 101',
        'key' => 'R101',
        'type' => 'Consultation',
        'is_active' => true,
    ]);
    $response->assertRedirect(route('admin.rooms.index'));
    expect(ClinicRoom::where('name', 'Room 101')->exists())->toBeTrue();
});
