<?php

namespace App\Services\Core;

use App\Exceptions\ModuleDependencyException;
use App\Exceptions\ModuleNotLicensedException;
use App\Models\Organization;

class ModuleEngine
{
    /**
     * Check if a module is currently licensed AND enabled for the organization.
     */
    public function isEnabled(Organization $organization, string $module): bool
    {
        $license = $organization->license;

        if (! $license) {
            return false;
        }

        return $license->hasModule($module);
    }

    /**
     * Check if a module is licensed (even if currently disabled).
     */
    public function isLicensed(Organization $organization, string $module): bool
    {
        $license = $organization->license;

        if (! $license) {
            return false;
        }

        return $license->isModuleLicensed($module);
    }

    /**
     * Enable a module for an organization.
     *
     * @throws ModuleNotLicensedException
     * @throws ModuleDependencyException
     */
    public function enable(Organization $organization, string $module): void
    {
        $license = $organization->license;

        if (! $license) {
            throw new ModuleNotLicensedException('Organization has no active license.');
        }

        if (! $license->isModuleLicensed($module)) {
            throw new ModuleNotLicensedException("Module '{$module}' is not included in the active license.");
        }

        // Validate dependencies
        $dependencies = config("modules.modules.{$module}.dependencies", []);

        foreach ($dependencies as $dependency) {
            if (! $this->isEnabled($organization, $dependency)) {
                throw new ModuleDependencyException(
                    "Cannot enable module '{$module}'. Missing required dependency: '{$dependency}'."
                );
            }
        }

        $license->enabledModules()
            ->where('module_name', $module)
            ->update(['is_enabled' => true]);
    }

    /**
     * Disable a module for an organization.
     *
     * @throws ModuleDependencyException
     */
    public function disable(Organization $organization, string $module): void
    {
        $license = $organization->license;

        if (! $license) {
            return;
        }

        // Validate reverse dependencies (are there any enabled modules that depend on this one?)
        $allModules = config('modules.modules', []);

        foreach ($allModules as $moduleName => $config) {
            $deps = $config['dependencies'] ?? [];
            if (in_array($module, $deps)) {
                // If the dependent module is currently enabled, we cannot disable the target module
                if ($this->isEnabled($organization, $moduleName)) {
                    throw new ModuleDependencyException(
                        "Cannot disable module '{$module}'. The '{$moduleName}' module depends on it and is currently enabled."
                    );
                }
            }
        }

        $license->enabledModules()
            ->where('module_name', $module)
            ->update(['is_enabled' => false]);
    }
}
