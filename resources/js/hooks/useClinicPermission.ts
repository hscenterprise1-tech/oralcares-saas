import { usePage } from '@inertiajs/react';
import { usePermissions } from './usePermissions';

/**
 * The one guard every clinic-portal surface asks.
 *
 * `usePermissions().hasPermission` alone under-grants for a clinic's primary
 * owner: `User::hasClinicPermission()` bypasses every check for them
 * server-side (`ClinicContext::membership()->is_primary_owner`), but that
 * bypass is runtime logic, not a Spatie grant, so it never appears in
 * `auth.user.permissions`. An owner with no role assigned would otherwise see
 * an emptied-out nav and every gated section hidden, despite every route
 * behind them happily letting them through — found by actually loading the
 * dashboard as a fixture owner account rather than assuming the shared hook
 * covered it.
 *
 * Mirrors the idiom `CompanyShell` already uses for its own manager bypass
 * (`isManager || mayCreate(permission)`).
 */
export function useClinicOwner(): boolean {
  const { props } = usePage<{ clinic?: { is_owner?: boolean } | null }>();

  return props.clinic?.is_owner ?? false;
}

export function useClinicPermission() {
  const { hasPermission } = usePermissions();
  const isOwner = useClinicOwner();

  return (permission?: string): boolean => {
    if (! permission) return true;

    return isOwner || hasPermission(permission);
  };
}
