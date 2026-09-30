<?php

namespace App\Models;

use App\FacilityType;
use App\LeadPriority;
use App\LeadStatus;
use App\LostReason;
use App\OrganizationRole;
use App\OrganizationType;
use App\QuotationStatus;
use App\Services\CommercialLedgerService;
use App\Traits\OrganizationScoped;
use App\UserType;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, OrganizationScoped, SoftDeletes;

    /**
     * PHP-level defaults mirroring the database column defaults, so a
     * freshly-created in-memory instance always has a valid status/priority
     * even before an explicit reload from the database (e.g. inside model
     * event listeners that fire on the same request).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
        'priority' => 'medium',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'slug',
        'contact_name',
        'email',
        'phone',
        'status',
        'qualified_at',
        'stage_entered_at',
        'stage_durations',
        'priority',
        'source',
        'assigned_user_id',
        'assigned_team_id',
        'organization_id',
        'customer_organization_id',
        'competitors',
        'lead_score',
        'automated_score',
        'organization_type',
        'facility_type',
        'healthcare_specialty',
        'healthcare_specialties',
        'organization_size',
        'branch_count',
        'expected_users',
        'expected_mrr',
        'expected_arr',
        'next_best_action',
        'next_followup_at',
        'followup_notes',
        'probability_to_close',
        'expected_go_live_date',
        'current_software',
        'migration_required',
        'deployment_preference',
        'required_modules',
        'required_integrations',
        'selected_plan_id',
        'product_interest',
        'lost_reason',
        'lost_at',
        'lost_competitor',
        'winback_scheduled_at',
        'marketing_contact_id',
        'marketing_campaign_id',
        'normalized_phone',
        'address',
        'city',
        'area',
        'state',
        'country',
        'postal_code',
        'gstin',
    ];

    protected $appends = [
        'has_accepted_agreement',
        'has_successful_payment',
        'has_complete_address',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'qualified_at' => 'datetime',
            'stage_entered_at' => 'datetime',
            'stage_durations' => 'array',
            'healthcare_specialties' => 'array',
            'facility_type' => FacilityType::class,
            'priority' => LeadPriority::class,
            'organization_type' => OrganizationType::class,
            'lost_reason' => LostReason::class,
            'lost_at' => 'datetime',
            'winback_scheduled_at' => 'date',
            'product_interest' => 'array',
            'required_modules' => 'array',
            'required_integrations' => 'array',
            'expected_go_live_date' => 'date',
            'next_followup_at' => 'datetime',
            'migration_required' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        /*
         * Every deal belongs to a tenant.
         *
         * `organization_id` is the CRM tenant, and the policy now compares it
         * strictly — so a row created without one would be reachable by nobody
         * except a master admin. Falling back to the acting user's tenant
         * keeps every creation path honest, including factories and seeders
         * that never thought about it.
         */
        static::creating(function (Lead $lead) {
            if ($lead->organization_id === null) {
                $lead->organization_id = auth()->user()?->resolveOrganizationId();
            }
        });

        static::creating(function (Lead $lead) {
            if (empty($lead->slug)) {
                $lead->slug = Str::slug($lead->company_name);
            }

            $originalSlug = $lead->slug;
            $count = 0;
            /*
             * Uniqueness is checked across every tenant, because the column is
             * unique across every tenant — `leads_slug_unique` has no
             * organisation in it. Left scoped, this loop would look only inside
             * the creator's own tenant, find no collision, and hand the insert
             * a slug the database then rejects.
             */
            while (static::withoutOrganizationScope()->where('slug', $lead->slug)->exists()) {
                $suffix = strtolower(Str::random(6));
                $lead->slug = $originalSlug.'-'.$suffix;
                $count++;
                if ($count > 10) {
                    break;
                }
            }
        });

        static::saving(function (Lead $lead) {
            /*
             * 0. The CRM tenant is the organisation *selling*, never one being
             *    sold to.
             *
             * This is finding T-2 made unrepeatable. Four leads were stamped
             * with customer organisations 18, 19 and 22 because the legacy
             * tenant backfill resolved a missing tenant from the assigned
             * user's `organization_id`, and a master admin in this database
             * carried a customer account there. Those rows were then invisible
             * to every HQ manager under `CrmScope::LEVEL_ORGANIZATION`.
             *
             * The account a deal converts into is `customer_organization_id`,
             * which is a different column and stays untouched. Enforced on
             * `saving` rather than `creating` so a later reassignment cannot
             * reintroduce it either.
             */
            if ($lead->isDirty('organization_id') && $lead->organization_id !== null) {
                $role = static::organizationRole((int) $lead->organization_id);

                /*
                 * Only rejected when this installation can actually tell a
                 * seller from a customer.
                 *
                 * The rule being enforced is "a deal belongs to the
                 * organisation selling it, never to one being sold to", and
                 * that sentence only means something once a provider exists to
                 * be the seller. A database holding a single organisation and
                 * no provider row cannot express the distinction, so there is
                 * nothing there to violate — refusing the write would be
                 * asserting a fact the data does not contain.
                 *
                 * In production the provider is organisation 1 and this fires
                 * exactly as T-2 requires. `resolveOrganizationId()` creates a
                 * provider when none exists, so the ambiguous window closes on
                 * the first lead a real installation writes.
                 */
                if ($role === OrganizationRole::Customer->value && static::providerExists()) {
                    throw new InvalidArgumentException(
                        "A lead's organization_id must reference a provider organization; "
                        ."organization {$lead->organization_id} is a {$role}."
                    );
                }
            }

            // 1. Enforce exclusive assignment semantics (cannot assign to both user and team)
            if ($lead->assigned_user_id !== null && $lead->assigned_team_id !== null) {
                throw new InvalidArgumentException('A lead cannot be assigned to both a user and a team simultaneously.');
            }

            // 2. Enforce platform-only context assignment validation (no clinic_user allowed)
            if ($lead->assigned_user_id !== null) {
                $user = User::find($lead->assigned_user_id);
                if ($user && $user->user_type === UserType::ClinicUser) {
                    throw new InvalidArgumentException('CRM records cannot be assigned to clinic users.');
                }
            }
        });
    }

    /**
     * An organisation's role, memoised for the life of the process.
     *
     * The guard above runs on every lead write where the tenant is set, which
     * is every creation. Asking the database each time turned one insert into
     * two queries and measurably slowed anything that writes leads in bulk —
     * imports, the pipeline seeders, and the test suite most of all.
     *
     * An organisation's role does not change during a request: `provider` and
     * `customer` are what a row *is*, not a status it moves through. Caching it
     * per process is therefore safe, and the cache is cleared by the process
     * ending — including between tests, which each get a fresh database.
     *
     * @var array<int, string|null>
     */
    protected static array $organizationRoles = [];

    /** Memoised answer to "does this installation have a seller at all". */
    protected static ?bool $providerExists = null;

    /** Resolve and memoise one organisation's role. */
    protected static function organizationRole(int $organizationId): ?string
    {
        if (array_key_exists($organizationId, static::$organizationRoles)) {
            return static::$organizationRoles[$organizationId];
        }

        $role = Organization::withoutGlobalScopes()->whereKey($organizationId)->value('role');

        return static::$organizationRoles[$organizationId] = $role instanceof OrganizationRole
            ? $role->value
            : $role;
    }

    /** Whether any organisation is marked as the provider. */
    protected static function providerExists(): bool
    {
        return static::$providerExists ??= Organization::withoutGlobalScopes()
            ->where('role', OrganizationRole::Provider->value)
            ->exists();
    }

    /**
     * Forget the memoised roles.
     *
     * Only needed where a role genuinely changes inside one process — a test
     * that promotes a customer to a provider, or a command that repairs roles
     * in bulk.
     */
    public static function forgetOrganizationRoles(): void
    {
        static::$organizationRoles = [];
        static::$providerExists = null;
    }

    /**
     * Get the internal platform user assigned to this lead.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Get the platform team assigned to this lead.
     */
    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }

    /**
     * The organization that owns this lead as a tenant.
     *
     * This is the vendor-side organization of whoever created the lead, set by
     * LeadWorkspaceService::createLead(), and it is what every CRM read path
     * filters on. It is NOT the customer the lead becomes when won — see
     * customerOrganization() for that.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * The customer account provisioned from this lead when it was won.
     *
     * Null for every lead that has not been won. CustomerProvisioningEngine
     * writes it, and uses its nullness as the idempotency guard, so the two
     * meanings this column and `organization_id` used to share can no longer
     * cancel each other out.
     */
    public function customerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id');
    }

    public function marketingContact(): BelongsTo
    {
        return $this->belongsTo(MarketingContact::class, 'marketing_contact_id');
    }

    public function marketingCampaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function whatsAppConversation(): HasOne
    {
        return $this->hasOne(MarketingWhatsAppConversation::class, 'crm_lead_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(CompanyQuotation::class, 'lead_id');
    }

    public function trials(): HasMany
    {
        return $this->hasMany(Trial::class);
    }

    public function demos(): HasMany
    {
        return $this->hasMany(Demo::class);
    }

    /**
     * Branches this prospect already runs.
     *
     * Distinct from the hosted `branches` a won deal is provisioned with —
     * these are what the clinic operates today, recorded during qualification.
     */
    public function branches(): HasMany
    {
        return $this->hasMany(LeadBranch::class)->orderBy('position')->orderBy('id');
    }

    /** The one branch marked as the main site, if a rep has said which. */
    public function mainBranch(): HasOne
    {
        return $this->hasOne(LeadBranch::class)->where('is_main', true);
    }

    /**
     * The package this deal is being sold, when one has been picked.
     *
     * The column has existed since the deal schema was written and never had
     * a relation, so nothing could read it without a second query.
     */
    public function selectedPlan(): BelongsTo
    {
        return $this->belongsTo(CompanyPlan::class, 'selected_plan_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /*
     * `whatsAppMessages()` is gone with `lead_whatsapp_messages`.
     *
     * The deal's WhatsApp history has been the Marketing conversation since
     * Phase 2, and the old table's rows were preserved into `activities`. The
     * relation outlived both and had no callers.
     */

    public function callLogs(): HasMany
    {
        return $this->hasMany(LeadCallLog::class);
    }

    public function emailThreads(): HasMany
    {
        return $this->hasMany(LeadEmailThread::class);
    }

    /**
     * Get the append-only activity timeline events logged for this lead.
     *
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->orderBy('created_at', 'desc');
    }

    /**
     * Get the tasks related to this lead.
     *
     * @return MorphMany<Task, $this>
     */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /**
     * Get the documents for the lead.
     *
     * @return MorphMany<CrmDocument, $this>
     */
    public function documents()
    {
        return $this->morphMany(CrmDocument::class, 'documentable');
    }

    public function commercialDocuments()
    {
        return $this->hasMany(CommercialDocument::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Whether a signed agreement exists on this deal.
     *
     * `paid` counts. A settled payment moves the agreement on from `accepted`,
     * and a check that looked only for `accepted` therefore said "no signed
     * agreement" the moment the money arrived — which blocked Won at exactly
     * the point the deal had finished satisfying it.
     */
    /**
     * Whether any quotation on this deal has been approved and settled in full.
     *
     * This replaces `hasAcceptedAgreement()`. The commercial flow is Quotation →
     * Approval → Payment → Invoice → Won; there is no agreement step, and the
     * old check was reading a document type nothing creates any more.
     *
     * *Any* qualifying quotation counts, deliberately. A customer may buy twice
     * on one deal, and requiring a single blessed quotation would make the
     * second purchase unable to close the deal it belongs to.
     */
    public function hasApprovedPaidQuotation(): bool
    {
        $approved = $this->quotations()
            ->where('status', QuotationStatus::Approved->value)
            ->get();

        if ($approved->isEmpty()) {
            return false;
        }

        /*
         * Reconciled as a set. This used to call `reconcile()` inside a
         * `contains()` closure — one `payments` query per approved quotation,
         * on a method the deal page, the stage rules and the conversion gate
         * each call. `reconcileMany()` answers for all of them in one.
         */
        return app(CommercialLedgerService::class)
            ->reconcileMany($approved)
            ->contains(fn (array $state) => $state['state'] === CommercialLedgerService::PAID);
    }

    /**
     * @deprecated The agreement step no longer exists. Reads legacy rows only,
     * so historical deals closed under the old flow still answer truthfully.
     */
    public function hasAcceptedAgreement(): bool
    {
        return $this->commercialDocuments()
            ->where('type', 'agreement')
            ->whereIn('status', ['accepted', 'paid'])
            ->exists();
    }

    public function hasSuccessfulPayment(): bool
    {
        return $this->payments()->where('status', 'successful')->exists();
    }

    public function getHasAcceptedAgreementAttribute(): bool
    {
        return $this->hasAcceptedAgreement();
    }

    public function getHasSuccessfulPaymentAttribute(): bool
    {
        return $this->hasSuccessfulPayment();
    }

    /**
     * Whether the lead has enough address detail (address, city, state) to be
     * considered complete — drives the "complete address" prompt in the UI.
     */
    public function getHasCompleteAddressAttribute(): bool
    {
        return filled($this->address) && filled($this->city) && filled($this->state);
    }

    /**
     * How long the lead has sat in its current stage, in whole days.
     */
    public function getStageAgeDaysAttribute(): int
    {
        $enteredAt = $this->stage_entered_at ?? $this->updated_at ?? $this->created_at;

        if (! $enteredAt) {
            return 0;
        }

        return (int) $enteredAt->diffInDays(now());
    }

    /**
     * Total time banked in a given stage (seconds), including the current stint
     * if the lead is sitting in that stage right now.
     */
    public function stageDurationSeconds(string $stage): int
    {
        $banked = (int) (($this->stage_durations ?? [])[$stage] ?? 0);

        $currentStage = is_object($this->status) && isset($this->status->value)
            ? $this->status->value
            : (string) $this->status;

        if ($currentStage === $stage && $this->stage_entered_at) {
            $banked += (int) $this->stage_entered_at->diffInSeconds(now());
        }

        return $banked;
    }

    /**
     * Leads that have sat in their current stage longer than the given number
     * of days. Terminal stages are excluded — a won or lost deal is finished,
     * not stalled.
     */
    public function scopeStaleFor(Builder $query, int $days): Builder
    {
        return $query
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->where(function ($q) use ($days) {
                $cutoff = now()->subDays($days);
                $q->where('stage_entered_at', '<=', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff) {
                        $inner->whereNull('stage_entered_at')->where('updated_at', '<=', $cutoff);
                    });
            });
    }

    /**
     * The same rule as {@see scopeStaleFor}, answered from the loaded row.
     *
     * Two expressions of one definition, deliberately adjacent so they cannot
     * drift: the scope is for selecting stale leads, this is for asking whether
     * *this* lead is one.
     *
     * It exists because the Deals list used to answer that question with a
     * `SELECT EXISTS` per row — twenty-five extra queries on every page, for a
     * predicate over three attributes the row already carries. Anything holding
     * a Lead can answer it without touching the database.
     */
    public function isStaleAfter(int $days): bool
    {
        $status = $this->status instanceof LeadStatus ? $this->status->value : (string) $this->status;

        if (in_array($status, [LeadStatus::Won->value, LeadStatus::Lost->value], true)) {
            return false;
        }

        $cutoff = now()->subDays($days);

        // Mirrors the scope's fallback: a lead that has never had a stage
        // recorded is measured from when it was last touched.
        $measuredFrom = $this->stage_entered_at ?? $this->updated_at;

        return $measuredFrom !== null && $measuredFrom->lessThanOrEqualTo($cutoff);
    }

    /**
     * Get the centralized, canonical lead sources.
     *
     * @return array<string, string>
     */
    public static function sources(): array
    {
        return [
            'website' => 'Website',
            'instagram' => 'Instagram',
            'whatsapp' => 'WhatsApp',
            'referral' => 'Referral',
            'cold_call' => 'Cold Call',
            'exhibition' => 'Exhibition',
            'partner' => 'Partner',
            'manual_entry' => 'Manual Entry',
        ];
    }
}
