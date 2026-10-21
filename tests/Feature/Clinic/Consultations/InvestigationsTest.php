<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\AttachedDocument;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicInvestigation;
use App\Models\ClinicMembership;
use App\Models\DiagnosticResult;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicalOrderService;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    $this->actingAs($this->doctorUser);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
});

/**
 * @param  array<int, array<string, mixed>>  $items
 */
function orderTests(object $test, array $items = [['item_name' => 'Complete blood count', 'category' => 'lab']], string $priority = 'routine'): ClinicalOrder
{
    ($test->asDoctor)()->post("/clinic/consultations/{$test->encounter->id}/orders", ['priority' => $priority, 'items' => $items])->assertSessionHasNoErrors();

    return ClinicalOrder::withoutGlobalScopes()->where('encounter_id', $test->encounter->id)->latest('id')->firstOrFail();
}

test('a doctor orders investigations from the consultation, numbered and learnt by the clinic', function () {
    $order = orderTests($this, [
        ['item_name' => 'Complete blood count', 'category' => 'lab'],
        ['item_name' => 'OPG', 'category' => 'imaging', 'body_site' => 'Full mouth'],
    ], 'urgent');

    expect($order->order_number)->toBe('ORD-2627-000001')
        ->and($order->clinic_id)->toBe($this->f->clinic->id)
        ->and($order->branch_id)->toBe($this->f->branch->id)
        ->and($order->orderer_id)->toBe($this->me->id)
        ->and($order->priority)->toBe('urgent')
        ->and($order->order_type)->toBe('mixed')
        ->and($order->items()->pluck('status')->unique()->all())->toBe([ClinicalOrderItem::ORDERED])
        ->and($order->items()->pluck('clinic_id')->unique()->all())->toBe([$this->f->clinic->id])
        ->and(ClinicInvestigation::where('clinic_id', $this->f->clinic->id)->pluck('name')->sort()->values()->all())->toBe(['Complete blood count', 'OPG'])
        ->and($this->patient->timelineEvents()->where('event_type', 'clinical_order_placed')->exists())->toBeTrue();
});

test('nothing is ordered from a signed consultation', function () {
    $workspace = app(EncounterWorkspace::class);
    $workspace->autosave($this->encounter, ['chief_complaint' => 'Review'], 0);
    $workspace->finalize($this->encounter, $this->me);

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/orders", ['priority' => 'routine', 'items' => [['item_name' => 'CBC', 'category' => 'lab']]])
        ->assertForbidden();
});

test('an investigation is collected, resulted, reviewed and its order completes', function () {
    $order = orderTests($this);
    $item = $order->items()->sole();

    ($this->asOwner)()->post("/clinic/orders/{$order->id}/items/{$item->id}/collect")->assertSessionHasNoErrors();
    ($this->asOwner)()->post("/clinic/orders/{$order->id}/items/{$item->id}/result", [
        'values' => [
            ['name' => 'Haemoglobin', 'value' => '9.1', 'unit' => 'g/dL', 'reference_range' => '12-16', 'flag' => 'low'],
            ['name' => 'WBC', 'value' => '7.2', 'unit' => '10^3/uL', 'flag' => 'normal'],
        ],
    ])->assertSessionHasNoErrors();

    $result = DiagnosticResult::withoutGlobalScopes()->where('clinical_order_item_id', $item->id)->sole();
    expect($item->fresh()->status)->toBe(ClinicalOrderItem::RESULTED)
        ->and($result->status)->toBe(DiagnosticResult::DRAFT)
        ->and($result->is_abnormal)->toBeTrue()
        ->and($result->clinic_id)->toBe($this->f->clinic->id)
        ->and($result->content['values'][0]['name'])->toBe('Haemoglobin');

    ($this->asDoctor)()->post("/clinic/orders/{$order->id}/items/{$item->id}/review", ['note' => 'Start iron; recheck in 4 weeks.'])->assertSessionHasNoErrors();

    $item->refresh();
    $result->refresh();
    $order->refresh();
    expect($item->status)->toBe(ClinicalOrderItem::REVIEWED)
        ->and($item->reviewed_by)->toBe($this->me->id)
        ->and($result->status)->toBe(DiagnosticResult::FINAL)
        ->and($result->reviewer_id)->toBe($this->me->id)
        ->and($result->review_note)->toBe('Start iron; recheck in 4 weeks.')
        ->and($order->status)->toBe(ClinicalOrder::COMPLETED)
        ->and($order->completed_at)->not->toBeNull()
        ->and($this->patient->timelineEvents()->where('event_type', 'investigation_reviewed')->exists())->toBeTrue()
        ->and($this->patient->timelineEvents()->where('event_type', 'clinical_order_completed')->exists())->toBeTrue()
        ->and(SecurityAuditLog::where('event', 'investigation.reviewed')->where('subject_id', $item->id)->exists())->toBeTrue();
});

