<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PatientClinicalAccessAudit extends Model
{
    use ClinicScoped, HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'clinic_id',
        'actor_user_id',
        'patient_id',
        'source_branch_id',
        'accessing_branch_id',
        'resource_type',
        'resource_id',
        'attendance_visit_id',
        'access_reason',
        'ip_address',
        'user_agent',
        'accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'accessed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('patient_clinical_access_audits records are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('patient_clinical_access_audits records are immutable and cannot be deleted.');
        });
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function accessingBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'accessing_branch_id');
    }

    public function attendanceVisit(): BelongsTo
    {
        return $this->belongsTo(PatientVisit::class, 'attendance_visit_id');
    }
}
