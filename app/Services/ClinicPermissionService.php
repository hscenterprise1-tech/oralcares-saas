<?php

namespace App\Services;

class ClinicPermissionService
{
    /**
     * List all standard permissions on the clinic side.
     */
    public function getAvailablePermissions(): array
    {
        return [
            'patients.view',
            'patients.create',
            'patients.edit',
            'patients.delete',
            'billing.view',
            'billing.create',
            'billing.refund',
            'reports.view',
            'reports.export',
            'queue.manage',
            'xray.manage',
            'inventory.manage',
        ];
    }
}
