<?php

namespace App\Services;

use App\Events\DepartmentCreated;
use App\Models\Clinic;
use App\Models\ClinicDepartment;

class DepartmentInitializationService
{
    /**
     * Initialize default departments for a clinic.
     */
    public function initializeDefaultDepartments(Clinic $clinic): void
    {
        $defaults = [
            'reception' => 'Reception',
            'doctors' => 'Doctors',
            'billing' => 'Billing',
            'xray' => 'X-Ray Department',
            'lab' => 'Laboratory',
            'admin' => 'Administration',
            'inventory' => 'Inventory Management',
        ];

        foreach ($defaults as $key => $name) {
            $exists = ClinicDepartment::where('clinic_id', $clinic->id)
                ->where('key', $key)
                ->exists();

            if (! $exists) {
                $dept = ClinicDepartment::create([
                    'clinic_id' => $clinic->id,
                    'key' => $key,
                    'name' => $name,
                    'is_active' => true,
                ]);

                event(new DepartmentCreated($dept));
            }
        }
    }
}
