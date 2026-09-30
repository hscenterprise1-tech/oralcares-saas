<?php

namespace App\Models;

use App\EmploymentStatus;
use Database\Factories\CompanyEmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /**
     * Get this employee's HQ attendance, most recent day first.
     *
     * Company-side only. The clinic `leaves` and `shifts` tables belong to
     * `clinic_employees` and describe a different workforce entirely.
     *
     * @return HasMany<CompanyAttendanceRecord, $this>
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(CompanyAttendanceRecord::class, 'employee_id')->orderByDesc('work_date');
    }

    /**
     * Get this employee's leave requests, most recent first.
     *
     * @return HasMany<CompanyLeaveRequest, $this>
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(CompanyLeaveRequest::class, 'employee_id')->orderByDesc('start_date');
    }

    /**
     * Get this employee's leave balances.
     *
     * @return HasMany<CompanyLeaveBalance, $this>
     */
    public function leaveBalances(): HasMany
    {
        return $this->hasMany(CompanyLeaveBalance::class, 'employee_id');
    }

    /**
     * The timezone this employee's working day is measured in.
     *
     * Three of the nine people on record carry no timezone, so a fallback is
     * needed rather than optional. It is the application's own timezone —
     * the only one this system establishes — never an invented default.
     */
    public function resolvedTimezone(): string
    {
        return $this->timezone ?: (string) config('app.timezone', 'UTC');
    }
}
