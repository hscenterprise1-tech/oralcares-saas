<?php

namespace App\Models;

use App\LeadStatus;
use App\Traits\OrganizationScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class MarketingContact extends Model
{
    use HasFactory, OrganizationScoped, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'import_id',
        'name',
        'phone',
        'normalized_phone',
        'organization',
        'city',
        'state',
        'area',
        'marketing_status',
        'suppression_reason',
        'suppression_source',
        'status_changed_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'status_changed_at' => 'datetime',
    ];

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MarketingTag::class, 'marketing_contact_tag', 'contact_id', 'tag_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(MarketingContactImport::class, 'import_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function tenantOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function crmLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'marketing_contact_id');
    }

    public function suppressionLogs(): HasMany
    {
        return $this->hasMany(MarketingContactSuppressionLog::class, 'contact_id')->latest();
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(MarketingCampaignRecipient::class, 'contact_id');
    }

    public function activeCrmLead(): ?Lead
    {
        return $this->crmLeads()
            ->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost])
            ->latest()
            ->first();
    }

    /**
     * Scope query to search across name, phone, organization, city, state, and area.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        $cleanTerm = trim($term);

        return $query->where(function (Builder $q) use ($cleanTerm) {
            $q->where('name', 'like', "%{$cleanTerm}%")
                ->orWhere('phone', 'like', "%{$cleanTerm}%")
                ->orWhere('normalized_phone', 'like', "%{$cleanTerm}%")
                ->orWhere('organization', 'like', "%{$cleanTerm}%")
                ->orWhere('city', 'like', "%{$cleanTerm}%")
                ->orWhere('state', 'like', "%{$cleanTerm}%")
                ->orWhere('area', 'like', "%{$cleanTerm}%");
        });
    }

    /**
     * Scope query to filter by state.
     */
    public function scopeFilterState(Builder $query, ?string $state): Builder
    {
        if (empty($state) || $state === 'all') {
            return $query;
        }

        return $query->where('state', $state);
    }

    /**
     * Scope query to filter by area.
     */
    public function scopeFilterArea(Builder $query, ?string $area): Builder
    {
        if (empty($area) || $area === 'all') {
            return $query;
        }

        return $query->where('area', $area);
    }

    /**
     * Scope query to filter by import batch ID.
     */
    /**
     * Contacts that a given file contained.
     *
     * Membership, not `import_id`: a person the file listed but did not create
     * was still in it, and the batch page counts them, so this filter has to
     * agree or clicking through from that page would show fewer people than the
     * figure that was clicked.
     */
    public function scopeFilterImportBatch(Builder $query, int|string|null $batchId): Builder
    {
        if (empty($batchId) || $batchId === 'all') {
            return $query;
        }

        return $query->whereExists(function ($sub) use ($batchId) {
            $sub->selectRaw('1')
                ->from('marketing_contact_import_rows as r')
                ->whereColumn('r.contact_id', 'marketing_contacts.id')
                ->where('r.import_id', (int) $batchId)
                ->whereIn('r.outcome', MarketingContactImportRow::CONTACT_OUTCOMES);
        });
    }

    /**
     * Filter by organisation name.
     *
     * A contains-match rather than a select. There can be thousands of distinct
     * organisations and no dropdown should try to hold them — the panel offers
     * a text box, and this is what it runs.
     */
    public function scopeFilterOrganization(Builder $query, ?string $organization): Builder
    {
        if (empty($organization) || $organization === 'all') {
            return $query;
        }

        return $query->where('marketing_contacts.organization', 'like', '%'.trim($organization).'%');
    }

    /**
     * The timezone the business reads its own dates in.
     *
     * Matches the default already used elsewhere in the application (see
     * EmployeeController and CustomerProvisioningEngine); the organisation's
     * own timezone overrides it wherever one is set.
     */
    public const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    /**
     * The one place that decides which timezone a date-range filter reads in.
     *
     * `MarketingContactController` resolved this inline; `AudienceQueryService`
     * did not resolve it at all and silently fell back to `BUSINESS_TIMEZONE`
     * regardless of the organisation's own setting — the one place a Segment's
     * `imported_from`/`imported_to` rule could disagree with the identical
     * filter in Contacts. Both now call this, so there is exactly one rule to
     * keep in sync rather than one written out at each call site.
     *
     * Takes the organisation directly rather than a user, so it works equally
     * from a request (`$user->organization`) and from a Segment, which knows
     * its own organisation but not any particular user
     * (`$audience->tenantOrganization`).
     */
    public static function resolveBusinessTimezone(?Organization $organization): string
    {
        return $organization?->timezone ?: self::BUSINESS_TIMEZONE;
    }

    /**
     * Filter by when the contact was added.
     *
     * Both bounds are inclusive of their whole day and either may stand alone,
     * so "everything since the first" works without inventing an end date. A
     * range ending "24 Aug" that excluded everything added on the 24th would
     * be a trap.
     */
    public function scopeFilterImportedBetween(Builder $query, ?string $from, ?string $to, ?string $timezone = null): Builder
    {
        $timezone = $timezone ?: self::BUSINESS_TIMEZONE;

        /*
         * The dates are read in the business timezone, not the database's.
         *
         * Timestamps are stored in UTC while the list shows the day the
         * business saw, so a contact added at 02:08 IST is stored under the
         * previous UTC date. Comparing the picked date against the stored
         * date would tell someone nobody was added on the very day every row
         * on screen is stamped with. Each bound is therefore resolved to the
         * UTC instant that business day actually begins at.
         *
         * The upper bound is exclusive of the following day rather than
         * inclusive of this one, which keeps the whole range a plain
         * comparison on the column — something an index can serve, unlike
         * DATE(created_at).
         */
        if (! empty($from)) {
            $query->where(
                'marketing_contacts.created_at',
                '>=',
                CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()
            );
        }

        if (! empty($to)) {
            $query->where(
                'marketing_contacts.created_at',
                '<',
                CarbonImmutable::parse($to, $timezone)->startOfDay()->addDay()->utc()
            );
        }

        return $query;
    }

    /**
     * Annotate each contact row with its latest campaign recipient status.
     *
     * This is a correlated subquery: for each contact, find the most recent
     * marketing_campaign_recipients row and surface its status. Combined with
     * marketing_status (opted_out / suppressed), this lets the UI show the
     * full campaign lifecycle (Never Contacted → Queued → Sent → Delivered
     * → Read → Replied / Failed) without a stored column that can go stale.
     */
    public function scopeWithLastCampaignStatus(Builder $query): Builder
    {
        /*
         * `updated_at` alone is not a total order — two recipient rows touched
         * in the same second would resolve arbitrarily, so the id breaks the
         * tie and the answer is stable between requests.
         */
        return $query->select('marketing_contacts.*')
            ->selectSub(self::latestRecipientStatusSql(), 'last_campaign_status')
            ->selectSub(self::latestRecipientAtSql(), 'last_campaign_at');
    }

    /**
     * When that same most-recent recipient row last moved.
     *
     * This is the "last contacted" column. It deliberately reads the row's
     * `updated_at` rather than any one of `sent_at` / `delivered_at` / …,
     * because the question the column answers is "when did anything last happen
     * with this person", not "when was a particular milestone reached".
     */
    protected static function latestRecipientAtSql(): string
    {
        return '(
            SELECT mcr.updated_at
            FROM marketing_campaign_recipients mcr
            WHERE mcr.contact_id = marketing_contacts.id
            ORDER BY mcr.updated_at DESC, mcr.id DESC
            LIMIT 1
        )';
    }

    /**
     * The compliance states. These are stored on the contact itself and mean
     * "this person may not be messaged", whatever any campaign says.
     *
     * @var list<string>
     */
    public const COMPLIANCE_STATUSES = ['opted_out', 'suppressed'];

    /**
     * The campaign delivery states, derived from the contact's most recent
     * `marketing_campaign_recipients` row rather than stored anywhere.
     *
     * `never_contacted` is the absence of such a row, not a value in it.
     *
     * @var list<string>
     */
    public const CAMPAIGN_STATUSES = [
        'never_contacted', 'queued', 'sent', 'delivered', 'read', 'replied', 'failed',
    ];

    /**
     * Filter by marketing status, compliance or campaign.
     *
     * These are two different things sharing one control, which is how people
     * actually think about it: "show me who I cannot message" and "show me who
     * has not replied yet" are the same question asked of one list.
     *
     * Compliance wins. A contact who opted out after a campaign delivered to
     * them is opted out, not delivered — and filtering by `delivered` must not
     * return them, or this list would disagree with the batch summary that
     * applies exactly the same precedence.
     */
    public function scopeFilterStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status) || $status === 'all') {
            return $query;
        }

        if (! in_array($status, [...self::COMPLIANCE_STATUSES, ...self::CAMPAIGN_STATUSES], true)) {
            // An unrecognised value narrows nothing rather than silently
            // returning an empty list that looks like "no matches".
            return $query;
        }

        return $query->where(DB::raw(self::lifecycleBucketSql()), $status);
    }

    /**
     * The one expression that decides which lifecycle state a contact is in.
     *
     * Everything that asks the question goes through here: the filter, the
     * counts above the list, and the summary on a batch page. That is
     * deliberate — a headline reading "412 never contacted" over a list that
     * returns 380 when you click it is the failure this prevents, and it can
     * only be prevented by there being nothing to disagree with.
     *
     * The rules it encodes:
     *
     *  - compliance beats campaign. Someone who opted out after a message was
     *    delivered is opted out, not delivered;
     *  - a contact's campaign state is their *most recent* recipient row, never
     *    a tally of every row they appear in;
     *  - `pending` and `queued` read the same to a person — the message has not
     *    gone out yet;
     *  - having no recipient row at all is itself a state: never contacted.
     */
    public static function lifecycleBucketSql(string $table = 'marketing_contacts'): string
    {
        return "(CASE
            WHEN {$table}.marketing_status = 'opted_out' THEN 'opted_out'
            WHEN {$table}.marketing_status = 'suppressed' THEN 'suppressed'
            ELSE COALESCE((
                SELECT CASE WHEN mcr.status = 'pending' THEN 'queued' ELSE mcr.status END
                FROM marketing_campaign_recipients mcr
                WHERE mcr.contact_id = {$table}.id
                ORDER BY mcr.updated_at DESC, mcr.id DESC
                LIMIT 1
            ), 'never_contacted')
        END)";
    }

    /**
     * How many contacts stand in each lifecycle state, for a given population.
     *
     * One grouped pass rather than one query per state. Nine separate counts,
     * each re-evaluating the same correlated sub-select over the same rows, was
     * the single slowest thing on the page — at ten thousand contacts it was
     * most of the wait.
     *
     * The population passed in is the list's own query with every filter
     * applied *except* status, so the counts always describe the list sitting
     * underneath them. Counting org-wide instead meant that with a batch
     * selected a chip read "Delivered 4" above a list of 2: both numbers
     * correct, together indistinguishable from a bug.
     *
     * @param  Builder<self>  $population
     * @return array<string, int>
     */
    public static function lifecycleCounts(Builder $population): array
    {
        $counted = $population
            ->reorder()
            ->selectRaw(self::lifecycleBucketSql().' AS lifecycle_bucket, COUNT(*) AS people')
            ->groupBy('lifecycle_bucket')
            ->pluck('people', 'lifecycle_bucket');

        /* Every state is reported, including the ones nobody is in. */
        $counts = collect([...self::CAMPAIGN_STATUSES, ...self::COMPLIANCE_STATUSES])
            ->mapWithKeys(fn (string $state) => [$state => (int) ($counted[$state] ?? 0)])
            ->all();

        /*
         * `all` is the same population with no status filter at all, and it is
         * summed from what the query actually returned rather than from the
         * states listed above — a recipient status with no bucket of its own
         * (`skipped`, for one) still belongs in the total.
         *
         * It cannot be taken from the list's own total, because that total has
         * the status filter applied: with "Delivered" selected the All button
         * would show the delivered figure and then hand you a longer list when
         * you pressed it.
         */
        $counts['all'] = (int) $counted->sum();

        return $counts;
    }

    /**
     * The sub-select that resolves one contact's current campaign status.
     *
     * Shared by the filter and by `withLastCampaignStatus` so the column the
     * list displays and the column it filters on can never be computed two
     * different ways.
     */
    protected static function latestRecipientStatusSql(): string
    {
        return '(
            SELECT mcr.status
            FROM marketing_campaign_recipients mcr
            WHERE mcr.contact_id = marketing_contacts.id
            ORDER BY mcr.updated_at DESC, mcr.id DESC
            LIMIT 1
        )';
    }

    /**
     * Scope query to filter by city.
     */
    public function scopeFilterCity(Builder $query, ?string $city): Builder
    {
        if (empty($city) || $city === 'all') {
            return $query;
        }

        return $query->where('city', $city);
    }

    /**
     * Scope query to filter by tag IDs or slugs.
     */
    public function scopeFilterTags(Builder $query, array|string|null $tags): Builder
    {
        if (empty($tags)) {
            return $query;
        }

        $tagList = is_array($tags) ? $tags : explode(',', $tags);
        $tagList = array_filter(array_map('trim', $tagList));

        if (empty($tagList)) {
            return $query;
        }

        return $query->whereHas('tags', function (Builder $q) use ($tagList) {
            $q->whereIn('marketing_tags.id', $tagList)
                ->orWhereIn('marketing_tags.slug', $tagList)
                ->orWhereIn('marketing_tags.name', $tagList);
        });
    }
}
