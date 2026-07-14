<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolePermission extends Model
{
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'permission',
    ];

    /**
     * Get the role that owns the permission mapping.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
