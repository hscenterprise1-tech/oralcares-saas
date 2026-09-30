import { usePage } from '@inertiajs/react';
import type { ClinicContextProps, ClinicPermission, ClinicSharedProps, NavDestination } from './types';

/**
 * The shared Clinic Portal props, typed.
 *
 * The clinic, branch, permissions and navigation all come from the server on
 * every visit. Nothing here is read from or written to browser storage: the
 * tenant a page acts for is whatever the session says, never a value the
 * browser kept.
 */
export function useClinicShared(): ClinicSharedProps {
  return usePage<ClinicSharedProps>().props;
}

export function useClinic(): ClinicContextProps | null {
  return useClinicShared().clinic ?? null;
}

/** The name the clinic chose to be shown by, falling back to its legal name. */
export function clinicDisplayName(clinic: Pick<ClinicContextProps, 'name' | 'branding_name'>): string {
  const branding = clinic.branding_name?.trim();

  return branding ? branding : clinic.name;
}

/**
 * Whether to show something to this user.
 *
 * Presentation only. It reads the permissions the server resolved for the
 * active branch; the route behind every action checks again for itself, so
 * hiding a control here is never what protects it.
 */
export function useCan(): (permission: ClinicPermission) => boolean {
  const permissions = useClinic()?.permissions ?? [];

  return (permission) => permissions.includes(permission);
}

export function useNavigation(): NavDestination[] {
  return useClinic()?.navigation ?? [];
}

/** The role this user holds here, as one label for the account menu. */
export function roleLabel(clinic: Pick<ClinicContextProps, 'roles' | 'is_owner'>): string {
  if (clinic.is_owner) {
    return 'Owner';
  }

  return clinic.roles.length > 0 ? clinic.roles.join(', ') : 'Staff';
}

/** Whether `href` is the current page or a page beneath it. */
export function isCurrent(href: string, currentUrl: string): boolean {
  const path = (value: string): string => {
    try {
      return new URL(value, window.location.origin).pathname.replace(/\/+$/, '');
    } catch {
      return value.split('?')[0].replace(/\/+$/, '');
    }
  };

  const target = path(href);
  const current = path(currentUrl);

  return current === target || current.startsWith(`${target}/`);
}
