<?php

namespace App\Models;

use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\UserType;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * @property int|null $organization_id
 */
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
        'type',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'assigned_user_id',
        'assigned_team_id',
        'creator_id',
        'organization_id',
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
            'type' => TaskType::class,
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

            /*
             * 4. Stamp tenant ownership once. Tasks are written from a dozen
             * different call sites, most of which never think to pass
             * `organization_id` themselves — so it is derived here, the one
             * place every write passes through, rather than trusted from the
             * caller. Never overwritten once set, so a task deliberately
             * reassigned to a different tenant's user later keeps the tenant
             * it was actually created for.
             */
            if ($task->organization_id === null) {
                $task->organization_id = static::deriveOrganizationId($task);
            }
        });
    }

    /**
     * Best available answer to "whose tenant does this task belong to",
     * checked in the order a task's own facts are trustworthy: the record
     * it's actually about, then who it's assigned to, then who wrote it.
     * Returns null — never a guess — when none of those resolve, so a task
     * with genuinely no traceable owner stays invisible to CrmScope's
     * fail-closed reads rather than being silently misfiled.
     */
    public static function deriveOrganizationId(self $task): ?int
    {
        if ($task->taskable_type && $task->taskable_id) {
            // Accepts both the registered morph alias and a bare FQCN — a
            // known writer (CustomerProvisioningEngine, since fixed) used to
            // stamp the raw class name, and old rows still carry it.
            if (in_array($task->taskable_type, [(new Lead)->getMorphClass(), Lead::class], true)) {
                return Lead::withoutGlobalScopes()->find($task->taskable_id)?->organization_id;
            }

            if (in_array($task->taskable_type, [(new Organization)->getMorphClass(), Organization::class], true)) {
                return static::providerOrganizationIdFor((int) $task->taskable_id);
            }

            if ($task->taskable_type === MarketingContact::class) {
                return MarketingContact::find($task->taskable_id)?->organization_id;
            }

            if (in_array($task->taskable_type, [(new Contact)->getMorphClass(), Contact::class], true)) {
                return Contact::find($task->taskable_id)?->organization_id;
            }
        }

        $ownerId = $task->assigned_user_id ?? $task->creator_id;

        return $ownerId ? User::find($ownerId)?->resolveOrganizationId() : null;
    }

    /**
     * The provider tenant behind a customer Organization record.
     *
     * `organizations` rows serve two roles — the one provider tenant, and
     * every customer account won into it — so a task filed against the
     * customer side (Account 360, post-conversion) still belongs to the
     * provider who manages that account, not to the customer's own id.
     * Traced through the lead that converted into it; if none did (this row
     * is the provider itself, or an account with no CRM history), the id is
     * used as-is rather than returning nothing for a row that does exist.
     */
    protected static function providerOrganizationIdFor(int $organizationId): ?int
    {
        $providerId = Lead::withoutGlobalScopes()
            ->where('customer_organization_id', $organizationId)
            ->value('organization_id');

        if ($providerId) {
            return $providerId;
        }

        return Organization::whereKey($organizationId)->exists() ? $organizationId : null;
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
     * The tenant this task belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
