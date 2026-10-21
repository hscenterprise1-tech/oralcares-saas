<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch where the patient has been seen. Not ownership — see
 * `Services\Patient\PatientBranchActivityRecorder`, its only writer.
 */
class PatientBranchActivity extends Model
{
    use ClinicScoped;

    protected $table = 'patient_branch_activity';

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'branch_id',
        'first_seen_at',
        'last_seen_at',
        'first_source',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
