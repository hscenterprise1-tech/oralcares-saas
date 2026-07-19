<?php

namespace App\Services;

use App\Events\LeaveApproved;
use App\Events\LeaveRequested;
use App\Events\ResourceUnavailable;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\Leave;
use App\Models\Resource;

class LeaveService
{
    /**
     * Submit a new leave request.
     */
    public function requestLeave(
        Clinic $clinic,
        ClinicEmployee $employee,
        string $leaveType,
        string $startDate,
        string $endDate,
        ?string $reason = null
    ): Leave {
        $leave = Leave::create([
            'clinic_id' => $clinic->id,
            'employee_id' => $employee->id,
            'leave_type' => $leaveType,
            'status' => 'requested',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => $reason,
        ]);

        event(new LeaveRequested($leave));

        return $leave;
    }

    /**
     * Approve a leave request and mark associated resource as unavailable.
     */
    public function approveLeave(Leave $leave): Leave
    {
        $leave->update(['status' => 'approved']);

        event(new LeaveApproved($leave));

        // Find associated generic resource for this employee
        $resource = Resource::where('employee_id', $leave->employee_id)->first();
        if ($resource) {
            event(new ResourceUnavailable($resource, $leave->start_date, $leave->end_date));
        }

        return $leave;
    }

    /**
     * Reject a leave request.
     */
    public function rejectLeave(Leave $leave): Leave
    {
        $leave->update(['status' => 'rejected']);

        return $leave;
    }

    /**
     * Cancel an approved or requested leave.
     */
    public function cancelLeave(Leave $leave): Leave
    {
        $leave->update(['status' => 'cancelled']);

        return $leave;
    }
}
