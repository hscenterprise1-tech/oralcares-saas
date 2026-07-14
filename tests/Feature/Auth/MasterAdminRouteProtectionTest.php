<?php

use App\Models\User;

test('guest is redirected to login when trying to access master area', function () {
    $response = $this->get('/master');

    $response->assertRedirect('/login');
});

test('master_admin is allowed to access master area', function () {
    $user = User::factory()->masterAdmin()->create();

    $response = $this->actingAs($user)->get('/master');

    $response->assertStatus(200)
        ->assertSee('OralCares Master Area');
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
