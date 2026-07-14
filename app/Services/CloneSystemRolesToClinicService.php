<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\Role;

class CloneSystemRolesToClinicService
{
    /**
     * Clone all active system role templates into a clinic.
     */
    public function execute(Clinic $clinic): void
    {
        $systemTemplates = Role::whereNull('clinic_id')
            ->where('is_system', true)
            ->where('is_active', true)
            ->with('permissions')
            ->get();

        foreach ($systemTemplates as $template) {
            // Check if the clinic already has a role cloned from this template
            // or has a role with the same key.
            // If it exists, do not overwrite it.
            $existingRole = Role::where('clinic_id', $clinic->id)
                ->where(function ($query) use ($template) {
                    $query->where('key', $template->key)
                        ->orWhere('source_system_role_id', $template->id);
                })
                ->exists();

            if (! $existingRole) {
                // Clone the role
                $newRole = Role::create([
                    'clinic_id' => $clinic->id,
                    'key' => $template->key,
                    'name' => $template->name,
                    'description' => $template->description,
                    'is_system' => false,
                    'is_active' => true,
                    'source_system_role_id' => $template->id,
                ]);

                // Copy permissions
                foreach ($template->permissions as $permMapping) {
                    $newRole->permissions()->create([
                        'permission' => $permMapping->permission,
                    ]);
                }
            }
        }
    }
}
