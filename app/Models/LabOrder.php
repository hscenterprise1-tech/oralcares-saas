<?php

namespace App\Models;

use App\LabCaseStatus;
use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabOrder extends Model
{
    use Auditable, ClinicScoped, HasFactory;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'encounter_id',
        'lab_order_number',
        'status',
        'notes',
        'treatment_record_id',
        'requesting_doctor_id',
        'branch_id',
        'laboratory_name',
        'is_internal',
        'lab_prosthetic_id',
        'lab_material_id',
        'lab_shade_id',
        'requested_teeth',
        'delivery_due_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LabCaseStatus::class,
            'requested_teeth' => 'array',
            'delivery_due_date' => 'date',
            'is_internal' => 'boolean',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function treatmentRecord(): BelongsTo
    {
        return $this->belongsTo(TreatmentRecord::class, 'treatment_record_id');
    }

    public function requestingDoctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'requesting_doctor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function prosthetic(): BelongsTo
    {
        return $this->belongsTo(LabProsthetic::class, 'lab_prosthetic_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(LabMaterial::class, 'lab_material_id');
    }

    public function shade(): BelongsTo
    {
        return $this->belongsTo(LabShade::class, 'lab_shade_id');
    }

    public function trials(): HasMany
    {
        return $this->hasMany(LabCaseTrial::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LabOrderAttachment::class);
    }
}
