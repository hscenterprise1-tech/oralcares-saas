<?php

namespace App\Models;

use App\EmploymentStatus;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Permission\Models\Role;

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

    protected static function booted(): void
    {
        static::saving(function (ClinicEmployee $employee) {
            if (empty($employee->attributes['name'])) {
                $employee->attributes['name'] = trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')) ?: 'Staff Member';
            }
        });
    }

    public function getFirstNameAttribute(): string
    {
        $parts = explode(' ', $this->name ?? '');

        return $parts[0] ?? '';
    }

    public function getLastNameAttribute(): string
    {
        $parts = explode(' ', $this->name ?? '');

        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
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

    /**
     * @return HasMany<ClinicEmployeeInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(ClinicEmployeeInvitation::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'clinic_employee_branches', 'clinic_employee_id', 'branch_id')
            ->using(StaffBranchPlacement::class);
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

    public function practitioner(): HasOne
    {
        return $this->hasOne(Practitioner::class, 'clinic_employee_id');
    }

    public function practitionerProfile(): HasOne
    {
        return $this->hasOne(Practitioner::class, 'clinic_employee_id');
    }

    /**
     * Active staff for a clinic, ready for a doctor-picker.
     *
     * `clinic_employees` carries no job title of its own — a specialty is a
     * property of the clinical profile (`doctor_profiles`, via
     * `practitionerProfile`), which not every employee has one of. Shared
     * between `Patient360Controller` (action-modal pickers) and
     * `PatientController::create()` (assign-at-registration) so the two
     * never drift into picking different columns.
     *
     * @return array<int, array{id: int, name: string, specialty: string}>
     */
    public static function activePractitioners(int $clinicId): array
    {
        return static::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->where('status', 'active')
            ->with('practitionerProfile')
            ->orderBy('name', 'asc')
            ->get(['id', 'name'])
            ->map(fn (self $emp) => [
                'id' => $emp->id,
                'name' => $emp->name,
                'specialty' => $emp->practitionerProfile?->specialty ?? 'Healthcare Provider',
            ])
            ->all();
    }
}
