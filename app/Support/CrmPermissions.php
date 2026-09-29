<?php

namespace App\Support;

use App\Models\User;
use App\UserType;

/**
 * Single source of truth for CRM permission resolution.
 *
 * Four permission naming conventions exist in the database, seeded by different
 * seeders over time:
 *
 *   kebab   view-leads          CompanyPortalRolesSeeder  (production convention)
 *   dot     leads.view          CompanyRBACSeeder
 *   snake   view_crm            RoleSeeder / SystemRoleTemplateSeeder
 *   spaced  view own leads      CompanyPortalRolesSeeder
 *
 * Rather than rewrite every seeded role in Phase 0 — which would lock out real
 * users mid-flight — the kebab-case name is canonical and the equivalent legacy
 * names are accepted as aliases. Collapsing the seeders onto one convention is
 * a later-phase change; this class is the only place that knowledge lives.
 *
 * Master admins bypass all of this via the Gate::before hook in
 * AppServiceProvider.
 */
class CrmPermissions
{
    /**
     * Canonical CRM permission => legacy names that also satisfy it.
     *
     * @var array<string, list<string>>
     */
    public const ALIASES = [
        'view-crm' => ['crm.view', 'view_crm'],
        'view-leads' => ['leads.view', 'crm.view', 'view_crm', 'manage_leads', 'view own leads'],
        'view-pipeline' => ['leads.view', 'crm.view', 'view_crm', 'manage_leads'],
        'view-demos' => ['crm.view', 'view_crm', 'manage_leads'],
        'view-trials' => ['trials.manage', 'crm.view', 'view_crm'],
        'view-quotations' => ['crm.view', 'view_crm'],
        'view-operations' => ['crm.view', 'view_crm'],
        'view-contacts' => ['crm.view', 'view_crm', 'manage_leads'],
        'view-tasks' => ['tasks.view', 'tasks.manage', 'crm.view', 'view_crm'],
        'view-onboarding' => ['onboarding.manage', 'view-pending-onboarding'],
        'view-organizations' => ['crm.view', 'view_crm'],
        'view-subscriptions' => ['subscriptions.view', 'subscriptions.manage'],
        'view-renewals' => ['renewals.manage', 'subscriptions.manage'],
        'view-plans' => ['subscriptions.manage', 'crm.view', 'view_crm'],

        // Mutations
        /*
         * Deliberately narrow aliases. `manage-trials` and `trials.provision`
         * exist to separate reading the trials board from acting on a
         * customer's live environment, so aliasing either to `crm.view` or
         * `view_crm` — which is what `view-trials` accepts — would hand the
         * authority straight back to every read-only user and undo the split.
         */
        'manage-trials' => ['trials.manage', 'crm.update', 'manage_leads', 'leads.update'],
        /*
         * Deliberately excludes `crm.view` and `view_crm`, which is what
         * `view-quotations` accepts — aliasing to those would hand editing back
         * to every read-only user and undo the separation.
         */
        'manage-quotations' => ['quotations.manage', 'crm.update', 'manage_leads', 'leads.update'],
        /*
         * Deliberately narrow aliases for Customer Success account mutations.
         * Excludes `view-organizations`, `view-crm`, and `view_crm` so reading an
         * account does not grant authority to rewrite health metrics, advance
         * onboarding, or alter clinic/branch structure.
         */
        'manage-organizations' => ['organizations.manage', 'cs.manage', 'manage_organizations'],
        /*
         * Separate from editing a quotation on purpose. Writing down that money
         * arrived is a finance act, not a sales one — the person who priced the
         * deal should not necessarily be the person who says it was paid.
         */
        'record-payment' => ['payments.record', 'payments.manage', 'subscriptions.manage'],
        'trials.provision' => ['trials.manage', 'onboarding.manage'],
        'create leads' => ['leads.create', 'crm.create', 'manage_leads'],
        'edit own leads' => ['leads.update', 'crm.update', 'manage_leads'],
        'convert leads' => ['crm.update', 'manage_leads'],
        'manage-employees' => ['teams.manage'],
    ];

    /**
     * Whether the user holds a CRM permission, accepting legacy aliases.
     */
    public static function check(?User $user, string $permission): bool
    {
        if (! $user || ! static::isCompanyPortalUser($user)) {
            return false;
        }

        $candidates = array_merge([$permission], static::ALIASES[$permission] ?? []);

        foreach ($candidates as $candidate) {
            if ($user->can($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user holds ANY of the supplied CRM permissions.
     */
    public static function checkAny(?User $user, string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (static::check($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only master admins and company employees may reach CRM at all — this
     * keeps clinic-tenant users out regardless of any permission grant.
     */
    public static function isCompanyPortalUser(User $user): bool
    {
        $type = is_object($user->user_type) && isset($user->user_type->value)
            ? $user->user_type->value
            : (string) $user->user_type;

        return in_array($type, [UserType::MasterAdmin->value, UserType::CompanyUser->value], true);
    }
}
