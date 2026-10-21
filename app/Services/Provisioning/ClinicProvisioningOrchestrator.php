<?php

namespace App\Services\Provisioning;

use App\Events\ClinicProvisioningSucceeded;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicProvisioningRun;
use App\Models\Lead;
use App\Models\LeadBranch;
use App\Models\User;
use App\OwnerInvitationStatus;
use App\ProvisioningRunStatus;
use App\Services\ClinicContext;
use App\Services\CustomerProvisioningEngine;
use App\Services\Onboarding\OnboardingEngine;
use App\Services\Onboarding\OwnerInvitationService;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a won deal into a clinic its owner can set up, in stages:
 *
 *   tenant             organization, clinic, subscription, licence, primary
 *                      branch, operational floor, owner login + membership
 *                      (CustomerProvisioningEngine, idempotent per deal)
 *   primary_branch     the CRM's branch details on the primary branch
 *   specialty          the specialty set CRM sold, on clinic and branch
 *   onboarding_session the owner's resumable onboarding, from the template
 *   owner_invitation   one invitation per clinic and owner, sent once (last,
 *                      so the onboarding is waiting when the owner arrives)
 *
 * Each completed stage is recorded on the run, so a retry after a failure
 * picks up at the stage that failed. Every stage is also idempotent on its
 * own, so a stage that finished but was not recorded is safe to repeat.
 */
class ClinicProvisioningOrchestrator
{
    public function __construct(
        protected CustomerProvisioningEngine $engine,
        protected OwnerInvitationService $invitations,
        protected OnboardingEngine $onboarding,
        protected ClinicContext $context,
        protected SecurityAudit $audit,
    ) {}

    public function run(ClinicProvisioningRun $run): ClinicProvisioningRun
    {
        $run->update([
            'status' => ProvisioningRunStatus::Running,
            'attempts' => $run->attempts + 1,
            'started_at' => $run->started_at ?? now(),
            'last_error' => null,
        ]);

        try {
            foreach ($run->stages() as $stage) {
                if ($run->hasCompleted($stage)) {
                    continue;
                }

                $run->update(['current_stage' => $stage]);
                $this->{Str::camel('stage_'.$stage)}($run);
                $run->update(['completed_stages' => [...($run->completed_stages ?? []), $stage]]);
            }
        } catch (Throwable $e) {
            $run->update(['status' => ProvisioningRunStatus::Failed, 'last_error' => Str::limit($e->getMessage(), 1000)]);
            $this->audit->record('provisioning.stage_failed', $run, ['stage' => $run->current_stage, 'attempt' => $run->attempts], ['clinic_id' => $run->clinic_id, 'actor_user_id' => null]);

            throw $e;
        }

        $run->update(['status' => ProvisioningRunStatus::Succeeded, 'current_stage' => null, 'finished_at' => now()]);
        $this->audit->record('provisioning.succeeded', $run, ['lead_id' => $run->lead_id], ['clinic_id' => $run->clinic_id, 'actor_user_id' => $run->requested_by]);
        event(new ClinicProvisioningSucceeded($run));

        return $run;
    }

    protected function stageTenant(ClinicProvisioningRun $run): void
    {
        $lead = $this->lead($run);
        $newCustomer = $run->source !== ClinicProvisioningRun::SOURCE_EXISTING_CUSTOMER;

        if ($newCustomer && ! filled($lead->email)) {
            throw new DomainException('The deal has no owner email, so there is nobody to invite. Add the email on the deal and retry.');
        }

        $crmBranch = $this->crmPrimaryBranch($lead);
        $organization = $this->context->withoutTenancy(fn () => $this->engine->provision($lead, $crmBranch?->name));

        $clinic = Clinic::withoutGlobalScopes()->where('organization_id', $organization->id)->oldest('id')->firstOrFail();
        $branch = $this->primaryBranch($clinic);

        // An existing customer's owner is whoever holds the clinic already.
        $ownerId = ClinicMembership::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_primary_owner', true)->value('user_id');
        $owner = $newCustomer ? User::where('email', $lead->email)->firstOrFail() : ($ownerId ? User::find($ownerId) : null);

        $run->update([
            'organization_id' => $organization->id,
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'owner_user_id' => $owner?->id,
        ]);
    }

    /**
     * Apply the CRM's branch to the primary branch. With no CRM branch the
     * tenant stage already created exactly one primary branch; nothing else
     * is created here and the owner is never asked to choose one.
     */
    protected function stagePrimaryBranch(ClinicProvisioningRun $run): void
    {
        $clinic = $this->clinic($run);
        $branch = $this->primaryBranch($clinic);
        $crmBranch = $this->crmPrimaryBranch($this->lead($run));

        DB::transaction(function () use ($clinic, $branch, $crmBranch) {
            Branch::withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereKeyNot($branch->id)->where('is_main', true)->update(['is_main' => false]);

            $updates = ['is_main' => true];
            if ($crmBranch) {
                $updates += array_filter([
                    'name' => $crmBranch->name,
                    'address_line1' => $crmBranch->address,
                    'address_line2' => $crmBranch->area,
                    'city' => $crmBranch->city,
                    'state' => $crmBranch->state,
                    'pincode' => $crmBranch->postal_code,
                    'phone' => $crmBranch->phone,
                ], fn ($value) => filled($value));
            }

            $branch->update($updates);
        });

        $run->update(['branch_id' => $branch->id]);
    }

    protected function stageSpecialty(ClinicProvisioningRun $run): void
    {
        // A deal that names no specialty leaves the clinic's set as it is.
        app(DealSpecialties::class)->apply($this->lead($run), $this->clinic($run), Branch::withoutGlobalScopes()->find($run->branch_id));
    }

    protected function stageOwnerInvitation(ClinicProvisioningRun $run): void
    {
        $clinic = $this->clinic($run);
        $owner = User::findOrFail($run->owner_user_id);

        // A clinic that already finished onboarding (a converted trial) is
        // already in its owner's hands.
        if ($this->onboarding->clinicSession($clinic)?->isCompleted()) {
            return;
        }

        $invitation = $this->invitations->issue($clinic, $owner, $run, $run->requested_by ? User::find($run->requested_by) : null);

        if ($invitation->status === OwnerInvitationStatus::Created) {
            $this->invitations->send($invitation);
        }
    }

    protected function stageOnboardingSession(ClinicProvisioningRun $run): void
    {
        $clinic = $this->clinic($run);
        $branch = Branch::withoutGlobalScopes()->find($run->branch_id);

        $this->context->runForClinic($clinic, $branch, fn () => $this->onboarding->startForClinic($clinic, User::find($run->owner_user_id)));
    }

    private function lead(ClinicProvisioningRun $run): Lead
    {
        return Lead::withoutGlobalScopes()->findOrFail($run->lead_id);
    }

    private function clinic(ClinicProvisioningRun $run): Clinic
    {
        return Clinic::withoutGlobalScopes()->findOrFail($run->clinic_id);
    }

    private function primaryBranch(Clinic $clinic): Branch
    {
        return Branch::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->firstOrFail();
    }

    private function crmPrimaryBranch(Lead $lead): ?LeadBranch
    {
        return LeadBranch::query()
            ->where('lead_id', $lead->id)
            ->orderByDesc('is_main')
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }
}
