<?php

namespace App\Models;

use App\ClinicMembershipStatus;
use App\Services\Security\SecurityAudit;
use App\Traits\ClinicScoped;
use Database\Factories\ClinicMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

class ClinicMembership extends Model
{
    /** @use HasFactory<ClinicMembershipFactory> */
    use ClinicScoped, HasFactory;

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
     * Make this membership's branch access exactly the given branches, and
     * record what was granted and revoked in the security trail.
     *
     * @param  Collection<int, Branch>  $branches
     *
     * @throws \InvalidArgumentException when a branch belongs to another clinic
     */
    public function syncAssignedBranches(Collection $branches, ?int $actorUserId = null): void
    {
        if ($branches->contains(fn (Branch $branch) => $branch->clinic_id !== $this->clinic_id)) {
            throw new \InvalidArgumentException('Cannot assign a branch belonging to another clinic.');
        }

        $changes = $this->assignedBranches()->sync($branches->pluck('id')->all());

        if ($changes['attached'] !== [] || $changes['detached'] !== []) {
            app(SecurityAudit::class)->record('access.branches_changed', $this, [
                'member_user_id' => $this->user_id,
                'granted' => array_values($changes['attached']),
                'revoked' => array_values($changes['detached']),
            ], array_filter(['actor_user_id' => $actorUserId, 'clinic_id' => $this->clinic_id], fn ($value) => $value !== null));
        }
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

        $changes = $this->assignedBranches()->syncWithoutDetaching([$branch->id]);

        if ($changes['attached'] !== []) {
            app(SecurityAudit::class)->record('access.branches_changed', $this, [
                'member_user_id' => $this->user_id,
                'granted' => [$branch->id],
            ], ['clinic_id' => $this->clinic_id]);
        }
    }
}
