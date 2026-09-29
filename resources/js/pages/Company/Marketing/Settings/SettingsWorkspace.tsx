import React from 'react';
import { CrmPageHead } from '@/pages/Company/design';
import { SettingsTabs, type SettingsTabKey } from './SettingsTabs';

/**
 * The header every section of Marketing Settings shares.
 *
 * Six sections sit behind one tab strip, and two of them — WhatsApp Accounts
 * and Message Templates — are separate routes rather than in-page state. That
 * makes the strip's vertical position easy to break: Templates carried two
 * figures in its title block and the other five did not, so the strip sat 69px
 * lower there and appeared to jump as you moved between sections that are
 * meant to read as one screen.
 *
 * So the head takes a title and actions, and nothing else. A section with
 * figures worth showing puts them in its own body, below the strip, where
 * every other section already puts its content — which keeps the strip's
 * offset a property of the workspace instead of a side effect of which
 * section you happen to be on.
 */
export function SettingsWorkspaceHead({
  title,
  actions,
  active,
  onSelectLocal,
}: {
  title: string;
  actions?: React.ReactNode;
  active: SettingsTabKey;
  /** Present only on the in-page sections; the route-backed ones navigate. */
  onSelectLocal?: (key: SettingsTabKey) => void;
}) {
  return (
    <>
      <CrmPageHead eyebrow="Marketing" title={title} actions={actions} />
      <SettingsTabs active={active} onSelectLocal={onSelectLocal} />
    </>
  );
}
