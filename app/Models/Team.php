<?php

namespace App\Models;

use App\UserType;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key',
        'description',
        'is_active',
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
        ];
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
}
