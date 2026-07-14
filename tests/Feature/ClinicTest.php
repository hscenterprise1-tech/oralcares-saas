<?php

use App\ClinicStatus;
use App\Models\Clinic;
use Illuminate\Database\QueryException;

test('a clinic can be created successfully', function () {
    $clinic = Clinic::factory()->create([
        'name' => 'Smile Dental Care',
        'slug' => 'smile-dental-care',
        'status' => ClinicStatus::Active,
    ]);

    expect($clinic)->not->toBeNull()
        ->and($clinic->name)->toBe('Smile Dental Care')
        ->and($clinic->slug)->toBe('smile-dental-care')
        ->and($clinic->status)->toBe(ClinicStatus::Active);
});

test('clinic status is cast to ClinicStatus enum correctly', function () {
    $activeClinic = Clinic::factory()->create(['status' => ClinicStatus::Active]);
    $inactiveClinic = Clinic::factory()->inactive()->create();
    $suspendedClinic = Clinic::factory()->suspended()->create();

    expect($activeClinic->status)->toBe(ClinicStatus::Active)
        ->and($inactiveClinic->status)->toBe(ClinicStatus::Inactive)
        ->and($suspendedClinic->status)->toBe(ClinicStatus::Suspended);
});

test('the default clinic status is active', function () {
    $clinic = Clinic::create([
        'name' => 'Default Clinic',
        'slug' => 'default-clinic',
    ]);

    $clinic->refresh();

    expect($clinic->status)->toBe(ClinicStatus::Active);
});

test('duplicate clinic slugs are rejected by database unique constraint', function () {
    Clinic::factory()->create(['slug' => 'duplicate-slug']);

    $this->expectException(QueryException::class);

    Clinic::factory()->create(['slug' => 'duplicate-slug']);
});
