<?php

namespace App\Models;

use App\UserType;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, SoftDeletes;

    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    public const STRATEGY_TERRITORY = 'territory';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'key',
        'description',
        'manager_id',
        'is_active',
        'assignment_strategy',
        'territory_map',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'territory_map' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The employee accountable for this team's number.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Get the internal users belonging to this operational team.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_users');
    }

    /**
     * Attach a user to this team with validation.
     *
     * @throws InvalidArgumentException
     */
    public function addUser(User $user): void
    {
        if ($user->user_type === UserType::ClinicUser) {
            throw new InvalidArgumentException('Clinic users cannot be added to platform operational teams.');
        }

        $this->users()->attach($user->id);
    }

    /**
     * Pick the next member in rotation for round-robin auto-assignment and
     * advance the cursor so the following lead goes to the next member.
     */
    public function nextRoundRobinUser(): ?User
    {
        $memberIds = $this->users()
            ->where('user_type', '!=', UserType::ClinicUser)
            ->orderBy('users.id')
            ->pluck('users.id')
            ->values();

        if ($memberIds->isEmpty()) {
            return null;
        }

        $position = $this->round_robin_cursor % $memberIds->count();
        $userId = $memberIds[$position];

        $this->round_robin_cursor = $position + 1;
        $this->save();

        return User::find($userId);
    }

    /**
     * Look up the rep mapped to a given state in this team's territory
     * configuration (case-insensitive match on the state name).
     */
    public function userForState(?string $state): ?User
    {
        if (! $state || empty($this->territory_map)) {
            return null;
        }

        foreach ($this->territory_map as $mappedState => $userId) {
            if (strcasecmp(trim((string) $mappedState), trim($state)) === 0) {
                return User::find($userId);
            }
        }

        return null;
    }
}
