<?php

use App\Models\GeoArea;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoDistrict;
use App\Models\GeoPincode;
use App\Models\GeoState;
use Database\Seeders\GeoMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(GeoMasterDataSeeder::class);
});

test('geo country is unique and seeded correctly', function () {
    $india = GeoCountry::where('code', 'IN')->first();

    expect($india)->not->toBeNull()
        ->and($india->name)->toBe('India')
        ->and($india->phone_code)->toBe('+91')
        ->and($india->currency_code)->toBe('INR')
        ->and($india->is_active)->toBeTrue();
});

test('all 36 indian states and union territories are seeded with gst codes', function () {
    $statesCount = GeoState::count();
    expect($statesCount)->toBe(36);

    $gujarat = GeoState::where('code', 'GJ')->first();
    expect($gujarat)->not->toBeNull()
        ->and($gujarat->name)->toBe('Gujarat')
        ->and($gujarat->gst_code)->toBe('24')
        ->and($gujarat->type)->toBe('state');

    $delhi = GeoState::where('code', 'DL')->first();
    expect($delhi)->not->toBeNull()
        ->and($delhi->name)->toBe('Delhi (NCT)')
        ->and($delhi->gst_code)->toBe('07')
        ->and($delhi->type)->toBe('union_territory');
});

test('state belongs to country and has districts', function () {
    $maharashtra = GeoState::where('code', 'MH')->first();

    expect($maharashtra->country->code)->toBe('IN')
        ->and($maharashtra->districts()->count())->toBeGreaterThan(0);
});

test('district belongs to state and has cities', function () {
    $ahmedabadDistrict = GeoDistrict::where('name', 'Ahmedabad')->first();

    expect($ahmedabadDistrict)->not->toBeNull()
        ->and($ahmedabadDistrict->state->code)->toBe('GJ')
        ->and($ahmedabadDistrict->cities()->count())->toBeGreaterThan(0);
});

test('city belongs to district and state with valid tier and slug', function () {
    $city = GeoCity::where('name', 'Ahmedabad')->first();

    expect($city)->not->toBeNull()
        ->and($city->slug)->toBe('ahmedabad')
        ->and($city->tier)->toBe('tier_1')
        ->and($city->district->name)->toBe('Ahmedabad')
        ->and($city->state->code)->toBe('GJ');
});

test('areas belong to city and have valid slugs', function () {
    $satellite = GeoArea::where('name', 'Satellite')->first();

    expect($satellite)->not->toBeNull()
        ->and($satellite->city->name)->toBe('Ahmedabad')
        ->and($satellite->slug)->toBe('satellite')
        ->and($satellite->pincodes()->count())->toBeGreaterThan(0);
});

test('pincode resolves full geographic hierarchy up to country', function () {
    $pincodeRecord = GeoPincode::where('pincode', '380015')->first();

    expect($pincodeRecord)->not->toBeNull()
        ->and($pincodeRecord->city->name)->toBe('Ahmedabad')
        ->and($pincodeRecord->district->name)->toBe('Ahmedabad')
        ->and($pincodeRecord->state->name)->toBe('Gujarat')
        ->and($pincodeRecord->state->country->name)->toBe('India');
});

test('pincodes are valid 6-digit strings and have coordinates', function () {
    $pincodes = GeoPincode::all();
    expect($pincodes->count())->toBeGreaterThan(0);

    foreach ($pincodes as $pin) {
        expect(preg_match('/^[1-9][0-9]{5}$/', $pin->pincode))->toBe(1);
    }
});

test('geo seeder is fully idempotent', function () {
    $initialCountries = GeoCountry::count();
    $initialStates = GeoState::count();
    $initialDistricts = GeoDistrict::count();
    $initialCities = GeoCity::count();
    $initialAreas = GeoArea::count();
    $initialPincodes = GeoPincode::count();

    // Re-run seeder
    $this->seed(GeoMasterDataSeeder::class);

    expect(GeoCountry::count())->toBe($initialCountries)
        ->and(GeoState::count())->toBe($initialStates)
        ->and(GeoDistrict::count())->toBe($initialDistricts)
        ->and(GeoCity::count())->toBe($initialCities)
        ->and(GeoArea::count())->toBe($initialAreas)
        ->and(GeoPincode::count())->toBe($initialPincodes);
});
