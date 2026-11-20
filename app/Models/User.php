<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\ClinicPermission;
use App\OrganizationRole;
use App\OrganizationType;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Staff\ClinicRolePermissions;
use App\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'user_type',
        'organization_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'user_type' => UserType::class,
        ];
    }

    /**
     * Determine if the user is a Master Super Admin.
     */
    public function isMasterAdmin(): bool
    {
        return $this->user_type === UserType::MasterAdmin;
    }

    /**
     * Get the clinic memberships for the user.
     *
     * @return HasMany<ClinicMembership, $this>
     */
    public function clinicMemberships(): HasMany
    {
        return $this->hasMany(ClinicMembership::class);
    }

    /**
     * Get the CRM leads assigned to this user.
     *
     * @return HasMany<Lead, $this>
     */
    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_user_id');
    }

    /**
     * Get the tasks assigned to this user.
     *
     * @return HasMany<Task, $this>
     */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_user_id');
    }

    /**
     * Get the tasks created by this user.
     *
     * @return HasMany<Task, $this>
     */
    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /**
     * Get the activities performed by this user.
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'user_id');
    }

    /**
     * Get the organization the user belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the internal teams this user belongs to.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_users');
    }

    /**
     * Get the company employee profile for this user.
     *
     * @return HasOne<CompanyEmployee, $this>
     */
    public function companyEmployee(): HasOne
    {
        return $this->hasOne(CompanyEmployee::class, 'user_id');
    }

    /**
     * Resolve the canonical Organization ID for this user.
     *
     * An explicit organization_id always wins. A company user without one
     * belongs to the provider — the organization that runs OralCares.
     *
     * This matched the literal name 'OralCares HQ' and fell back to
     * `min('id')`. Both were guesses: renaming the row broke the product, and
     * `min('id')` would happily hand a company user the first *customer* on any
     * install where the seller was not inserted first. Role is now recorded, so
     * the question has a real answer.
     */
    public function resolveOrganizationId(): int
    {
        if ($this->organization_id) {
            return (int) $this->organization_id;
        }

        if ($provider = Organization::provider()) {
            return (int) $provider->id;
        }

        /*
         * A completely fresh install with no provider yet. Created explicitly as
         * one rather than hoping a later query infers it.
         */
        $provider = Organization::create([
            'name' => 'OralCares HQ',
            'legal_name' => 'OralCares India Pvt Ltd',
            'type' => OrganizationType::Other,
            'role' => OrganizationRole::Provider,
            'email' => 'hq@oralcares.in',
        ]);

        return (int) $provider->id;
    }

    /**
     * Determine if user has a clinic permission within the active clinic context.
     */
    public function hasClinicPermission(string|ClinicPermission $permission): bool
    {
        if ($this->isMasterAdmin()) {
            return true;
        }

        /** @var ClinicContext $context */
        $context = app(ClinicContext::class);
        $permissionValue = $permission instanceof ClinicPermission ? $permission->value : $permission;
        if ($context->hasClinic()) {
            $membership = $context->membership();
            if ($membership?->is_primary_owner) {
                // The owner holds every permission, except a doctor-owner in Doctor Mode,
                // who holds a doctor's until they enter Owner Mode (`OwnerMode`).
                $ownerMode = app(OwnerMode::class);
                if ($ownerMode->authorized() || ! $ownerMode->isDoctorOwner()) {
                    return true;
                }
                if (in_array($permissionValue, OwnerMode::doctorModePermissions(), true)) {
                    return true;
                }
            }
        }

        // Fail-closed: only an explicit Spatie grant (via an assigned
        // ClinicRole, scoped to the active branch's team) grants access,
        // unless this clinic's owner has changed what that role may do.
        try {
            $decision = $context->hasClinic() ? app(ClinicRolePermissions::class)->decide($context->clinic(), $this->getRoleNames(), $permissionValue) : null;
            if ($decision !== null) {
                return $decision;
            }

            return $this->hasPermissionTo($permissionValue);
        } catch (\Throwable) {
            // permission string might not exist in Spatie permissions table
            return false;
        }
    }

    /**
     * Whether this user holds a clinic permission at a given branch of the
     * clinic the session is bound to, rather than at the session's own branch.
     *
     * Staff roles are granted per branch (the Spatie team is the branch), so
     * opening a branch is not the same as being allowed to act there. The
     * owner may act at every branch of their clinic. Anyone else needs access
     * to the branch and the permission held at it. The request's permission
     * team is restored afterwards.
     */
    public function hasClinicPermissionAt(string|ClinicPermission $permission, Branch $branch): bool
    {
        /** @var ClinicContext $context */
        $context = app(ClinicContext::class);
        $membership = $context->membership();

        if (! $membership || $membership->user_id !== $this->id || $branch->clinic_id !== $membership->clinic_id) {
            return false;
        }

        $permissionValue = $permission instanceof ClinicPermission ? $permission->value : $permission;

        if ($membership->is_primary_owner) {
            // As `hasClinicPermission`: a doctor-owner in Doctor Mode holds a doctor's permissions.
            $ownerMode = app(OwnerMode::class);
            if ($ownerMode->authorized() || ! $ownerMode->isDoctorOwner() || in_array($permissionValue, OwnerMode::doctorModePermissions(), true)) {
                return true;
            }
        }

        if (! $membership->hasAccessToBranch($branch)) {
            return false;
        }
        $previousTeamId = getPermissionsTeamId();

        try {
            setPermissionsTeamId($branch->id);
            $this->unsetRelation('roles')->unsetRelation('permissions');

            $decision = app(ClinicRolePermissions::class)->decide($context->hasClinic() ? $context->clinic() : null, $this->getRoleNames(), $permissionValue);
            if ($decision !== null) {
                return $decision;
            }

            return $this->hasPermissionTo($permissionValue);
        } catch (\Throwable) {
            return false;
        } finally {
            setPermissionsTeamId($previousTeamId);
            $this->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * Backward-compatible permission check helper.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->hasClinicPermission($permission);
    }
}
