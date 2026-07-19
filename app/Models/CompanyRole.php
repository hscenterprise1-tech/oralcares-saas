<?php

namespace App\Models;

use App\CompanyRoleStatus;
use Database\Factories\CompanyRoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CompanyRole extends Model
{
    /** @use HasFactory<CompanyRoleFactory> */
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
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyRoleStatus::class,
        ];
    }

    /**
     * Get the permissions assigned to this role.
     *
     * @return BelongsToMany<CompanyPermission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyPermission::class,
            'company_role_permissions',
            'company_role_id',
            'company_permission_id'
        );
    }

    /**
     * Get the employees assigned to this role.
     *
     * @return BelongsToMany<CompanyEmployee, $this>
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyEmployee::class,
            'company_employee_roles',
            'company_role_id',
            'company_employee_id'
        );
    }
}
