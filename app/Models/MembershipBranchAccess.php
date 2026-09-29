<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One row of branch access: this membership may open this branch
 * (`branch_clinic_membership`). The row carries the clinic, and the database
 * refuses it unless the membership and the branch both belong to that clinic.
 */
class MembershipBranchAccess extends Pivot
{
    protected $table = 'branch_clinic_membership';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::creating(function (self $access) {
            $access->clinic_id ??= ClinicMembership::withoutGlobalScopes()->whereKey($access->clinic_membership_id)->value('clinic_id');
        });
    }
}
