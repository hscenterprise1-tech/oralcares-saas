<?php

namespace App\Services\Marketing;

use App\Models\MarketingAudience;
use App\Models\MarketingContact;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a Segment's saved rules into the contacts that currently match them.
 *
 * The rules are stored as the same shape Marketing Contacts already filters
 * by — `state`, `city`, `area`, `organization`, `tags`, `import_batch`,
 * `imported_from`/`imported_to`, `status` — and every one of those is applied
 * through `MarketingContact`'s own scopes (`filterState`, `filterCity`, and so
 * on), the exact ones the Contacts list itself runs. There is no separate rule
 * language here: a saved Segment is a named `ContactFilterState`, nothing more.
 * That is deliberate — it is what makes "Save as Segment" from Contacts a
 * matter of handing this class the filters already on screen, and what makes
 * "one source of truth" true rather than aspirational.
 *
 * Nothing here computes or stores membership. Every method runs a query
 * against `marketing_contacts` at the moment it is called; a Segment's count
 * can never go stale because none of it is ever written down.
 */
class AudienceQueryService
{
    /**
     * The only keys a Segment's rules are allowed to carry.
     *
     * Reading rules through this list rather than trusting whatever is in the
     * JSON column is the whole of the input handling this needs: an unknown
     * key is simply never looked at, the same "narrows nothing" default every
     * other filter in this application already uses for a value it does not
     * recognise. There is no field/operator/value language to validate against
     * a whitelist any more, because there is no field/operator/value language —
     * removing the free-form rule engine removed the class of bug it existed
     * to guard against.
     *
     * @var list<string>
     */
    public const RULE_KEYS = [
        'state', 'city', 'area', 'organization', 'tags',
        'import_batch', 'imported_from', 'imported_to', 'status',
    ];

    /**
     * Build the Eloquent query for everyone a Segment's rules currently match.
     *
     * This is intentionally the *targeting* question only — it does not
     * exclude opted-out or suppressed contacts. A Segment describes who the
     * rules pick; whether those people may actually be messaged is a question
     * for campaign launch, not for the Segment. Conflating the two was the old
     * system's mistake: it made a Segment's headline number a compliance
     * figure instead of a targeting one, and meant "how many people match"
     * and "how many can be reached" were impossible to tell apart on screen.
     */
    public function buildContactQuery(array $rules, ?int $orgId = null, ?string $timezone = null): Builder
    {
        return MarketingContact::query()
            ->when($orgId, fn (Builder $q) => $q->where('organization_id', $orgId))
            ->filterState($rules['state'] ?? null)
            ->filterCity($rules['city'] ?? null)
            ->filterArea($rules['area'] ?? null)
            ->filterOrganization($rules['organization'] ?? null)
            ->filterTags($rules['tags'] ?? null)
            ->filterImportBatch($rules['import_batch'] ?? null)
            ->filterImportedBetween($rules['imported_from'] ?? null, $rules['imported_to'] ?? null, $timezone)
            ->filterStatus($rules['status'] ?? null);
    }

    /**
     * How many contacts a Segment's rules match right now.
     *
     * One `COUNT` query. Nothing is fetched, nothing is cached — the number is
     * always exactly as current as the contact table itself.
     */
    public function matchCount(array $rules, ?int $orgId = null, ?string $timezone = null): int
    {
        return $this->buildContactQuery($rules, $orgId, $timezone)->count();
    }

    /**
     * A small sample of who a Segment currently matches — enough to read as a
     * sanity check, never the whole list.
     *
     * The full list already exists: it is Contacts, filtered by these same
     * rules. Rendering thousands of rows a second time inside Segments would
     * be exactly the duplication this rebuild exists to remove.
     */
    public function previewContacts(array $rules, ?int $orgId = null, int $limit = 3, ?string $timezone = null): array
    {
        return $this->buildContactQuery($rules, $orgId, $timezone)
            ->latest('created_at')
            ->limit($limit)
            ->get(['id', 'name', 'organization', 'city'])
            ->toArray();
    }

    /**
     * The city and area options valid for the state/city a Segment's rules
     * currently have selected.
     *
     * Mirrors the same three-level scoping the Contacts filter panel uses —
     * picking a state narrows the cities on offer, picking a city narrows the
     * areas — so the hierarchy can never show "Alkapuri" under a state it does
     * not belong to.
     */
    public function cascadedLocationOptions(?int $orgId, ?string $state, ?string $city): array
    {
        $cities = MarketingContact::query()
            ->when($orgId, fn (Builder $q) => $q->where('organization_id', $orgId))
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->when($state && $state !== 'all', fn (Builder $q) => $q->where('state', $state))
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        $areas = MarketingContact::query()
            ->when($orgId, fn (Builder $q) => $q->where('organization_id', $orgId))
            ->whereNotNull('area')
            ->where('area', '!=', '')
            ->when($state && $state !== 'all', fn (Builder $q) => $q->where('state', $state))
            ->when($city && $city !== 'all', fn (Builder $q) => $q->where('city', $city))
            ->distinct()
            ->orderBy('area')
            ->pluck('area');

        return ['cities' => $cities, 'areas' => $areas];
    }

    /**
     * Only the rule keys this system recognises, in case a request carries
     * anything else — a stale client, a hand-crafted request, a future field
     * not yet supported. Everything downstream reads named keys directly, so
     * this is a courtesy rather than a security boundary: an unknown key was
     * already going to be ignored, this just keeps it out of what gets stored.
     */
    public function sanitizeRules(array $rules): array
    {
        return array_intersect_key($rules, array_flip(self::RULE_KEYS));
    }

    /**
     * Who a Segment's rules match *and* who is actually eligible to be
     * messaged — the query campaign launch resolves recipients from.
     *
     * Kept as its own method, with this exact name, because
     * `CampaignPreparationService` depends on it directly: this is the one
     * place targeting (the Segment's rules) and eligibility (not opted out,
     * not suppressed) are combined. Nowhere else in this service does that —
     * deliberately, per the same separation the Segment pages themselves
     * observe.
     *
     * The timezone comes from the Segment's own organisation, not a request —
     * there is no user in this call path, only the audience being launched
     * against. This is what makes campaign recipient resolution agree with
     * Contacts and the Segment pages without `CampaignPreparationService`
     * having to know anything about timezones at all.
     */
    public function resolveEligibleContactsQuery(MarketingAudience $audience): Builder
    {
        $timezone = MarketingContact::resolveBusinessTimezone($audience->tenantOrganization);

        return $this->buildContactQuery($audience->rules ?? [], $audience->organization_id, $timezone)
            ->where('marketing_status', 'active');
    }
}
