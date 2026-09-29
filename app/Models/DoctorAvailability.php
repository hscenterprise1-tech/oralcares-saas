<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OrganizationScoped;
use Database\Factories\DoctorAvailabilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DoctorAvailability extends Model
{
    /** @use HasFactory<DoctorAvailabilityFactory> */
    use Auditable, HasFactory, OrganizationScoped;

    protected $fillable = [
        'branch_id',
        'doctor_profile_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_available',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function doctorProfile()
    {
        return $this->belongsTo(DoctorProfile::class);
    }
}
