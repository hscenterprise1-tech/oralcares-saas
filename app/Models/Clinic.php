<?php

namespace App\Models;

use App\ClinicStatus;
use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

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
        'organization_id',
        'name',
        'slug',
        'status',
        'logo_path',
        'display_name',
        'legal_name',
        'invoice_logo_path',
        'use_clinic_logo_for_invoice',
        'patient_field_config',
        'case_number_config',
        'patient_flow_config',
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
            'use_clinic_logo_for_invoice' => 'boolean',
            'patient_field_config' => 'array',
            'case_number_config' => 'array',
            'patient_flow_config' => 'array',
        ];
    }

    /**
     * The commercial account this tenant belongs to.
     *
     * `clinics.organization_id` has existed since July but was missing from
     * $fillable, so every write silently dropped it and the middle of the
     * Organization → Clinic → Branch hierarchy was never linked.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
     * The name to show the clinic's own staff and patients.
     *
     * Separate from `name`, the legal/CRM-facing name used across Company
     * HQ — a clinic may want a shorter or different trading name on its own
     * login page and portal header.
     */
    public function getBrandingNameAttribute(): string
    {
        return $this->display_name ?: $this->name;
    }

    /**
     * Public URL for the clinic's logo, or null when none is set.
     *
     * Served from the `public` disk: this is shown on the pre-auth login
     * page before the visitor has any session, so it can't go through a
     * signed-URL/private-disk path the way clinical documents do.
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /**
     * Public URL for the clinic's invoice logo, or null when none is set.
     *
     * Schema-only for now — nothing renders an invoice yet. `logo_url` is
     * returned instead whenever `use_clinic_logo_for_invoice` is on, so a
     * clinic that never bothers with a separate invoice logo still gets a
     * sensible one once billing exists.
     */
    public function getInvoiceLogoUrlAttribute(): ?string
    {
        if ($this->use_clinic_logo_for_invoice) {
            return $this->logo_url;
        }

        return $this->invoice_logo_path ? Storage::disk('public')->url($this->invoice_logo_path) : null;
    }

    /**
     * Get the primary branch of the clinic.
     *
     * @return HasOne<Branch, $this>
     */
    public function primaryBranch(): HasOne
    {
        return $this->hasOne(Branch::class)->where('is_main', true);
    }

    /**
     * Get the subscriptions for the clinic.
     *
     * @return HasMany<CompanySubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class);
    }
}
