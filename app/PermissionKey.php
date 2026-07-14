<?php

namespace App;

enum PermissionKey: string
{
    case ClinicManage = 'clinic.manage';
    case ClinicSettingsManage = 'clinic.settings.manage';
    case BranchManage = 'branch.manage';
    case StaffManage = 'staff.manage';
    case RolesManage = 'roles.manage';
    case ReportsView = 'reports.view';

    /**
     * Get all permission values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Determine if the given permission is valid.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
