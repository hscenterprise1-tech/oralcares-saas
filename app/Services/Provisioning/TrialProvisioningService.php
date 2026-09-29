<?php

namespace App\Services\Provisioning;

use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\ProvisioningAudit;
use App\Models\Trial;
use App\Models\User;
use App\Services\CustomerProvisioningEngine;
use App\Services\LeadWorkspaceService;
use App\TrialStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Give a trial customer a real, working environment.
 *
 * "Trial" previously meant a row in `trials` and a date. Nothing was
 * provisioned, so there was nothing for the customer to try and nothing for the
 * salesperson to follow up on; the status moved through its states describing a
 * product the customer had never seen.
 *
 * This provisions the same tenant a sale would, on the plan being sold, dated to
 * the trial window. Deliberately the same engine — a trial environment that
 * differs from the real one proves nothing, and building a second one would
 * guarantee the customer's data got left behind at conversion.
 *
 * Failure is a first-class outcome. Provisioning can fail on a slug collision, a
 * missing plan or a database error, and the two things that must never happen
 * are a trial silently stuck in `preparing` with no explanation, and a half-built
 * tenant. The engine's transaction handles the second; `provisioning_error` plus
 * a `provisioning_audits` row handles the first.
 */
class TrialProvisioningService
{
    /** Bumped when the shape of what gets provisioned changes. */
    public const VERSION = '2.0';

    public function __construct(
        protected CustomerProvisioningEngine $engine,
        protected AdminHandoverService $handover,
        protected EntitlementResolver $entitlements,
        protected LeadWorkspaceService $workspace,
    ) {}

