<?php

use App\Models\GeoArea;
use App\Models\GeoPincode;
use Database\Seeders\GeoMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(GeoMasterDataSeeder::class);
    $this->tempDir = storage_path('framework/testing/geo');
    if (! File::isDirectory($this->tempDir)) {
        File::makeDirectory($this->tempDir, 0755, true);
    }
});

afterEach(function () {
    if (File::isDirectory($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

test('pincode importer dry run validates without writing', function () {
    $csvContent = <<<'CSV'
pincode,office,district,state,city,latitude,longitude
380099,Test Locality,Ahmedabad,Gujarat,Ahmedabad,23.0100,72.5000
CSV;
    $tempCsv = $this->tempDir.'/test_pincodes.csv';
    file_put_contents($tempCsv, $csvContent);

    $this->artisan('geo:import-pincodes', [
        'file' => $tempCsv,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();

    // Verify record was NOT written
    expect(GeoPincode::where('pincode', '380099')->exists())->toBeFalse();
});

test('pincode importer live mode writes and is idempotent', function () {
    $csvContent = <<<'CSV'
pincode,office,district,state,city,latitude,longitude
380099,Test New Area,Ahmedabad,Gujarat,Ahmedabad,23.0100,72.5000
999999,Invalid Pincode,Bad,Bad,Bad,0,0
CSV;
    $tempCsv = $this->tempDir.'/test_pincodes.csv';
    file_put_contents($tempCsv, $csvContent);

    $this->artisan('geo:import-pincodes', [
        'file' => $tempCsv,
    ])
        ->assertSuccessful();

    // Verify valid row created
    expect(GeoPincode::where('pincode', '380099')->exists())->toBeTrue()
        ->and(GeoArea::where('name', 'Test New Area')->exists())->toBeTrue();

    // Re-run to verify idempotency
    $this->artisan('geo:import-pincodes', [
        'file' => $tempCsv,
    ])
        ->assertSuccessful();

    expect(GeoPincode::where('pincode', '380099')->count())->toBe(1);
});
