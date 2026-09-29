<?php

namespace App\Console\Commands;

use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Services\Security\SecurityAudit;
use App\SubscriptionStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives every active clinic an explicit subscription row, so the plan layer
 * (`clinic_entitled`, `subscription_window`) stops exempting it as "legacy".
 *
 * Dry run by default. With `--apply`, per clinic:
 *   1. Its organization holds exactly one subscription not tied to any
 *      clinic, and the organization has only this clinic: that subscription
 *      is linked to the clinic, keeping its plan, dates and limits.
 *   2. Otherwise a new active subscription is created with no plan. The
 *      entitlement resolver then grants its fallback modules (patients,
 *      appointments, billing), which is everything a legacy clinic can reach
 *      today, so nobody is locked out. Limits are set no lower than the
 *      clinic's current members and branches, so no existing user or branch
 *      ends up over its limit.
 *   3. A clinic with no organization cannot hold a subscription. It is
 *      reported and left for a person to fix.
 *
 * Every change is audited. Assigning the right commercial plan afterwards is
 * a sales/account decision, not something this command guesses.
 */
class BackfillLegacySubscriptions extends Command
{
    protected $signature = 'subscriptions:backfill-legacy {--apply : Make the changes (default is a dry run)}';

    protected $description = 'Give every active clinic an explicit subscription so plan limits apply to it';

    public function handle(SecurityAudit $audit): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];

        $clinics = Clinic::query()
            ->where('status', ClinicStatus::Active)
            ->whereNotIn('id', CompanySubscription::query()->whereNotNull('clinic_id')->select('clinic_id'))
            ->orderBy('id')
            ->get();

        foreach ($clinics as $clinic) {
            if (! $clinic->organization_id) {
                $rows[] = [$clinic->id, $clinic->name, 'SKIPPED', 'No organization. Attach one first; a subscription belongs to an organization.'];

                continue;
            }

            $orphans = CompanySubscription::query()->where('organization_id', $clinic->organization_id)->whereNull('clinic_id')->get();
            $siblingClinics = Clinic::query()->where('organization_id', $clinic->organization_id)->count();

            if ($orphans->count() === 1 && $siblingClinics === 1) {
                $rows[] = [$clinic->id, $clinic->name, $apply ? 'LINKED' : 'would link', "Organization's unlinked subscription #{$orphans->first()->id}"];

                if ($apply) {
                    $orphans->first()->update(['clinic_id' => $clinic->id]);
                    $audit->record('subscription.backfilled', $orphans->first(), ['mode' => 'linked'], ['clinic_id' => $clinic->id]);
                }

                continue;
            }

            $members = ClinicMembership::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('status', ClinicMembershipStatus::Active)->count();
            $branches = Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->count();

            $rows[] = [$clinic->id, $clinic->name, $apply ? 'CREATED' : 'would create', "Legacy subscription, fallback modules, limits ≥ current ({$members} members, {$branches} branches)"];

            if ($apply) {
                DB::transaction(function () use ($clinic, $members, $branches, $audit) {
                    $subscription = CompanySubscription::create([
                        'organization_id' => $clinic->organization_id,
                        'clinic_id' => $clinic->id,
                        'company_plan_id' => null,
                        'status' => SubscriptionStatus::Active,
                        'starts_at' => now(),
                        'max_users' => max(10, $members),
                        'max_doctors' => max(5, $members),
                        'max_branches' => max(1, $branches),
                        'storage_limit_gb' => 20,
                    ]);

                    $audit->record('subscription.backfilled', $subscription, ['mode' => 'created_legacy'], ['clinic_id' => $clinic->id]);
                });
            }
        }

        $this->table(['Clinic', 'Name', 'Action', 'Detail'], $rows);
        $this->line($apply ? 'Applied.' : 'Dry run. Nothing changed. Re-run with --apply.');

        return self::SUCCESS;
    }
}
