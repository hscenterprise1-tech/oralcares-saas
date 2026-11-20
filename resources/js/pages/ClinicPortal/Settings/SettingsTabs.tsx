import { LinkTabs } from '@/clinic/ui/Tabs';

/** The addresses of the owner's settings pages (`LinksSettingsPages`). Physio is there only for a clinic that practises physiotherapy. */
export interface SettingsLinks {
  general: string;
  patient_form: string;
  automation: string;
  consents: string;
  whatsapp: string;
  physio: string | null;
}

export type SettingsTab = 'general' | 'patient-form' | 'automation' | 'consents' | 'whatsapp' | 'physio';

/** The owner's settings pages, as tabs above each one. The hrefs come from the server (admin routes are not in the client route list). */
export function SettingsTabs({ active, links }: { active: SettingsTab; links: SettingsLinks }) {
  const tabs: { key: SettingsTab; label: string; href: string | null }[] = [
    { key: 'general', label: 'General', href: links.general },
    { key: 'patient-form', label: 'Patient form', href: links.patient_form },
    { key: 'automation', label: 'Automation & messages', href: links.automation },
    { key: 'consents', label: 'Consents', href: links.consents },
    { key: 'whatsapp', label: 'WhatsApp', href: links.whatsapp },
    { key: 'physio', label: 'Physio problems & prices', href: links.physio },
  ];

  return (
    <LinkTabs
      label="Settings"
      className="mb-5"
      tabs={tabs.filter((tab) => tab.href).map((tab) => ({ key: tab.key, label: tab.label, href: tab.href as string, active: tab.key === active }))}
    />
  );
}
