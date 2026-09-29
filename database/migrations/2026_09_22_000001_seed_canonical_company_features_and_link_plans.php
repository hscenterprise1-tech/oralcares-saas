<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `EntitlementResolver` decided which modules a plan grants from a hardcoded
 * PHP array (`PLAN_MODULES`), keyed by the plan's string `key` — not from
 * `company_plans` or the (empty) `company_plan_features` pivot that already
 * exists for exactly this purpose. `PackageFormModal` therefore had nowhere
 * to let an admin pick modules for a package: there was no row to write to.
 *
 * This seeds the 9 canonical features `PLAN_MODULES` already named, and
 * attaches them to the 3 plans it already named, in the exact same
 * combination — so the resolved module list for every existing plan is
 * unchanged before and after. Any plan created since (through the Package
 * UI) is deliberately left with none: an unconfigured plan should grant the
 * least, not silently inherit the starter set.
 */
return new class extends Migration
{
    /**
     * @return array<string, array{name: string, description: string}>
     */
    private function catalogue(): array
    {
        return [
            'appointments' => ['name' => 'Appointments', 'description' => 'Scheduling and calendar management.'],
            'patients' => ['name' => 'Patients', 'description' => 'Patient records and directory.'],
            'billing' => ['name' => 'Billing', 'description' => 'Invoicing and payment collection.'],
            'emr' => ['name' => 'EMR', 'description' => 'Electronic medical records and clinical charting.'],
            'whatsapp' => ['name' => 'WhatsApp', 'description' => 'WhatsApp messaging and reminders.'],
            'inventory' => ['name' => 'Inventory', 'description' => 'Pharmacy and stock management.'],
            'lab' => ['name' => 'Lab', 'description' => 'Laboratory case management.'],
            'xray' => ['name' => 'X-Ray', 'description' => 'Diagnostic imaging.'],
            'analytics' => ['name' => 'Analytics', 'description' => 'Reporting and business analytics.'],
        ];
    }

    /**
     * The exact combination `EntitlementResolver::PLAN_MODULES` hardcoded.
     *
     * @return array<string, list<string>>
     */
    private function planFeatures(): array
    {
        return [
            'starter' => ['appointments', 'patients', 'billing'],
            'growth' => ['appointments', 'patients', 'billing', 'emr', 'whatsapp', 'inventory'],
            'enterprise' => ['appointments', 'patients', 'billing', 'emr', 'whatsapp', 'inventory', 'lab', 'xray', 'analytics'],
        ];
    }

    public function up(): void
    {
        $featureIds = [];

        foreach ($this->catalogue() as $key => $attributes) {
            $existing = DB::table('company_features')->where('key', $key)->first();

            $featureIds[$key] = $existing?->id ?? DB::table('company_features')->insertGetId([
                'key' => $key,
                'name' => $attributes['name'],
                'description' => $attributes['description'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($this->planFeatures() as $planKey => $moduleKeys) {
            $plan = DB::table('company_plans')->where('key', $planKey)->first();

            if (! $plan) {
                continue;
            }

            foreach ($moduleKeys as $moduleKey) {
                $exists = DB::table('company_plan_features')
                    ->where('company_plan_id', $plan->id)
                    ->where('company_feature_id', $featureIds[$moduleKey])
                    ->exists();

                if (! $exists) {
                    DB::table('company_plan_features')->insert([
                        'company_plan_id' => $plan->id,
                        'company_feature_id' => $featureIds[$moduleKey],
                    ]);
                }
            }
        }
    }

    /**
     * Irreversible: once a plan created after this migration might hold its
     * own attachments to these same catalogue rows, detaching or deleting
     * them here could take a customer's genuinely-chosen modules with it.
     */
    public function down(): void {}
};
