<?php

use App\Services\GeoLocationService;
use Database\Seeders\GeoMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(GeoMasterDataSeeder::class);
    $this->geoService = app(GeoLocationService::class);
});

test('resolvePincode returns complete geographic hierarchy for 380001', function () {
    $result = $this->geoService->resolvePincode('380001');

    expect($result)->not->toBeNull()
        ->and($result['pincode'])->toBe('380001')
        ->and($result['country']['code'])->toBe('IN')
        ->and($result['state']['name'])->toBe('Gujarat')
        ->and($result['district']['name'])->toBe('Ahmedabad')
        ->and($result['city']['name'])->toBe('Ahmedabad')
        ->and($result['areas'])->toBeArray()
        ->and(count($result['areas']))->toBeGreaterThan(0);
});

test('resolvePincode supports multiple areas mapped to the same pincode 380015', function () {
    $result = $this->geoService->resolvePincode('380015');

    expect($result)->not->toBeNull()
        ->and($result['pincode'])->toBe('380015')
        ->and($result['city']['name'])->toBe('Ahmedabad');

    $areaNames = array_column($result['areas'], 'name');
    expect($areaNames)->toContain('Satellite')
        ->and($areaNames)->toContain('Vastrapur')
        ->and($areaNames)->toContain('Prahlad Nagar');
});

test('resolvePincode returns null for non-existent or invalid pincode', function () {
    expect($this->geoService->resolvePincode('999999'))->toBeNull()
        ->and($this->geoService->resolvePincode('123'))->toBeNull()
        ->and($this->geoService->resolvePincode(''))->toBeNull();
});

test('getStates returns active states and caches the result', function () {
    $states = $this->geoService->getStates();
    expect($states->count())->toBe(36);

    // Verify cache key exists
    expect(Cache::has('geo:states:1'))->toBeTrue();
});

test('getCities filters by state and district', function () {
    $gujaratState = $this->geoService->getStates()->firstWhere('code', 'GJ');
    $gujaratCities = $this->geoService->getCities(stateId: $gujaratState->id);

    expect($gujaratCities->count())->toBeGreaterThan(0);
    $cityNames = $gujaratCities->pluck('name')->toArray();
    expect($cityNames)->toContain('Ahmedabad')
        ->and($cityNames)->toContain('Surat')
        ->and($cityNames)->toContain('Vadodara');
});

test('search returns matching cities, areas, and pincodes', function () {
    $results = $this->geoService->search('Ahmed');

    expect($results['cities'])->toBeArray()
        ->and(count($results['cities']))->toBeGreaterThan(0)
        ->and($results['cities'][0]['name'])->toBe('Ahmedabad');

    $pincodeResults = $this->geoService->search('380015');
    expect($pincodeResults['pincodes'])->toBeArray()
        ->and(count($pincodeResults['pincodes']))->toBeGreaterThan(0)
        ->and($pincodeResults['pincodes'][0]['pincode'])->toBe('380015');
});

test('validateHierarchy validates correct and incorrect relationships', function () {
    $gujaratState = $this->geoService->getStates()->firstWhere('code', 'GJ');
    $maharashtraState = $this->geoService->getStates()->firstWhere('code', 'MH');
    $ahmedabadCity = $this->geoService->getCities(stateId: $gujaratState->id)->firstWhere('name', 'Ahmedabad');

    // Valid combination: Ahmedabad + Gujarat + 380015
    $validCheck = $this->geoService->validateHierarchy([
        'state_id' => $gujaratState->id,
        'city_id' => $ahmedabadCity->id,
        'pincode' => '380015',
    ]);
    expect($validCheck['valid'])->toBeTrue()
        ->and($validCheck['errors'])->toBeEmpty();

    // Invalid combination: Ahmedabad + Maharashtra
    $invalidCheck = $this->geoService->validateHierarchy([
        'state_id' => $maharashtraState->id,
        'city_id' => $ahmedabadCity->id,
    ]);
    expect($invalidCheck['valid'])->toBeFalse()
        ->and(count($invalidCheck['errors']))->toBeGreaterThan(0)
        ->and($invalidCheck['errors'][0])->toContain('does not belong to');
});
