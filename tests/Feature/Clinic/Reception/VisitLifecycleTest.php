<?php

use App\Models\ClinicalEncounter;
use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\PatientBilling;
use App\Models\PatientVisit;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\QueueStateMachine;
use App\Services\Reception\PatientFlowConfigService;
use App\Support\Specialty\PracticeAreas;
use Carbon\CarbonImmutable;
use Tests\Support\TreatmentFixture;

/**
 * A visit's life: every return after a finished visit is a visit of its own
 * (a finished one is never reopened), and a visit is New until the patient
 * has actually been seen at the clinic — a visit they never attended does
 * not count — and Repeat after that.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->machine = app(QueueStateMachine::class);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    $this->dentist = function (string $name, float $fee): ClinicEmployee {
        $employee = $this->f->doctor($name);
        $keyed = ['organization_id' => $this->f->clinic->organization_id, 'practice_area_key' => 'general_dentistry'];
        $entry = ClinicalSpecialty::withoutGlobalScopes()->where($keyed)->first()
            ?? ClinicalSpecialty::withoutGlobalScopes()->forceCreate([...$keyed, 'name' => app(PracticeAreas::class)->get('general_dentistry')['label'], 'is_active' => true]);
        $employee->practitionerProfile()->withoutGlobalScopes()->update(['primary_specialty_id' => $entry->id, 'specialty' => $entry->name, 'consultation_fee' => $fee]);

        return $employee->fresh();
    };
    $this->raj = ($this->dentist)('Dr. Raj Patel', 300);
    $this->priya = ($this->dentist)('Dr. Priya Patel', 500);
    $this->patient = $this->f->patient('Asha', 'Rao');

    $this->checkIn = fn (ClinicEmployee $doctor, array $body = []) => ($this->asOwner)()
        ->postJson('/clinic/reception/check-in', ['patient_id' => $this->patient->id, 'practitioner_id' => $doctor->id, ...$body]);
    $this->latestItem = fn () => ClinicQueueItem::query()->latest('id')->firstOrFail();
    $this->finish = function (ClinicQueueItem $item): void {
        $this->machine->start($item);
        $this->machine->complete($item->fresh());
    };
    $this->visits = fn () => PatientVisit::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->orderBy('id')->get();
});

test('the first attended visit is New; a visit the patient never attended does not make the next one Repeat', function () {
    ($this->checkIn)($this->raj)->assertCreated();
    // Left without being seen.
    $this->machine->cancel(($this->latestItem)());

    $this->travel(1)->days();
    ($this->checkIn)($this->raj)->assertCreated();
    ($this->finish)(($this->latestItem)());

    $this->travel(1)->days();
    ($this->checkIn)($this->raj)->assertCreated();

    expect(($this->visits)()->map(fn (PatientVisit $visit) => [$visit->visit_status, $visit->registration_type->value])->all())->toBe([
        ['cancelled', 'new'],
        ['completed', 'new'],
        ['checked_in', 'repeat'],
    ]);
});

test('back the same day after a finished visit: a new Repeat visit and queue entry; the finished visit keeps its doctor and state', function () {
    ($this->checkIn)($this->raj)->assertCreated();
    $first = ($this->latestItem)();
    ($this->finish)($first);

    $this->travel(2)->hours();
    ($this->checkIn)($this->priya)->assertCreated();

    [$finished, $return] = ($this->visits)()->all();
    expect($finished->id)->toBe($first->patient_visit_id)
        ->and($finished->visit_status)->toBe('completed')
        ->and($finished->doctor_id)->toBe($this->raj->id)
        ->and($finished->registration_type->value)->toBe('new')
        ->and($return->id)->not->toBe($finished->id)
        ->and($return->visit_status)->toBe('checked_in')
        ->and($return->doctor_id)->toBe($this->priya->id)
        ->and($return->registration_type->value)->toBe('repeat')
        ->and($return->visit_date->toDateString())->toBe($finished->visit_date->toDateString());

    // One queue entry per visit; the first still finished.
    expect(ClinicQueueItem::query()->orderBy('id')->get(['patient_visit_id', 'status'])->map(fn ($item) => [$item->patient_visit_id, $item->status->value])->all())
        ->toBe([[$finished->id, 'completed'], [$return->id, 'waiting']]);
});

test('charged at check-in: the return visit is charged its own fee instead of being refused as already charged', function () {
    app(PatientFlowConfigService::class)->updateCheckIn($this->f->clinic, ['payment' => 'at_check_in'], $this->f->owner);

    ($this->checkIn)($this->raj, ['expected_charge' => 300, 'pay_later' => true])->assertCreated()->assertJsonPath('billing.total', 300);
    ($this->finish)(($this->latestItem)());
    ($this->checkIn)($this->priya, ['expected_charge' => 500, 'pay_later' => true])->assertCreated()->assertJsonPath('billing.total', 500);

    $visitIds = ($this->visits)()->pluck('id')->all();
    $bills = PatientBilling::query()->withoutGlobalScopes()->with(['items' => fn ($items) => $items->withoutGlobalScopes()])->orderBy('id')->get();
    expect($bills)->toHaveCount(2)
        ->and($bills->map(fn ($bill) => [(int) $bill->items->sole()->source_id, (float) $bill->items->sole()->unit_price])->all())
        ->toBe([[$visitIds[0], 300.0], [$visitIds[1], 500.0]]);
});

test('charged after the consultation: the return visit gets its own consultation, with its own fee at checkout', function () {
    ($this->checkIn)($this->raj)->assertCreated();
    $this->t->actAsOwner();
    $workspace = app(EncounterWorkspace::class);
    $first = $workspace->startFromQueue(($this->latestItem)(), $this->raj);
    $this->machine->complete(($this->latestItem)()->fresh());

    ($this->checkIn)($this->priya)->assertCreated();
    $this->t->actAsOwner();
    $second = $workspace->startFromQueue(($this->latestItem)(), $this->priya);

    expect($second->id)->not->toBe($first->id)
        ->and($second->patient_visit_id)->not->toBe($first->patient_visit_id)
        ->and(ClinicalEncounter::query()->withoutGlobalScopes()->find($first->id)->doctor_id)->toBe($this->raj->id);
    $consultation = collect(app(EncounterCheckout::class)->lines($second->fresh()))->firstWhere('key', 'consultation');
    expect($consultation['unit_price'] ?? null)->toBe(500.0);
});

test('a repeated submit with the same request key checks in once; a second check-in while queued is refused', function () {
    $key = 'check-in-key-0001';
    $first = ($this->checkIn)($this->raj, ['idempotency_key' => $key])->assertCreated()->json('check_in');
    $again = ($this->checkIn)($this->raj, ['idempotency_key' => $key])->assertSuccessful()->json('check_in');
    ($this->checkIn)($this->raj)->assertStatus(422)->assertJsonValidationErrors('patient_id');

    expect($first['case_number'])->toBeString()
        ->and($again)->toBe($first)
        ->and(($this->visits)())->toHaveCount(1)
        ->and(ClinicQueueItem::query()->count())->toBe(1);
});

test('a visit made at registration that never reached the queue is the one check-in uses, classified on arrival', function () {
    $registered = PatientVisit::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id,
        'registration_type' => 'repeat', 'visit_date' => '2026-10-10', 'visit_status' => 'registered', 'queue_status' => 'not_queued',
    ]);

    ($this->checkIn)($this->raj)->assertCreated();

    expect(($this->visits)())->toHaveCount(1)
        ->and($registered->fresh()->visit_status)->toBe('checked_in')
        ->and($registered->fresh()->registration_type->value)->toBe('new');
});

test('another clinic\'s attended visits never make this clinic\'s patient Repeat, and its patients cannot be checked in here', function () {
    $other = TreatmentFixture::make('other-clinic');
    $theirs = $other->f->patient('Other', 'Person');
    PatientVisit::query()->withoutGlobalScopes()->create([
        'clinic_id' => $other->f->clinic->id, 'branch_id' => $other->f->branch->id, 'patient_id' => $theirs->id,
        'registration_type' => 'new', 'visit_date' => '2026-10-01', 'visit_status' => 'completed', 'queue_status' => 'completed',
    ]);

    ($this->asOwner)()->postJson('/clinic/reception/check-in', ['patient_id' => $theirs->id, 'practitioner_id' => $this->raj->id])->assertNotFound();
    ($this->checkIn)($this->raj)->assertCreated();
    expect(($this->visits)()->sole()->registration_type->value)->toBe('new')
        ->and(PatientVisit::classifyNext($this->f->clinic->id, $theirs->id)->value)->toBe('new');
});

test('the older start-a-visit route dates the visit on the clinic\'s day and classifies it like check-in', function () {
    // 00:30 in the clinic is still the previous day in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 00:30', 'Asia/Kolkata'));
    ($this->asOwner)()->post('/clinic/visits', ['patient_id' => $this->patient->id, 'doctor_id' => $this->raj->id])->assertRedirect();
    ($this->asOwner)()->post('/clinic/visits', ['patient_id' => $this->patient->id, 'doctor_id' => $this->raj->id])->assertRedirect();

    expect(($this->visits)()->map(fn (PatientVisit $visit) => [$visit->visit_date->toDateString(), $visit->registration_type->value])->all())
        ->toBe([['2026-10-11', 'new'], ['2026-10-11', 'repeat']]);
});
