<?php

use App\ClinicRole;
use App\Models\PatientFollowUp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * The follow-up worklist: overdue, today, coming up, done; a practitioner
 * starts on their own; another clinic's follow-ups never appear; changes go
 * through the one follow-up writer.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('fu-board');
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->doctor = $this->f->doctor('Dr. Follow', $this->doctorUser);
    $this->followUp = fn (string $due, $practitioner, string $status = PatientFollowUp::DUE, $fixture = null) => PatientFollowUp::query()->withoutGlobalScopes()->create([
        'clinic_id' => ($fixture ?? $this->f)->clinic->id, 'branch_id' => ($fixture ?? $this->f)->branch->id, 'patient_id' => ($fixture ?? $this->f)->patient()->id,
        'practitioner_id' => $practitioner?->id, 'due_on' => $due, 'status' => $status, 'reason' => 'Review',
    ]);
    $this->board = function (callable $as, array $query = []): array {
        $props = [];
        $as()->get('/clinic/follow-ups?'.http_build_query($query))->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
});

test('a doctor starts on their own overdue follow-ups; everyone\'s is one switch away', function () {
    $mineOverdue = ($this->followUp)('2026-10-01', $this->doctor);
    ($this->followUp)('2026-10-03', $this->f->doctorA);
    ($this->followUp)('2026-10-07', $this->doctor);
    ($this->followUp)('2026-10-12', $this->doctor);

    $mine = ($this->board)($this->asDoctor);
    expect($mine['mine'])->toBeTrue()
        ->and(collect($mine['followUps']['data'])->pluck('id')->all())->toBe([$mineOverdue->id])
        ->and($mine['followUps']['data'][0]['days_overdue'])->toBe(6)
        ->and($mine['counts'])->toBe(['overdue' => 1, 'today' => 1]);

    expect(count(($this->board)($this->asDoctor, ['mine' => '0'])['followUps']['data']))->toBe(2)
        ->and(count(($this->board)($this->asDoctor, ['tab' => 'upcoming'])['followUps']['data']))->toBe(1);
});

test('another clinic\'s follow-ups never appear, and cannot be annotated', function () {
    $other = ReceptionFixture::make('fu-other');
    $theirs = ($this->followUp)('2026-10-01', null, PatientFollowUp::DUE, $other);
    $ours = ($this->followUp)('2026-10-01', null);

    $owner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    expect(collect(($this->board)($owner)['followUps']['data'])->pluck('id')->all())->toBe([$ours->id]);

    $owner()->patch("/clinic/follow-ups/{$theirs->id}/notes", ['priority' => 'urgent'])->assertNotFound();
    $owner()->patch("/clinic/follow-ups/{$ours->id}/notes", ['priority' => 'urgent', 'notes' => 'Called twice'])->assertSessionHasNoErrors();
    expect($ours->fresh()->priorityLabel())->toBe('urgent')->and($ours->fresh()->notes)->toBe('Called twice');
});

test('urgent follow-ups lead within a day; done ones leave the open lists', function () {
    $normal = ($this->followUp)('2026-10-01', null);
    $urgent = ($this->followUp)('2026-10-01', null);
    DB::table('patient_follow_ups')->where('id', $urgent->id)->update(['priority' => PatientFollowUp::PRIORITIES['urgent']]);
    $owner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    expect(collect(($this->board)($owner)['followUps']['data'])->pluck('id')->all())->toBe([$urgent->id, $normal->id]);

    $owner()->post(route('clinic.patients.follow-ups.complete', [$normal->patient_id, $normal->id]))->assertSessionHasNoErrors();
    expect(collect(($this->board)($owner)['followUps']['data'])->pluck('id')->all())->toBe([$urgent->id])
        ->and(collect(($this->board)($owner, ['tab' => 'done'])['followUps']['data'])->pluck('id')->all())->toBe([$normal->id]);
});

test('billing staff have no follow-up worklist', function () {
    [$user, $session] = $this->f->memberWithRole(ClinicRole::BillingStaff);

    $this->actingAs($user)->withSession($session)->get('/clinic/follow-ups')->assertForbidden();
});
