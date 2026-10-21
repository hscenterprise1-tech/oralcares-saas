<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Support\ClinicAccess\ClinicModules;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * OralCares switching a separately sold module on or off for one clinic
 * (a pilot, a goodwill grant, a withdrawal), or back to what its plan and
 * add-ons say. Audited by `ClinicModules`.
 */
class ClinicModuleOverride extends Command
{
    protected $signature = 'clinic:module-override
        {clinic : Clinic id}
        {module : Module key from config/clinic_modules.php}
        {--enable : Switch the module on for this clinic}
        {--disable : Switch the module off for this clinic}
        {--clear : Remove the override; the plan, add-ons and default decide}
        {--reason= : Why (kept on the override and in the audit)}';

    protected $description = 'Enable, disable or clear a company override of a clinic module';

    public function handle(ClinicModules $modules): int
    {
        $choices = array_filter([$this->option('enable'), $this->option('disable'), $this->option('clear')]);
        if (count($choices) !== 1) {
            $this->error('Pass exactly one of --enable, --disable or --clear.');

            return self::INVALID;
        }

        $clinic = Clinic::query()->withoutGlobalScopes()->find((int) $this->argument('clinic'));
        if (! $clinic) {
            $this->error('No such clinic.');

            return self::FAILURE;
        }

        $enabled = $this->option('enable') ? true : ($this->option('disable') ? false : null);
        try {
            $modules->override($clinic, (string) $this->argument('module'), $enabled, null, $this->option('reason'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s is now %s for clinic %d.', $this->argument('module'), $modules->enabled($clinic, (string) $this->argument('module')) ? 'available' : 'not available', $clinic->id));

        return self::SUCCESS;
    }
}
