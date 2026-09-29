<?php

namespace App\Services;

use App\ActivityType;
use App\ClinicStatus;
use App\ContactRole;
use App\CustomerChurnRisk;
use App\Events\CustomerProvisioned;
use App\LeadStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\CompanySubscription;
use App\Models\Contact;
use App\Models\EnabledModule;
use App\Models\Lead;
use App\Models\License;
use App\Models\NumberingSequence;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\OrganizationRole;
use App\OrganizationType;
use App\QuotationStatus;
use App\Services\CustomerSuccess\AccountSnapshotService;
use App\Services\Finance\MrrService;
use App\Services\Provisioning\AdminHandoverService;
use App\Services\Provisioning\ClinicOperationsProvisioner;
use App\Services\Provisioning\EntitlementResolver;
use App\Services\Provisioning\ProvisioningIntent;
use App\SubscriptionStatus;
use App\UserType;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CustomerProvisioningEngine
{
    public function __construct(
        protected BranchProvisioningService $branchProvisioning,
        protected AssignPrimaryOwnerService $assignPrimaryOwner,
        protected CommercialLedgerService $ledger,
        protected EntitlementResolver $entitlements,
        protected ClinicOperationsProvisioner $operations,
        protected AdminHandoverService $handover,
    ) {}

    /**
     * Provision a new customer tenant from a converted lead.
     *
     * Builds the full hierarchy the product actually runs on:
     *
     *   Organization  the commercial account
     *     └── Clinic  the operational tenant the clinic portal routes by
     *           └── Branch
     *
     * Idempotency keys on `customer_organization_id`, not `organization_id`.
     * The latter is the vendor tenant stamped at lead creation, so using it as
     * the guard meant provisioning returned early for every lead created
     * through the UI and the whole post-sale chain stayed empty.
     *
     * The intent decides three things and nothing else: which plan supplies the
     * entitlements, how the subscription window is dated, and whether the deal
     * moves to Won. A trial gets the same environment a sale does — that is what
     * lets a won trial keep its tenant instead of being rebuilt around the
     * customer's data.
     *
     * @throws Exception
     */
    public function provision(Lead $lead, string $defaultBranchName = 'Main Branch', ?ProvisioningIntent $intent = null): Organization
    {
        $intent ??= ProvisioningIntent::sale();

        // Idempotency: return the customer already provisioned from this lead,
        // and handle any renewal or expansion updates for post-sale won deals.
        if ($lead->customer_organization_id) {
            $existingOrg = Organization::find($lead->customer_organization_id);
            if ($existingOrg) {
                $this->ensureTenantHierarchy($lead, $existingOrg, $defaultBranchName);

                // Only a sale closes a deal. Re-running trial provisioning must
                // never renew a subscription or move the lead.
                //
                // The status update belongs here as well as on the fresh path:
                // once a trial has provisioned the tenant, winning the deal
                // always arrives through this branch, and leaving it out meant a
                // converted trial stayed open in the pipeline forever.
                if ($intent->winsTheDeal()) {
                    $this->handlePostSaleDealWon($lead, $existingOrg);

                    if ($lead->status !== LeadStatus::Won) {
                        $lead->update([
                            'status' => LeadStatus::Won,
                            'next_followup_at' => null,
                            'followup_notes' => null,
                        ]);
                    }
                }

                return $existingOrg;
            }
        }

        return DB::transaction(function () use ($lead, $defaultBranchName, $intent) {
            // 1. Organization
            $org = Organization::create([
                'name' => $lead->company_name ?: ($lead->contact_name.' Clinic'),
                'legal_name' => $lead->company_name ?: $lead->contact_name,
                'type' => $lead->organization_type ?? OrganizationType::Other,
                /*
                 * Stated, not left to the column default. A provisioned
                 * organization is a customer by definition, and saying so here
                 * means the Accounts directory finds it from the moment it
                 * exists rather than once it happens to acquire a clinic.
                 */
                'role' => OrganizationRole::Customer,
                'clinic_type' => null,
                'organization_scale' => $lead->organization_scale ?? null,
                'contact_person' => $lead->contact_name,
                'email' => $lead->email,
                'mobile' => $lead->phone,
                'assigned_user_id' => $lead->assigned_user_id,
                'health_score' => 100,
                'renewal_probability' => 100,
                'churn_risk' => CustomerChurnRisk::Low,
                'expansion_opportunity' => false,
            ]);

            // Map existing Contacts to Organization
            $lead->contacts()->update(['organization_id' => $org->id]);

            // Create Primary Contact if no contacts exist
            if ($lead->contacts()->count() === 0 && $lead->contact_name) {
                Contact::create([
                    'organization_id' => $org->id,
                    'lead_id' => $lead->id,
                    'first_name' => collect(explode(' ', $lead->contact_name))->first(),
                    'last_name' => collect(explode(' ', $lead->contact_name))->slice(1)->implode(' ') ?: null,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'decision_role' => ContactRole::Owner,
                    'is_primary' => true,
                ]);
            }

            // Move tasks and documents to organization
            $lead->tasks()->update([
                'taskable_type' => (new Organization)->getMorphClass(),
                'taskable_id' => $org->id,
            ]);
            $lead->documents()->update([
                'documentable_type' => Organization::class,
                'documentable_id' => $org->id,
            ]);

            // 2. Commercial Quotation & Subscription Resolution
            $approvedQuotation = CompanyQuotation::where('lead_id', $lead->id)
                ->where('status', QuotationStatus::Approved)
                ->latest()
                ->first()
                ?? CompanyQuotation::where('lead_id', $lead->id)->latest()->first();

            // Entitlements come from the purchased plan whenever the quotation
            // names one. The value-threshold branch below is only a fallback
            // for legacy quotations created before packages were linked — it
            // must never override a real plan, otherwise the customer receives
            // limits they did not buy and Revenue cannot report what was sold.
            //
            // A trial carries its plan on the intent instead: there is no
            // approved quotation yet, and the whole point is to demonstrate the
            // plan being sold.
            $purchasedPlan = $intent->plan ?? ($approvedQuotation?->company_plan_id
                ? CompanyPlan::find($approvedQuotation->company_plan_id)
                : null);

            if ($purchasedPlan) {
                $maxUsers = $purchasedPlan->max_users ?? 5;
                $maxBranches = $purchasedPlan->max_branches ?? 1;
                $planName = $purchasedPlan->name;
            } else {
                $maxUsers = 5;
                $maxBranches = 1;
                $planName = 'Enterprise Dental Suite';

                if ($approvedQuotation) {
                    $grandTotal = (float) $approvedQuotation->grand_total;
                    if ($grandTotal > 100000) {
                        $maxUsers = 15;
                        $maxBranches = 3;
                        $planName = 'Multi-Specialty Group License';
                    } elseif ($grandTotal > 50000) {
                        $maxUsers = 10;
                        $maxBranches = 2;
                        $planName = 'Professional HMS License';
                    }
                }
            }

            /*
             * When access ends.
             *
             * For a trial that is the *grace* end, not the trial end — grace is
             * full access, so expiring the subscription earlier would contradict
             * the thing grace exists to provide.
             */
            $expiresAt = $intent->isTrial() ? $intent->graceEndsAt : now()->addYear();
            $storageMb = $this->entitlements->planStorageMb($purchasedPlan);

            // 2a. Clinic tenant.
            //
            // Created before the subscription and the branch because both hang
            // off it: `company_subscriptions.clinic_id` is what Finance bills
            // against, and `branches.clinic_id` is what the clinic portal
            // resolves. Neither was being set.
            $clinic = $this->resolveClinic($org);

            /*
             * Doctors and storage live on the plan and on the subscription, and
             * provisioning wrote neither — so every customer silently got the
             * table default regardless of what they bought. Omitted rather than
             * nulled when the plan is unknown, so the column default still
             * applies; both columns are NOT NULL.
             *
             * `mrr` is stamped from the price in force right now and never
             * recalculated, which is what lets a plan price change later leave
             * historical revenue alone. Null when the plan is unknown: Revenue
             * reports that as unrecorded rather than counting it as zero.
             */
            $planLimits = array_filter([
                'max_doctors' => $purchasedPlan?->max_doctors,
                'storage_limit_gb' => $purchasedPlan?->storage_limit_gb,
                'mrr' => app(MrrService::class)->snapshotValueFor($purchasedPlan),
            ], fn ($value) => $value !== null);

            $subscription = CompanySubscription::create(array_merge([
                'organization_id' => $org->id,
                'clinic_id' => $clinic->id,
                'company_plan_id' => $purchasedPlan?->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'trial_ends_at' => $intent->trialEndsAt,
                'grace_ends_at' => $intent->graceEndsAt,
                'ends_at' => $expiresAt,
                'max_users' => $maxUsers,
                'max_branches' => $maxBranches,
            ], $planLimits));

            // 3. License
            $license = License::create([
                'organization_id' => $org->id,
                'plan_name' => $planName,
                'max_branches' => $maxBranches,
                'max_users' => $maxUsers,
                // Was hardcoded to 10 GB for everyone, which handed a Starter
                // customer and an Enterprise customer the same allowance.
                'storage_limit_mb' => $storageMb,
                'status' => 'active',
                'expires_at' => $expiresAt,
            ]);

            /*
             * Licensed modules, from the plan.
             *
             * Every plan used to grant the same hardcoded five, so a trial could
             * not demonstrate what the customer would actually be buying.
             */
            foreach ($this->entitlements->modulesFor($purchasedPlan) as $module) {
                EnabledModule::create([
                    'license_id' => $license->id,
                    'module_name' => $module,
                    'is_enabled' => true,
                ]);
            }

            // 4. Default Primary Branch, owned by the clinic
            $branch = $this->branchProvisioning->provision($org, $defaultBranchName, true, $clinic);

            // 4a. The operational floor.
            //
            // Opening hours, departments, rooms, a queue, branch settings,
            // specialties and a treatment catalogue. Without these the product
            // does not run at all — the appointment grid has no bookable span —
            // so a tenant handed over without them cannot be evaluated.
            $this->operations->provision($org, $clinic, $branch);

            // 5. Primary Clinic Admin User
            $adminUser = User::firstOrCreate(
                ['email' => $lead->email],
                [
                    'organization_id' => $org->id,
                    'name' => $lead->contact_name,
                    'password' => Hash::make(Str::random(32)), // Password setup flow via invitation
                    'user_type' => UserType::ClinicUser,
                ]
            );

            if (! $adminUser->organization_id) {
                $adminUser->update(['organization_id' => $org->id]);
            }

            // 5a. Tenant access.
            //
            // A ClinicUser reaches the portal through a ClinicMembership, not
            // through `users.organization_id`. Without this the provisioned
            // admin had a login and no tenant to log in to.
            $this->assignPrimaryOwner->execute($clinic, $adminUser);

            // 6. Spatie Permissions & Team Scope
            //
            // The team id is global request state. Switching it to the new
            // branch and leaving it there meant every permission check *after*
            // a provision looked in the wrong team and silently found nothing —
            // so a rep who had just created a trial appeared to lose their
            // own permissions for the rest of the request.
            $previousTeamId = getPermissionsTeamId();

            try {
                setPermissionsTeamId($branch->id);
                $adminRole = Role::firstOrCreate([
                    'name' => 'Organization Admin',
                    'guard_name' => 'web',
                ]);
                $adminUser->assignRole($adminRole);
            } finally {
                setPermissionsTeamId($previousTeamId);
            }

            // 7. Organization Settings
            OrganizationSetting::create([
                'organization_id' => $org->id,
                'group' => 'general',
                'key' => 'timezone',
                'value' => 'Asia/Kolkata',
            ]);

            // 8. Numbering Sequences
            NumberingSequence::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'entity_type' => 'invoice',
                'prefix' => 'INV-',
                'current_number' => 1,
            ]);

            // 9. Onboarding Progress Machine
            OnboardingProgress::create([
                'organization_id' => $org->id,
                'current_step' => 'password_setup',
                'status' => 'pending',
                'completed_steps' => [],
            ]);

            // 10. Link the customer account, and close the deal only on a sale.
            //
            // `organization_id` keeps its tenant meaning and is only filled in
            // here when the lead had none — a legacy lead created before
            // createLead() stamped it. `customer_organization_id` is the link
            // that matters, and the one idempotency reads.
            //
            // Won is conditional. This used to be unconditional, so starting a
            // trial closed the very deal the trial exists to help close.
            $leadUpdate = [
                'organization_id' => $lead->organization_id ?: $org->id,
                'customer_organization_id' => $org->id,
            ];

            if ($intent->winsTheDeal()) {
                $leadUpdate += [
                    'status' => LeadStatus::Won,
                    'next_followup_at' => null,
                    'followup_notes' => null,
                ];
            }

            $lead->update($leadUpdate);

            /*
             * A trial starting is a change to what this tenant is entitled to,
             * so it belongs in the entitlement history rather than only in the
             * subscription row that will later be overwritten.
             */
            if ($intent->isTrial()) {
                SubscriptionEvent::create([
                    'company_subscription_id' => $subscription->id,
                    'organization_id' => $org->id,
                    'event' => SubscriptionEvent::TRIAL_STARTED,
                    'to_plan_id' => $subscription->company_plan_id,
                    'lead_id' => $lead->id,
                    'reason' => 'Trial environment provisioned.',
                    'changes' => [
                        'trial_ends_at' => $intent->trialEndsAt?->toDateString(),
                        'grace_ends_at' => $intent->graceEndsAt?->toDateString(),
                    ],
                ]);
            }

            // 10a. Attribute pre-sale money to the tenant that now exists.
            //
            // A deal is invoiced while it is still a lead, so its ledger rows
            // were raised without a clinic. Now that one exists, Finance can
            // attribute that revenue.
            $this->ledger->attachTenant($org, $clinic);

            // 11. Log Activity
            $org->activities()->create([
                'type' => 'provisioning',
                'description' => "Organization provisioned from Lead #{$lead->id} (Quotation #".($approvedQuotation?->quotation_number ?? 'N/A').').',
            ]);

            // Dispatch Lifecycle Event
            event(new CustomerProvisioned($org, $adminUser));

            return $org;
        });

        app(AccountSnapshotService::class)->refreshIfCustomer($org);

        return $org;
    }

    /**
     * Find or create the clinic tenant for an organization.
     *
     * One clinic per account at provisioning time; additional clinics are a
     * later expansion concern. The slug is globally unique because the portal
     * routes by it in domain mode, so a collision is resolved with a suffix
     * rather than by failing the whole provisioning transaction.
     */
    protected function resolveClinic(Organization $organization, ?string $name = null): Clinic
    {
        $existing = Clinic::where('organization_id', $organization->id)->oldest('id')->first();

        if ($existing) {
            return $existing;
        }

        return Clinic::create([
            'organization_id' => $organization->id,
            'name' => $name ?: $organization->name,
            'slug' => $this->uniqueClinicSlug($name ?: $organization->name),
            'status' => ClinicStatus::Active,
        ]);
    }

    /**
     * A clinic slug that no other clinic holds.
     */
    protected function uniqueClinicSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'clinic';
        $slug = $base;

        for ($attempt = 0; Clinic::where('slug', $slug)->exists(); $attempt++) {
            $slug = $base.'-'.Str::lower(Str::random(6));

            if ($attempt > 10) {
                $slug = $base.'-'.Str::uuid();
                break;
            }
        }

        return $slug;
    }

    /**
     * Repair the tenant hierarchy for an account provisioned before this fix.
     *
     * Runs on the idempotent path so re-provisioning an existing customer
     * backfills what the old engine never created — a clinic, the branch's
     * clinic link, the subscription's clinic link, and the owner's membership —
     * without creating a second of anything.
     */
    protected function ensureTenantHierarchy(Lead $lead, Organization $org, string $defaultBranchName): void
    {
        DB::transaction(function () use ($lead, $org, $defaultBranchName) {
            $clinic = $this->resolveClinic($org);

            $branch = Branch::withoutOrganizationScope()
                ->where('organization_id', $org->id)
                ->orderByDesc('is_main')
                ->oldest('id')
                ->first();

            if (! $branch) {
                $branch = $this->branchProvisioning->provision($org, $defaultBranchName, true, $clinic);
            } elseif (! $branch->clinic_id) {
                $branch->update(['clinic_id' => $clinic->id]);
            }

            Branch::withoutOrganizationScope()
                ->where('organization_id', $org->id)
                ->whereNull('clinic_id')
                ->update(['clinic_id' => $clinic->id]);

            CompanySubscription::where('organization_id', $org->id)
                ->whereNull('clinic_id')
                ->update(['clinic_id' => $clinic->id]);

            /*
             * The operational floor, for tenants provisioned before it existed.
             *
             * Idempotent by construction, so this adds nothing to an account
             * that already has it and fills in the empty tables of one that
             * does not.
             */
            $this->operations->provision($org, $clinic, $branch);

            $adminUser = $lead->email ? User::where('email', $lead->email)->first() : null;

            if ($adminUser && $adminUser->user_type === UserType::ClinicUser) {
                $this->assignPrimaryOwner->execute($clinic, $adminUser);
            }

            $this->ledger->attachTenant($org, $clinic);
        });
    }

    /**
     * Handle post-sale deal won transitions for renewal and expansion opportunities.
     *
     * Guarantees that winning a renewal deal extends the customer's existing
     * subscription without creating duplicate tenant records, and that winning
     * an expansion deal bumps the purchased entitlements on the subscription
     * and license.
     */
    protected function handlePostSaleDealWon(Lead $lead, Organization $org): void
    {
        $dealTitle = strtolower($lead->company_name ?? '');
        $isExpansion = str_contains($dealTitle, 'expansion');

        $subscription = CompanySubscription::where('organization_id', $org->id)
            ->latest('id')
            ->first();

        $license = License::where('organization_id', $org->id)
            ->latest('id')
            ->first();

        if ($isExpansion) {
            $approvedQuotation = CompanyQuotation::where('lead_id', $lead->id)
                ->where('status', QuotationStatus::Approved)
                ->latest()
                ->first()
                ?? CompanyQuotation::where('lead_id', $lead->id)->latest()->first();

            $purchasedPlan = $approvedQuotation?->company_plan_id
                ? CompanyPlan::find($approvedQuotation->company_plan_id)
                : null;

            if ($subscription) {
                $maxUsers = $purchasedPlan?->max_users ?? ($subscription->max_users + 5);
                $maxBranches = $purchasedPlan?->max_branches ?? ($subscription->max_branches + 1);

                $subscription->update([
                    'max_users' => $maxUsers,
                    'max_branches' => $maxBranches,
                    'company_plan_id' => $purchasedPlan?->id ?? $subscription->company_plan_id,
                ]);

                if ($license) {
                    $license->update([
                        'max_users' => $maxUsers,
                        'max_branches' => $maxBranches,
                        'plan_name' => $purchasedPlan?->name ?? $license->plan_name,
                    ]);
                }
            }

            $org->activities()->create([
                'type' => ActivityType::SystemUpdate,
                'description' => "Expansion Deal (#{$lead->id}) Won. Entitlements and license limits updated.",
            ]);

            return;
        }

        if (! $subscription) {
            return;
        }

        /*
         * Converting a trial is not the same act as renewing a paid term.
         *
         * A trial subscription is dated to the trial and grace window, so
         * extending its `ends_at` the way a renewal does would leave the trial
         * dates in place and hand the customer a paid year that still reports
         * itself as a trial. The window is replaced outright instead: the trial
         * is over, and what follows is a paid term measured from today.
         */
        if ($subscription->trial_ends_at || $subscription->grace_ends_at) {
            $newEnd = now()->addYear();

            $subscription->update([
                'status' => SubscriptionStatus::Active,
                'trial_ends_at' => null,
                'grace_ends_at' => null,
                'ends_at' => $newEnd,
            ]);

            if ($license) {
                $license->update([
                    'status' => 'active',
                    'expires_at' => $newEnd,
                ]);
            }

            $org->update([
                'renewal_probability' => 100,
                'churn_risk' => CustomerChurnRisk::Low,
            ]);

            SubscriptionEvent::create([
                'company_subscription_id' => $subscription->id,
                'organization_id' => $org->id,
                'event' => SubscriptionEvent::CONVERTED_TO_PAID,
                'from_plan_id' => $subscription->company_plan_id,
                'to_plan_id' => $subscription->company_plan_id,
                'lead_id' => $lead->id,
                'reason' => 'Deal won; trial converted to a paid term.',
                'changes' => ['ends_at' => $newEnd->toDateString()],
            ]);

            $org->activities()->create([
                'type' => ActivityType::SystemUpdate,
                'description' => "Trial converted on Deal (#{$lead->id}) Won. Paid term runs to {$newEnd->format('Y-m-d')}.",
            ]);

            return;
        }

        $currentEnd = $subscription->ends_at ? Carbon::parse($subscription->ends_at) : now();
        $newEnd = ($currentEnd->isFuture() ? $currentEnd : now())->addYear();

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'ends_at' => $newEnd,
        ]);

        if ($license) {
            $license->update([
                'status' => 'active',
                'expires_at' => $newEnd,
            ]);
        }

        $org->update([
            'renewal_probability' => 100,
            'churn_risk' => CustomerChurnRisk::Low,
        ]);

        $org->activities()->create([
            'type' => ActivityType::SystemUpdate,
            'description' => "Renewal Deal (#{$lead->id}) Won. Subscription extended to {$newEnd->format('Y-m-d')}.",
        ]);
    }
}
