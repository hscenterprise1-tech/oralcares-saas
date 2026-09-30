<?php

use App\Models\Clinic;
use App\Models\Patient;

test('a patient created without a UHID is given one', function () {
    $patient = Patient::factory()->create(['clinic_id' => Clinic::factory()->create()->id, 'uhid' => null]);

    expect($patient->fresh()->uhid)->toStartWith(config('app.patient.uhid_prefix', 'OC-'));
});

test('a UHID cannot be changed once issued', function () {
    $patient = Patient::factory()->create(['clinic_id' => Clinic::factory()->create()->id]);

    $patient->update(['uhid' => 'OC-99999999']);
})->throws(LogicException::class, 'A patient UHID cannot be changed once it has been issued.');

test('editing other patient details leaves the UHID alone', function () {
    $patient = Patient::factory()->create(['clinic_id' => Clinic::factory()->create()->id]);
    $uhid = $patient->uhid;

    $patient->update(['first_name' => 'Renamed']);

    expect($patient->fresh()->uhid)->toBe($uhid);
});
