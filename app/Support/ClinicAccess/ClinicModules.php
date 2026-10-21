<?php

namespace App\Support\ClinicAccess;

use App\Models\Clinic;
use App\Models\User;
use App\Services\Provisioning\EntitlementResolver;
use App\Services\Security\SecurityAudit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Whether a separately sold module (`config/clinic_modules.php`) is
 * available to a clinic: a company override, else the plan or an active
 * add-on, else the module's default. One answer for every surface, so
 * selling, gifting or withdrawing a module is a data change, never a code
 * change.
 */
class ClinicModules
{
    public function __construct(
        protected EntitlementResolver $entitlements,
        protected SecurityAudit $audit,
    ) {}

    public function enabled(Clinic $clinic, string $module): bool
    {
        $this->assertKnown($module);

        return $this->all($clinic)[$module];
    }

    /**
     * Every module and whether it is on for the clinic, in one query. Pass
     * the plan's modules when the caller already resolved them.
     *
     * @param  list<string>|null  $planModules
     * @return array<string, bool>
     */
    public function all(Clinic $clinic, ?array $planModules = null): array
    {
        $overrides = DB::table('clinic_module_overrides')->where('clinic_id', $clinic->id)->pluck('enabled', 'module_key');
        $planModules ??= $this->entitlements->forClinic($clinic)['modules'] ?? [];

        return collect((array) config('clinic_modules'))->mapWithKeys(fn (array $definition, string $module) => [$module => match (true) {
            $overrides->has($module) => (bool) $overrides[$module],
            in_array($module, $planModules, true) => true,
            default => (bool) ($definition['default_included'] ?? false),
        }])->all();
    }

    /**
     * OralCares switching a module on or off for one clinic, or back to what
     * the plan says (`null`). Audited.
     */
    public function override(Clinic $clinic, string $module, ?bool $enabled, ?User $by, ?string $reason = null): void
    {
        $this->assertKnown($module);
        $before = DB::table('clinic_module_overrides')->where('clinic_id', $clinic->id)->where('module_key', $module)->value('enabled');

        if ($enabled === null) {
            DB::table('clinic_module_overrides')->where('clinic_id', $clinic->id)->where('module_key', $module)->delete();
        } else {
            DB::table('clinic_module_overrides')->updateOrInsert(
                ['clinic_id' => $clinic->id, 'module_key' => $module],
                ['enabled' => $enabled, 'reason' => $reason, 'set_by' => $by?->id, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $this->audit->record('clinic.module_override', $clinic, ['module' => $module, 'before' => $before === null ? null : (bool) $before, 'after' => $enabled, 'reason' => $reason]);
    }

    private function assertKnown(string $module): void
    {
        if (config("clinic_modules.{$module}") === null) {
            throw new InvalidArgumentException("Unknown clinic module “{$module}”.");
        }
    }
}
