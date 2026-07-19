<?php

namespace App\Models;

use Database\Factories\CompanyPermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CompanyPermission extends Model
{
    /** @use HasFactory<CompanyPermissionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key',
        'description',
        'module',
    ];

    /**
     * Get the roles that possess this permission.
     *
     * @return BelongsToMany<CompanyRole, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyRole::class,
            'company_role_permissions',
            'company_permission_id',
            'company_role_id'
        );
    }
}
