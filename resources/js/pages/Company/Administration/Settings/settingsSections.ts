import {
  Banknote,
  Bell,
  Briefcase,
  Building2,
  GaugeCircle,
  Lock,
  Megaphone,
  Plug,
  Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * The Settings hub's own section list.
 *
 * A directory, not a second navigation registry: nothing here declares a
 * destination that is not already declared somewhere else (`COMPANY_MODULES`
 * for the pages this deep-links to, each target route for the permission it
 * enforces). This file only decides which of those existing destinations
 * belongs under which Settings section, exactly the way `SettingsTabs.tsx`
 * already does for Marketing's own six tabs — a config local to one page, not
 * a competitor to `resources/js/ovs/layouts/sidebar-configs.ts`.
 */

export type SettingsTabKey =
  | 'general'
  | 'users-access'
  | 'notifications'
  | 'integrations'
  | 'marketing'
  | 'people'
  | 'crm'
  | 'revenue'
  | 'operations';

export interface SettingsLink {
  id: string;
  label: string;
  description: string;
  /** The route this deep-links to. Absent means nothing exists to link to yet. */
  href?: string;
  /** The exact permission the destination route itself enforces. */
  permission?: string;
}

export interface SettingsSection {
  id: SettingsTabKey;
  label: string;
  icon: LucideIcon;
  description: string;
  links: SettingsLink[];
}

export const SETTINGS_SECTIONS: SettingsSection[] = [
  {
    id: 'general',
    label: 'General & Organization',
    icon: Building2,
    description: 'Company profile, branding and locale.',
    links: [],
  },
  {
    id: 'users-access',
    label: 'Users & Access',
    icon: Lock,
    description: 'Who may sign in, and what each role may do.',
    links: [
      {
        id: 'roles',
        label: 'Roles & Permissions',
        description: 'View every role and what it grants, and assign or revoke one.',
        href: '/company/people/roles',
        permission: 'roles.manage',
      },
    ],
  },
  {
    id: 'notifications',
    label: 'Notifications',
    icon: Bell,
    description: 'What you are notified about, and how.',
    links: [
      {
        id: 'notification-preferences',
        label: 'Notification Preferences',
        description: 'Channels and events for your own account.',
        href: '/notifications/preferences',
      },
    ],
  },
  {
    id: 'integrations',
    label: 'Integrations',
    icon: Plug,
    description: 'Systems OralCares sends to and receives from.',
    links: [
      {
        id: 'whatsapp',
        label: 'WhatsApp',
        description: 'Connected numbers and sending accounts.',
        href: '/company/marketing/whatsapp',
        permission: 'view-marketing',
      },
      {
        id: 'whatsapp-templates',
        label: 'Message Templates',
        description: 'Approved WhatsApp message templates.',
        href: '/company/marketing/templates',
        permission: 'view-marketing',
      },
      {
        id: 'instagram',
        label: 'Instagram',
        description: 'Connected accounts, AI replies and automation.',
        href: '/company/marketing/instagram/settings',
        permission: 'view-marketing',
      },
      {
        id: 'other-integrations',
        label: 'Other Integrations',
        description: 'No other integrations are connected yet.',
      },
    ],
  },
  {
    id: 'marketing',
    label: 'Marketing',
    icon: Megaphone,
    description: 'Sender, pacing, quiet hours and compliance.',
    links: [
      {
        id: 'marketing-settings',
        label: 'Marketing Settings',
        description: 'General, sending & delivery, compliance and safety.',
        href: '/company/marketing/settings',
        permission: 'view-marketing',
      },
    ],
  },
  {
    id: 'people',
    label: 'People',
    icon: Users,
    description: 'The policy attendance, leave and payroll run on.',
    links: [
      {
        id: 'leave-types',
        label: 'Leave Types',
        description: 'The kinds of leave employees may request.',
        href: '/company/people/leave-types',
        permission: 'view-leave',
      },
      {
        id: 'working-calendar',
        label: 'Working Calendar',
        description: 'The working week and company holidays.',
        href: '/company/people/working-calendar',
        permission: 'view-attendance',
      },
    ],
  },
  {
    id: 'crm',
    label: 'CRM',
    icon: Briefcase,
    description: 'Pipeline and sales configuration.',
    links: [],
  },
  {
    id: 'revenue',
    label: 'Revenue',
    icon: Banknote,
    description: 'Billing and finance configuration.',
    links: [],
  },
  {
    id: 'operations',
    label: 'Operations',
    icon: GaugeCircle,
    description: 'Support and platform-operations configuration.',
    links: [],
  },
];
