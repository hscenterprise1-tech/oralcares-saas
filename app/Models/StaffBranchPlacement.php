<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One row of staff work placement: this staff member works at this branch
 * (`clinic_employee_branches`). The row carries the clinic, and the database
 * refuses it unless the staff record and the branch both belong to that clinic.
 */
class StaffBranchPlacement extends Pivot
{
    protected $table = 'clinic_employee_branches';

    public $incrementing = true;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $placement) {
            $placement->clinic_id ??= ClinicEmployee::withoutGlobalScopes()->whereKey($placement->clinic_employee_id)->value('clinic_id');
        });
    }
}
