<?php

use App\ClinicRole;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\PaymentService;
use App\Services\QueueStateMachine;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use App\Services\Treatment\PatientTreatments;
use App\Support\Dashboard\OwnerCommandCenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * The Command Center: the owner's or administrator's view of the clinic.
 * Aggregates and real exceptions only, from data the clinic already keeps,
 * sent with the page in the first response.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->checkIn = fn (string $first, ?ClinicEmployee $doctor, string $specialty = 'physiotherapy'): ClinicQueueItem => tap(
        app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->f->patient($first, 'Pulse'), doctor: $doctor,
            context: VisitContext::fromInput($this->f->clinic, ['visit_specialty' => $specialty])),
        fn (ClinicQueueItem $item) => $doctor === null ? $item->update(['doctor_id' => null]) : null,
    );
    $this->sections = function (callable $as): array {
        $sections = [];
        $as()->get('/clinic/dashboard')->assertOk()->assertInertia(function (Assert $page) use (&$sections) {
            // The sections come with the page, in the first response.
            $sections = $page->component('ClinicPortal/CommandCenter/Index', false)->toArray()['props']['sections'];
        });

        return $sections;
    };
    $this->figures = fn (array $sections) => collect($sections['today']['figures'])->keyBy('key');
    $this->inbox = fn (array $sections) => collect($sections['decisions']['items'])->keyBy('id');
});

test('the Command Center shows the clinic pulse from real records, flags an overloaded doctor, and shows no reporting', function () {
    foreach (range(1, 5) as $i) {
        ($this->checkIn)("Busy{$i}", $this->t->physio);
    }
    $seen = ($this->checkIn)('Seen', $this->f->doctorA, 'dental');
    app(QueueStateMachine::class)->start($seen);
    $this->travel(5)->minutes();
    $done = ($this->checkIn)('Done', $this->f->doctorA, 'dental');
    app(QueueStateMachine::class)->start($done);
    app(QueueStateMachine::class)->complete($done);
    $this->t->actAsOwner();
    $physioPatient = $this->f->patient('Care', 'Plan');
    app(PatientTreatments::class)->startAdHoc($physioPatient, 'physiotherapy', 'Knee rehab', $this->t->physio, $this->f->branch, $this->f->owner);

    $sections = ($this->sections)(fn () => $this->actingAs($this->f->owner)->withSession($this->f->session));

    expect(($this->figures)($sections)['waiting_now']['value'])->toBe(5)
        ->and(($this->figures)($sections)['with_doctor_now']['value'])->toBe(1)
        // A doctor with a long queue is raised in the decision inbox; there is no per-doctor list.
        ->and(($this->inbox)($sections)['doctor_overload']['count'])->toBe(1)
        // Only the Command Center's own blocks: no mixes, care activity or reporting.
        ->and(array_diff(array_keys($sections), ['mine', 'today', 'money', 'spending', 'decisions', 'branches', 'people', 'security', 'activity']))->toBe([]);
});

test('the decision inbox lists only real exceptions: long waits, no doctor, an overloaded doctor, packages ending, approvals, follow-ups due', function () {
    ($this->checkIn)('Walkin', null);
    foreach (range(1, 5) as $i) {
        ($this->checkIn)("Queue{$i}", $this->t->physio);
    }
    $this->travel(30)->minutes();
    $patient = $this->f->patient('Follow', 'Up');
    DB::table('patient_follow_ups')->insert(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'due_on' => '2026-10-05', 'status' => 'due', 'created_at' => now(), 'updated_at' => now()]);
    $this->t->actAsOwner();
    $treatment = app(PatientTreatments::class)->startAdHoc($patient, 'physiotherapy', 'Back rehab', $this->t->physio, $this->f->branch, $this->f->owner);
    DB::table('patient_session_packages')->insert(['clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'patient_treatment_id' => $treatment->id, 'name' => 'Ending', 'sessions_total' => 5, 'status' => 'active', 'valid_until' => '2026-10-09', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('deletion_requests')->insert(['clinic_id' => $this->f->clinic->id, 'requester_user_id' => $this->f->owner->id, 'action_type' => 'patient.merge', 'target_type' => 'patient', 'target_id' => $patient->id, 'target_label' => 'Follow Up', 'target_fingerprint' => str_repeat('a', 64), 'reason' => 'Duplicate', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

    $sections = ($this->sections)(fn () => $this->actingAs($this->f->owner)->withSession($this->f->session));
    $owner = ($this->inbox)($sections);
    expect($owner->keys()->all())->toContain('long_waits', 'unassigned', 'doctor_overload', 'packages_expiring', 'approvals', 'follow_ups_due')
        ->and($owner['long_waits']['count'])->toBe(6)
        ->and($owner['unassigned']['count'])->toBe(1)
        ->and($owner['doctor_overload']['count'])->toBe(1)
        // Follow-ups appear once.
        ->and($owner['follow_ups_due']['count'])->toBe(1)
        ->and($sections['decisions']['total'])->toBe($owner->sum('count'))
        // Queue exceptions open the Front Desk, follow-ups their board; nothing links to a page that is not built (approvals).
        ->and($owner->pluck('href')->filter()->unique()->values()->all())->toBe(['/clinic/reception', '/clinic/follow-ups?tab=overdue&mine=0']);

    // An administrator runs the clinic but does not decide the owner's approvals.
    [$adminUser, $adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $admin = ($this->inbox)(($this->sections)(fn () => $this->actingAs($adminUser)->withSession($adminSession)))->keys();
    expect($admin)->toContain('long_waits')->not->toContain('approvals');
});

test('in Owner Mode the Command Center shows the money: billed, collected, refunded, net, outstanding', function () {
    $patient = $this->f->patient('Money', 'Matters');
    $this->t->actAsOwner();
    $bill = app(BillingService::class)->createBill($this->f->clinic, $patient, $this->f->branch, [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 1000]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Upi, 500);
    app(PaymentService::class)->recordRefund($bill->fresh(), 100, 'Overcharged');

    $money = ($this->sections)(fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session)))['money'];
    // Net collected is paid today less refunded today.
    expect($money['today']['billed']['value'])->toEqual(1000)
        ->and($money['today']['collected']['value'])->toEqual(500)
        ->and($money['today']['refunded']['value'])->toEqual(100)
        ->and($money['today']['net_collected']['value'])->toEqual(400)
        ->and($money['outstanding']['value'])->toEqual((float) $bill->fresh()->outstanding_balance);

    // An owner who does not treat patients has the money without Owner Mode.
    $this->flushSession();
    expect(($this->sections)(fn () => $this->actingAs($this->f->owner)->withSession($this->f->session))['money']['today']['net_collected']['value'])->toEqual(400);
});

test('the owner\'s money stays out of other roles\' homes', function () {
    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    [$adminUser, $adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);

    // Reception's home is the Front Desk: never the Command Center, so no owner or clinic-wide money.
    $this->actingAs($receptionUser)->withSession($receptionSession)->get('/clinic/dashboard')->assertRedirect(route('clinic.reception'));
    $this->actingAs($receptionUser)->withSession($receptionSession)->get('/clinic/reception')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/FrontDesk/Index', false)
        ->missing('sections')
        ->where('clinic.owner_subscription', null));
    // An administrator's Command Center has no owner-only block, locked or otherwise.
    $admin = ($this->sections)(fn () => $this->actingAs($adminUser)->withSession($adminSession));
    expect(array_intersect(array_keys($admin), OwnerCommandCenter::OWNER_SECTIONS))->toBe([]);
});
