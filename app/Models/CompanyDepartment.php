<?php

namespace App\Models;

use App\DepartmentStatus;
use Database\Factories\CompanyDepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyDepartment extends Model
{
    /** @use HasFactory<CompanyDepartmentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'status',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DepartmentStatus::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the teams belonging to the department.
     *
     * @return HasMany<CompanyTeam, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(CompanyTeam::class, 'department_id');
    }

    /**
     * Get the employees belonging to the department.
     *
     * @return HasMany<CompanyEmployee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(CompanyEmployee::class, 'department_id');
    }
}
