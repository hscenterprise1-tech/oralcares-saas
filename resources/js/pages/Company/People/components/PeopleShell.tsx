import React from 'react';
import { CompanyShell } from '@/pages/Company/design';
import { moduleRail, moduleCreate, moduleSettingsHref } from '@/ovs/layouts/sidebar-configs';

/**
 * People's shell.
 *
 * People is a module in the header now. Until this change its six pages had a
 * rail and no place in the module menu — they were filed under ADMIN beside
 * Packages and Workflows — so once you were inside one, the rail was the only
 * way to reach the others and there was no way in from anywhere else.
 *
 * Roles has left the rail. Deciding what a role may do is platform
 * administration, not HR, and it is reached from Administration → Roles &
 * Permissions. The route `/company/people/roles` is unchanged.
 */
export function PeopleShell({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <CompanyShell
      title={title}
      rail={moduleRail('people')}
      create={moduleCreate('people')}
      settingsHref={moduleSettingsHref('people')}
    >
      {children}
    </CompanyShell>
  );
}
