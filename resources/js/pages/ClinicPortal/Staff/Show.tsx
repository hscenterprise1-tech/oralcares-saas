import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button, ButtonLink } from '@/clinic/ui/Button';
import { Modal } from '@/clinic/ui/Modal';
import { PersonAvatar } from '@/clinic/ui/PersonAvatar';
import { PillTabs } from '@/clinic/ui/Tabs';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { ActivityTab, AppointmentsTab, DocumentsTab, LeaveTab, OverviewTab, ProfessionalTab, ScheduleTab, ServicesTab } from './components/ProfileTabs';
import type { Profile } from './components/types';

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';
const STATUS_TONE: Record<string, string> = {
  active: 'bg-cx-green-bg text-cx-green-ink',
  verified: 'bg-cx-green-bg text-cx-green-ink',
  invited: 'bg-cx-amber-bg text-cx-amber-ink',
  pending_invitation: 'bg-cx-amber-bg text-cx-amber-ink',
  suspended: 'bg-cx-rose-bg text-cx-rose-ink',
};

export default function StaffShow(props: Profile) {
  return (
    <ClinicPortalShell title={props.employee.name}>
      <StaffProfile {...props} />
    </ClinicPortalShell>
  );
}

/** One staff member: details, sign-in, and for a practitioner their professional record, services, hours and documents. */
function StaffProfile(p: Profile) {
  const e = p.employee;
  const practitioner = p.practitioner !== null;
  const tabs = [
    { key: 'overview', label: 'Overview' },
    ...(practitioner ? [{ key: 'professional', label: 'Professional' }, { key: 'services', label: 'Services' }, { key: 'schedule', label: 'Hours' }] : []),
    { key: 'documents', label: 'Documents', badge: p.documents.summary.attention > 0 ? p.documents.summary.attention : undefined },
    { key: 'leave', label: 'Leave' },
    ...(p.can.appointments && practitioner ? [{ key: 'appointments', label: 'Appointments' }] : []),
    { key: 'activity', label: 'Activity' },
  ];
  const [tab, setTab] = useState(tabs.some((t) => t.key === p.tab) ? p.tab : 'overview');
  const [confirm, setConfirm] = useState<'inactive' | 'resigned' | 'suspended' | null>(null);
  const [busy, setBusy] = useState(false);
  const away = ['inactive', 'suspended', 'resigned'].includes(e.status ?? '');

  const setStatus = (status: string, done?: () => void) =>
    router.patch(`${p.links.self}/status`, { status }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); done?.(); } });

  return (
    <div className="w-full pb-12">
      <header className={cn(SURFACE, 'mb-4 flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between')}>
        <div className="flex min-w-0 items-center gap-4">
          {e.photo_url ? <img src={e.photo_url} alt="" className="h-14 w-14 shrink-0 rounded-full object-cover" /> : <PersonAvatar name={e.name} size="lg" />}
          <div className="min-w-0">
            <h1 className="break-words text-[20px] font-semibold leading-7 tracking-[-0.01em] text-cx-nav-ink">{e.name}</h1>
            <p className="text-[12.5px] text-cx-text-3">{[e.staff_type_label, p.practitioner?.headline].filter(Boolean).join(' · ') || 'Staff'}</p>
            <div className="mt-1.5 flex flex-wrap gap-1.5">
              <span className={cn('rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', STATUS_TONE[e.status ?? ''] ?? 'bg-cx-slate-bg text-cx-slate-ink')}>{humanize(e.status === 'resigned' ? 'archived' : e.status)}</span>
              {e.roles.map((role) => <span key={role} className="rounded-full bg-cx-slate-bg px-2.5 py-0.5 text-[11.5px] font-semibold text-cx-slate-ink">{humanize(role)}</span>)}
            </div>
          </div>
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2.5">
          {p.can.edit && !e.is_self && (away ? (
            <Button onClick={() => setStatus('active')} loading={busy}>Reactivate</Button>
          ) : (
            <>
              <Button onClick={() => setConfirm('suspended')}>Suspend</Button>
              <Button onClick={() => setConfirm('inactive')}>Deactivate</Button>
            </>
          ))}
          {p.can.edit && !e.is_self && e.status !== 'resigned' && <Button onClick={() => setConfirm('resigned')}>Archive</Button>}
          {p.can.edit && <ButtonLink href={p.links.edit} tone="primary" icon={Pencil}>Edit</ButtonLink>}
        </div>
      </header>

      <PillTabs
        label="Staff profile"
        value={tab}
        onChange={setTab}
        options={tabs.map((t) => ({ key: t.key, label: t.label, count: t.badge }))}
        className="mb-4"
      />

      {tab === 'overview' && <OverviewTab p={p} />}
      {tab === 'professional' && <ProfessionalTab p={p} />}
      {tab === 'services' && <ServicesTab p={p} />}
      {tab === 'schedule' && <ScheduleTab p={p} />}
      {tab === 'documents' && <DocumentsTab p={p} />}
      {tab === 'leave' && <LeaveTab p={p} />}
      {tab === 'appointments' && <AppointmentsTab p={p} />}
      {tab === 'activity' && <ActivityTab p={p} />}

      {confirm && (
        <Modal
          open
          onOpenChange={(open) => !open && setConfirm(null)}
          size="sm"
          title={confirm === 'resigned' ? `Archive ${e.name}?` : confirm === 'suspended' ? `Suspend ${e.name}?` : `Deactivate ${e.name}?`}
          description={confirm === 'resigned' ? 'They leave the active staff and can no longer sign in. Their record and history stay.' : 'They cannot sign in until you reactivate them. Their record and history stay.'}
          footer={
            <>
              <Button onClick={() => setConfirm(null)} disabled={busy}>Cancel</Button>
              <Button tone="danger" loading={busy} onClick={() => setStatus(confirm, () => setConfirm(null))}>
                {confirm === 'resigned' ? 'Archive' : confirm === 'suspended' ? 'Suspend' : 'Deactivate'}
              </Button>
            </>
          }
        >
          <p className="text-[13px] text-cx-text-2">Appointments already booked with them are not moved automatically.</p>
        </Modal>
      )}
    </div>
  );
}
