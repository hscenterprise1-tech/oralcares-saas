<?php

namespace App\Services\Staff;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\Leave;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\Security\SecurityAudit;
use App\Support\ClinicClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Leave from request to decision: Pending (stored `requested`) → Approved,
 * Rejected or Cancelled. Built on `LeaveService`, which fires the leave
 * events.
 *
 * Approved leave blocks appointment availability at once (the appointment
 * engine reads approved leave directly). Existing appointments are never
 * moved or cancelled here; they are listed for review instead.
 */
class LeaveWorkflow
{
    public function __construct(
        protected LeaveService $leaves,
        protected SecurityAudit $audit,
    ) {}

    /**
     * @param  array{leave_type: string, start_date: string, end_date: string, reason?: string|null, note?: string|null}  $data
     */
    public function request(Clinic $clinic, ClinicEmployee $employee, array $data, ?User $actor): Leave
    {
        $overlapping = Leave::query()
            ->where('clinic_id', $clinic->id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', [Leave::REQUESTED, Leave::APPROVED])
            ->whereDate('start_date', '<=', $data['end_date'])
            ->whereDate('end_date', '>=', $data['start_date'])
            ->exists();

        if ($overlapping) {
            throw ValidationException::withMessages(['start_date' => 'This overlaps leave that is already pending or approved.']);
        }

        $leave = $this->leaves->requestLeave($clinic, $employee, $data['leave_type'], $data['start_date'], $data['end_date'], $data['reason'] ?? null);
        $leave->update(['note' => $data['note'] ?? null, 'requested_by' => $actor?->id]);

        $this->audit->record('leave.requested', $leave, ['employee_id' => $employee->id]);

        return $leave;
    }

    public function approve(Leave $leave, ?string $note, ?User $actor): void
    {
        $this->assertPending($leave);
        $this->leaves->approveLeave($leave);
        $leave->update(['decided_by' => $actor?->id, 'decided_at' => now(), 'decision_note' => $note]);

        $this->audit->record('leave.approved', $leave, ['employee_id' => $leave->employee_id]);
    }

    public function reject(Leave $leave, ?string $note, ?User $actor): void
    {
        $this->assertPending($leave);
        $this->leaves->rejectLeave($leave);
        $leave->update(['decided_by' => $actor?->id, 'decided_at' => now(), 'decision_note' => $note]);

        $this->audit->record('leave.rejected', $leave, ['employee_id' => $leave->employee_id]);
    }

    /**
     * Withdraw a pending request, or cancel approved leave that has not ended.
     */
    public function cancel(Leave $leave, ?User $actor): void
    {
        if (! in_array($leave->status, [Leave::REQUESTED, Leave::APPROVED], true)) {
            throw ValidationException::withMessages(['leave' => 'Only pending or approved leave can be cancelled.']);
        }
        if ($leave->end_date->lt(ClinicClock::today())) {
            throw ValidationException::withMessages(['leave' => 'Leave that has already ended cannot be cancelled.']);
        }

        $this->leaves->cancelLeave($leave);
        $this->audit->record('leave.cancelled', $leave, ['employee_id' => $leave->employee_id]);
    }

    /**
     * The staff member's upcoming appointments during the leave, at the given
     * branches: what Reception must reschedule, reassign or cancel.
     *
     * @param  list<int>|null  $branchIds  null for every branch
     * @return Builder<Appointment>
     */
    public function affected(Leave $leave, ?array $branchIds): Builder
    {
        $from = max($leave->start_date->toDateString(), ClinicClock::today()->toDateString());

        return Appointment::withoutGlobalScopes()
            ->where('clinic_id', $leave->clinic_id)
            ->where('practitioner_id', $leave->employee_id)
            ->whereBetween('scheduled_at', [$from.' 00:00:00', $leave->end_date->toDateString().' 23:59:59'])
            ->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::checkable()))
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds));
    }

    /**
     * Affected-appointment counts for many leaves, in one query.
     *
     * @param  Collection<int, Leave>  $leaves
     * @param  list<int>|null  $branchIds
     * @return array<int, int> leave id => count
     */
    public function affectedCounts(Collection $leaves, ?array $branchIds): array
    {
        $relevant = $leaves->filter(fn (Leave $leave) => in_array($leave->status, [Leave::REQUESTED, Leave::APPROVED], true)
            && $leave->end_date->gte(ClinicClock::today()));

        if ($relevant->isEmpty()) {
            return [];
        }

        $appointments = Appointment::withoutGlobalScopes()
            ->where('clinic_id', $relevant->first()->clinic_id)
            ->whereIn('practitioner_id', $relevant->pluck('employee_id')->unique())
            ->whereBetween('scheduled_at', [ClinicClock::today()->toDateString().' 00:00:00', $relevant->max(fn (Leave $leave) => $leave->end_date)->toDateString().' 23:59:59'])
            ->whereIn('status', array_map(fn (AppointmentStatus $status) => $status->value, AppointmentStatus::checkable()))
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->limit(5000)
            ->get(['id', 'practitioner_id', 'scheduled_at']);

        return $relevant->mapWithKeys(fn (Leave $leave) => [$leave->id => $appointments->filter(fn (Appointment $appointment) => (int) $appointment->practitioner_id === (int) $leave->employee_id
            && $appointment->scheduled_at->toDateString() >= $leave->start_date->toDateString()
            && $appointment->scheduled_at->toDateString() <= $leave->end_date->toDateString())->count()])->all();
    }

    private function assertPending(Leave $leave): void
    {
        if ($leave->status !== Leave::REQUESTED) {
            throw ValidationException::withMessages(['leave' => 'This leave has already been decided.']);
        }
    }
}
