<?php

namespace App\Support;

use App\Models\Clinic;
use App\Models\CommercialDocument;
use App\Models\CompanyQuotation;
use App\Models\CompanySubscription;
use App\Models\Contact;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\UserType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-side data scoping for the CRM.
 *
 * Authorization answers "may this user reach this surface"; scoping answers
 * "which rows may they see once they are there". Those are different
 * questions, and conflating them was the previous defect: a company user who
 * held any CRM permission received every CRM record, because the check was
 * effectively `user_type === CompanyUser`.
 *
 * Three levels, resolved from permissions rather than user_type:
 *
 *   all           master admin — the platform operator, no tenant boundary
 *   organization  manager — every record inside their own organization
 *   assigned      employee — only what is assigned to them
 *
 * Managers are identified by the management permissions the CRM role seeder
 * grants exclusively to manager personas. A rep who somehow acquires one of
 * those permissions is by definition a manager, so the signal stays honest.
 *
 * Every CRM read path must start from one of the builders here. Filtering in
 * React is presentation, never a security boundary.
 */
class CrmScope
{
    public const LEVEL_ALL = 'all';

    public const LEVEL_ORGANIZATION = 'organization';

    public const LEVEL_ASSIGNED = 'assigned';

    /**
     * Permissions that mark a user as CRM management rather than an individual
     * contributor. Kept in sync with CrmRolePermissionSeeder::CRM_MANAGER_PERMISSIONS.
     *
     * @var list<string>
     */
    public const MANAGER_PERMISSIONS = [
        'view-operations',
        'view-analytics',
        'manage-employees',
    ];

    /**
     * Resolve which slice of CRM data this user may read.
     */
    public static function level(?User $user): string
    {
        if (! $user || ! CrmPermissions::isCompanyPortalUser($user)) {
            return self::LEVEL_ASSIGNED;
        }

        if ($user->isMasterAdmin()) {
            return self::LEVEL_ALL;
        }

        if (self::isManager($user)) {
            return self::LEVEL_ORGANIZATION;
        }

        return self::LEVEL_ASSIGNED;
    }

    /**
     * Whether the user sees team-wide data and management surfaces.
     */
    public static function isManager(?User $user): bool
    {
        if (! $user || ! CrmPermissions::isCompanyPortalUser($user)) {
            return false;
        }

        if ($user->isMasterAdmin()) {
            return true;
        }

        return CrmPermissions::checkAny($user, ...self::MANAGER_PERMISSIONS);
    }

    /**
     * Customer accounts this user may read.
     *
     * Strict definition: only customer accounts (excluding vendor org) are returned,
     * scoped to the user's role and assignment.
     *
     * @return Builder<Organization>
     */
    public static function customerAccounts(?User $user): Builder
    {
        $level = self::level($user);
        $query = Organization::customerAccounts();

        if ($level === self::LEVEL_ALL) {
            return $query;
        }

        if ($level === self::LEVEL_ASSIGNED) {
            return $query->where(function (Builder $q) use ($user) {
                $q->where('assigned_user_id', $user?->id)
                    ->orWhereHas('wonFromLeads', fn (Builder $lq) => $lq->where('assigned_user_id', $user?->id));
            });
        }

        $orgId = $user?->resolveOrganizationId();

        return $query->where(function (Builder $q) use ($orgId, $user) {
            $visibleUserIds = self::organizationUserIds($user);
            $q->whereIn('assigned_user_id', $visibleUserIds)
                ->orWhereHas('wonFromLeads', fn (Builder $lq) => $lq->where('organization_id', $orgId));
        });
    }

    /**
     * Subscriptions this user may read.
     *
     * Scoped through customer accounts: provider/vendor organization subscriptions
     * are strictly excluded, and records are bounded to the user's role and visibility.
     *
     * @return Builder<CompanySubscription>
     */
    public static function subscriptions(?User $user): Builder
    {
        return CompanySubscription::query()
            ->whereIn('organization_id', self::customerAccounts($user)->select('organizations.id'));
    }

    /**
     * Leads this user may read.
     *
     * @return Builder<Lead>
     */
    public static function leads(?User $user): Builder
    {
        return self::apply(Lead::query(), $user, 'assigned_user_id', 'organization_id');
    }

