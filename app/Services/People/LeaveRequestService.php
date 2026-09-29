<?php

namespace App\Services\People;

use App\LeaveDayPortion;
use App\LeaveRequestStatus;
use App\Models\CompanyEmployee;
use App\Models\CompanyLeaveRequest;
use App\Models\CompanyLeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Asking for time off, and deciding on it.
 *
 * Deliberately not `App\Services\LeaveService` — that one exists, takes a
 * `Clinic` and a `ClinicEmployee`, and belongs to a different workforce
 * entirely. Nothing here touches it.
 *
 * What this refuses to invent: how much leave anybody is entitled to, whether a
 * request may exceed what is left, whether a type needs approval beyond what is
 * configured on it, and how many hours make a working day. None of those is
 * established anywhere, and each would become company policy the moment it was
 * written down here.
 */
class LeaveRequestService
{
    public function __construct(protected LeaveBalanceService $balances) {}

    /**
     * Raise a request.
     *
     * The day breakdown is the substance: one row per calendar day, each
     * carrying its own portion, so a week off can be four full days and a half
     * without the total becoming a lie.
     *
     * @param  array<string, array{portion?: string, half_period?: ?string, hours?: ?float}>  $dayOverrides
     */
    public function request(
        CompanyEmployee $employee,
        CompanyLeaveType $type,
        Carbon $start,
        Carbon $end,
        ?string $reason = null,
        array $dayOverrides = [],
        ?User $actor = null,
    ): CompanyLeaveRequest {
        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('Leave cannot end before it starts.');
        }

        if (! $type->is_active) {
            throw new InvalidArgumentException('That leave type is no longer offered.');
        }

        return DB::transaction(function () use ($employee, $type, $start, $end, $reason, $dayOverrides) {
            $request = CompanyLeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'reason' => $reason,
                'status' => LeaveRequestStatus::Pending,
                'submitted_at' => now(),
            ]);

            $totalDays = 0.0;
            $totalHours = 0.0;

            for ($date = $start->copy()->startOfDay(); $date->lessThanOrEqualTo($end); $date->addDay()) {
                $key = $date->toDateString();
                $override = $dayOverrides[$key] ?? [];

                $portion = LeaveDayPortion::tryFrom($override['portion'] ?? LeaveDayPortion::Full->value)
                    ?? LeaveDayPortion::Full;

                if ($portion === LeaveDayPortion::Half && ! $type->allows_half_day) {
                    throw new InvalidArgumentException("{$type->name} cannot be taken as a half day.");
                }

                $hours = $portion === LeaveDayPortion::Part ? (float) ($override['hours'] ?? 0) : null;

                if ($portion === LeaveDayPortion::Part && $hours <= 0) {
                    throw new InvalidArgumentException('A part day needs the number of hours.');
                }

                $request->days()->create([
                    'leave_date' => $key,
                    'portion' => $portion,
                    'half_period' => $portion === LeaveDayPortion::Half ? ($override['half_period'] ?? 'first_half') : null,
                    'hours' => $hours,
                    'day_value' => $portion->dayValue(),
                ]);

                $totalDays += $portion->dayValue();
                $totalHours += (float) ($hours ?? 0);
            }

            $request->update([
                'total_days' => round($totalDays, 2),
                'total_part_day_hours' => round($totalHours, 2),
            ]);

            return $request->fresh(['days', 'leaveType', 'employee']);
        });
    }

    /**
     * Approve a request, and take the days out of the balance.
     *
     * `$via` records *how* the decider was entitled to decide — as the
     * employee's manager, or through the HR permission fallback. Two people can
     * both be allowed to approve for entirely different reasons, and which one
     * applied is worth keeping.
     */
    public function approve(CompanyLeaveRequest $request, User $actor, string $via, ?string $note = null): CompanyLeaveRequest
    {
        $this->assertTransition($request, LeaveRequestStatus::Approved);

        return DB::transaction(function () use ($request, $actor, $via, $note) {
            $request->update([
                'status' => LeaveRequestStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decided_via' => $via,
                'decision_note' => $note,
            ]);

            $this->balances->consume($request->fresh(['employee', 'leaveType']), $actor);

            return $request->fresh(['days', 'leaveType', 'employee']);
        });
    }

    public function reject(CompanyLeaveRequest $request, User $actor, string $via, ?string $note = null): CompanyLeaveRequest
    {
        $this->assertTransition($request, LeaveRequestStatus::Rejected);

        $request->update([
            'status' => LeaveRequestStatus::Rejected,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decided_via' => $via,
            'decision_note' => $note,
        ]);

        return $request->fresh();
    }

    /**
     * Cancel a request, returning anything it had taken.
     *
     * An approved leave can still be cancelled; the balance is given back by a
     * reversal entry rather than by editing the figure the approval produced.
     */
    public function cancel(CompanyLeaveRequest $request, ?User $actor = null): CompanyLeaveRequest
    {
        $this->assertTransition($request, LeaveRequestStatus::Cancelled);

        return DB::transaction(function () use ($request, $actor) {
            $wasApproved = $request->status === LeaveRequestStatus::Approved;

            $request->update([
                'status' => LeaveRequestStatus::Cancelled,
                'cancelled_by' => $actor?->id,
                'cancelled_at' => now(),
            ]);

            if ($wasApproved) {
                $this->balances->reverse($request->fresh(['employee', 'leaveType']), $actor);
            }

            return $request->fresh();
        });
    }

    protected function assertTransition(CompanyLeaveRequest $request, LeaveRequestStatus $target): void
    {
        if (! $request->status->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                "A {$request->status->label()} request cannot become {$target->label()}."
            );
        }
    }
}
