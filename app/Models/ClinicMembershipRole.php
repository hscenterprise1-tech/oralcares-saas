<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicMembershipRole extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'clinic_membership_id',
        'role_id',
        'branch_id',
    ];

    /**
     * Get the clinic membership that the assignment belongs to.
     */
    public function clinicMembership(): BelongsTo
    {
        return $this->belongsTo(ClinicMembership::class);
    }

    /**
     * Get the role assigned to the membership.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the branch the role is scoped to (if null, clinic-wide).
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
