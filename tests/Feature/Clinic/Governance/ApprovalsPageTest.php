<?php

use App\ClinicRole;
use App\Exceptions\OwnerModeRequired;
use App\Models\AttachedDocument;
use App\Models\ClinicPayrollPeriod;
use App\Models\DeletionRequest;
use App\Models\SecurityAuditLog;
use App\Services\Business\ClinicPayroll;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * The Approvals page and the Owner Mode step-up contract behind it.
 *
 * The owner sees every request and may decide open ones; anyone else sees
 * only their own and may answer or withdraw them. An owner refused only
 * because Owner Mode is off or not fresh gets the explicit step-up answer
 * (403, `X-Owner-Mode-Required`, `executed: false`); every other refusal,
 * validation error or missing record stays what it was.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('approvals-page');
    $this->patient = $this->f->patient('Asha', 'Rao');
    [$this->doctor, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    [$this->admin, $this->adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);

    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->asOwnerInOwnerMode = fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session));
    $this->asDoctor = fn () => $this->actingAs($this->doctor)->withSession($this->doctorSession);
    $this->asAdmin = fn () => $this->actingAs($this->admin)->withSession($this->adminSession);
    $this->inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

    $document = fn (string $name) => AttachedDocument::create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'uploaded_by' => $this->f->owner->id,
        'file_name' => $name, 'file_path' => "x/{$name}", 'file_size_bytes' => 10, 'document_type' => 'other', 'scan_status' => AttachedDocument::SCAN_CLEAN,
    ]);
    $ask = fn ($as, int $target) => $as()->post('/clinic/approvals', ['action' => 'document.remove', 'target_id' => $target, 'reason' => 'Uploaded to the wrong patient record'])->assertSessionHasNoErrors();

    $ask($this->asDoctor, $document('doctor.pdf')->id);
    $ask($this->asAdmin, $document('admin.pdf')->id);
    $this->doctorRequest = DeletionRequest::query()->where('requester_user_id', $this->doctor->id)->sole();
    $this->adminRequest = DeletionRequest::query()->where('requester_user_id', $this->admin->id)->sole();
    $this->page = function ($as, string $query = ''): array {
        $props = [];
        $as()->get("/clinic/approvals{$query}")->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $page->component('ClinicPortal/Approvals/Index', true);
            $props = $page->toArray()['props'];
        });

        return $props;
    };
});

// ---------------------------------------------------------------- The page

test('the owner sees every open request and may decide each; the menu entry waits for the full inbox', function () {
    $props = ($this->page)($this->asOwner);

    expect($props['owner'])->toBeTrue()
        // An owner who does not treat patients decides without entering any mode.
        ->and($props['ownerMode'])->toBeTrue()
        ->and($props['counts'])->toBe(['open' => 2, 'decided' => 0])
        ->and(collect($props['requests']['data'])->pluck('id')->sort()->values()->all())->toBe(collect([$this->doctorRequest->id, $this->adminRequest->id])->sort()->values()->all())
        ->and(collect($props['requests']['data'])->pluck('can')->unique()->values()->all())->toBe([['decide' => true, 'respond' => false, 'cancel' => false]])
        ->and($props['links']['approve'])->toBe('/clinic/approvals/__ID__/approve')
        ->and($props['links']['respond'])->toBe('/clinic/approvals/__ID__/respond');

    // D1 (approved): not offered in the menu until the aggregated inbox (Phase 1.6); the page still answers, authorized on the server.
    $approvals = collect($props['clinic']['navigation'])->firstWhere('key', 'approvals');
    expect($approvals)->toMatchArray(['available' => false, 'section' => 'admin', 'href' => '/clinic/approvals']);
});

test('a requester sees only their own requests, and may withdraw but not decide them', function () {
    $props = ($this->page)($this->asDoctor);

    expect($props['owner'])->toBeFalse()
        ->and($props['counts'])->toBe(['open' => 1, 'decided' => 0])
        ->and(collect($props['requests']['data'])->pluck('id')->all())->toBe([$this->doctorRequest->id])
        ->and($props['requests']['data'][0]['can'])->toBe(['decide' => false, 'respond' => false, 'cancel' => true]);
});

