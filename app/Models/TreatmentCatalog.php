<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OrganizationScoped;
use Database\Factories\TreatmentCatalogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TreatmentCatalog extends Model
{
    /** @use HasFactory<TreatmentCatalogFactory> */
    use Auditable, HasFactory, OrganizationScoped;

    protected $fillable = [
        'name',
        'category',
        'description',
        'is_active',
    ];
}
