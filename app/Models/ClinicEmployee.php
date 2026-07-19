<?php

namespace App\Models;

use App\EmploymentStatus;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicEmployee extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'user_id',
        'name',
        'email',
        'phone',
        'status',
        'reports_to_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmploymentStatus::class,
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'clinic_employee_branches', 'clinic_employee_id', 'branch_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(ClinicDepartment::class, 'clinic_employee_departments', 'clinic_employee_id', 'clinic_department_id');
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(ClinicRoom::class, 'clinic_employee_rooms', 'clinic_employee_id', 'clinic_room_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'clinic_employee_roles', 'clinic_employee_id', 'role_id');
    }
}
