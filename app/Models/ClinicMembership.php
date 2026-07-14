<?php

namespace App\Models;

use App\ClinicMembershipStatus;
use Database\Factories\ClinicMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicMembership extends Model
{
    /** @use HasFactory<ClinicMembershipFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'user_id',
        'is_primary_owner',
        'has_all_branches_access',
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
            'is_primary_owner' => 'boolean',
            'has_all_branches_access' => 'boolean',
            'status' => ClinicMembershipStatus::class,
        ];
    }

    /**
     * Get the clinic that the membership belongs to.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Get the user that the membership belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the explicitly assigned branches for the membership.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function assignedBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_clinic_membership');
    }

    /**
     * Determine whether the membership can access the given branch.
     */
    public function hasAccessToBranch(Branch $branch): bool
    {
        if ($branch->clinic_id !== $this->clinic_id) {
            return false;
        }

        if ($this->has_all_branches_access) {
            return true;
        }

        return $this->assignedBranches()->where('branch_id', $branch->id)->exists();
    }

    /**
     * Explicitly assign a branch to this membership.
     *
     * @throws \InvalidArgumentException
     */
    public function assignBranch(Branch $branch): void
    {
        if ($branch->clinic_id !== $this->clinic_id) {
            throw new \InvalidArgumentException('Cannot assign a branch belonging to another clinic.');
        }

        $this->assignedBranches()->syncWithoutDetaching([$branch->id]);
    }

    /**
     * Get the role assignments for the membership.
     *
     * @return HasMany<ClinicMembershipRole, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(ClinicMembershipRole::class);
    }
}
