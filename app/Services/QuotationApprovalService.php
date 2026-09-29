<?php

namespace App\Services;

use App\Models\CompanyQuotation;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CRM\LeadAssignmentService;
use App\Services\CRM\TrialExtensionApprovalService;
use App\UserType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Discount approval gate for quotations.
 *
 * `config('crm.quotation_approval_discount_percent')` has existed since Slice A
 * but nothing read it, so any rep could discount without limit. The rule is
 * deliberately narrow: a quotation discounted at or above the threshold cannot
 * be *sent* until someone holding `crm.quotations.approve` has approved it.
 * Below the threshold nothing changes, so the ordinary path stays one click.
 *
 * Phase 2B adds a second, higher tier on top of that unchanged rule: an
 * admin-tier user (master admin, or anyone already holding
 * `crm.quotations.approve`) may discount up to `admin_direct_discount_percent`
 * (20% by default) on their own authority, with no approval step at all —
 * they don't need to approve their own discount, they can just apply it.
 * Which rule applies to a given quotation is decided by *who set the
 * discount*, recorded on `discount_requested_by_id` at the moment
 * `LeadWorkspaceService::setQuotationDiscount()` runs — not by whoever
 * happens to call `send()` later.
 *
 * That column is null for any quotation whose discount was never set through
 * that method — every quotation from before Phase 2B, and any test fixture
 * that writes `discount` directly onto the model — so every method below
 * falls back to the original global-threshold behaviour for those rows,
 * completely unchanged.
 *
 * Approval attaches to a specific version. A revision is a new record with no
 * approval, so re-discounting a previously-approved quotation cannot smuggle
 * the old approval forward.
 */
class QuotationApprovalService
{
    /**
     * Discount as a percentage of the pre-discount subtotal.
     *
     * A zero subtotal has no meaningful percentage — reported as 0 rather than
     * dividing by zero and declaring every free quotation an exception.
     *
     * Rounded for *display* only. The actual approval decision below never
     * compares this rounded float against a threshold — it cross-multiplies
     * the raw decimal-string amounts instead, so a fixed-amount discount
     * converted to a percentage can never drift across a boundary due to
     * binary floating-point rounding.
     */
    public function discountPercent(CompanyQuotation $quotation): float
    {
        $subtotal = (float) $quotation->subtotal;

        if ($subtotal <= 0.0) {
            return 0.0;
        }

        return round(((float) $quotation->discount / $subtotal) * 100, 2);
    }

    /**
     * The configured employee threshold, or null when that gate is switched
     * off entirely (an employee may then discount without limit).
     */
    public function threshold(): ?float
    {
        $configured = config('crm.quotation_approval_discount_percent');

        return $configured === null || $configured === '' ? null : (float) $configured;
    }

    /** The percentage an admin-tier user may discount directly, with no approval. */
    public function adminDirectLimit(): float
    {
        return (float) config('commercial.admin_direct_discount_percent', 20.00);
    }

    /**
     * Whether this user gets the higher, admin-tier direct-discount ceiling.
     *
     * Deliberately the same permission set as `canApprove()` — someone who
     * can already sign off on someone else's discount does not need to
     * approve their own; they can simply apply it. A master admin always
     * qualifies via the platform-wide bypass.
     */
    public function isAdminTier(?User $user): bool
    {
        return $user !== null && ($user->isMasterAdmin() || $this->canApprove($user));
    }

    /**
     * Whether a discount at this percentage, requested by this user, needs
     * approval — the tiered rule, evaluated once at the moment the discount
     * is set (see `LeadWorkspaceService::setQuotationDiscount()`).
     *
     * Two distinct rules, not one formula stretched to fit both: an admin's
     * own ceiling names its boundary as still fine ("up to 20%"), so only
     * strictly *above* it needs approval, while the employee threshold's
     * existing, unchanged semantics treat the boundary itself as already the
     * exception ("at or above").
     */
    public function requiresApprovalForUser(?User $user, string|float $discountAmount, string|float $subtotal): bool
    {
        if ($this->isAdminTier($user)) {
            return $this->percentExceeds($discountAmount, $subtotal, $this->adminDirectLimit(), strict: true);
        }

        $threshold = $this->threshold();

        if ($threshold === null) {
            return false;
        }

        return $this->percentExceeds($discountAmount, $subtotal, $threshold, strict: false);
    }

