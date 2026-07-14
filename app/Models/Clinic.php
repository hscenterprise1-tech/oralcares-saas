<?php

namespace App\Models;

use App\ClinicStatus;
use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Clinic extends Model
{
    /** @use HasFactory<ClinicFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClinicStatus::class,
        ];
    }

    /**
     * Get the branches for the clinic.
     *
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Get the memberships for the clinic.
     *
     * @return HasMany<ClinicMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ClinicMembership::class);
    }

    /**
     * Get the primary owner membership for the clinic.
     *
     * @return HasOne<ClinicMembership, $this>
     */
    public function primaryOwnerMembership(): HasOne
    {
        return $this->hasOne(ClinicMembership::class)->where('is_primary_owner', true);
    }

    /**
     * Get the primary owner (user) of the clinic.
     */
    public function getPrimaryOwnerAttribute(): ?User
    {
        return $this->primaryOwnerMembership?->user;
    }

    /**
     * Get the roles scoped to the clinic.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }
}
