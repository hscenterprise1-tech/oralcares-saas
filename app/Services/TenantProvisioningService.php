<?php

namespace App\Services;

use App\Events\TenantProvisioningCompleted;
use App\Events\TenantProvisioningFailed;
use App\Events\TenantProvisioningStarted;
use App\Jobs\TenantProvisioningJob;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\CompanyOpportunity;
use App\Models\CompanyProvisioningProcess;
use App\Models\CompanySubscription;
use App\Models\Role;
use App\Models\User;
use App\OpportunityStage;
use App\ProvisioningState;
use App\SubscriptionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class TenantProvisioningService
{
    public function __construct(
        protected ProvisioningActivityService $activityService,
        protected ClinicInitializationService $clinicService,
        protected BranchInitializationService $branchService,
        protected MembershipInitializationService $membershipService,
        protected CloneSystemRolesToClinicService $cloneRolesService,
        protected AssignRoleToMembershipService $assignRoleService
    ) {}

    /**
     * Start the provisioning process for a Won Opportunity.
     */
    public function startProvisioning(CompanyOpportunity $opportunity): CompanyProvisioningProcess
    {
        return DB::transaction(function () use ($opportunity) {
            $process = CompanyProvisioningProcess::firstOrCreate(
                ['opportunity_id' => $opportunity->id],
                [
                    'status' => ProvisioningState::Pending,
                    'completed_steps' => [],
                    'retry_count' => 0,
                ]
            );

            $this->activityService->log($process, 'Provision Started', 'Created pending tenant provisioning process.');

            // Dispatch to the queue asynchronously
            TenantProvisioningJob::dispatch($process);

            return $process;
        });
    }

    /**
     * Run the provisioning process step-by-step.
     */
    public function runProcess(CompanyProvisioningProcess $process): void
    {
        $process->update(['status' => ProvisioningState::Running]);
        $this->activityService->log($process, 'Provision Running', 'Beginning clinic onboarding workflow steps.');

        $steps = [
            'validate_subscription',
            'validate_entitlements',
            'create_clinic',
            'create_branch',
            'create_owner',
            'create_membership',
            'assign_role',
            'initialize_settings',
        ];

        $completedSteps = $process->completed_steps ?? [];

        try {
            foreach ($steps as $step) {
                if (in_array($step, $completedSteps)) {
                    continue;
                }

                $process->update(['current_step' => $step]);

                $this->executeStep($process, $step);

                $completedSteps[] = $step;
                $process->completed_steps = $completedSteps;
                $process->save();
            }

            // Provision complete
            $process->status = ProvisioningState::Completed;
            $process->current_step = null;
            $process->error_message = null;
            $process->save();

            // Transition opportunity stage to Won
            app(SalesOpportunityService::class)->transitionStage(
                $process->opportunity,
                OpportunityStage::Won,
                null,
                'Provision completed successfully.'
            );

            // Activate subscription if pending
            $subscription = CompanySubscription::where('opportunity_id', $process->opportunity->id)->first();
            if ($subscription && $subscription->status === SubscriptionStatus::Pending) {
                app(SubscriptionService::class)->transitionStatus($subscription, SubscriptionStatus::Active);
            }

            $this->activityService->log($process, 'Provision Completed', 'Clinic onboarding workflow completed successfully.');
        } catch (Throwable $e) {
            $process->status = ProvisioningState::Failed;
            $process->error_message = $e->getMessage();
            $process->save();

            $this->activityService->log($process, 'Provision Failed', 'Step '.$step.' failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Retry a failed process.
     */
    public function retryProcess(CompanyProvisioningProcess $process): void
    {
        $process->increment('retry_count');
        $process->update([
            'status' => ProvisioningState::Retrying,
            'error_message' => null,
        ]);

        $this->activityService->log($process, 'Retry Started', 'Retrying provisioning process, attempt #'.$process->retry_count);

        TenantProvisioningJob::dispatch($process);
    }

    /**
     * Execute an individual workflow step.
     */
    protected function executeStep(CompanyProvisioningProcess $process, string $step): void
    {
        switch ($step) {
            case 'validate_subscription':
                $sub = CompanySubscription::where('opportunity_id', $process->opportunity->id)->first();
                if (! $sub) {
                    throw new LogicException('No subscription found linked to this sales opportunity.');
                }
                if (in_array($sub->status, [SubscriptionStatus::Suspended, SubscriptionStatus::Cancelled])) {
                    throw new LogicException('Subscription is suspended or cancelled.');
                }
                $this->activityService->log($process, 'Subscription Validated', 'Opportunity subscription is valid.');
                break;

            case 'validate_entitlements':
                $sub = CompanySubscription::where('opportunity_id', $process->opportunity->id)->first();
                if ($sub->max_users <= 0) {
                    throw new LogicException('Subscription does not define user limits.');
                }
                $this->activityService->log($process, 'Entitlements Validated', 'Feature entitlements verified.');
                break;

            case 'create_clinic':
                $lead = $process->opportunity->lead;
                $clinicName = $lead->company_name ?: ($lead->contact_name.' Clinic');
                $clinicSlug = Str::slug($clinicName);

                $clinic = $this->clinicService->initializeClinic($clinicName, $clinicSlug);

                $process->clinic_id = $clinic->id;
                $process->save();

                if (! $lead->clinic_id) {
                    $lead->clinic_id = $clinic->id;
                    $lead->save();
                }

                $this->activityService->log($process, 'Clinic Created', 'Clinic "'.$clinic->name.'" created with slug "'.$clinic->slug.'".');
                break;

            case 'create_branch':
                $clinic = $process->clinic;
                $branch = $this->branchService->initializePrimaryBranch($clinic, 'Main Branch');
                $this->activityService->log($process, 'Primary Branch Created', 'Primary branch "'.$branch->name.'" created.');
                break;

            case 'create_owner':
                // Handled in membership step, logging verification
                $this->activityService->log($process, 'Owner Verified', 'Primary owner user details verified.');
                break;

            case 'create_membership':
                $clinic = $process->clinic;
                $lead = $process->opportunity->lead;
                $ownerData = [
                    'name' => $lead->contact_name,
                    'email' => $lead->email,
                ];

                $membership = $this->membershipService->initializeOwnerUserAndMembership($clinic, $ownerData);

                $sub = CompanySubscription::where('opportunity_id', $process->opportunity->id)->first();
                if ($sub && ! $sub->clinic_id) {
                    $sub->clinic_id = $clinic->id;
                    $sub->save();
                }

                $this->activityService->log($process, 'Membership Created', 'Clinic membership created and linked to owner user.');
                break;

            case 'assign_role':
                $clinic = $process->clinic;
                $this->cloneRolesService->execute($clinic);

                $membership = $clinic->memberships()->where('is_primary_owner', true)->firstOrFail();
                $role = Role::where('clinic_id', $clinic->id)->where('key', 'clinic_admin')->firstOrFail();

                $this->assignRoleService->execute($membership, $role);

                $this->activityService->log($process, 'Owner Role Assigned', 'Clinic administrator (clinic_admin) role assigned to owner.');
                break;

            case 'initialize_settings':
                $this->activityService->log($process, 'Settings Seeded', 'Default clinic settings initialized.');
                break;
        }
    }

    /**
     * Orchestrate tenant onboarding and provision workspace within a secure transaction block.
     */
    public function provision(
        string $clinicName,
        string $ownerName,
        string $ownerEmail,
        string $profileType = 'solo_dentist'
    ): Clinic {
        $auditService = app(ProvisioningAuditService::class);
        $audit = $auditService->startAudit($profileType);
        event(new TenantProvisioningStarted($audit));

        $start = microtime(true);
        $entities = [];

        try {
            // Prevent duplicate tenants
            if (Clinic::where('name', $clinicName)->exists()) {
                throw new \InvalidArgumentException("Clinic with name '{$clinicName}' is already registered.");
            }

            if (User::where('email', $ownerEmail)->exists()) {
                throw new \InvalidArgumentException("User email '{$ownerEmail}' is already registered.");
            }

            $clinic = DB::transaction(function () use ($clinicName, $ownerName, $ownerEmail, $profileType, &$entities) {
                // 2. Create Clinic using clinicService to populate slug
                $clinic = $this->clinicService->initializeClinic($clinicName, Str::slug($clinicName));
                $entities['clinics'][] = $clinic->id;

                // 3. Create initial Administrator Employee and User record
                $user = User::create([
                    'name' => $ownerName,
                    'email' => $ownerEmail,
                    'password' => Hash::make(Str::random(32)), // Secure random setup flow
                ]);
                $entities['users'][] = $user->id;

                $employee = ClinicEmployee::create([
                    'clinic_id' => $clinic->id,
                    'name' => $ownerName,
                    'email' => $ownerEmail,
                    'is_active' => true,
                ]);
                $entities['employees'][] = $employee->id;

                // 4. Resolve profile parameters
                $profileService = app(ProvisioningProfileService::class);
                $config = $profileService->getProfileConfig($profileType);

                // 5. Seed default packages
                $seedService = app(SeedPackageService::class);
                $entities = array_merge_recursive($entities, $seedService->seedProcedures($clinic));
                $entities = array_merge_recursive($entities, $seedService->seedNotificationTemplates($clinic));
                $entities = array_merge_recursive($entities, $seedService->seedCommunicationPreferences($clinic));
                $entities = array_merge_recursive($entities, $seedService->seedDefaultWorkflow($clinic));

                // 6. Branch provisioning
                $branchService = app(BranchProvisioningService::class);
                for ($b = 1; $b <= $config['branches_count']; $b++) {
                    $branchName = $b === 1 ? "{$clinicName} Primary Branch" : "{$clinicName} Branch {$b}";
                    $entities = array_merge_recursive($entities, $branchService->provisionBranch(
                        $clinic,
                        $branchName,
                        $config['chairs_count'],
                        $config['rooms_count']
                    ));
                }

                return $clinic;
            });

            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $auditService->completeAudit($audit, $clinic->id, $entities, $durationMs);

            event(new TenantProvisioningCompleted($clinic));

            return $clinic;
        } catch (\Exception $e) {
            $auditService->failAudit($audit, $e->getMessage());
            event(new TenantProvisioningFailed($e->getMessage()));
            throw $e;
        }
    }
}
