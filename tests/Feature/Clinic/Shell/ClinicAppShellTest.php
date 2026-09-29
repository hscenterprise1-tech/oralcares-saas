<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

test('unauthenticated visitor accessing clinic dashboard is redirected to clinic login', function () {
    $response = $this->get('/clinic/dashboard');
    $response->assertRedirect('/clinic/login');
});

test('clinic dashboard renders successfully with operational metrics and shared clinic context', function () {
    $clinic = Clinic::factory()->create(['name' => 'Metro Care Clinic', 'slug' => 'metro-care']);
    $branch = Branch::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'City Center Branch',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $user = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'name' => 'Dr. Sameer Khan',
    ]);

    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'is_primary_owner' => true,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $patient = Patient::create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Aarav',
        'last_name' => 'Mehta',
        'gender' => 'male',
        'dob' => '1998-04-12',
    ]);

    Appointment::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'scheduled_at' => now(),
        'status' => 'scheduled',
        'type' => 'Consultation',
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
            ClinicContext::SESSION_BRANCH_ID => $branch->id,
        ])
        ->get('/clinic/dashboard?clinic=metro-care');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Dashboard')
        ->has('metrics')
        ->where('metrics.totalAppointments', 1)
        ->has('upcomingAppointments', 1)
        ->has('queue')
        ->has('practitioners')
        ->where('clinic.id', $clinic->id)
        ->where('clinic.name', 'Metro Care Clinic')
        ->where('clinic.is_owner', true)
        ->where('clinic.active_branch.id', $branch->id)
        ->where('clinic.active_branch.name', 'City Center Branch')
        ->where('clinic.active_branch.display_name', null)
        ->where('clinic.active_branch.logo_url', null)
    );
});

test('active branch exposes its own name/logo override for the portal header to fall back on', function () {
    $clinic = Clinic::factory()->create(['name' => 'Metro Care Clinic', 'display_name' => 'Metro Care']);
    $branch = Branch::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'City Center Branch',
        'display_name' => 'MC City Center',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'is_primary_owner' => true,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
            ClinicContext::SESSION_BRANCH_ID => $branch->id,
        ])
        ->get('/clinic/dashboard?clinic='.$clinic->slug);

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->where('clinic.branding_name', 'Metro Care')
        ->where('clinic.active_branch.display_name', 'MC City Center')
    );
});
