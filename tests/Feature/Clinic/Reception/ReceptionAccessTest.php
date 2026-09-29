<?php

use App\ClinicRole;
use App\Models\ClinicQueueItem;
use App\QueueItemStatus;
use App\Services\Reception\ReceptionCheckIn;
use App\VisitPriority;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * Every reception route needs `queue.manage`, and nothing another clinic
 * owns can be read or changed from this one (404, so its existence is not
 * confirmed).
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('home-clinic');
    $this->other = ReceptionFixture::make('other-clinic');
    $this->theirItem = app(ReceptionCheckIn::class)->checkIn($this->other->clinic, $this->other->branch, $this->other->patient('Their', 'Patient'));
});

test('staff without queue permission are refused every reception route', function (string $method, string $url) {
    [$billing, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->f->patient());

    $this->actingAs($billing)->withSession($session)
        ->call($method, str_replace('{item}', (string) $item->id, $url), ['action' => 'call', 'priority' => 'emergency', 'patient_id' => $item->patientVisit->patient_id])
        ->assertForbidden();

    expect($item->fresh()->status)->toBe(QueueItemStatus::Waiting)
        ->and($item->fresh()->priority)->toBe(VisitPriority::Normal);
})->with([
    'live queue' => ['GET', '/clinic/reception'],
    'check-in search' => ['GET', '/clinic/reception/check-in/search?q=Rahul'],
    'check in' => ['POST', '/clinic/reception/check-in'],
    'walk in' => ['POST', '/clinic/reception/walk-in'],
    'change status' => ['PATCH', '/clinic/reception/queue/{item}/status'],
    'mark emergency' => ['PATCH', '/clinic/reception/queue/{item}/priority'],
    'call next' => ['POST', '/clinic/queue/call-next'],
]);

test('receptionists and doctors can open the live queue', function (ClinicRole $role) {
    [$user, $session] = $this->f->memberWithRole($role);

    $this->actingAs($user)->withSession($session)->get('/clinic/reception')->assertOk();
})->with([ClinicRole::Receptionist, ClinicRole::Doctor]);

test('another clinic\'s queue item cannot be moved', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session)
        ->patch("/clinic/reception/queue/{$this->theirItem->id}/status", ['action' => 'call'])
        ->assertNotFound();

    expect($this->theirItem->fresh()->status)->toBe(QueueItemStatus::Waiting);
});

test('another clinic\'s queue item cannot be marked as an emergency', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session)
        ->patch("/clinic/reception/queue/{$this->theirItem->id}/priority", ['priority' => 'emergency'])
        ->assertNotFound();

    expect($this->theirItem->fresh()->priority)->toBe(VisitPriority::Normal);
});

test('another clinic\'s patient cannot be checked in here', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session)
        ->post('/clinic/reception/check-in', ['patient_id' => $this->other->patient('Foreign', 'Person')->id])
        ->assertNotFound();

    expect(ClinicQueueItem::count())->toBe(1);
});

test('another clinic\'s appointment cannot be checked in here', function () {
    $appointment = $this->other->appointment($this->other->patient('Foreign', 'Booking'), $this->other->doctorA, CarbonImmutable::parse('2026-09-28 11:00'));

    $this->actingAs($this->f->owner)->withSession($this->f->session)
        ->post('/clinic/reception/check-in', ['appointment_id' => $appointment->id])
        ->assertNotFound();
});

test('another clinic\'s doctor cannot be chosen at check-in', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session)
        ->post('/clinic/reception/check-in', ['patient_id' => $this->f->patient()->id, 'practitioner_id' => $this->other->doctorA->id])
        ->assertNotFound();
});

test('check-in search never returns another clinic\'s patients', function () {
    $this->f->patient('Their', 'Lookalike');

    $response = $this->actingAs($this->f->owner)->withSession($this->f->session)->getJson('/clinic/reception/check-in/search?q=Their');

    expect($response->json('patients.*.short_name'))->toBe(['Their L.']);
});

test('another clinic\'s queue never appears on this live queue', function () {
    $this->actingAs($this->f->owner)->withSession($this->f->session)->get('/clinic/reception')
        ->assertInertia(fn ($page) => $page->component('Clinic/Reception/Index')->where('board.queue', []));
});

test('an emergency can be marked by queue staff and is logged', function () {
    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->f->patient('Urgent', 'Case'));

    $this->actingAs($receptionist)->withSession($session)
        ->patch("/clinic/reception/queue/{$item->id}/priority", ['priority' => 'emergency'])
        ->assertSessionHas('success', 'Urgent C. marked as an emergency.');

    expect($item->fresh()->priority)->toBe(VisitPriority::Emergency)
        ->and($item->histories()->where('action', 'priority_changed')->exists())->toBeTrue();
});
