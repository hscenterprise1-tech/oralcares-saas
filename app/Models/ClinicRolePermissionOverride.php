<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One clinic's change to a standard role's permission. Written by
 * `ClinicRolePermissions` only; read through it, never directly.
 */
class ClinicRolePermissionOverride extends Model
{
    protected $fillable = ['clinic_id', 'role_name', 'permission', 'granted', 'set_by'];

    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }
}
