import React from 'react';
import { CompanyShell } from '@/pages/Company/design';
import { moduleRail, moduleCreate, moduleSettingsHref } from '@/ovs/layouts/sidebar-configs';

/**
 * Marketing's shell.
 *
 * The chrome is the company's — the same full-width header, module nav and
 * floating rail every module uses — so moving between modules never changes
 * where anything is. Only the rail's destinations and the create menu are
 * Marketing's own, and both are now read from COMPANY_MODULES rather than
 * written out again here.
 *
 * That list used to be a second copy, and it had already drifted: Execution
 * Logs was in the dropdown and not in the rail with nothing recording that as
 * a decision. It still is not in the rail — a run log is somewhere you go when
 * an automation misbehaves — but now the registry says so, once, where both
 * menus can read it.
 *
 * Settings stays out of the rail's body and sits in its own slot below the
 * divider, from `settingsHref`; listing it in both rendered two identical
 * Settings icons a divider apart.
 */
export function MarketingShell({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <CompanyShell
      title={title}
      rail={moduleRail('marketing')}
      create={moduleCreate('marketing')}
      settingsHref={moduleSettingsHref('marketing')}
    >
      {children}
    </CompanyShell>
  );
}
