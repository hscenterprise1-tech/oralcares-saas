<?php

namespace App\Models;

use App\EmploymentStatus;
use Database\Factories\CompanyEmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyEmployee extends Model
{
    /** @use HasFactory<CompanyEmployeeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_code',
        'user_id',
        'department_id',
        'team_id',
        'manager_id',
        'designation',
        'employment_status',
        'joining_date',
        'profile_photo',
        'timezone',
        'language',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_status' => EmploymentStatus::class,
            'joining_date' => 'date',
        ];
    }

    /**
     * Get the user linked to this employee profile.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the department the employee belongs to.
     *
     * @return BelongsTo<CompanyDepartment, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(CompanyDepartment::class, 'department_id');
    }

    /**
     * Get the team the employee belongs to.
     *
     * @return BelongsTo<CompanyTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(CompanyTeam::class, 'team_id');
    }

    /**
     * Get the manager the employee reports to.
     *
     * @return BelongsTo<CompanyEmployee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(CompanyEmployee::class, 'manager_id');
    }

    /**
     * Get the direct reports (employees) reporting to this employee.
     *
     * @return HasMany<CompanyEmployee, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(CompanyEmployee::class, 'manager_id');
    }

    /**
     * Get the roles assigned to this employee.
     *
     * @return BelongsToMany<CompanyRole, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyRole::class,
            'company_employee_roles',
            'company_employee_id',
            'company_role_id'
        );
    }

    /**
     * Get the invitations sent to this employee.
     *
     * @return HasMany<CompanyEmployeeInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyEmployeeInvitation::class, 'company_employee_id');
    }

    /**
     * Get the audit activities logged for this employee.
     *
     * @return HasMany<CompanyEmployeeActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(CompanyEmployeeActivity::class, 'company_employee_id');
    }
}
