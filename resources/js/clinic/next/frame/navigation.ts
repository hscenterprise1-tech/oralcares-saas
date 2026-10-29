import {
  Bell,
  BarChart3,
  Building2,
  CalendarDays,
  CalendarHeart,
  Hourglass,
  LayoutGrid,
  LifeBuoy,
  Receipt,
  Settings,
  ShieldCheck,
  Users,
  UsersRound,
  type LucideIcon,
} from 'lucide-react';
import type { ClinicContextProps, NavDestination } from '../../types';

/** The server's icon keys (`ClinicNavigation`), drawn in the new frame's icon set. */
const ICONS: Record<string, LucideIcon> = {
  dashboard: LayoutGrid,
  appointments: CalendarDays,
  patients: Users,
  waiting: Hourglass,
  billing: Receipt,
  reports: BarChart3,
  'follow-ups': CalendarHeart,
  notifications: Bell,
  support: LifeBuoy,
  shield: ShieldCheck,
  staff: UsersRound,
  branches: Building2,
  settings: Settings,
};

export interface FramePlace {
  key: string;
  label: string;
  icon: LucideIcon;
  /** Null when the place has no screen yet: listed, but not a link. */
  href: string | null;
  current: boolean;
}

/** Whether a destination is the page being shown: its own address, or one below it. */
function isCurrent(destination: NavDestination, currentPath: string): boolean {
  if (!destination.href) {
    return false;
  }
  const path = new URL(destination.href, 'http://x').pathname.replace(/\/$/, '');

  return currentPath === path || currentPath.startsWith(`${path}/`);
}

/**
 * The frame's places, adapted from the server's resolved navigation: the
 * frame decides nothing about access, it only draws what the server sent.
 * `work` are the daily places (spine and bottom bar), `more` the rest,
 * `admin` the account menu's.
 */
export function framePlaces(clinic: ClinicContextProps, currentUrl: string): { work: FramePlace[]; more: FramePlace[]; admin: FramePlace[] } {
  const currentPath = new URL(currentUrl, 'http://x').pathname.replace(/\/$/, '');
  const toPlace = (destination: NavDestination): FramePlace => ({
    key: destination.key,
    label: destination.label,
    icon: ICONS[destination.icon] ?? LayoutGrid,
    href: destination.available ? destination.href : null,
    current: isCurrent(destination, currentPath),
  });

  return {
    work: clinic.navigation.filter((d) => d.section === 'main').map(toPlace),
    more: clinic.navigation.filter((d) => d.section === 'additional').map(toPlace),
    admin: clinic.navigation.filter((d) => d.section === 'admin').map(toPlace),
  };
}

/** The patients destination, which the frame's search opens with a query. */
export function patientsHref(clinic: ClinicContextProps): string | null {
  const patients = clinic.navigation.find((d) => d.key === 'patients');

  return patients?.available ? patients.href : null;
}
