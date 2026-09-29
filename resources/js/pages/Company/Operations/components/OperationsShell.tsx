import React from 'react';
import { CompanyShell } from '@/pages/Company/design';
import { moduleRail, moduleCreate, moduleSettingsHref } from '@/ovs/layouts/sidebar-configs';

/**
 * Operations' shell.
 *
 * Two destinations: Support and Reports.
 *
 * Notifications left for the header's bell — the operations page is delivery
 * health, and the entry that sat beside it was the reader's own inbox, which is
 * a personal utility rather than a business destination. Audit left for
 * Administration, where the platform's other oversight surfaces are. Both
 * routes are unchanged.
 */
export function OperationsShell({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <CompanyShell
      title={title}
      rail={moduleRail('operations')}
      create={moduleCreate('operations')}
      settingsHref={moduleSettingsHref('operations')}
    >
      {children}
    </CompanyShell>
  );
}
