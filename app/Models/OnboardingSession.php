<?php

namespace App\Models;

use App\OnboardingSessionStatus;
use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One onboarding journey: the clinic's own setup (kind "clinic"), or one
 * staff member's profile setup (kind "staff"). Unique per clinic and
 * subject, so starting twice resumes the same session.
 */
class OnboardingSession extends Model
{
    use ClinicScoped;

    public const KIND_CLINIC = 'clinic';

    public const KIND_STAFF = 'staff';

    protected $fillable = [
        'clinic_id',
        'subject_key',
        'kind',
        'clinic_employee_id',
        'template_key',
        'template_version',
        'status',
        'current_step',
        'blocked_reason',
        'status_before_block',
        'owner_user_id',
        'started_at',
        'paused_at',
        'blocked_at',
        'completed_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnboardingSessionStatus::class,
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'blocked_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public static function subjectKeyFor(?ClinicEmployee $employee): string
    {
        return $employee ? 'staff:'.$employee->id : self::KIND_CLINIC;
    }

    public function isCompleted(): bool
    {
        return $this->status === OnboardingSessionStatus::Completed;
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'clinic_employee_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(OnboardingSessionStep::class)->orderBy('position');
    }
}