test('a result can be corrected until it is reviewed, never after', function () {
    $order = orderTests($this, [['item_name' => 'CBC', 'category' => 'lab'], ['item_name' => 'ESR', 'category' => 'lab']]);
    [$cbc] = $order->items()->orderBy('id')->get();
    $orders = app(ClinicalOrderService::class);

    $orders->recordResult($cbc, null, ['summary' => 'First entry']);
    $orders->recordResult($cbc, null, ['summary' => 'Corrected entry']);
    expect(DiagnosticResult::withoutGlobalScopes()->where('clinical_order_item_id', $cbc->id)->count())->toBe(1)
        ->and($cbc->result()->first()->summary)->toBe('Corrected entry');

    $orders->review($cbc, $this->me);

    ($this->asOwner)()->post("/clinic/orders/{$order->id}/items/{$cbc->id}/result", ['summary' => 'Rewritten after review'])
        ->assertSessionHasErrors(['investigation' => 'This investigation is closed: it was reviewed or cancelled.']);

    expect($cbc->result()->first()->summary)->toBe('Corrected entry');
});

test('only a practitioner reviews; other clinical staff fulfil', function () {
    $order = orderTests($this);
    $item = $order->items()->sole();
    app(ClinicalOrderService::class)->recordResult($item, null, ['summary' => 'Normal study']);

    ($this->asOwner)()->post("/clinic/orders/{$order->id}/items/{$item->id}/review")->assertForbidden();

    expect($item->fresh()->status)->toBe(ClinicalOrderItem::RESULTED);
});