    /**
     * Whether `discount / subtotal * 100` is over (`strict`) or at-or-over
     * (inclusive) the given percentage limit.
     *
     * Computed by cross-multiplying the raw decimal amounts (`discount * 100`
     * vs. `limit * subtotal`) via bcmath rather than by dividing two floats
     * and comparing the rounded result — division is exactly where a
     * fixed-amount discount converted to a percentage could drift a hair
     * across a threshold boundary and silently bypass approval.
     *
     * Accepts `string|float` deliberately: `CompanyQuotation`'s `decimal:2`
     * cast already returns money as an exact string (`"8000.00"`, never a
     * binary float), and passing that string straight through here — instead
     * of casting it to `float` first — is what keeps this comparison exact
     * end to end. A caller that only has a float (e.g. a freshly-validated
     * request value) still works; it is simply not the precision-critical
     * path a stored, already-decimal amount is.
     */
    protected function percentExceeds(string|float $discountAmount, string|float $subtotal, float $limitPercent, bool $strict): bool
    {
        $subtotal = $this->decimalString($subtotal);

        if (bccomp($subtotal, '0', 4) <= 0) {
            return false;
        }

        $left = bcmul($this->decimalString($discountAmount), '100', 6);
        $right = bcmul($this->decimalString($limitPercent), $subtotal, 6);
        $cmp = bccomp($left, $right, 6);

        return $strict ? $cmp > 0 : $cmp >= 0;
    }

    /**
     * Normalise a money/percentage value to a plain decimal string bcmath
     * can consume, without ever routing an already-exact decimal string
     * through a binary float.
     */
    protected function decimalString(string|float $value): string
    {
        return is_string($value) ? $value : number_format($value, 4, '.', '');
    }

    /**
     * Whether this quotation's discount is exceptional enough to need
     * sign-off, under the original global-threshold rule alone.
     *
     * This is the legacy fallback `effectiveRequiresApproval()` uses for a
     * quotation with no recorded discount-requester — kept verbatim,
     * including its inclusive-at-the-boundary comparison, so nothing that
     * predates the tiered rule changes behaviour.
     */
    public function requiresApproval(CompanyQuotation $quotation): bool
    {
        $threshold = $this->threshold();

        if ($threshold === null) {
            return false;
        }

        return $this->discountPercent($quotation) >= $threshold;
    }

    /**
     * The real, current answer to "does this quotation's discount need
     * approval" — tiered by who requested it when one is on record, falling
     * back to the untiered global-threshold rule when there is none.
     */
    public function effectiveRequiresApproval(CompanyQuotation $quotation): bool
    {
        if ($quotation->discount_requested_by_id === null) {
            return $this->requiresApproval($quotation);
        }

        return $this->requiresApprovalForUser(
            $quotation->discountRequestedBy,
            $quotation->getAttribute('discount'),
            $quotation->getAttribute('subtotal'),
        );
    }

    /**
     * Whether the quotation already carries a valid approval.
     */
    public function isApproved(CompanyQuotation $quotation): bool
    {
        return $quotation->approved_at !== null
            && $quotation->status !== QuotationStatus::Rejected;
    }

    /**
     * Whether sending is currently permitted.
     */
    public function canBeSent(CompanyQuotation $quotation): bool
    {
        return ! $this->effectiveRequiresApproval($quotation) || $this->isApproved($quotation);
    }

    /**
     * Whether this user may record an approval decision.
     */
    public function canApprove(?User $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('crm.quotations.approve');
    }

