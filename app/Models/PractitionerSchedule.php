<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A weekly window when a clinician works at a branch.
 *
 * Keyed by `clinic_employee_id`, the same id appointments, visits and queue
 * items use for the doctor. `day_of_week` follows `clinic_operating_hours`:
 * 0 = Sunday … 6 = Saturday. `is_available = false` marks a blocked window.
 */
class PractitionerSchedule extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'clinic_employee_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_available',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_available' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /**
     * The staff member and branch must belong to the schedule's clinic; the
     * clinic is taken from the staff member when it is not given.
     */
    protected function performInsert(Builder $query)
    {
        $this->assertSameClinic();

        return parent::performInsert($query);
    }

    protected function performUpdate(Builder $query)
    {
        $this->assertSameClinic();

        return parent::performUpdate($query);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'clinic_employee_id');
    }

    private function assertSameClinic(): void
    {
        $employeeClinic = ClinicEmployee::withoutGlobalScopes()->whereKey($this->clinic_employee_id)->value('clinic_id');
        $branchClinic = Branch::withoutGlobalScopes()->whereKey($this->branch_id)->value('clinic_id');

        $this->clinic_id ??= $employeeClinic;

        if (! $employeeClinic || (int) $employeeClinic !== (int) $this->clinic_id || (int) $branchClinic !== (int) $this->clinic_id) {
            throw new LogicException('A practitioner schedule must use a staff member and a branch of its own clinic.');
        }
    }
}
