<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One exercise as it actually went in a session: performed or skipped, with
 * the dose given. Linked to the prescribed exercise when there was one (null
 * for an exercise added on the day). `name` is a snapshot, so renaming or
 * revising the plan later never changes what the session shows.
 */
class PhysioSessionExercise extends Model
{
    use ClinicScoped;

    public const PERFORMED = 'performed';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'clinic_id',
        'physio_session_id',
        'physio_exercise_id',
        'name',
        'status',
        'sets',
        'reps',
        'duration_seconds',
        'intensity',
        'notes',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'sets' => 'integer',
            'reps' => 'integer',
            'duration_seconds' => 'integer',
            'position' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PhysioSession::class, 'physio_session_id');
    }

    public function prescribed(): BelongsTo
    {
        return $this->belongsTo(PhysioExercise::class, 'physio_exercise_id');
    }
}
