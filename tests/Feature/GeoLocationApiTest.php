<?php

use App\Models\GeoCity;
use App\Models\GeoState;
use App\Models\User;
use App\UserType;
use Database\Seeders\GeoMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(GeoMasterDataSeeder::class);
    $this->user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
    ]);
});

test('unauthenticated user cannot access geo api endpoints', function () {
    $response = $this->getJson('/api/v1/geo/pincodes/380001');
    $response->assertStatus(401);
});

test('authenticated user can resolve pincode via api', function () {
    $response = $this->actingAs($this->user)->getJson('/api/v1/geo/pincodes/380001');

    $response->assertStatus(200)
        ->assertJsonPath('data.pincode', '380001')
        ->assertJsonPath('data.state.name', 'Gujarat')
        ->assertJsonPath('data.city.name', 'Ahmedabad')
        ->assertJsonPath('meta.resolved', true);
});

test('pincode api returns 404 for unknown pincode', function () {
    $response = $this->actingAs($this->user)->getJson('/api/v1/geo/pincodes/999999');

    $response->assertStatus(404)
        ->assertJsonPath('error', 'Pincode not found');
});

test('countries endpoint lists active countries', function () {
    $response = $this->actingAs($this->user)->getJson('/api/v1/geo/countries');

    $response->assertStatus(200)
        ->assertJsonStructure(['data', 'meta'])
        ->assertJsonPath('data.0.code', 'IN');
});

test('states endpoint lists states and union territories', function () {
    $response = $this->actingAs($this->user)->getJson('/api/v1/geo/states');

    $response->assertStatus(200)
        ->assertJsonPath('meta.count', 36);
});

test('cities endpoint filters by state id', function () {
    $gujarat = GeoState::where('code', 'GJ')->first();

    $response = $this->actingAs($this->user)->getJson("/api/v1/geo/cities?state_id={$gujarat->id}");

    $response->assertStatus(200)
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'tier']]]);
});

test('areas endpoint requires city_id', function () {
    $response = $this->actingAs($this->user)->getJson('/api/v1/geo/areas');
    $response->assertStatus(422);

    $ahmedabad = GeoCity::where('name', 'Ahmedabad')->first();
    $validResponse = $this->actingAs($this->user)->getJson("/api/v1/geo/areas?city_id={$ahmedabad->id}");

    $validResponse->assertStatus(200)
        ->assertJsonStructure(['data' => [['id', 'name', 'slug']]]);
});

test('search endpoint returns multi-tier results', function () {
    $response = $this->actingAs($this->user)->getJson('/api/v1/geo/search?q=Mumb');

    $response->assertStatus(200)
        ->assertJsonStructure(['data' => ['cities', 'areas', 'pincodes']]);
});

test('validate endpoint returns 200 for valid and 422 for mismatched combination', function () {
    $gujarat = GeoState::where('code', 'GJ')->first();
    $maharashtra = GeoState::where('code', 'MH')->first();
    $ahmedabad = GeoCity::where('name', 'Ahmedabad')->first();

    // Valid check
    $validRes = $this->actingAs($this->user)->postJson('/api/v1/geo/validate', [
        'state_id' => $gujarat->id,
        'city_id' => $ahmedabad->id,
        'pincode' => '380015',
    ]);
    $validRes->assertStatus(200)
        ->assertJsonPath('data.valid', true);

    // Mismatched check
    $invalidRes = $this->actingAs($this->user)->postJson('/api/v1/geo/validate', [
        'state_id' => $maharashtra->id,
        'city_id' => $ahmedabad->id,
    ]);
    $invalidRes->assertStatus(422)
        ->assertJsonPath('data.valid', false);
});
