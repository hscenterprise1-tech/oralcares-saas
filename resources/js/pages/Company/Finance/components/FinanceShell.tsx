import React from 'react';
import { CompanyShell } from '@/pages/Company/design';
import { moduleRail, moduleCreate, moduleSettingsHref } from '@/ovs/layouts/sidebar-configs';

/**
 * Finance's shell.
 *
 * Rail and create menu come from COMPANY_MODULES, so the dropdown and the rail
 * cannot disagree about what Finance contains.
 *
 * The rail no longer carries Payroll. It pointed at `/company/finance/payroll`,
 * which reads `company_payroll` — the eighteen legacy rows People's own payroll
 * code describes as "shown, counted and never touched" — while the live payroll
 * system is periods and entries under People. The route and the page are
 * untouched; what is gone is the second door onto dead data.
 *
 * Finance has no create shortcuts: raising an invoice or recording a payment is
 * a multi-step workflow that belongs on its own page, and the shell renders no
 * New button when the list is empty.
 */
export function FinanceShell({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <CompanyShell
      title={title}
      rail={moduleRail('finance')}
      create={moduleCreate('finance')}
      settingsHref={moduleSettingsHref('finance')}
    >
      {children}
    </CompanyShell>
  );
}