    /**
     * Everyone in this organization who can act on a pending approval.
     *
     * Scoped to the quotation's own tenant deliberately — a manager in one
     * clinic organisation must never be notified about another's discount, so
     * this never queries across `organization_id`. Filtered candidate-by-
     * candidate through {@see self::canApprove()} rather than a role-name
     * query, because Spatie runs in teams mode here and a set query against
     * the role pivot outside a single user's own request context is exactly
     * how {@see LeadAssignmentService} once silently found
     * nobody.
     *
     * @return Collection<int, User>
     */
    public function approversFor(int $organizationId): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->whereIn('user_type', [UserType::CompanyUser->value, UserType::MasterAdmin->value])
            ->get()
            ->filter(fn (User $candidate) => $this->canApprove($candidate))
            ->values();
    }

    /**
     * The quotations sitting on this manager's desk right now.
     *
     * Mirrors {@see TrialExtensionApprovalService::pendingForUser()} —
     * empty for anyone without approval authority, since this is the queue My
     * Work's Approvals tab reads, not "quotations I authored". Scoped to the
     * approver's own tenant so a master admin still sees one organisation's
     * asks at a time.
     *
     * A quotation's `discount_requested_by_id` decides candidacy — narrows the
     * query before the tiered admin/employee rule (which cannot be expressed
     * as a single SQL predicate) is evaluated in PHP over that small, already
     * tenant-scoped set.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingForUser(User $user): array
    {
        if (! $this->canApprove($user)) {
            return [];
        }

        $orgId = $user->resolveOrganizationId();
        $isMasterAdmin = $user->isMasterAdmin();

        return CompanyQuotation::query()
            ->whereNull('approved_at')
            ->where('status', '!=', QuotationStatus::Rejected)
            ->whereNotNull('discount_requested_by_id')
            ->when($orgId && ! $isMasterAdmin, fn ($query) => $query->where('organization_id', $orgId))
            ->with(['lead:id,company_name,contact_name,city', 'discountRequestedBy:id,name'])
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->filter(fn (CompanyQuotation $quotation) => $this->effectiveRequiresApproval($quotation))
            ->map(fn (CompanyQuotation $quotation) => [
                'id' => $quotation->id,
                'quotation_id' => $quotation->id,
                'lead_id' => $quotation->lead_id,
                'company_name' => $quotation->lead?->company_name ?? 'Quotation',
                'contact_name' => $quotation->lead?->contact_name ?? '',
                'city' => $quotation->lead?->city,
                'requested_by' => $quotation->discountRequestedBy?->name ?? 'Unknown',
                'quotation_number' => $quotation->quotation_number,
                'discount' => (float) $quotation->discount,
                'discount_percent' => $this->discountPercent($quotation),
                'time_ago' => $quotation->updated_at?->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    /**
     * Reason a send is blocked, or null when it may proceed. Suitable for
     * surfacing directly to the user.
     */
    public function blockedReason(CompanyQuotation $quotation): ?string
    {
        if ($this->canBeSent($quotation)) {
            return null;
        }

        if ($quotation->status === QuotationStatus::Rejected) {
            return "Quotation {$quotation->quotation_number} was rejected and cannot be sent. Revise it to continue.";
        }

        $percent = $this->discountPercent($quotation);
        $limit = $quotation->discount_requested_by_id !== null && $this->isAdminTier($quotation->discountRequestedBy)
            ? $this->adminDirectLimit()
            : ($this->threshold() ?? $this->adminDirectLimit());

        return sprintf(
            'This quotation discounts %.2f%%, at or above the %.2f%% approval threshold. It requires management approval before it can be sent.',
            $percent,
            $limit
        );
    }

    /**
     * Approval state for the UI.
     *
     * @return array<string, mixed>
     */
    public function state(CompanyQuotation $quotation, ?User $user = null): array
    {
        return [
            'discount_percent' => $this->discountPercent($quotation),
            'discount_type' => $quotation->discount_type,
            'discount_value' => (float) ($quotation->discount_value ?? $quotation->discount),
            'threshold_percent' => $this->threshold(),
            'admin_direct_limit_percent' => $this->adminDirectLimit(),
            'requires_approval' => $this->effectiveRequiresApproval($quotation),
            'is_approved' => $this->isApproved($quotation),
            'can_be_sent' => $this->canBeSent($quotation),
            'can_approve' => $this->canApprove($user),
            /* Whether the *current viewer* would get the admin ceiling if they set a discount here — lets the UI skip showing an approval flow to someone who would never need one. */
            'viewer_is_admin_tier' => $this->isAdminTier($user),
            'blocked_reason' => $this->blockedReason($quotation),
            'requested_by' => $quotation->discountRequestedBy?->name,
        ];
    }
}
