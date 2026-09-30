<?php

namespace App\Services\Provisioning;

use App\Models\CompanyPlan;
use Carbon\CarbonInterface;

/**
 * Why a tenant is being provisioned.
 *
 * The engine builds the same environment either way — that is the point, and
 * it is what lets a won trial keep its tenant instead of being rebuilt. Only
 * three things differ, and they are all carried here:
 *
 *   - which plan supplies the entitlements (a trial names one directly; a sale
 *     reads it off the approved quotation),
 *   - how the subscription window is dated,
 *   - whether the deal is moved to Won.
 *
 * That last one matters most. Provisioning used to mark the lead Won
 * unconditionally, so starting a trial would have closed the deal that the
 * trial exists to help close.
 */
final class ProvisioningIntent
{
    public const SALE = 'sale';

    public const TRIAL = 'trial';

    private function __construct(
        public readonly string $mode,
        public readonly ?CompanyPlan $plan = null,
        public readonly ?CarbonInterface $trialEndsAt = null,
        public readonly ?CarbonInterface $graceEndsAt = null,
    ) {}

    /** A won deal: paid term, deal moves to Won. */
    public static function sale(): self
    {
        return new self(self::SALE);
    }

    /**
     * A time-boxed trial on a real plan.
     *
     * `$graceEndsAt` is the true end of access, so it — not the trial end — is
     * what the subscription expires on. The customer keeps working through
     * grace; only the banner changes.
     */
    public static function trial(CompanyPlan $plan, CarbonInterface $trialEndsAt, CarbonInterface $graceEndsAt): self
    {
        return new self(self::TRIAL, $plan, $trialEndsAt, $graceEndsAt);
    }

    public function isTrial(): bool
    {
        return $this->mode === self::TRIAL;
    }

    /** Whether provisioning should close the deal. Only a sale does. */
    public function winsTheDeal(): bool
    {
        return $this->mode === self::SALE;
    }
}
