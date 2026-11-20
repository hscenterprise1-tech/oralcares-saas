import { Link } from '@inertiajs/react';
import { Activity, ClipboardList, Workflow } from 'lucide-react';
import { cn } from '@/lib/utils';

/** The owner's settings pages, as tabs above each one. The hrefs come from the server (admin routes are not in the client route list). */
export function SettingsTabs({ active, links }: { active: 'patient-form' | 'automation' | 'physio'; links: { patient_form?: string; automation?: string; physio?: string | null } }) {
  const tabs = [
    { key: 'patient-form', label: 'Patient form', icon: ClipboardList, href: links.patient_form },
    { key: 'automation', label: 'Automation & messages', icon: Workflow, href: links.automation },
    { key: 'physio', label: 'Physio problems & prices', icon: Activity, href: links.physio },
  ].filter((tab) => tab.href);

  return (
    <nav aria-label="Settings" className="mb-5 flex gap-1.5 overflow-x-auto rounded-[18px] bg-white/70 p-1.5 shadow-[inset_0_0_0_1px_rgba(213,219,230,0.8)] [scrollbar-width:none]">
      {tabs.map((tab) => {
        const Icon = tab.icon;
        const current = tab.key === active;

        return (
          <Link
            key={tab.key}
            href={tab.href as string}
            aria-current={current ? 'page' : undefined}
            className={cn(
              'inline-flex h-10 shrink-0 items-center gap-2 rounded-[12px] px-4 text-[13.5px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35',
              current ? 'bg-white text-cx-nav-ink shadow-cx-card' : 'text-[#5F6B7E] hover:bg-white/80 hover:text-cx-nav-ink',
            )}
          >
            <Icon className="h-4 w-4" strokeWidth={1.75} aria-hidden="true" />
            {tab.label}
          </Link>
        );
      })}
    </nav>
  );
}
