<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcedureMaster extends Model
{
    use Auditable, HasFactory, OrganizationScoped;

    protected $fillable = [
        'organization_id',
        'treatment_catalog_id',
        'code',
        'name',
        'description',
        'base_price',
        'duration_minutes',
        'is_active',
    ];

    public function treatmentCatalog()
    {
        return $this->belongsTo(TreatmentCatalog::class);
    }
}
