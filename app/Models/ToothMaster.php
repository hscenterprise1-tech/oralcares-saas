<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ToothMaster extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'organization_id',
        'universal_number',
        'fdi_number',
        'tooth_type',
        'quadrant',
        'name',
    ];
}