test('a resulted investigation cannot be cancelled, and cancelling every item cancels the order', function () {
    $order = orderTests($this, [['item_name' => 'CBC', 'category' => 'lab'], ['item_name' => 'Chest X-ray', 'category' => 'imaging']]);
    [$cbc, $xray] = $order->items()->orderBy('id')->get();
    app(ClinicalOrderService::class)->recordResult($cbc, null, ['summary' => 'Normal']);

    ($this->asOwner)()->post("/clinic/orders/{$order->id}/items/{$cbc->id}/cancel", ['reason' => 'Not needed'])
        ->assertSessionHasErrors(['investigation' => 'A resulted investigation cannot be cancelled; review it instead.']);
    ($this->asOwner)()->post("/clinic/orders/{$order->id}/items/{$xray->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');

    $other = orderTests($this, [['item_name' => 'HbA1c', 'category' => 'lab']]);
    $only = $other->items()->sole();
    ($this->asOwner)()->post("/clinic/orders/{$other->id}/items/{$only->id}/cancel", ['reason' => 'Patient declined'])->assertSessionHasNoErrors();

    expect($only->fresh()->status)->toBe(ClinicalOrderItem::CANCELLED)
        ->and($other->fresh()->status)->toBe(ClinicalOrder::CANCELLED);
});

test('an item cannot be acted on through another order', function () {
    $first = orderTests($this);
    $second = orderTests($this, [['item_name' => 'ESR', 'category' => 'lab']]);
    $theirs = $second->items()->sole();

    ($this->asOwner)()->post("/clinic/orders/{$first->id}/items/{$theirs->id}/collect")->assertNotFound();

    expect($theirs->fresh()->status)->toBe(ClinicalOrderItem::ORDERED);
});

test('another clinic\'s test list cannot be ordered from', function () {
    $foreign = ClinicInvestigation::withoutGlobalScopes()->create(['clinic_id' => Clinic::factory()->create()->id, 'name' => 'Theirs', 'category' => 'lab', 'lookup_key' => 'lab|theirs']);

    ($this->asDoctor)()->post("/clinic/consultations/{$this->encounter->id}/orders", ['priority' => 'routine', 'items' => [['investigation_id' => $foreign->id, 'category' => 'lab']]])
        ->assertSessionHasErrors(['investigation' => 'That investigation is not on this clinic\'s list.']);

    expect(ClinicalOrder::withoutGlobalScopes()->count())->toBe(0);
});

test('the worklist shows what is waiting, urgent first, with counts', function () {
    orderTests($this, [['item_name' => 'Routine CBC', 'category' => 'lab']]);
    $this->travel(1)->minute();
    orderTests($this, [['item_name' => 'Urgent troponin', 'category' => 'lab']], 'stat');
    $reviewable = orderTests($this, [['item_name' => 'OPG', 'category' => 'imaging']]);
    app(ClinicalOrderService::class)->recordResult($reviewable->items()->sole(), null, ['summary' => 'Normal']);

    ($this->asDoctor)()->get('/clinic/orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ClinicPortal/Orders/Index', false)
            ->where('counts.pending', 2)
            ->where('counts.review', 1)
            ->has('items', 2)
            ->where('items.0.name', 'Urgent troponin')
            ->where('items.1.name', 'Routine CBC'));

    ($this->asDoctor)()->get('/clinic/orders?tab=review&category=imaging')
        ->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.name', 'OPG'));
});

test('reception cannot open investigations', function () {
    $order = orderTests($this);
    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $this->actingAs($receptionUser)->withSession($receptionSession)->get('/clinic/orders')->assertForbidden();
    $this->actingAs($receptionUser)->withSession($receptionSession)->get("/clinic/orders/{$order->id}")->assertForbidden();
});

test('a member without access to the order\'s branch cannot open it, and another clinic cannot find it', function () {
    $order = orderTests($this);
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    $membership->assignBranch($east);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Doctor->value], [$east]);

    $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $east->id])
        ->get("/clinic/orders/{$order->id}")->assertForbidden();

    $other = ReceptionFixture::make('other-clinic');
    [$otherUser, $otherSession] = $other->memberWithRole(ClinicRole::Doctor);
    app()->instance('enforce_tenant_scope_in_tests', true);
    $this->actingAs($otherUser)->withSession($otherSession)->get("/clinic/orders/{$order->id}")->assertNotFound();
});

test('a report file is filed against its investigation only if it is the patient\'s', function () {
    Storage::fake('private');
    $order = orderTests($this);
    $item = $order->items()->sole();
    $stranger = $this->f->patient('Someone', 'Else');
    $upload = fn (int $patientId) => ($this->asOwner)()->post("/clinic/patients/{$patientId}/documents", [
        'document' => UploadedFile::fake()->create('cbc.pdf', 50, 'application/pdf'), 'document_type' => 'lab_report', 'clinical_order_item_id' => $item->id,
    ]);

    $upload($stranger->id)->assertNotFound();
    $upload($this->patient->id)->assertRedirect();

    $document = AttachedDocument::where('patient_id', $this->patient->id)->sole();
    expect($document->clinical_order_item_id)->toBe($item->id)
        ->and($document->encounter_id)->toBe($this->encounter->id);
});

test('the consultation screen lists its investigations', function () {
    orderTests($this, [['item_name' => 'CBC', 'category' => 'lab']]);

    ($this->asDoctor)()->get("/clinic/consultations/{$this->encounter->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.order', true)
            ->where('orders.0.number', 'ORD-2627-000001')
            ->where('orders.0.items.0.item_name', 'CBC')
            ->where('orders.0.items.0.status', 'ordered'));
});
