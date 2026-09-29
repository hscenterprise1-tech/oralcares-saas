<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalProcedure extends Model
{
    use Auditable, OrganizationScoped;

    protected $table = 'patient_procedures';

    protected $fillable = [
        'organization_id',
        'encounter_id',
        'patient_id',
        'procedure_master_id',
        'code',
        'name',
        'description',
        'details',
        'status',
        'performed_by',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(ProcedureMaster::class, 'procedure_master_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
