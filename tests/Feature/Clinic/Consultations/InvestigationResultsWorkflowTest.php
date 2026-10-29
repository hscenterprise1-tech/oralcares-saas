<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicMembership;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * An investigation end to end through the screens' own endpoints: ordered in
 * a consultation, its sample collected, a result entered (preliminary),
 * corrected (the earlier result kept in the timeline), reviewed (final,
 * closed), and shown in Patient 360 — and only to the right people.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->actingAs($this->doctorUser);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
});

test('order → collect → result → correct → review, then Patient 360 shows the final result', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/orders", ['priority' => 'routine', 'items' => [['item_name' => 'HbA1c', 'category' => 'lab']]])->assertSessionHasNoErrors();
    $order = ClinicalOrder::query()->withoutGlobalScopes()->sole();
    $item = $order->items()->sole();

    ($this->asDoctor)()->get("/clinic/orders/{$order->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Orders/Show')->where('items.0.status', 'ordered')->where('can.fulfil', true));

    ($this->asDoctor)()->post("/clinic/orders/{$order->id}/items/{$item->id}/collect")->assertSessionHasNoErrors();
    ($this->asDoctor)()->post("/clinic/orders/{$order->id}/items/{$item->id}/result", ['values' => [['name' => 'HbA1c', 'value' => '9.1', 'unit' => '%', 'reference_range' => '4.0–5.6', 'flag' => 'high']]])->assertSessionHasNoErrors();
    // A correction before review: the earlier result is kept in the timeline.
    ($this->asDoctor)()->post("/clinic/orders/{$order->id}/items/{$item->id}/result", ['values' => [['name' => 'HbA1c', 'value' => '8.4', 'unit' => '%', 'reference_range' => '4.0–5.6', 'flag' => 'high']], 'summary' => 'Repeat run'])->assertSessionHasNoErrors();
    $corrected = PatientTimelineEvent::where('patient_id', $this->patient->id)->where('event_type', 'investigation_resulted')->latest('id')->first();
    expect($corrected->context['corrected'])->toBeTrue()->and($corrected->context['previous']['values'][0]['value'])->toBe('9.1');

    ($this->asDoctor)()->post("/clinic/orders/{$order->id}/items/{$item->id}/review", ['note' => 'Discussed with the patient'])->assertSessionHasNoErrors();
    expect($item->fresh()->status)->toBe(ClinicalOrderItem::REVIEWED);

    // Final: it can no longer be changed.
    ($this->asDoctor)()->post("/clinic/orders/{$order->id}/items/{$item->id}/result", ['summary' => 'Different'])->assertForbidden();
    expect($item->fresh()->result->summary)->toBe('Repeat run');

    $row = ($this->asDoctor)()->getJson(route('clinic.patients.360.section', [$this->patient->id, 'investigations']))->assertOk()->json('data.0');
    expect($row)->toMatchArray(['status' => 'reviewed', 'url' => route('clinic.orders.show', $order->id)])
        ->and($row['result'])->toMatchArray(['final' => true, 'is_abnormal' => true, 'review_note' => 'Discussed with the patient'])
        ->and($row['result']['values'][0]['value'])->toBe('8.4');
});

test('a result needs a value or a report, and the server checks the flag', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/orders", ['priority' => 'routine', 'items' => [['item_name' => 'CBC', 'category' => 'lab']]]);
    $order = ClinicalOrder::query()->withoutGlobalScopes()->sole();
    $item = $order->items()->sole();

    ($this->asDoctor)()->post("/clinic/orders/{$order->id}/items/{$item->id}/result", ['values' => []])->assertSessionHasErrors('summary');
    ($this->asDoctor)()->post("/clinic/orders/{$order->id}/items/{$item->id}/result", ['values' => [['name' => 'Hb', 'value' => '12', 'flag' => 'invented']]])->assertSessionHasErrors('values.0.flag');
    expect($item->fresh()->status)->toBe(ClinicalOrderItem::ORDERED);
});

test('reception sees where an investigation is but never its result; another clinic cannot open the order', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/orders", ['priority' => 'routine', 'items' => [['item_name' => 'CBC', 'category' => 'lab']]]);
    $order = ClinicalOrder::query()->withoutGlobalScopes()->sole();
    $item = $order->items()->sole();
    ($this->asDoctor)()->post("/clinic/orders/{$order->id}/items/{$item->id}/result", ['summary' => 'Within normal limits']);

    [$reception, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $row = $this->actingAs($reception)->withSession($session)->getJson(route('clinic.patients.360.section', [$this->patient->id, 'investigations']))->assertOk()->json('data.0');
    expect($row)->toMatchArray(['status' => 'result_in'])->not->toHaveKey('result');
    $this->actingAs($reception)->withSession($session)->get("/clinic/orders/{$order->id}")->assertForbidden();
    $this->actingAs($reception)->withSession($session)->get('/clinic/orders')->assertForbidden();
    [$billing, $billingSession] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $this->actingAs($billing)->withSession($billingSession)->get('/clinic/orders')->assertForbidden();
    $this->actingAs($billing)->withSession($billingSession)->post("/clinic/orders/{$order->id}/items/{$item->id}/review")->assertForbidden();

    $other = ReceptionFixture::make('other-clinic');
    $this->actingAs($other->owner)->withSession($other->session)->get("/clinic/orders/{$order->id}")->assertForbidden();
});

test('a doctor limited to another branch can neither open the order nor enter its result', function () {
    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/orders", ['priority' => 'routine', 'items' => [['item_name' => 'CBC', 'category' => 'lab']]]);
    $order = ClinicalOrder::query()->withoutGlobalScopes()->sole();
    $item = $order->items()->sole();

    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    DB::table('branch_clinic_membership')->insert(['clinic_id' => $this->f->clinic->id, 'clinic_membership_id' => $membership->id, 'branch_id' => $north->id]);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Doctor->value], [$north]);
    $asNorth = fn () => $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $north->id]);

    $asNorth()->get("/clinic/orders/{$order->id}")->assertForbidden();
    $asNorth()->post("/clinic/orders/{$order->id}/items/{$item->id}/result", ['summary' => 'Not theirs'])->assertForbidden();
    expect($item->fresh()->status)->toBe(ClinicalOrderItem::ORDERED);
});
