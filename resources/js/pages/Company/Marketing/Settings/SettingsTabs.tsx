import React from 'react';
import { router } from '@inertiajs/react';
import { CrmTabs } from '@/pages/Company/design';

/**
 * The one tab bar for Marketing Settings.
 *
 * Settings is six areas, not six sidebar destinations: connecting a number,
 * syncing templates and setting a daily cap are all the same kind of
 * occasional configuration work. Three of those areas already had their own
 * controllers and routes — WhatsApp and Templates as top-level pages — so the
 * tabs navigate rather than swap client state for those two. That keeps
 * template pagination, template sync and the account lifecycle exactly as
 * they were while still presenting one workspace.
 *
 * The remaining areas are plain settings on a single record, so they are
 * in-page state on the Settings route itself and cost nothing to switch
 * between.
 */

export type SettingsTabKey = 'general' | 'whatsapp' | 'templates' | 'sending' | 'compliance' | 'safety';

const ROUTES: Partial<Record<SettingsTabKey, string>> = {
  whatsapp: '/company/marketing/whatsapp',
  templates: '/company/marketing/templates',
};

const SETTINGS_ROUTE = '/company/marketing/settings';

export function SettingsTabs({
  active,
  onSelectLocal,
}: {
  active: SettingsTabKey;
  /** Provided by the Settings page for the tabs it renders itself. */
  onSelectLocal?: (tab: SettingsTabKey) => void;
}) {
  const handleChange = (next: SettingsTabKey) => {
    if (next === active) {
      return;
    }

    const externalRoute = ROUTES[next];

    if (externalRoute) {
      router.visit(externalRoute);

      return;
    }

    // A settings-record tab. Switch in place when we are already on that
    // page; otherwise come back to it with the tab preselected.
    if (onSelectLocal) {
      onSelectLocal(next);

      return;
    }

    router.visit(`${SETTINGS_ROUTE}?tab=${next}`);
  };

  return (
    <div className="mb-4">
      <CrmTabs
        value={active}
        onChange={handleChange}
        tabs={[
          { key: 'general', label: 'General' },
          { key: 'whatsapp', label: 'WhatsApp Accounts' },
          { key: 'templates', label: 'Message Templates' },
          { key: 'sending', label: 'Sending & Delivery' },
          { key: 'compliance', label: 'Compliance' },
          { key: 'safety', label: 'Safety' },
        ]}
      />
    </div>
  );
}
