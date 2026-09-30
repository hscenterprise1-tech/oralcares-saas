<?php

namespace App\Console\Commands;

use App\Models\User;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Assigns a CRM role to a company-portal user.
 *
 * Role assignment is deliberately a manual, explicit operation rather than
 * something a seeder does automatically: granting CRM access decides who can
 * see the commercial pipeline, so it should never be a side effect of running
 * migrations. Phase 0 found zero company users with roles, and the fix for that
 * is an operator running this per user — not a script guessing intent.
 */
class AssignCrmRoleCommand extends Command
{
    protected $signature = 'crm:assign-role
                            {email : The company-portal user\'s email address}
                            {role : Role to assign (e.g. "Sales Representative", "CRM Manager")}
                            {--list : List assignable CRM roles and exit}';

    protected $description = 'Assign a CRM role to a company-portal user';

    public function handle(): int
    {
        if ($this->option('list')) {
            $this->listRoles();

            return self::SUCCESS;
        }

        $email = $this->argument('email');
        $roleName = $this->argument('role');

        if (! array_key_exists($roleName, CrmRolePermissionSeeder::ROLE_MAP)) {
            $this->error("Unknown role \"{$roleName}\".");
            $this->listRoles();

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email \"{$email}\".");

            return self::FAILURE;
        }

        $userType = is_object($user->user_type) && isset($user->user_type->value)
            ? $user->user_type->value
            : (string) $user->user_type;

        if (! in_array($userType, [UserType::MasterAdmin->value, UserType::CompanyUser->value], true)) {
            $this->error("{$email} is a \"{$userType}\", not a company-portal user. CRM roles cannot be assigned to clinic tenants.");

            return self::FAILURE;
        }

        if ($userType === UserType::MasterAdmin->value) {
            $this->warn("{$email} is a master admin and already bypasses CRM permission checks. Assigning the role anyway for completeness.");
        }

        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

        if (! $role) {
            $this->error("Role \"{$roleName}\" does not exist. Run: php artisan db:seed --class=CrmRolePermissionSeeder");

            return self::FAILURE;
        }

        // Spatie runs in teams mode (branch_id); team 0 is the company scope.
        setPermissionsTeamId(0);

        $user->assignRole($role);
        $user->forgetCachedPermissions();

        $granted = collect(CrmRolePermissionSeeder::ROLE_MAP[$roleName]);

        $this->info("Assigned \"{$roleName}\" to {$user->name} <{$email}>.");
        $this->line($granted->isEmpty()
            ? '  This role carries no CRM permissions by design.'
            : '  CRM permissions granted: '.$granted->implode(', '));

        return self::SUCCESS;
    }

    protected function listRoles(): void
    {
        $this->line('');
        $this->line('Assignable roles:');

        foreach (CrmRolePermissionSeeder::ROLE_MAP as $name => $permissions) {
            $summary = empty($permissions)
                ? '<comment>no CRM access (by design)</comment>'
                : count($permissions).' CRM permissions';

            $this->line("  • {$name} — {$summary}");
        }

        $this->line('');
    }
}
