<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OrganizationScoped;
use Database\Factories\DiagnosisMasterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiagnosisMaster extends Model
{
    /** @use HasFactory<DiagnosisMasterFactory> */
    use Auditable, HasFactory, OrganizationScoped;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'category',
        'description',
        'is_active',
    ];
}
