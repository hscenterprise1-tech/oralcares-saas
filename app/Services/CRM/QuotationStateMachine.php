<?php

namespace App\Services\CRM;

use App\Models\CompanyQuotation;
use App\QuotationStatus;
use InvalidArgumentException;

/**
 * The quotation state machine, mirroring `DealStageService`'s `blockReason()`
 * pattern: a pure legality check, separate from the mutation itself, so every
 * caller — controller, service, a future API — asks the same question the
 * same way.
 *
 * Unlike a lead's stage, a quotation has no single "transition to X" entry
 * point — it has four distinct actions (send, approve, reject, revise), each
 * legal from a different set of source statuses. The graph is derived from
 * what actually calls each one, not invented: `Commercial/Show.tsx` shows
 * approve/reject for sent/negotiation (a client's decision), but
 * `QuotationApprovalService` also lets a manager pre-approve — or refuse —
 * an over-threshold discount while the quotation is still a Draft, before it
 * has ever been sent. A revision is the deal's own documented escape hatch
 * out of every other status except one already replaced or already won.
 *
 * `negotiation` and `converted` are enum values with no current writer
 * anywhere in the codebase — dormant states. They are still given legal
 * edges here so the graph stays correct the day something starts writing
 * them, rather than needing to be revisited alongside whatever wires them up.
 */
class QuotationStateMachine
{
    /**
     * No guarded action may move a quotation out of this status. The deal it
     * belongs to has already been won on it — there is nothing left to
     * decide.
     *
     * @var list<string>
     */
    public const TERMINAL = [QuotationStatus::Converted->value];

    /**
     * The statuses each guarded action may legally start from.
     *
     * @var array<string, list<string>>
     */
    protected const LEGAL_SOURCES = [
        /*
         * Two real, distinct events share this one action: a manager
         * pre-approving a discount that exceeds the send threshold — which
         * happens on a still-Draft quotation, before it is ever sent, per
         * `QuotationApprovalService` — and a client's decision on a
         * quotation already in front of them. Both are legal starting
         * points; only a quotation already decided, retired, or won is not.
         */
        'approve' => [QuotationStatus::Draft->value, QuotationStatus::Sent->value, QuotationStatus::Negotiation->value],
        'reject' => [QuotationStatus::Draft->value, QuotationStatus::Sent->value, QuotationStatus::Negotiation->value],
        /*
         * Revising is the documented way through a rejected or expired
         * quotation — `CommercialTermsLockTest` and the "retry" flow both
         * depend on it staying open from those statuses. Only a quotation
         * already replaced by a newer version, or one that already won the
         * deal, has nothing left to revise.
         */
        'revise' => [
            QuotationStatus::Draft->value,
            QuotationStatus::Sent->value,
            QuotationStatus::Negotiation->value,
            QuotationStatus::Approved->value,
            QuotationStatus::Rejected->value,
            QuotationStatus::Expired->value,
        ],
    ];

    /**
     * Why this action may not run from the quotation's current status, or
     * null if it may.
     *
     * `send` is not in `LEGAL_SOURCES` because its legality depends on more
     * than the status: a discount pre-approval can leave a quotation
     * `Approved` before it has ever actually gone out, and completing that
     * send is legal — but re-sending an `Approved` quotation that already
     * has a `sent_at` is not. `sent_at` is the fact that distinguishes them,
     * the same one `setQuotationDiscount()`'s revocation branch already
     * reads for the identical reason.
     */
    public function blockReason(CompanyQuotation $quotation, string $action): ?string
    {
        $status = $this->value($quotation);

        if ($action === 'send') {
            $legal = $status === QuotationStatus::Draft->value
                || ($status === QuotationStatus::Approved->value && $quotation->sent_at === null);

            return $legal ? null : $this->reasonFor($status, 'send');
        }

        $legalSources = self::LEGAL_SOURCES[$action] ?? null;

        if ($legalSources === null) {
            throw new InvalidArgumentException("Unknown quotation action [{$action}].");
        }

        if (in_array($status, $legalSources, true)) {
            return null;
        }

        return $this->reasonFor($status, $action);
    }

    /** Throws with a human-readable reason when the action is not legal. */
    public function ensure(CompanyQuotation $quotation, string $action): void
    {
        if ($reason = $this->blockReason($quotation, $action)) {
            throw new InvalidArgumentException($reason);
        }
    }

    /** The status each action's own successful outcome leaves a quotation in. */
    protected const OUTCOME_OF = [
        'send' => QuotationStatus::Sent->value,
        'approve' => QuotationStatus::Approved->value,
        'reject' => QuotationStatus::Rejected->value,
    ];

    protected function reasonFor(string $status, string $action): string
    {
        $verb = match ($action) {
            'send' => 'sent',
            'approve' => 'approved',
            'reject' => 'rejected',
            'revise' => 'revised',
            default => $action,
        };

        if ($status === (self::OUTCOME_OF[$action] ?? null)) {
            return "This quotation has already been {$verb}.";
        }

        return match (true) {
            in_array($status, self::TERMINAL, true) => "This quotation already converted and won the deal, so it cannot be {$verb}.",
            $status === QuotationStatus::Superseded->value => "This quotation has been superseded by a newer version and cannot be {$verb}.",
            $status === QuotationStatus::Expired->value => "This quotation has expired and cannot be {$verb}.",
            $status === QuotationStatus::Rejected->value => "This quotation was rejected and cannot be {$verb}.",
            $status === QuotationStatus::Approved->value && $action === 'send' => 'This quotation has already been sent.',
            $status === QuotationStatus::Approved->value => "This quotation is already approved and cannot be {$verb} again.",
            default => "This quotation cannot be {$verb} from its current status ({$status}).",
        };
    }

    protected function value(CompanyQuotation $quotation): string
    {
        return $quotation->status instanceof QuotationStatus
            ? $quotation->status->value
            : (string) $quotation->status;
    }
}