    /**
     * Demos this user may read — ownership follows the parent lead's owner,
     * because `presenter_id` records who ran the session, not who owns the deal.
     *
     * @return Builder<Demo>
     */
    public static function demos(?User $user): Builder
    {
        return self::throughLead(Demo::query(), $user);
    }

    /**
     * Trials this user may read.
     *
     * @return Builder<Trial>
     */
    public static function trials(?User $user): Builder
    {
        $level = self::level($user);

        if ($level === self::LEVEL_ALL) {
            return Trial::query();
        }

        if ($level === self::LEVEL_ORGANIZATION) {
            return self::throughLead(Trial::query(), $user);
        }

        // A trial carries its own owner, so honour it as well as the lead's.
        return Trial::query()->where(function (Builder $q) use ($user) {
            $q->where('assigned_user_id', $user?->id)
                ->orWhereHas('lead', fn (Builder $lq) => $lq->where('assigned_user_id', $user?->id));
        });
    }

    /**
     * Quotations this user may read.
     *
     * `prepared_by_id` points at company_employees, not users, so lead
     * ownership is the reliable rep signal here.
     *
     * @return Builder<CompanyQuotation>
     */
    public static function quotations(?User $user): Builder
    {
        return self::throughLead(CompanyQuotation::query(), $user);
    }

    /**
     * Contacts this user may read.
     *
     * @return Builder<Contact>
     */
    public static function contacts(?User $user): Builder
    {
        $level = self::level($user);

        if ($level === self::LEVEL_ALL) {
            return Contact::query();
        }

        if ($level === self::LEVEL_ORGANIZATION) {
            $orgId = $user?->resolveOrganizationId();

            // Fail closed, as everywhere else in this class.
            if (! $orgId) {
                return Contact::query()->whereRaw('1 = 0');
            }

            // A contact belongs to the tenant either directly, or through the
            // lead it was captured against — a lead-attached contact may have
            // no organization of its own until the deal is won. Reaching it
            // through the lead keeps that case in scope without the blanket
            // `orWhereNull` that also exposed every other tenant's contacts.
            return Contact::query()->where(function (Builder $inner) use ($orgId) {
                $inner->where('organization_id', $orgId)
                    ->orWhereHas('lead', fn (Builder $lq) => $lq->where('organization_id', $orgId));
            });
        }

        return Contact::query()->whereHas('lead', fn (Builder $lq) => $lq->where('assigned_user_id', $user?->id));
    }

    /**
     * Commercial documents this user may read.
     *
     * Ownership follows the lead the document was raised against — a document
     * has no tenant column of its own.
     *
     * @return Builder<CommercialDocument>
     */
    public static function commercialDocuments(?User $user): Builder
    {
        return self::throughLead(CommercialDocument::query(), $user);
    }

    /**
     * Payments this user may read.
     *
     * A payment carries no tenant column of its own — ownership follows the
     * lead it settles, the same rule `quotations()`/`commercialDocuments()`
     * apply.
     *
     * @return Builder<Payment>
     */
    public static function payments(?User $user): Builder
    {
        return self::throughLead(Payment::query(), $user);
    }

    /**
     * Customer tenants (clinics) provisioned from a won deal this user may
     * read — scoped through the same customer accounts `customerAccounts()`
     * already resolves, since a clinic belongs to the customer organization
     * a deal became rather than the vendor tenant that sold it.
     *
     * @return Builder<Clinic>
     */
    public static function clinics(?User $user): Builder
    {
        return Clinic::query()
            ->whereIn('organization_id', self::customerAccounts($user)->select('organizations.id'));
    }

    /**
     * Tasks this user may read.
     *
     * @return Builder<Task>
     */
    public static function tasks(?User $user): Builder
    {
        $level = self::level($user);

        if ($level === self::LEVEL_ALL) {
            return Task::query();
        }

        if ($level === self::LEVEL_ASSIGNED) {
            return Task::query()->where('assigned_user_id', $user?->id);
        }

        /*
         * Constrained on the task's own `organization_id` rather than
         * `assigned_user_id IN (organizationUserIds())` — the latter is
         * exactly how an unassigned task (an automation-created one, or one
         * a rep unassigned) went invisible to every manager in its own
         * tenant. The column now carries the tenant directly, so a manager
         * sees the org's tasks whether or not anyone currently owns them.
         */
        return self::constrainToTenant(Task::query(), $user?->resolveOrganizationId(), 'organization_id');
    }

