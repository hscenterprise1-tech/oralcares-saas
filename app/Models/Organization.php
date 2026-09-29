<?php

namespace App\Models;

use App\ClinicType;
use App\CustomerChurnRisk;
use App\OrganizationRole;
use App\OrganizationScale;
use App\OrganizationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'type',
        'role',
        'owner_name',
        'contact_person',
        'email',
        'mobile',
        'gst',
        'upi_id',
        'bank_account_name',
        'bank_account_number',
        'bank_ifsc',
        'bank_name',
        'website',
        'address',
        'city',
        'state',
        'country',
        'timezone',
        'assigned_user_id',
        'clinic_type',
        'organization_scale',
        'health_score',
        'renewal_probability',
        'churn_risk',
        'health_score_overridden_at',
        'churn_risk_overridden_at',
        'metrics_overridden_by',
        'expansion_opportunity',
    ];

    /*
     * The column carries this default too, but a database default only shows up
     * after a refresh — code asking `$org->role` on a row it just created would
     * otherwise get null and have to guess, which is the habit this whole change
     * exists to remove. Same answer in memory and on disk.
     */
    protected $attributes = [
        'role' => 'customer',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'role' => OrganizationRole::class,
            'clinic_type' => ClinicType::class,
            'organization_scale' => OrganizationScale::class,
            'churn_risk' => CustomerChurnRisk::class,
            'health_score_overridden_at' => 'datetime',
            'churn_risk_overridden_at' => 'datetime',
            'expansion_opportunity' => 'boolean',
        ];
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function license(): HasOne
    {
        return $this->hasOne(License::class);
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(CustomerSuccessSnapshot::class);
    }

    /**
     * The one definition of a customer account.
     *
     * `organizations` holds two different things: the vendor's own
     * organization (every company user resolves to it) and the customers sold
     * to. Without this distinction Accounts, Onboarding, Subscriptions and
     * Renewals all listed OralCares itself as a client.
     *
     * An organization is a customer when either is true:
     *
     *   - a lead was won and provisioned into it
     *     (`leads.customer_organization_id`, the column Phase 0 added), or
     *   - an operational tenant exists beneath it (a Clinic), which covers
     *     customers provisioned outside the CRM lead flow.
     *
     * The vendor organization satisfies neither. Every post-sale read path
     * uses this scope rather than inventing its own filter.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    /**
     * The clinics and hospitals we have sold to.
     *
     * This used to ask whether a row happened to have won leads or clinics,
     * which is a question about history rather than identity — a customer
     * provisioned a moment ago has neither, so the Accounts directory read
     * empty even when customers existed. Role is the fact; it is set the moment
     * the organization is created and never inferred.
     */
    public function scopeCustomerAccounts(Builder $query): Builder
    {
        return $query->where('organizations.role', OrganizationRole::Customer->value);
    }

    /** The company running OralCares — the one organization that sells. */
    public function scopeProviders(Builder $query): Builder
    {
        return $query->where('organizations.role', OrganizationRole::Provider->value);
    }

    /**
     * The provider organization.
     *
     * The single answer to "which organization are we", replacing a literal
     * name match in two services and a `min('id')` fallback.
     *
     * Deliberately not memoised in a static. A static outlives the request in a
     * queue worker and outlives the *database* in a test suite, where it would
     * hand back an organization that no longer exists — which it promptly did.
     * The column is indexed; a primary-key-ish lookup is not worth a correctness
     * risk.
     */
    public static function provider(): ?self
    {
        return static::query()->providers()->orderBy('id')->first();
    }

    public function isProvider(): bool
    {
        return $this->role === OrganizationRole::Provider;
    }

    public function isCustomer(): bool
    {
        return $this->role === OrganizationRole::Customer;
    }

    /**
     * The operational tenants belonging to this commercial account.
     *
     * Organization → Clinic → Branch is the real hierarchy; a branch also
     * carries `organization_id` denormalised for scoping, but its owner is the
     * clinic.
     *
     * @return HasMany<Clinic, $this>
     */
    public function clinics(): HasMany
    {
        return $this->hasMany(Clinic::class);
    }

    /**
     * The clinic that acts as this account's primary tenant.
     *
     * @return HasOne<Clinic, $this>
     */
    public function primaryClinic(): HasOne
    {
        return $this->hasOne(Clinic::class)->oldest('id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class);
    }

    /**
     * Leads owned by this organization as a tenant.
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Leads that were won and became this organization.
     *
     * @return HasMany<Lead, $this>
     */
    public function wonFromLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'customer_organization_id');
    }

    public function onboardingProgress(): HasOne
    {
        return $this->hasOne(OnboardingProgress::class);
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_primary', true)->latest('id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(CrmDocument::class, 'documentable');
    }

    /** Who last pinned this account's health or churn by hand. */
    public function metricsOverriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'metrics_overridden_by');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }
}
