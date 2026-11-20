<?php

use App\ClinicRole;
use App\Models\ConsentRecord;
use App\Models\PatientTimelineEvent;
use App\Models\SecurityAuditLog;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * Consents (X1): captured as evidence, revoked with a reason and kept,
 * by members who hold consent.manage, in their own clinic only.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Asha', 'Rao');
    $this->url = "/clinic/patients/{$this->patient->id}/consents";
    $this->as = function (ClinicRole $role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return fn () => $this->actingAs($user)->withSession($session);
    };
    $this->text = 'I agree to a root canal treatment on tooth 16 and understand the risks explained to me.';
});

test('reception captures a consent as evidence: wording hashed, method, who and when', function () {
    $id = ($this->as)(ClinicRole::Receptionist)()->postJson($this->url, ['type' => 'treatment', 'method' => 'written', 'version' => 'RCT-v2', 'text' => $this->text])
        ->assertCreated()->json('data.id');

    expect(ConsentRecord::find($id))
        ->status->toBe(ConsentRecord::GRANTED)
        ->text_hash->toBe(hash('sha256', $this->text))
        ->consent_version->toBe('RCT-v2')
        ->capture_method->toBe('written')
        ->and(PatientTimelineEvent::where('patient_id', $this->patient->id)->where('event_type', 'consent_captured')->exists())->toBeTrue()
        ->and(SecurityAuditLog::where('event', 'consent.created')->exists())->toBeTrue();

    ($this->as)(ClinicRole::Doctor)()->getJson($this->url)->assertOk()
        ->assertJsonPath('data.0.type', 'treatment')
        ->assertJsonPath('data.0.status', 'granted')
        ->assertJsonPath('data.0.text_hash', hash('sha256', $this->text));
});

test('revoking needs a reason, keeps the record, and happens once', function () {
    $consent = ($this->as)(ClinicRole::Receptionist)()->postJson($this->url, ['type' => 'communication', 'method' => 'verbal'])->json('data.id');
    $doctor = ($this->as)(ClinicRole::Doctor);

    $doctor()->postJson("{$this->url}/{$consent}/revoke", [])->assertUnprocessable();
    $doctor()->postJson("{$this->url}/{$consent}/revoke", ['reason' => 'Patient asked for no WhatsApp messages'])->assertOk();
    $doctor()->postJson("{$this->url}/{$consent}/revoke", ['reason' => 'Again'])->assertUnprocessable();

    expect(ConsentRecord::find($consent))
        ->status->toBe(ConsentRecord::REVOKED)->revocation_reason->toBe('Patient asked for no WhatsApp messages')->revoked_at->not->toBeNull()
        ->and(SecurityAuditLog::where('event', 'consent.revoked')->count())->toBe(1)
        ->and(PatientTimelineEvent::where('event_type', 'consent_revoked')->count())->toBe(1);
});

test('only known types and methods are recorded, and only by members with consent.manage', function () {
    $reception = ($this->as)(ClinicRole::Receptionist);
    $reception()->postJson($this->url, ['type' => 'anything', 'method' => 'written'])->assertUnprocessable();
    $reception()->postJson($this->url, ['type' => 'treatment', 'method' => 'telepathy'])->assertUnprocessable();

    ($this->as)(ClinicRole::BillingStaff)()->postJson($this->url, ['type' => 'treatment', 'method' => 'written'])->assertForbidden();
    expect(ConsentRecord::count())->toBe(0);
});

test('a consent is reached only through its own patient, in its own clinic', function () {
    $consent = ($this->as)(ClinicRole::Receptionist)()->postJson($this->url, ['type' => 'treatment', 'method' => 'written'])->json('data.id');
    $someoneElse = $this->f->patient('Ravi', 'Menon');

    ($this->as)(ClinicRole::Doctor)()->postJson("/clinic/patients/{$someoneElse->id}/consents/{$consent}/revoke", ['reason' => 'Wrong patient'])->assertNotFound();

    $other = ReceptionFixture::make('other-clinic');
    $asOther = fn () => $this->actingAs($other->owner)->withSession($other->session);
    $asOther()->getJson($this->url)->assertNotFound();
    $asOther()->postJson($this->url, ['type' => 'treatment', 'method' => 'written'])->assertNotFound();
    $asOther()->postJson("{$this->url}/{$consent}/revoke", ['reason' => 'Not ours'])->assertNotFound();

    expect(ConsentRecord::find($consent)->status)->toBe(ConsentRecord::GRANTED);
});

test('a consent form can be printed to sign: the clinic\'s wording, the patient, signature lines, and nothing is recorded', function () {
    $page = ($this->as)(ClinicRole::Receptionist)()->get("{$this->url}/print")->assertOk();

    $page->assertSee('Patient consent')->assertSee('Asha Rao')->assertSee('General treatment')->assertSee('Witness (clinic staff)');
    expect(ConsentRecord::query()->where('patient_id', $this->patient->id)->count())->toBe(0)
        ->and(SecurityAuditLog::where('event', 'patient.consent_form_printed')->exists())->toBeTrue();
});

test('one consent can be printed by its key, and an unknown key prints nothing', function () {
    ($this->as)(ClinicRole::Receptionist)()->get("{$this->url}/print?type=communication")->assertOk()->assertSee('Communication')->assertDontSee('General treatment');
    ($this->as)(ClinicRole::Receptionist)()->get("{$this->url}/print?type=nothing")->assertNotFound();
});

test('only a member who may manage consents prints the form', function () {
    ($this->as)(ClinicRole::BillingStaff)()->get("{$this->url}/print")->assertForbidden();
});
