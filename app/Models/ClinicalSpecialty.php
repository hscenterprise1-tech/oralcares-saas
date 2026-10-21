<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClinicalSpecialty extends Model
{
    use Auditable, HasFactory, OrganizationScoped;

    protected $fillable = [
        'name',
        'practice_area_key',
        'description',
        'is_active',
    ];
}