    /**
     * User ids whose work this user may see. Null means "no restriction".
     *
     * @return list<int>|null
     */
    public static function visibleUserIds(?User $user): ?array
    {
        return match (self::level($user)) {
            self::LEVEL_ALL => null,
            self::LEVEL_ORGANIZATION => self::organizationUserIds($user),
            default => [(int) $user?->id],
        };
    }

    /**
     * Company-portal users inside the acting user's organization.
     *
     * @return list<int>
     */
    public static function organizationUserIds(?User $user): array
    {
        $orgId = $user?->resolveOrganizationId();

        /*
         * Fail closed, and the most important place to do it.
         *
         * Without a tenant this used to return every company-portal user on the
         * platform — and the result feeds `tasks()` and `customerAccounts()`, so
         * one unresolvable organization widened three separate reads at once.
         *
         * The fallback is the user themselves: a manager with no resolvable
         * organization still owns their own work, and seeing only that is the
         * honest answer.
         */
        if (! $orgId) {
            return array_filter([(int) $user?->id]);
        }

        $ids = User::query()
            ->where('user_type', '!=', UserType::ClinicUser)
            ->where('organization_id', $orgId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $ids ?: array_filter([(int) $user?->id]);
    }

    /**
     * Constrain a query that owns both an assignee and a tenant column.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected static function apply(Builder $query, ?User $user, string $assigneeColumn, string $tenantColumn): Builder
    {
        $level = self::level($user);

        if ($level === self::LEVEL_ALL) {
            return $query;
        }

        if ($level === self::LEVEL_ASSIGNED) {
            return $query->where($assigneeColumn, $user?->id);
        }

        // Strict tenant equality. This used to add `orWhereNull($tenantColumn)`
        // because pre-Won leads carried no organization; since Phase 0,
        // createLead() always stamps the creating user's tenant, so that branch
        // only exposed every other tenant's open pipeline to every manager.
        //
        // Records that genuinely have no tenant (legacy rows created before the
        // stamp) remain readable at LEVEL_ALL, so the platform operator can
        // still find and backfill them.
        return self::constrainToTenant($query, $user?->resolveOrganizationId(), $tenantColumn);
    }

    /**
     * Apply a tenant predicate, or refuse.
     *
     * The one place the null-organization case is decided, for every entry
     * point in this class.
     *
     * It used to be `->when($orgId, ...)`, which is fail-*open*: a manager whose
     * organization could not be resolved received no tenant predicate at all and
     * therefore every tenant's rows. That is the same defect the `orWhereNull`
     * branches were removed for, wearing different clothes — a manager is by
     * definition someone with elevated permissions, so the failure mode landed
     * on exactly the accounts that could do most with it.
     *
     * An unresolvable tenant is now an empty result. A reader who should see
     * nothing sees nothing, and the surface can say so.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected static function constrainToTenant(Builder $query, ?int $organizationId, string $tenantColumn): Builder
    {
        if (! $organizationId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($tenantColumn, $organizationId);
    }

    /**
     * Constrain a lead-owned entity by the lead's assignee.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected static function throughLead(Builder $query, ?User $user): Builder
    {
        $level = self::level($user);

        if ($level === self::LEVEL_ALL) {
            return $query;
        }

        if ($level === self::LEVEL_ASSIGNED) {
            return $query->whereHas('lead', fn (Builder $lq) => $lq->where('assigned_user_id', $user?->id));
        }

        $orgId = $user?->resolveOrganizationId();

        // Fail closed. This used to `return $query` unscoped when the tenant
        // could not be resolved, which handed a manager every tenant's
        // quotations, demos and commercial documents.
        if (! $orgId) {
            return $query->whereRaw('1 = 0');
        }

        // Strict tenant equality on the parent lead, for the same reason as
        // apply(): since Phase 0 every lead carries its tenant, so the old
        // null branch only widened the read across tenants.
        return $query->whereHas('lead', fn (Builder $lq) => $lq->where('organization_id', $orgId));
    }
}
