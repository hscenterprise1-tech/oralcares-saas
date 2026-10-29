<?php

use App\BranchStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\SecurityAuditLog;
use App\Services\ClinicContext;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * Changing where a staff member may work is a sensitive action: the owner
 * does it in Owner Mode, on either path that changes it (the employee form
 * and the branch placements). Staff who manage staff keep doing it on their
 * own permissions, only at branches they work at; nobody places anyone at
 * another clinic's branch.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('branch-access-owner');
    $this->east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $this->employee = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Priya Staff', 'email' => 'priya@example.test', 'status' => 'active']);
    $this->employee->branches()->attach($this->f->branch->id);

    $this->update = fn ($as, array $branches) => $as->put(route('admin.employees.update', $this->employee->id), [
        'name' => 'Priya Staff', 'email' => 'priya@example.test', 'status' => 'active', 'branches' => $branches,
    ]);
    $this->place = fn ($as, array $placements) => $as->put(route('admin.employees.branches', $this->employee->id), [
        'placements' => array_map(fn (int $id) => ['branch_id' => $id, 'is_active' => true], $placements),
    ]);
    $this->branches = fn () => $this->employee->branches()->pluck('branches.id')->sort()->values()->all();
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->inOwnerMode = fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session));
    $this->refusals = fn () => SecurityAuditLog::query()->where('event', 'owner_mode.action_refused')->get()
        // Refused in the action itself, or (in Doctor Mode, without staff.manage) at the route.
        ->filter(fn (SecurityAuditLog $log) => ($log->context['action'] ?? null) === 'staff.branches' || ($log->context['permission'] ?? null) === 'staff.manage')->count();
});

test('the owner changes a staff member\'s branches only in Owner Mode, on the employee form', function () {
    // The owner works here as a doctor too: Doctor Mode until they enter Owner Mode.
    $this->f->doctor('Dr. Owner', $this->f->owner);
    ($this->update)(($this->asOwner)(), [$this->f->branch->id, $this->east->id])->assertForbidden();
    expect(($this->branches)())->toBe([$this->f->branch->id])->and(($this->refusals)())->toBe(1);

    ($this->update)(($this->inOwnerMode)(), [$this->f->branch->id, $this->east->id])->assertSessionHasNoErrors();
    expect(($this->branches)())->toBe(collect([$this->f->branch->id, $this->east->id])->sort()->values()->all());
});

test('the owner changes a staff member\'s branch placements only in Owner Mode', function () {
    // The owner works here as a doctor too: Doctor Mode until they enter Owner Mode.
    $this->f->doctor('Dr. Owner', $this->f->owner);
    ($this->place)(($this->asOwner)(), [$this->east->id])->assertForbidden();
    expect(($this->branches)())->toBe([$this->f->branch->id])->and(($this->refusals)())->toBe(1);

    ($this->place)(($this->inOwnerMode)(), [$this->east->id])->assertSessionHasNoErrors();
    expect(($this->branches)())->toBe([$this->east->id]);
});

test('saving the same branches is not a change and needs no Owner Mode', function () {
    ($this->update)(($this->asOwner)(), [$this->f->branch->id])->assertSessionHasNoErrors();
    ($this->place)(($this->asOwner)(), [$this->f->branch->id])->assertSessionHasNoErrors();

    expect(($this->branches)())->toBe([$this->f->branch->id])->and(($this->refusals)())->toBe(0);
});

test('another clinic\'s branch is refused even in Owner Mode, and nothing changes', function () {
    $other = ReceptionFixture::make('branch-access-other');

    ($this->update)(($this->inOwnerMode)(), [$this->f->branch->id, $other->branch->id])->assertSessionHasErrors('branches.1');
    ($this->place)(($this->inOwnerMode)(), [$other->branch->id])->assertSessionHasErrors('placements.0.branch_id');

    expect(($this->branches)())->toBe([$this->f->branch->id]);
});

test('a clinic administrator keeps changing branches on their own permission, only where they work', function () {
    [$admin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $asAdmin = fn () => $this->actingAs($admin)->withSession($session);

    // No new restriction for staff: the administrator needs no Owner Mode.
    ($this->update)($asAdmin(), [$this->f->branch->id, $this->east->id])->assertSessionHasNoErrors();
    expect(($this->branches)())->toBe(collect([$this->f->branch->id, $this->east->id])->sort()->values()->all());

    // Working at the main branch only, they cannot place anyone at East.
    $membership = ClinicMembership::query()->withoutGlobalScopes()->findOrFail($session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID]);
    $membership->update(['has_all_branches_access' => false]);
    $membership->assignBranch($this->f->branch);
    $this->employee->branches()->sync([$this->f->branch->id]);

    ($this->place)($asAdmin(), [$this->f->branch->id, $this->east->id])->assertSessionHasErrors('placements.1.branch_id');
    ($this->update)($asAdmin(), [$this->f->branch->id, $this->east->id])->assertSessionHasErrors('branches.1');
    expect(($this->branches)())->toBe([$this->f->branch->id]);
});
