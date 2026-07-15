<?php

namespace App\Models;

use App\TaskPriority;
use App\TaskStatus;
use App\UserType;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'assigned_user_id',
        'assigned_team_id',
        'creator_id',
        'taskable_type',
        'taskable_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Task $task) {
            // 1. Enforce exclusive assignment semantics (cannot assign to both user and team)
            if ($task->assigned_user_id !== null && $task->assigned_team_id !== null) {
                throw new InvalidArgumentException('A task cannot be assigned to both a user and a team simultaneously.');
            }

            // 2. Enforce platform-only context assignment validation (no clinic_user allowed)
            if ($task->assigned_user_id !== null) {
                $user = User::find($task->assigned_user_id);
                if ($user && $user->user_type === UserType::ClinicUser) {
                    throw new InvalidArgumentException('CRM records cannot be assigned to clinic users.');
                }
            }

            // 3. Manage completed_at timestamp consistency
            if ($task->status === TaskStatus::Completed) {
                $task->completed_at = $task->completed_at ?? now();
            } else {
                $task->completed_at = null;
            }
        });
    }

    /**
     * Get the internal platform user assigned to this task.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Get the platform team assigned to this task.
     */
    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }

    /**
     * Get the internal operator who created this task.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Get the generic related business entity.
     *
     * @return MorphTo<Model, $this>
     */
    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }
}
