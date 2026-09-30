import { usePage } from '@inertiajs/react';
import { PageProps } from '../types';
import { usePreview } from '@/contexts/PreviewContext';

/**
 * The one place the frontend decides what a user may see.
 *
 * Two rules, and only two. A master admin is authorized for everything,
 * because the server's `Gate::before` hook says so and the menu must not
 * disagree with the routes. Everybody else is filtered by the permissions they
 * actually hold.
 *
 * The bypass reads `auth.user.is_super_admin`, which the server derives from
 * the same `isMasterAdmin()` call `Gate::before` uses. It deliberately does not
 * read role names: this application has no role called `Super Admin` or
 * `master-admin`, so every check written against those names was dead, and a
 * master admin — who holds no roles at all — failed all of them.
 */

// Dummy permission map for simulation
const SIMULATED_ROLE_PERMISSIONS: Record<string, string[]> = {
    'master-admin': ['view-patients', 'view-appointments', 'view-finance', 'view-reports', 'manage-users'],
    'clinic-owner': ['view-patients', 'view-appointments', 'view-finance', 'view-reports'],
    'doctor': ['view-patients', 'view-appointments'],
    'reception': ['view-patients', 'view-appointments', 'manage-billing'],
    'finance': ['view-finance', 'view-reports'],
};

export function usePermissions() {
    const { auth } = usePage<PageProps>().props;
    const { previewState } = usePreview();

    // If preview is enabled (development only), intercept the permissions
    const isPreview = previewState.isEnabled;

    const roles = isPreview ? [previewState.role] : (auth?.user?.roles ?? []);

    let permissions = auth?.user?.permissions ?? [];
    if (isPreview) {
        permissions = SIMULATED_ROLE_PERMISSIONS[previewState.role] || [];
    }

    /*
     * Preview simulation wins over the bypass.
     *
     * The whole point of previewing as a receptionist is to see what a
     * receptionist sees, so a real master admin must not keep their bypass
     * while simulating somebody else.
     */
    const isSuperAdmin = !isPreview && auth?.user?.is_super_admin === true;

    const hasPermission = (permission: string) => isSuperAdmin || permissions.includes(permission);

    const hasAnyPermission = (permissionsToCheck: string[]) => {
        return isSuperAdmin || permissionsToCheck.some(permission => permissions.includes(permission));
    };

    const hasAllPermissions = (permissionsToCheck: string[]) => {
        return isSuperAdmin || permissionsToCheck.every(permission => permissions.includes(permission));
    };

    const hasRole = (role: string) => roles.includes(role);

    return {
        permissions,
        roles,
        isSuperAdmin,
        hasPermission,
        hasAnyPermission,
        hasAllPermissions,
        hasRole
    };
}