    /**
     * Provision the tenant behind a trial.
     *
     * Idempotent on `trials.clinic_id`: a trial that already has an environment
     * is returned untouched rather than given a second one. That is what makes
     * the retry button safe to press twice.
     *
     * @return array{provisioned: bool, trial: Trial, handover: ?array<string, mixed>, error: ?string}
     */
    public function provision(Trial $trial): array
    {
        if ($trial->isProvisioned()) {
            return ['provisioned' => true, 'trial' => $trial, 'handover' => null, 'error' => null];
        }

        $lead = $trial->lead;

        if (! $lead) {
            return $this->fail($trial, null, 'The trial is not linked to a deal.');
        }

        $startedAt = microtime(true);

        try {
            $plan = $this->resolvePlan($trial, $lead);

            if (! $plan) {
                /*
                 * Not a soft failure. The locked requirement is that a trial
                 * runs on the plan's real limits, so provisioning one without a
                 * plan would grant entitlements nobody chose and demonstrate a
                 * product the customer is not being sold.
                 */
                throw new RuntimeException('No plan selected for this trial. Choose a plan before provisioning.');
            }

            [$startDate, $endDate, $graceEndsAt] = $this->window($trial, $plan);

            $organization = $this->engine->provision(
                $lead,
                $lead->company_name ?: 'Main Branch',
                ProvisioningIntent::trial($plan, $endDate, $graceEndsAt),
            );

            $clinic = $organization->clinics()->oldest('id')->first();

            if (! $clinic) {
                throw new RuntimeException('Provisioning finished without a clinic tenant.');
            }

            $trial->forceFill([
                'company_plan_id' => $plan->id,
                'customer_organization_id' => $organization->id,
                'clinic_id' => $clinic->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'grace_ends_at' => $graceEndsAt,
                'provisioned_at' => now(),
                'provisioning_error' => null,
                'status' => TrialStatus::Active,
            ])->save();

            /*
             * Going live is what provisioning *is*. There used to be a manual
             * "Activate" button that set the status to Active and nothing else,
             * so a rep could declare a trial live with no environment behind
             * it. The button is gone; the side effects it carried — the lead
             * stage, the mid-point check task, the activity entry — happen here
             * instead, once there is genuinely something to log in to.
             */
            $this->workspace->activateTrial($trial->fresh(), auth()->user());

            $handover = $this->handOver($trial, $lead, $clinic);

            $this->audit($trial, $lead, $clinic->id, 'succeeded', $startedAt, [
                'organization_id' => $organization->id,
                'clinic_id' => $clinic->id,
                'plan' => $plan->key,
                'modules' => $this->entitlements->modulesFor($plan),
                'handover_delivered' => $handover['delivered'],
            ]);

            return ['provisioned' => true, 'trial' => $trial->fresh(), 'handover' => $handover, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Trial provisioning failed', [
                'trial_id' => $trial->id,
                'lead_id' => $lead->id,
                'reason' => $e->getMessage(),
            ]);

            $this->audit($trial, $lead, null, 'failed', $startedAt, null, $e->getMessage());

            return $this->fail($trial, $lead, $e->getMessage());
        }
    }

    /**
     * Try again after a failure.
     *
     * Clears the recorded error first so a run that fails a second time for a
     * different reason does not leave the first reason showing. The engine's own
     * idempotency means a retry after a partial success adopts what exists
     * rather than duplicating it.
     *
     * @return array{provisioned: bool, trial: Trial, handover: ?array<string, mixed>, error: ?string}
     */
    public function retry(Trial $trial): array
    {
        $trial->forceFill(['provisioning_error' => null])->save();

        return $this->provision($trial->fresh());
    }

    /**
     * Re-send the setup link without touching the environment.
     *
     * Handover fails for reasons that have nothing to do with provisioning — a
     * wrong number, a provider outage — and the fix should not involve
     * rebuilding a tenant that is already correct.
     *
     * @return array{delivered: bool, channel: string, reason: ?string}
     */
    public function resendHandover(Trial $trial): array
    {
        $lead = $trial->lead;
        $clinic = $trial->clinic;

        if (! $lead || ! $clinic) {
            return ['delivered' => false, 'channel' => 'whatsapp', 'reason' => 'This trial has no provisioned environment yet.'];
        }

        return $this->handOver($trial, $lead, $clinic);
    }

    /**
     * Which plan this trial runs on.
     *
     * In order of how explicit the choice was: the plan set on the trial, then
     * the one named on the trial's free-text `plan` column (older rows), then
     * whatever a quotation on the deal already proposes.
     */
    protected function resolvePlan(Trial $trial, Lead $lead): ?CompanyPlan
    {
        if ($trial->company_plan_id) {
            return CompanyPlan::find($trial->company_plan_id);
        }

        if ($trial->plan) {
            $named = CompanyPlan::query()
                ->where('key', strtolower($trial->plan))
                ->orWhere('name', $trial->plan)
                ->first();

            if ($named) {
                return $named;
            }
        }

        $quotation = CompanyQuotation::where('lead_id', $lead->id)
            ->whereNotNull('company_plan_id')
            ->latest()
            ->first();

        return $quotation ? CompanyPlan::find($quotation->company_plan_id) : null;
    }

    /**
     * The trial's dates.
     *
     * Length comes from the trial when it names one, otherwise from the plan —
     * `trial_duration_days` existed on every plan and was read by nothing.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface, 2: CarbonInterface}
     */
    protected function window(Trial $trial, CompanyPlan $plan): array
    {
        $start = $trial->start_date ? Carbon::parse($trial->start_date) : Carbon::today();

        $days = $trial->duration_days ?: ($plan->trial_duration_days ?: 14);

        $end = $trial->end_date
            ? Carbon::parse($trial->end_date)->endOfDay()
            : $start->copy()->addDays($days)->endOfDay();

        return [$start, $end, $end->copy()->addDays(EntitlementResolver::GRACE_DAYS)];
    }

    /**
     * Hand the environment to the customer's initial admin.
     *
     * The admin identity is the one the engine created from the deal's contact
     * — CRM does not create any other user in the customer's clinic, and every
     * later account is theirs to make.
     *
     * @return array{delivered: bool, channel: string, reason: ?string}
     */
    protected function handOver(Trial $trial, Lead $lead, Clinic $clinic): array
    {
        $admin = $lead->email ? User::where('email', $lead->email)->first() : null;

        if (! $admin) {
            return ['delivered' => false, 'channel' => 'whatsapp', 'reason' => 'No initial admin identity on the deal.'];
        }

        $result = $this->handover->send($lead, $admin, $clinic);

        $checklist = $trial->onboarding_checklist ?: [];
        $checklist['handover'] = [
            'delivered' => $result['delivered'],
            'channel' => $result['channel'],
            'at' => now()->toISOString(),
        ];

        $trial->forceFill(['onboarding_checklist' => $checklist])->save();

        return $result;
    }

    /**
     * Record the failure on the trial itself.
     *
     * Status stays `preparing`, because that is true — the customer has no
     * environment. What changes is that the reason is now visible to whoever
     * has to act on it.
     *
     * @return array{provisioned: bool, trial: Trial, handover: ?array<string, mixed>, error: ?string}
     */
    protected function fail(Trial $trial, ?Lead $lead, string $reason): array
    {
        $trial->forceFill([
            'status' => TrialStatus::Preparing,
            'provisioning_error' => $reason,
        ])->save();

        return ['provisioned' => false, 'trial' => $trial->fresh(), 'handover' => null, 'error' => $reason];
    }

    /**
     * @param  array<string, mixed>|null  $entities
     */
    protected function audit(Trial $trial, ?Lead $lead, ?int $clinicId, string $status, float $startedAt, ?array $entities = null, ?string $error = null): void
    {
        ProvisioningAudit::create([
            'clinic_id' => $clinicId,
            'trial_id' => $trial->id,
            'lead_id' => $lead?->id,
            'profile' => 'trial',
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'created_entities' => $entities,
            'version' => self::VERSION,
            'error_message' => $error,
        ]);
    }
}
