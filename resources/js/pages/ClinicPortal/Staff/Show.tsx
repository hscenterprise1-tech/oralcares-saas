import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Documents } from './components/Documents';
import { Professional } from './components/Professional';
import { ProfileHeader } from './components/ProfileHeader';
import { BranchesAndSchedule } from './components/Schedule';
import { Access, Activity, Appointments, LeaveSection, Overview } from './components/Sections';
import { Services } from './components/Services';
import type { ProfileProps } from './lib/types';

/**
 * A staff member's profile. Practitioners get the professional sections
 * (qualifications, specialty, registration, services, schedule, leave,
 * appointments); other staff a simpler set. Every section is on the page,
 * so switching tabs needs no round trip.
 */
export default function StaffShow(props: ProfileProps) {
  const practitioner = props.practitioner !== null;
  const tabs = practitioner
    ? [
        { key: 'overview', label: 'Overview' },
        { key: 'professional', label: 'Professional' },
        { key: 'services', label: 'Services' },
        { key: 'schedule', label: 'Branches & schedule' },
        { key: 'leave', label: 'Leave' },
        { key: 'appointments', label: 'Appointments' },
        { key: 'documents', label: 'Documents' },
        { key: 'access', label: 'Account & access' },
      ]
    : [
        { key: 'overview', label: 'Overview' },
        { key: 'access', label: 'Access' },
        { key: 'schedule', label: 'Branches' },
        { key: 'documents', label: 'Documents' },
        { key: 'leave', label: 'Leave' },
        { key: 'activity', label: 'Activity' },
      ];
  const [tab, setTab] = useState(() => (tabs.some((item) => item.key === props.tab) ? props.tab : 'overview'));

  const choose = (key: string) => {
    setTab(key);
    const url = new URL(window.location.href);
    url.searchParams.set('tab', key);
    window.history.replaceState(window.history.state, '', url);
  };

  return (
    <ClinicPortalShell title={props.employee.name}>
      <Link href={props.links.index} className="mb-3 inline-flex min-h-11 items-center gap-1.5 rounded-full text-[12.5px] font-semibold text-clinic-secondary hover:text-clinic-ink">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Staff
      </Link>

      <ProfileHeader props={props} />

      <div role="tablist" aria-label="Profile sections" className="mb-4 flex max-w-full gap-0.5 overflow-x-auto rounded-full border border-white/70 bg-white/[0.93] p-1 backdrop-blur-sm [scrollbar-width:none]">
        {tabs.map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={tab === item.key}
            onClick={() => choose(item.key)}
            className={cn(
              'h-11 shrink-0 whitespace-nowrap rounded-full px-3.5 text-[12.5px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:h-9',
              tab === item.key ? 'bg-clinic-ink text-white' : 'text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink',
            )}
          >
            {item.label}
          </button>
        ))}
      </div>

      <div role="tabpanel" aria-label={tabs.find((item) => item.key === tab)?.label}>
        {tab === 'overview' && <Overview props={props} onTab={choose} />}
        {tab === 'professional' && practitioner && <Professional props={props} />}
        {tab === 'services' && practitioner && <Services props={props} />}
        {tab === 'schedule' && <BranchesAndSchedule props={props} />}
        {tab === 'leave' && <LeaveSection props={props} />}
        {tab === 'appointments' && practitioner && <Appointments props={props} />}
        {tab === 'documents' && <Documents props={props} />}
        {tab === 'access' && <Access props={props} />}
        {tab === 'activity' && <Activity props={props} />}
      </div>
    </ClinicPortalShell>
  );
}
