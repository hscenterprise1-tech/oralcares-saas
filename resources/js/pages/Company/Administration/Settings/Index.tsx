import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { AdministrationShell, CrmPageHead, CrmSection, CrmDataState, CrmTabs } from '@/pages/Company/design';
import { useNavAuthorization } from '@/hooks/useNavAuthorization';
import { SETTINGS_SECTIONS, type SettingsLink, type SettingsTabKey } from './settingsSections';

interface SettingsIndexProps {
  tab?: string;
}

const TAB_IDS = SETTINGS_SECTIONS.map((section) => section.id);

function isSettingsTab(value: string | undefined): value is SettingsTabKey {
  return !!value && (TAB_IDS as string[]).includes(value);
}

/** One row: a real destination to follow, or — with no `href` — not built yet. */
function SettingsLinkRow({ link }: { link: SettingsLink }) {
  if (!link.href) {
    return (
      <div className="flex items-center justify-between gap-3 rounded-[18px] bg-ovs-crm-surface-inset px-4 py-3">
        <div className="min-w-0">
          <p className="text-[13px] font-medium text-ovs-crm-text-primary">{link.label}</p>
          <p className="mt-0.5 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">{link.description}</p>
        </div>
        <span className="shrink-0 rounded-full bg-white px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-ovs-crm-text-muted">
          Not available
        </span>
      </div>
    );
  }

  return (
    <Link
      href={link.href}
      className="group flex items-center justify-between gap-3 rounded-[18px] border border-ovs-crm-border-soft bg-white px-4 py-3 transition-colors hover:bg-ovs-crm-surface-inset"
    >
      <div className="min-w-0">
        <p className="text-[13px] font-semibold text-ovs-crm-text-primary">{link.label}</p>
        <p className="mt-0.5 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">{link.description}</p>
      </div>
      <ArrowUpRight
        className="h-4 w-4 shrink-0 text-ovs-crm-text-secondary transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
        aria-hidden="true"
      />
    </Link>
  );
}

export default function AdministrationSettingsIndex({ tab }: SettingsIndexProps) {
  const [activeTab, setActiveTab] = useState<SettingsTabKey>(isSettingsTab(tab) ? tab : 'general');
  const isAuthorized = useNavAuthorization();

  const section = SETTINGS_SECTIONS.find((candidate) => candidate.id === activeTab) ?? SETTINGS_SECTIONS[0];
  const visibleLinks = section.links.filter((link) => isAuthorized(link.permission));
  const hiddenByPermission = section.links.length > 0 && visibleLinks.length === 0;

  return (
    <AdministrationShell title="Settings">
      <CrmPageHead
        eyebrow="Administration"
        title="Settings"
        description="The one place to configure every module — WhatsApp, roles, leave policy and more — instead of hunting through each one for its own settings page."
      />

      <div className="mb-4">
        <CrmTabs
          value={activeTab}
          onChange={setActiveTab}
          tabs={SETTINGS_SECTIONS.map((candidate) => ({ key: candidate.id, label: candidate.label }))}
        />
      </div>

      <CrmSection title={section.label} description={section.description}>
        {visibleLinks.length > 0 ? (
          <div className="space-y-2">
            {visibleLinks.map((link) => (
              <SettingsLinkRow key={link.id} link={link} />
            ))}
          </div>
        ) : hiddenByPermission ? (
          <CrmDataState
            kind="empty"
            compact
            title="Nothing here for your role"
            description="You don't hold the permission any of this section's settings require."
          />
        ) : (
          <CrmDataState
            kind="empty"
            compact
            title="Not yet configurable"
            description="No settings exist for this area yet."
          />
        )}
      </CrmSection>
    </AdministrationShell>
  );
}
