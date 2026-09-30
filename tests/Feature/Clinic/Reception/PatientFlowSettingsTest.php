<?php

use App\ClinicRole;
use App\Models\Appointment;
use App\Models\ClinicRoom;
use App\Models\ClinicSettingAudit;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->room = ClinicRoom::create(['branch_id' => $this->f->branch->id, 'name' => 'Chair 1', 'key' => 'chair-1', 'type' => 'chair', 'is_active' => true]);
});

function bookWithRoom(int $roomId)
{
    $test = test();

    return $test->actingAs($test->f->owner)->withSession($test->f->session)->post('/clinic/appointments', [
        'patient_id' => $test->f->patient()->id, 'practitioner_id' => $test->f->doctorA->id, 'room_id' => $roomId,
        'scheduled_at' => '2026-09-28 11:00:00', 'duration_minutes' => 15, 'appointment_type' => 'consultation',
    ]);
}

test('rooms are off until a clinic turns them on', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session)->get('/clinic/admin/settings')
        ->assertInertia(fn ($page) => $page->where('patientFlow.rooms_enabled', false));
});

test('with rooms off, appointment booking offers no rooms and ignores a room sent anyway', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session)->get('/clinic/appointments?date=2026-09-28')
        ->assertInertia(fn ($page) => $page->where('rooms', []));

    bookWithRoom($this->room->id)->assertSessionHasNoErrors();

    expect(Appointment::sole()->room_id)->toBeNull();
});

test('with rooms on, booking offers and saves the room', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session)->put('/clinic/admin/settings/patient-flow', ['rooms_enabled' => true]);

    $this->actingAs($this->f->owner)->withSession($this->f->session)->get('/clinic/appointments?date=2026-09-28')
        ->assertInertia(fn ($page) => $page->has('rooms', 1));
    bookWithRoom($this->room->id);

    expect(Appointment::sole()->room_id)->toBe($this->room->id);
});

test('turning rooms off keeps rooms already saved on appointments', function () {
    $this->f->clinic->update(['patient_flow_config' => ['rooms_enabled' => true]]);
    bookWithRoom($this->room->id);

    $this->actingAs($this->f->owner)->withSession($this->f->session)->put('/clinic/admin/settings/patient-flow', ['rooms_enabled' => false]);

    expect(Appointment::sole()->room_id)->toBe($this->room->id);
});

test('turning rooms on or off is audited', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session)->put('/clinic/admin/settings/patient-flow', ['rooms_enabled' => true]);

    $audit = ClinicSettingAudit::sole();
    expect($audit->section)->toBe('patient_flow')
        ->and($audit->old_values)->toBe(['rooms_enabled' => false])
        ->and($audit->new_values)->toBe(['rooms_enabled' => true])
        ->and($audit->user_id)->toBe($this->f->owner->id);
});

test('staff without settings permission cannot switch rooms', function () {
    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $this->actingAs($receptionist)->withSession($session)->put('/clinic/admin/settings/patient-flow', ['rooms_enabled' => true])->assertForbidden();

    expect($this->f->clinic->fresh()->patient_flow_config)->toBeNull();
});
