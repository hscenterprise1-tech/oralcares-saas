<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\ToothSurfaceMasterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ToothSurfaceMaster extends Model
{
    /** @use HasFactory<ToothSurfaceMasterFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
    ];
}
