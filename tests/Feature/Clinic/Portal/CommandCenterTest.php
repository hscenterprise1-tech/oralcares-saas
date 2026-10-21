<?php

use App\ClinicRole;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\PaymentService;
use App\Services\QueueStateMachine;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use App\Services\Treatment\PatientTreatments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * The Command Center: the owner's or administrator's view of the clinic.
 * Aggregates and real exceptions only, from data the clinic already keeps;
 * figures arrive deferred, never in the first response.
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
    $this->widgetData = function (callable $as): array {
        $data = [];
        $as()->get('/clinic/dashboard')->assertOk()->assertInertia(function (Assert $page) use (&$data) {
            // The figures come with the page, in the first response.
            $data = $page->toArray()['props']['widgetData'];
        });

        return $data;
    };
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

    $data = ($this->widgetData)(fn () => $this->actingAs($this->f->owner)->withSession($this->f->session));

    expect($data['waiting_now']['value'])->toBe(5)
        ->and($data['with_doctor_now']['value'])->toBe(1)
        // A doctor with a long queue is raised in Needs attention; there is no per-doctor list on the dashboard.
        ->and(collect($data['needs_attention']['items'])->firstWhere('id', 'doctor_overload')['count'])->toBe(1)
        // Mixes, care activity, doctor lists and the "Right now" repeats are not on the dashboard.
        ->and($data)->not->toHaveKeys(['doctor_load', 'completed_today', 'care_activity', 'specialty_mix', 'patient_types', 'recent_activity']);
});

test('Needs attention lists only real exceptions: long waits, no doctor, an overloaded doctor, packages ending, approvals; follow-ups have their own list', function () {
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
    DB::table('deletion_requests')->insert(['clinic_id' => $this->f->clinic->id, 'requester_user_id' => $this->f->owner->id, 'action_type' => 'patient.delete', 'target_type' => 'patient', 'target_id' => $patient->id, 'target_label' => 'Follow Up', 'target_fingerprint' => str_repeat('a', 64), 'reason' => 'Duplicate', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

    $data = ($this->widgetData)(fn () => $this->actingAs($this->f->owner)->withSession($this->f->session));
    $owner = collect($data['needs_attention']['items'])->keyBy('id');
    expect($owner->keys()->all())->toContain('long_waits', 'unassigned', 'doctor_overload', 'packages_expiring', 'approvals')
        ->and($owner['long_waits']['count'])->toBe(6)
        ->and($owner['unassigned']['count'])->toBe(1)
        ->and($owner['doctor_overload']['count'])->toBe(1)
        // Follow-ups are listed once, in their own widget, not repeated here.
        ->and($owner->keys()->all())->not->toContain('follow_ups_due')
        ->and($data['follow_ups_due']['total'])->toBe(1)
        // No item links to a page that is not built (the Reception queue, approvals).
        ->and($owner->pluck('href')->filter()->all())->toBe([]);

    // An administrator runs the clinic but does not decide the owner's approvals.
    [$adminUser, $adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $admin = collect(($this->widgetData)(fn () => $this->actingAs($adminUser)->withSession($adminSession))['needs_attention']['items'])->pluck('id');
    expect($admin)->toContain('long_waits')->not->toContain('approvals');
});

test('in Owner Mode the Command Center shows the money: billed, net collected, outstanding, overdue, refunds', function () {
    $patient = $this->f->patient('Money', 'Matters');
    $this->t->actAsOwner();
    $bill = app(BillingService::class)->createBill($this->f->clinic, $patient, $this->f->branch, [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 1000]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Upi, 500);
    app(PaymentService::class)->recordRefund($bill->fresh(), 100, 'Overcharged');
    $now = now()->getTimestamp();
    $ownerMode = [OwnerMode::SESSION_KEY => ['user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id,
        'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID], 'method' => 'password', 'activated_at' => $now, 'last_seen_at' => $now]];

    $data = ($this->widgetData)(fn () => $this->actingAs($this->f->owner)->withSession([...$this->f->session, ...$ownerMode]));
    $money = collect($data['owner_finance']['stats'])->pluck('value', 'key');
    // Net collected is the Revenue report's: paid today less refunded today.
    expect($money['billed_today'])->toEqual(1000)->and($money['collected_today'])->toEqual(400)->and($money['refunds_month'])->toEqual(100)
        ->and($money['outstanding'])->toEqual((float) $bill->fresh()->outstanding_balance)->and($money['overdue'])->toEqual(0)
        ->and($data['collections_today']['value'])->toEqual(400)
        // The plan is the sidebar's (owner only); trends, spending and report shortcuts are Reports'.
        ->and($data)->not->toHaveKeys(['owner_plan', 'owner_revenue', 'owner_performance', 'owner_collection_methods', 'owner_spending', 'report_shortcuts', 'operations_today', 'dental_activity']);

    // Without Owner Mode, no money widgets at all.
    $this->flushSession();
    expect(($this->widgetData)(fn () => $this->actingAs($this->f->owner)->withSession($this->f->session)))->not->toHaveKeys(['owner_finance', 'owner_security']);
});

test('the owner\'s money stays out of other roles\' homes', function () {
    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    [$adminUser, $adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);

    // Reception's home is the one Dashboard, with no owner or clinic-wide Command Center figures.
    $this->actingAs($receptionUser)->withSession($receptionSession)->get('/clinic/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('widgets', fn ($widgets) => collect($widgets)->pluck('key')->intersect(['owner_finance', 'owner_revenue', 'owner_performance', 'owner_plan', 'owner_security', 'doctor_load', 'operations_today', 'staff_overview'])->isEmpty()));
    // An administrator's Command Center has no owner-only revenue, plan or security widgets.
    $this->actingAs($adminUser)->withSession($adminSession)->get('/clinic/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('widgets', fn ($widgets) => collect($widgets)->pluck('key')->intersect(['owner_revenue', 'owner_plan', 'owner_security', 'owner_performance'])->isEmpty()));
});
