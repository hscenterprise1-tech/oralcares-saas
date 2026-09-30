<?php

namespace App\Models;

use App\BranchStatus;
use App\Traits\OrganizationScoped;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, OrganizationScoped;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'clinic_id',
        'name',
        'slug',
        'status',
        'is_main',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'pincode',
        'phone',
        'whatsapp_number',
        'resource_capacity',
        'gstin',
        'clinical_establishment_registration_number',
        'display_name',
        'logo_path',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
            'is_main' => 'boolean',
        ];
    }

    /**
     * Get the organization that owns the branch.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the clinic that owns the branch.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Get the clinic memberships assigned to this branch.
     *
     * @return BelongsToMany<ClinicMembership, $this>
     */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(ClinicMembership::class, 'branch_clinic_membership')
            ->using(MembershipBranchAccess::class)
            ->withTimestamps();
    }

    /**
     * Get the weekly operating hours for this branch.
     *
     * Lives on `clinic_operating_hours`, not a column here — see
     * `2026_09_23_000001_add_details_fields_to_branches_table` for why.
     *
     * @return HasMany<ClinicOperatingHour, $this>
     */
    public function operatingHours(): HasMany
    {
        return $this->hasMany(ClinicOperatingHour::class);
    }

    /**
     * Public URL for this branch's own logo override, or null when it has
     * none — callers fall back to the clinic's `logo_url` themselves, the
     * same override pattern as `display_name`.
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