test('a requester answers the owner\'s question, and withdraws their own request', function () {
    ($this->asOwnerInOwnerMode)()->post("/clinic/approvals/{$this->doctorRequest->id}/clarify", ['question' => 'Which document was it meant for?'])->assertSessionHasNoErrors();
    expect(($this->page)($this->asDoctor)['requests']['data'][0]['can'])->toBe(['decide' => false, 'respond' => true, 'cancel' => true]);

    ($this->asDoctor)()->post("/clinic/approvals/{$this->doctorRequest->id}/respond", ['answer' => 'It belongs to Ravi Rao.'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Answer sent to the owner.');
    expect($this->doctorRequest->fresh()->status)->toBe(DeletionRequest::PENDING);

    ($this->asDoctor)()->post("/clinic/approvals/{$this->doctorRequest->id}/cancel")->assertSessionHas('success', 'Request withdrawn.');
    $decided = ($this->page)($this->asDoctor, '?tab=decided');
    expect($this->doctorRequest->fresh()->status)->toBe(DeletionRequest::CANCELLED)
        ->and($decided['tab'])->toBe('decided')
        ->and($decided['counts'])->toBe(['open' => 0, 'decided' => 1])
        ->and($decided['requests']['data'][0]['status'])->toBe(DeletionRequest::CANCELLED);
});

test('nobody else may decide, answer or withdraw someone\'s request', function () {
    // Not the owner: refused outright, never offered a step-up.
    ($this->asAdmin)()->withHeaders($this->inertia)->post("/clinic/approvals/{$this->doctorRequest->id}/approve")
        ->assertForbidden()->assertHeaderMissing(OwnerModeRequired::HEADER);
    ($this->asAdmin)()->post("/clinic/approvals/{$this->doctorRequest->id}/cancel")->assertForbidden();
    ($this->asAdmin)()->post("/clinic/approvals/{$this->doctorRequest->id}/respond", ['answer' => 'Not mine to answer'])->assertForbidden();

    expect($this->doctorRequest->fresh()->status)->toBe(DeletionRequest::PENDING)
        ->and(AttachedDocument::query()->count())->toBe(2);
});

test('a refused decision never also reports success', function () {
    ($this->asOwnerInOwnerMode)()->post("/clinic/approvals/{$this->doctorRequest->id}/reject", ['reason' => 'No'])
        ->assertSessionHasErrors(['governance' => 'Say why the request is rejected.'])
        ->assertSessionMissing('success');

    expect($this->doctorRequest->fresh()->status)->toBe(DeletionRequest::PENDING);
});

// ---------------------------------------------------------------- The step-up contract

test('an owner who also treats patients, in Doctor Mode, gets the explicit step-up answer, and nothing is decided', function () {
    $this->f->doctor('Dr. Owner', $this->f->owner);

    ($this->asOwner)()->withHeaders($this->inertia)->post("/clinic/approvals/{$this->doctorRequest->id}/approve")
        ->assertForbidden()
        ->assertHeader(OwnerModeRequired::HEADER, 'inactive')
        ->assertExactJson(['message' => 'Owner Mode is required. Enter Owner Mode from your account menu.', 'owner_mode' => ['required' => true, 'reason' => 'inactive', 'executed' => false]]);

    ($this->asOwner)()->postJson("/clinic/approvals/{$this->doctorRequest->id}/reject", ['reason' => 'Not a duplicate after all'])
        ->assertForbidden()->assertHeader(OwnerModeRequired::HEADER, 'inactive')->assertJsonPath('owner_mode.executed', false);

    expect($this->doctorRequest->fresh()->status)->toBe(DeletionRequest::PENDING)
        ->and(AttachedDocument::query()->count())->toBe(2);
});

test('a T2 action with an old password confirmation gets the stale step-up answer', function () {
    $period = app(ClinicPayroll::class)->open($this->f->clinic, '2026-10', $this->f->owner);

    $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session, verifiedMinutesAgo: 6))->withHeaders($this->inertia)
        ->post("/clinic/payroll/periods/{$period->id}/finalise")
        ->assertForbidden()->assertHeader(OwnerModeRequired::HEADER, 'stale')->assertJsonPath('owner_mode.reason', 'stale');

    expect(ClinicPayrollPeriod::query()->withoutGlobalScopes()->find($period->id)->status)->not->toBe(ClinicPayrollPeriod::FINALISED);
});

test('after confirming, sending the same decision again carries it out once', function () {
    $this->f->doctor('Dr. Owner', $this->f->owner);

    ($this->asOwner)()->withHeaders($this->inertia)->post("/clinic/approvals/{$this->doctorRequest->id}/approve")->assertHeader(OwnerModeRequired::HEADER, 'inactive');

    $this->post('/clinic/owner-mode', ['password' => 'password'])->assertSessionHasNoErrors();
    $this->post("/clinic/approvals/{$this->doctorRequest->id}/approve")->assertSessionHasNoErrors()->assertSessionHas('success', 'Approved and carried out.');
    // A duplicate of the resend does nothing a second time.
    $this->post("/clinic/approvals/{$this->doctorRequest->id}/approve")->assertSessionHasNoErrors();

    expect($this->doctorRequest->fresh()->status)->toBe(DeletionRequest::EXECUTED)
        ->and(SecurityAuditLog::query()->where('event', 'deletion.executed')->count())->toBe(1)
        ->and(AttachedDocument::query()->count())->toBe(1);
});

test('validation errors, missing records, plain refusals and signed-out requests are never a step-up', function () {
    // A validation error: the usual redirect with errors.
    ($this->asOwnerInOwnerMode)()->withHeaders($this->inertia)->post("/clinic/approvals/{$this->doctorRequest->id}/reject", [])
        ->assertRedirect()->assertSessionHasErrors('reason')->assertHeaderMissing(OwnerModeRequired::HEADER);

    // Another clinic's request: not found.
    $other = ReceptionFixture::make('approvals-page-other');
    $theirs = DeletionRequest::query()->create([
        'clinic_id' => $other->clinic->id, 'requester_user_id' => $other->owner->id, 'action_type' => 'document.remove', 'target_type' => 'document',
        'target_id' => 1, 'target_label' => 'x', 'target_fingerprint' => 'x', 'reason' => 'Another clinic', 'status' => DeletionRequest::PENDING, 'expires_at' => now()->addDays(30),
    ]);
    ($this->asOwnerInOwnerMode)()->withHeaders($this->inertia)->post("/clinic/approvals/{$theirs->id}/approve")
        ->assertNotFound()->assertHeaderMissing(OwnerModeRequired::HEADER);

    // A staff member refused a sensitive action: a plain 403.
    ($this->asAdmin)()->withHeaders($this->inertia)->post('/clinic/approvals/direct', [])
        ->assertForbidden()->assertHeaderMissing(OwnerModeRequired::HEADER);

    // Signed out: sent to sign in, or 401 for a background call.
    auth()->logout();
    $this->postJson("/clinic/approvals/{$this->doctorRequest->id}/approve")->assertUnauthorized()->assertHeaderMissing(OwnerModeRequired::HEADER);

    expect($theirs->fresh()->status)->toBe(DeletionRequest::PENDING)
        ->and($this->doctorRequest->fresh()->status)->toBe(DeletionRequest::PENDING);
});
