<?php

use App\ClinicRole;
use App\Models\Prescription;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicalOrderService;
use App\Services\ClinicalPrescriptionService;
use App\Services\Patient\FollowUps;
use App\Services\Patient\PatientBranchActivityRecorder;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * The record sections link only to screens that exist: a prescription links
 * to its consultation and print, an investigation to its order page.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Meera', 'Iyer');
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->actingAs($this->doctorUser);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->section = fn (string $key) => ($this->asDoctor)()->getJson(route('clinic.patients.360.section', [$this->patient->id, $key]))->assertOk()->json('data.0');
});

test('sections link to the consultation screen, print and the order page', function () {
    $rx = app(ClinicalPrescriptionService::class)->draftFor($this->encounter, $this->me);
    app(ClinicalPrescriptionService::class)->addItem($rx, ['drug_name' => 'Paracetamol', 'dosage' => '1 tablet', 'frequency' => 'TDS', 'duration' => '3 days']);
    app(ClinicalPrescriptionService::class)->finalize($rx->fresh(), $this->me);
    $order = app(ClinicalOrderService::class)->placeOrder($this->encounter, ['priority' => 'routine'], [['item_name' => 'CBC', 'category' => 'lab']], $this->me);
    app(FollowUps::class)->add($this->patient, '2026-11-02', 'Review', $this->me, $this->f->branch->id);

    expect(($this->section)('prescriptions'))->toMatchArray(['view_url' => route('clinic.consultations.show', $this->encounter->id), 'print_url' => route('clinic.prescriptions.print', Prescription::sole())])
        ->and(($this->section)('investigations'))->toMatchArray(['url' => route('clinic.orders.show', $order->id), 'status' => 'ordered'])
        ->and(($this->section)('follow-ups')['source_url'] ?? null)->toBeNull(); // added outside a consultation: nothing to link
});

test('the documents section offers uploading only to members who manage documents, with the types they may see', function () {
    $types = function (ClinicRole $role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return $this->actingAs($user)->withSession($session)->getJson(route('clinic.patients.360.section', [$this->patient->id, 'documents']))->assertOk()->json('upload');
    };

    $doctor = $types(ClinicRole::Doctor);
    $reception = $types(ClinicRole::Receptionist);

    expect($doctor['url'] ?? null)->toBe(route('clinic.patients.documents.store', $this->patient->id))
        ->and(array_column($doctor['types'], 'value'))->toContain('medical_report', 'xray')
        // Reception never sees clinical files, so is not offered clinical types to file.
        ->and(array_column($reception['types'], 'value'))->toBe(['id_proof', 'other']);

    // Billing staff do not open documents at all.
    [$billing, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $this->actingAs($billing)->withSession($session)->getJson(route('clinic.patients.360.section', [$this->patient->id, 'documents']))->assertForbidden();
});
