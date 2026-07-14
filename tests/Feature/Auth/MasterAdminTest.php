<?php

use App\Models\User;
use App\UserType;
use Database\Seeders\MasterAdminSeeder;

test('user_type column is correctly cast to UserType enum', function () {
    $masterUser = User::factory()->masterAdmin()->create();
    $companyUser = User::factory()->companyUser()->create();
    $clinicUser = User::factory()->create(); // default is clinic_user

    expect($masterUser->user_type)->toBe(UserType::MasterAdmin)
        ->and($companyUser->user_type)->toBe(UserType::CompanyUser)
        ->and($clinicUser->user_type)->toBe(UserType::ClinicUser);
});

test('user correctly identifies Master Super Admin status', function () {
    $masterUser = User::factory()->masterAdmin()->create();
    $clinicUser = User::factory()->create();

    expect($masterUser->isMasterAdmin())->toBeTrue()
        ->and($clinicUser->isMasterAdmin())->toBeFalse();
});

test('MasterAdminSeeder creates the Super Admin user securely from environment configuration', function () {
    // Delete any existing user with master email to test from clean state
    $email = config('auth.master_admin.email', 'master@oralcares.com');
    User::where('email', $email)->delete();

    $this->seed(MasterAdminSeeder::class);

    $user = User::where('email', $email)->first();

    expect($user)->not->toBeNull()
        ->and($user->user_type)->toBe(UserType::MasterAdmin)
        ->and($user->isMasterAdmin())->toBeTrue();
});

test('MasterAdminSeeder is idempotent and does not create duplicate users', function () {
    $email = config('auth.master_admin.email', 'master@oralcares.com');
    User::where('email', $email)->delete();

    // Run first time
    $this->seed(MasterAdminSeeder::class);
    $count1 = User::where('email', $email)->count();

    // Run second time
    $this->seed(MasterAdminSeeder::class);
    $count2 = User::where('email', $email)->count();

    expect($count1)->toBe(1)
        ->and($count2)->toBe(1);
});

test('MasterAdminSeeder does not overwrite existing password', function () {
    $email = config('auth.master_admin.email', 'master@oralcares.com');
    User::where('email', $email)->delete();

    // Run first time to create user
    $this->seed(MasterAdminSeeder::class);
    $user = User::where('email', $email)->first();

    // Change password manually
    $newPassword = 'my-new-secure-password';
    $user->password = Hash::make($newPassword);
    $user->save();

    // Run seeder again
    $this->seed(MasterAdminSeeder::class);
    $user->refresh();

    // Verify password is NOT reset to seed default
    expect(Hash::check($newPassword, $user->password))->toBeTrue();
});
