<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /*
         * No demo account.
         *
         * This seeded `test@example.com` with the password `password`, so any
         * install that ran `db:seed` — production included — came up with a
         * working clinic account whose credentials are written in this file.
         * The only administrator this seeder creates is the master admin, and
         * `MasterAdminSeeder` refuses to run without `MASTER_ADMIN_PASSWORD`.
         */
        $this->call([
            CompanyPortalRolesSeeder::class,
            MasterAdminSeeder::class,
            RoleSeeder::class,
            SystemRoleTemplateSeeder::class,
            CompanyOrganizationSeeder::class,
            CompanyRBACSeeder::class,
            GeoMasterDataSeeder::class,

            /*
             * The onboarding sequences. Idempotent, and needed before any
             * customer is provisioned — an account whose clinic type has no
             * template resolves to the default, but only if the default exists.
             */
            OnboardingTemplateSeeder::class,

            // Runs last: several seeders above create overlapping roles with
            // differing CRM permissions, so this one has the final say on which
            // personas hold CRM access.
            CrmRolePermissionSeeder::class,
        ]);
    }
}
