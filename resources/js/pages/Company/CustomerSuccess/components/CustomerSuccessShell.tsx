import React from 'react';
import { CompanyShell } from '@/pages/Company/design';
import { moduleRail, moduleCreate, moduleSettingsHref } from '@/ovs/layouts/sidebar-configs';

/**
 * Customer Success's shell.
 *
 * Five destinations now, not two. Subscriptions, Usage and Lifecycle used to be
 * a separate top-level module called Account Lifecycle, over the same
 * organisations Accounts lists — `/company/lifecycle/{organization}` and the
 * Account 360 subscription tab are the same record reached two ways. What
 * happens to a customer after they sign is customer success, so they live here.
 * Every route is unchanged.
 *
 * The Lifecycle pages use this same shell, so all five share one rail rather
 * than the two competing ones they had before.
 */
export function CustomerSuccessShell({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <CompanyShell
      title={title}
      rail={moduleRail('customer-success')}
      create={moduleCreate('customer-success')}
      settingsHref={moduleSettingsHref('customer-success')}
    >
      {children}
    </CompanyShell>
  );
}
