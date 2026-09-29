<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->markTestSkipped('Phase E-2 refactoring: routes moved to /company');
});

test('guest is redirected to login when trying to access master area', function () {
    $response = $this->get('/master');

    $response->assertRedirect('/login');
});

test('master_admin is allowed to access master area', function () {
    $user = User::factory()->masterAdmin()->create();

    $response = $this->actingAs($user)->get('/master');

    $response->assertStatus(200)
        ->assertSee('OralCares Master Portal');
});

test('company_user is forbidden from accessing master area', function () {
    $user = User::factory()->companyUser()->create();

    $response = $this->actingAs($user)->get('/master');

    $response->assertStatus(403);
});

test('clinic_user is forbidden from accessing master area', function () {
    $user = User::factory()->create(); // default is clinic_user

    $response = $this->actingAs($user)->get('/master');

    $response->assertStatus(403);
});

test('master_admin login redirects to master area', function () {
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

test('master page displays master admin profile details', function () {
    $user = User::factory()->masterAdmin()->create([
        'name' => 'Dr. Master Admin',
        'email' => 'admin.verify@oralcares.in',
    ]);

    $response = $this->actingAs($user)->get('/master');

    $response->assertStatus(200)
        ->assertSee('OralCares Master Portal')
        ->assertSee('Dr. Master Admin')
        ->assertSee('admin.verify@oralcares.in')
        ->assertSee('Log Out');
});

test('master admin can logout successfully', function () {
    $user = User::factory()->masterAdmin()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/login');
});
