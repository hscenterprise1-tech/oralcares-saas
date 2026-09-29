import { ReactNode } from 'react';
import { usePermissions } from '@/hooks/usePermissions';

interface PermissionGuardProps {
    requires?: string;
    requiresAny?: string[];
    requiresAll?: string[];
    requiresRole?: string;
    children: ReactNode;
    fallback?: ReactNode;
}

export function PermissionGuard({
    requires,
    requiresAny,
    requiresAll,
    requiresRole,
    children,
    fallback = null
}: PermissionGuardProps) {
    const { hasPermission, hasAnyPermission, hasAllPermissions, hasRole, isSuperAdmin } = usePermissions();

    /*
     * Master admins see everything, matching the server's `Gate::before` hook.
     *
     * This tested for a role named `master-admin`, which no role in this
     * application is called — and a master admin holds no roles at all, so the
     * bypass never fired for the user it was written for.
     */
    if (isSuperAdmin) {
        return <>{children}</>;
    }

    let isAuthorized = true;

    if (requiresRole && !hasRole(requiresRole)) {
        isAuthorized = false;
    }

    if (requires && !hasPermission(requires)) {
        isAuthorized = false;
    }

    if (requiresAny && requiresAny.length > 0 && !hasAnyPermission(requiresAny)) {
        isAuthorized = false;
    }

    if (requiresAll && requiresAll.length > 0 && !hasAllPermissions(requiresAll)) {
        isAuthorized = false;
    }

    if (isAuthorized) {
        return <>{children}</>;
    }

    return <>{fallback}</>;
}
