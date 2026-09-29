<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OrganizationScoped;
use Database\Factories\ClinicalDepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClinicalDepartment extends Model
{
    /** @use HasFactory<ClinicalDepartmentFactory> */
    use Auditable, HasFactory, OrganizationScoped;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];
}
