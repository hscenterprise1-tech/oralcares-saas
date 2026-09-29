<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use App\Models\Patient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->clinic = Clinic::factory()->create(['organization_id' => $this->organization->id]);
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->organization->id]);
    $this->patient = Patient::withoutGlobalScopes()->create([
        'clinic_id' => $this->clinic->id, 'organization_id' => $this->organization->id, 'uhid' => 'UH-'.Str::upper(Str::random(8)),
        'first_name' => 'A', 'last_name' => 'B', 'name' => 'A B', 'phone' => '9123456789', 'gender' => 'male',
    ]);
});

it('refuses a clinical record that belongs to no clinic', function (string $table, array $row) {
    $row = array_merge($row, ['organization_id' => $this->organization->id, 'patient_id' => $this->patient->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table($table)->insert($row + ['clinic_id' => null]))->toThrow(QueryException::class);
})->with([
    'encounter' => ['clinical_encounters', ['encounter_number' => 'E-1', 'status' => 'waiting', 'started_at' => '2026-09-29 10:00:00']],
    'prescription' => ['prescriptions', ['prescription_number' => 'RX-1', 'status' => 'draft']],
]);
