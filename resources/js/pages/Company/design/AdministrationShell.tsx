import React from 'react';
import { CompanyShell } from './CrmShell';
import { moduleRail, moduleCreate, moduleSettingsHref } from '@/ovs/layouts/sidebar-configs';

/**
 * Administration's shell.
 *
 * The module had no shell at all, which is why its pages had invented three
 * private ones: Security's rail listed Security, Roles and Audit; Workflows'
 * listed Workflows, Integrations and Security; Integrations' listed
 * Integrations, "Marketing" and Security. Three hand-written menus, none of
 * them the whole module, each pointing at a different two of its neighbours.
 *
 * It lives in `design/` rather than under a module folder because
 * Administration's pages are spread across four directories — Security,
 * Workflow, Integrations, Admin, plus Roles under People — and none of them
 * owns the others.
 */
export function AdministrationShell({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <CompanyShell
      title={title}
      rail={moduleRail('administration')}
      create={moduleCreate('administration')}
      settingsHref={moduleSettingsHref('administration')}
    >
      {children}
    </CompanyShell>
  );
}
