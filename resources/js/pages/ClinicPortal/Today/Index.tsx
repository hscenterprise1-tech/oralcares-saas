import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, ChevronLeft, ChevronRight, ClipboardList, Clock, FileText, FlaskConical, HeartPulse, Stethoscope, UserRound, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { useLiveProps } from '@/clinic/live';
import { IconChip, Panel, rise, type Tone } from '@/pages/ClinicPortal/CommandCenter/sections';

/*
 * The Doctor Dashboard (Today): the approved Option B layout
 * (docs/design-review/clinic-portal-v2/doctor-dashboard-option-b-refined.html)
 * in the Command Center's language. The main column is the doctor's day of
 * patients from the one queue Reception checks into (`DoctorToday`); the
 * panel beside it is their booked day, for the day chosen in its week strip.
 * Starting a consultation always goes through the queue entry, so the visit,
 * its check-in charge and the queue stay one record.
 */

type Safety = { kind: string; label: string; severity: string };
type QueueRow = {
  key: string;
  queue_item_id: number | null;
  status: 'waiting' | 'called' | 'in_consultation' | 'completed' | string;
  priority: string;
  patient: { id: number; name: string; uhid: string | null; age: number | null; gender: string | null } | null;
  visit: { specialty: string | null; type: string | null; reason: string | null };
  doctor: string | null;
  booked_for: string | null;
  checked_in_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  wait_minutes: number | null;
  safety: Safety[];
  safety_more: number;
  treatments: { id: number; title: string; progress: { done: number; total: number | null; unit: string } }[];
  treatments_more: number;
  encounter: { id: number; number: string | null; status: 'signed' | 'draft'; url: string } | null;
  links: { start: string | null; patient: string | null };
};
type Booking = {
  id: number;
  start: string | null;
  end: string | null;
  patient: { id: number; name: string; uhid: string | null } | null;
  new_case: { name: string } | null;
  service: string | null;
  status: string;
  safety: Safety[];
  patient_url: string | null;
};
interface TodayProps {
  branch: { id: number; name: string };
  is_doctor: boolean;
  doctor: { name: string; practice: string | null } | null;
  today?: { waiting: QueueRow[]; in_consultation: QueueRow[]; completed: QueueRow[] };
  schedule: { date: string; today: string; appointments: Booking[] } | null;
  mine: { assigned: number; booked_today: number } | null;
  /** Results back for orders this doctor placed, waiting for their review; null for non-practitioners. */
  results?: { total: number; rows: { id: number; test: string; category: string | null; patient: { name: string; uhid: string | null } | null; resulted_at: string | null; waiting_days: number | null; url: string }[] } | null;
  encounter_label: string;
  links: { self: string; call_next: string | null; calendar: string | null; follow_ups: string | null };
}

const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35 focus-visible:ring-offset-1';
const PRIMARY = cn('inline-flex h-10 items-center gap-2 rounded-full bg-[#1769AA] px-4 text-[13px] font-semibold text-white shadow-[0_1px_2px_rgba(23,59,96,0.18)] transition-colors hover:bg-[#135A93] disabled:opacity-60', FOCUS);
const QUIET = cn('inline-flex h-10 items-center gap-2 rounded-full border border-[#DCE6F3] bg-white px-4 text-[13px] font-semibold text-cx-nav-ink transition-colors hover:border-[#B9C6D8] hover:bg-[#F8FAFC]', FOCUS);

/** Queue and booking times are the clinic's wall clock with no offset: read the digits as they are. */
function clock(value: string | null): string | null {
  const match = value?.match(/T(\d{2}):(\d{2})/) ?? value?.match(/ (\d{2}):(\d{2})/);
  if (!match) {
    return null;
  }
  const hour = Number(match[1]);

  return `${hour % 12 || 12}:${match[2]} ${hour < 12 ? 'am' : 'pm'}`;
}

function initials(name: string): string {
  return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('');
}

function greetingFor(hour: number): string {
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

/** The week (Monday first) holding an ISO date, as ISO dates. */
function weekOf(iso: string): string[] {
  const [y, m, d] = iso.split('-').map(Number);
  const day = new Date(Date.UTC(y, m - 1, d));
  const monday = new Date(day);
  monday.setUTCDate(day.getUTCDate() - ((day.getUTCDay() + 6) % 7));

  return Array.from({ length: 7 }, (_, index) => {
    const next = new Date(monday);
    next.setUTCDate(monday.getUTCDate() + index);

    return next.toISOString().slice(0, 10);
  });
}

function shiftDays(iso: string, days: number): string {
  const [y, m, d] = iso.split('-').map(Number);
  const day = new Date(Date.UTC(y, m - 1, d + days));

  return day.toISOString().slice(0, 10);
}

const BOOKING_STATUS: Record<string, { label: string; tone: string }> = {
  scheduled: { label: 'To arrive', tone: 'bg-cx-slate-bg text-cx-slate-ink' },
  confirmed: { label: 'To arrive', tone: 'bg-cx-slate-bg text-cx-slate-ink' },
  arrived: { label: 'Arrived', tone: 'bg-cx-blue-bg text-cx-blue-ink' },
  checked_in: { label: 'Checked in', tone: 'bg-cx-blue-bg text-cx-blue-ink' },
  waiting: { label: 'Waiting', tone: 'bg-cx-amber-bg text-cx-amber-ink' },
  in_consultation: { label: 'With you', tone: 'bg-cx-violet-bg text-cx-violet-ink' },
  completed: { label: 'Seen', tone: 'bg-cx-green-bg text-cx-green-ink' },
  no_show: { label: 'Did not come', tone: 'bg-cx-rose-bg text-cx-rose-ink' },
};

function SafetyChips({ flags, more = 0 }: { flags: Safety[]; more?: number }) {
  if (flags.length === 0) {
    return null;
  }

  return (
    <span className="flex flex-wrap gap-1">
      {flags.map((flag) => (
        <span key={`${flag.kind}-${flag.label}`} className="inline-flex items-center gap-1 rounded-full bg-cx-rose-bg px-2 py-0.5 text-[11.5px] font-semibold text-cx-rose-ink">
          <AlertTriangle className="h-3 w-3" strokeWidth={2} aria-hidden="true" />
          {flag.label}
        </span>
      ))}
      {more > 0 && <span className="rounded-full bg-cx-rose-bg px-2 py-0.5 text-[11.5px] font-semibold text-cx-rose-ink">+{more}</span>}
    </span>
  );
}

/** One of the day's four figures, as the Command Center draws a figure: a soft wash, a tile, the number. */
function Figure({ tone, icon, label, value, detail }: { tone: Tone; icon: typeof Users; label: string; value: ReactNode; detail?: ReactNode }) {
  return (
    <div className="flex min-w-0 items-start gap-3 rounded-2xl bg-white p-4 shadow-cx-card">
      <IconChip icon={icon} tone={tone} />
      <div className="min-w-0">
        <p className="text-[12.5px] font-medium text-cx-text-2">{label}</p>
        <p className="mt-0.5 text-[24px] font-semibold leading-7 tracking-tight text-cx-nav-ink tabular-nums">{value}</p>
        {detail && <p className="mt-0.5 text-[12px] leading-4 text-cx-text-3 [overflow-wrap:anywhere]">{detail}</p>}
      </div>
    </div>
  );
}

function who(row: QueueRow): string {
  const patient = row.patient;

  return [patient?.age ? `${patient.age}` : null, patient?.gender ? patient.gender[0].toUpperCase() : null].filter(Boolean).join(' ');
}

/** Start (through the queue entry) or resume (the open consultation): never a second consultation. */
function useStart() {
  const [busy, setBusy] = useState<string | null>(null);
  const start = (row: QueueRow) => {
    if (row.encounter && row.encounter.status === 'draft') {
      router.visit(row.encounter.url);

      return;
    }
    if (!row.links.start || busy) {
      return;
    }
    setBusy(row.key);
    router.post(row.links.start, {}, { preserveScroll: true, onFinish: () => setBusy(null) });
  };

  return { busy, start };
}

function QueueCard({ row, onStart, busy, featured = false }: { row: QueueRow; onStart: (row: QueueRow) => void; busy: boolean; featured?: boolean }) {
  const name = row.patient?.name ?? 'Patient';
  const resumable = row.encounter?.status === 'draft';
  const actionable = resumable || !!row.links.start;

  return (
    <li className={cn('flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-cx-card sm:flex-row sm:items-start', featured && 'ring-1 ring-inset ring-[#CFE3F7]')}>
      <span className="grid h-11 w-11 shrink-0 place-items-center rounded-[14px] bg-gradient-to-br from-[#EAF3FD] to-[#E6E0FB] text-[14px] font-semibold text-cx-blue-ink" aria-hidden="true">
        {initials(name)}
      </span>
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-2">
          <span className="text-[15px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{name}</span>
          <span className="text-[12.5px] text-cx-text-2">{[who(row), row.patient?.uhid].filter(Boolean).join(' · ')}</span>
        </p>
        <p className="flex flex-wrap gap-1.5 text-[12px]">
          {row.priority === 'emergency' && <span className="rounded-full bg-cx-rose-bg px-2 py-0.5 font-semibold text-cx-rose-ink">Emergency</span>}
          {row.priority === 'urgent' && <span className="rounded-full bg-cx-amber-bg px-2 py-0.5 font-semibold text-cx-amber-ink">Urgent</span>}
          {row.visit.type && <span className="rounded-full bg-cx-slate-bg px-2 py-0.5 font-medium text-cx-slate-ink">{row.visit.type}</span>}
          <span className="rounded-full bg-cx-slate-bg px-2 py-0.5 font-medium text-cx-slate-ink">{row.booked_for ? `Booked ${clock(row.booked_for)}` : 'Walk-in'}</span>
          {!row.doctor && <span className="rounded-full bg-cx-teal-bg px-2 py-0.5 font-medium text-cx-teal-ink">Any doctor</span>}
        </p>
        {row.visit.reason && <p className="text-[13px] text-cx-text [overflow-wrap:anywhere]">{row.visit.reason}</p>}
        {row.treatments.length > 0 && (
          <p className="text-[12.5px] text-cx-text-2">
            {row.treatments.map((t) => `${t.title}${t.progress.total ? ` · ${t.progress.done} of ${t.progress.total}` : ''}`).join(' · ')}
            {row.treatments_more > 0 ? ` · +${row.treatments_more}` : ''}
          </p>
        )}
        <SafetyChips flags={row.safety} more={row.safety_more} />
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-end">
        {row.wait_minutes !== null && (
          <span className={cn('inline-flex items-center gap-1 text-[12.5px] font-medium', row.wait_minutes >= 20 ? 'text-cx-amber-ink' : 'text-cx-text-2')}>
            <Clock className="h-3.5 w-3.5" strokeWidth={1.75} aria-hidden="true" />
            {row.wait_minutes} min waiting
          </span>
        )}
        <div className="flex gap-2">
          {row.links.patient && (
            <Link href={row.links.patient} className={QUIET} aria-label={`Open ${name}'s record`}>
              Record
            </Link>
          )}
          {actionable && (
            <button type="button" onClick={() => onStart(row)} disabled={busy} className={PRIMARY}>
              <Stethoscope className="h-4 w-4" strokeWidth={1.75} aria-hidden="true" />
              {resumable ? 'Resume' : featured ? 'Start consultation' : 'Start'}
            </button>
          )}
        </div>
      </div>
    </li>
  );
}

function AppointmentsPanel({ schedule, calendarUrl, selfUrl }: { schedule: NonNullable<TodayProps['schedule']>; calendarUrl: string | null; selfUrl: string }) {
  const [loading, setLoading] = useState(false);
  const week = useMemo(() => weekOf(schedule.date), [schedule.date]);
  const go = (date: string) => {
    setLoading(true);
    router.get(selfUrl, date === schedule.today ? {} : { date }, { only: ['schedule', 'links'], preserveState: true, preserveScroll: true, replace: true, onFinish: () => setLoading(false) });
  };
  const label = (iso: string, part: 'weekday' | 'day') => {
    const [y, m, d] = iso.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d));

    return part === 'weekday' ? date.toLocaleDateString('en-IN', { weekday: 'short', timeZone: 'UTC' }).toUpperCase() : String(d);
  };
  const upcoming = schedule.appointments.filter((booking) => ['scheduled', 'confirmed'].includes(booking.status)).length;

  return (
    <Panel
      id="appointments"
      title="Your appointments"
      icon={CalendarDays}
      tone="violet"
      aside={
        calendarUrl ? (
          <Link href={calendarUrl} className={cn('inline-flex min-h-11 items-center rounded-lg text-[13px] font-medium text-cx-primary hover:text-cx-primary-strong', FOCUS)}>
            Open calendar
          </Link>
        ) : undefined
      }
    >
      <div className="flex items-center gap-1">
        <button type="button" onClick={() => go(shiftDays(week[0], -7))} className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-full text-cx-text-2 hover:bg-cx-slate-bg', FOCUS)} aria-label="Previous week">
          <ChevronLeft className="h-4 w-4" aria-hidden="true" />
        </button>
        <ol className="grid min-w-0 flex-1 grid-cols-7 gap-1" aria-label="Choose a day">
          {week.map((iso) => {
            const current = iso === schedule.date;

            return (
              <li key={iso}>
                <button
                  type="button"
                  onClick={() => go(iso)}
                  aria-pressed={current}
                  className={cn(
                    'flex w-full flex-col items-center rounded-xl py-1.5 text-[10.5px] font-semibold transition-colors',
                    FOCUS,
                    current ? 'bg-gradient-to-b from-[#2B86D6] to-[#6B48DE] text-white shadow-cx-card' : 'text-cx-text-2 hover:bg-cx-slate-bg',
                    iso === schedule.today && !current && 'text-cx-blue-ink',
                  )}
                >
                  <span>{label(iso, 'weekday')}</span>
                  <span className="text-[14px] tabular-nums">{label(iso, 'day')}</span>
                </button>
              </li>
            );
          })}
        </ol>
        <button type="button" onClick={() => go(shiftDays(week[6], 1))} className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-full text-cx-text-2 hover:bg-cx-slate-bg', FOCUS)} aria-label="Next week">
          <ChevronRight className="h-4 w-4" aria-hidden="true" />
        </button>
      </div>

      <div className={cn('mt-4', loading && 'opacity-60 motion-safe:transition-opacity')} aria-busy={loading || undefined} aria-live="polite">
        {schedule.appointments.length === 0 ? (
          <p className="rounded-xl bg-cx-slate-bg px-4 py-6 text-center text-[13px] text-cx-text-2">No appointments booked with you on this day.</p>
        ) : (
          <ol className="space-y-2">
            {schedule.appointments.map((booking) => {
              const status = BOOKING_STATUS[booking.status] ?? { label: booking.status, tone: 'bg-cx-slate-bg text-cx-slate-ink' };
              const name = booking.patient?.name ?? booking.new_case?.name ?? 'Patient';
              const body = (
                <>
                  <span className="w-14 shrink-0 pt-0.5 text-[12px] font-semibold tabular-nums text-cx-text-2">{clock(booking.start)}</span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-[13.5px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{name}</span>
                    <span className="block text-[12px] text-cx-text-2">{[booking.patient?.uhid ?? (booking.new_case ? 'New patient' : null), booking.service].filter(Boolean).join(' · ')}</span>
                    {booking.safety.length > 0 && (
                      <span className="mt-1 block">
                        <SafetyChips flags={booking.safety} />
                      </span>
                    )}
                  </span>
                  <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-[11.5px] font-semibold', status.tone)}>{status.label}</span>
                </>
              );

              return (
                <li key={booking.id}>
                  {booking.patient_url ? (
                    <Link href={booking.patient_url} className={cn('flex items-start gap-2 rounded-xl px-2 py-2 hover:bg-cx-slate-bg', FOCUS)}>
                      {body}
                    </Link>
                  ) : (
                    <div className="flex items-start gap-2 px-2 py-2">{body}</div>
                  )}
                </li>
              );
            })}
          </ol>
        )}
        {upcoming > 0 && (
          <p className="mt-3 text-[12.5px] text-cx-text-2">
            {upcoming} {upcoming === 1 ? 'booking' : 'bookings'} still to arrive
          </p>
        )}
      </div>
    </Panel>
  );
}

/** Results back for the doctor's own orders, oldest first: each opens its order to review. */
function ResultsPanel({ results }: { results: NonNullable<TodayProps['results']> }) {
  return (
    <Panel id="results" title="Results to review" caption={results.total > 0 ? `${results.total} waiting for you` : 'Lab and imaging results for your orders'} icon={FlaskConical} tone="amber">
      {results.rows.length === 0 ? (
        <p className="rounded-xl bg-cx-slate-bg px-4 py-6 text-center text-[13px] text-cx-text-2">Nothing waiting. New results appear here.</p>
      ) : (
        <ul className="divide-y divide-cx-line">
          {results.rows.map((row) => (
            <li key={row.id}>
              <Link href={row.url} className={cn('flex items-center justify-between gap-3 rounded-lg py-2.5 hover:bg-[#F8FAFC]', FOCUS)}>
                <span className="min-w-0">
                  <span className="block truncate text-[13.5px] font-semibold text-cx-nav-ink">{row.test}</span>
                  <span className="block truncate text-[12px] text-cx-text-2">{[row.patient?.name, row.patient?.uhid].filter(Boolean).join(' · ') || 'Patient'}</span>
                </span>
                <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-[11.5px] font-semibold', (row.waiting_days ?? 0) >= 1 ? 'bg-cx-amber-bg text-cx-amber-ink' : 'bg-cx-blue-bg text-cx-blue-ink')}>
                  {(row.waiting_days ?? 0) >= 1 ? `${row.waiting_days} d` : 'New'}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
      {results.total > results.rows.length && <p className="mt-2 text-[12px] text-cx-text-2">Showing the oldest {results.rows.length} of {results.total}.</p>}
    </Panel>
  );
}

export default function TodayIndex(props: TodayProps) {
  return (
    <ClinicPortalShell title="Dashboard">
      <DoctorDashboard {...props} />
    </ClinicPortalShell>
  );
}

function DoctorDashboard({ branch, is_doctor: isDoctor, doctor, today, schedule, mine, results, links }: TodayProps) {
  // New arrivals and the desk's changes come in without a reload.
  useLiveProps(['today'], { pollMs: 30_000 });
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const { busy, start } = useStart();
  const [hour, setHour] = useState(() => new Date().getHours());
  useEffect(() => {
    const timer = window.setInterval(() => setHour(new Date().getHours()), 60_000);

    return () => window.clearInterval(timer);
  }, []);

  const waiting = today?.waiting ?? [];
  const withMe = today?.in_consultation ?? [];
  const seen = today?.completed ?? [];
  const drafts = [...withMe, ...seen].filter((row) => row.encounter?.status === 'draft');
  const [next, ...after] = waiting;
  const current = withMe[0] ?? null;
  const longest = waiting.reduce((max, row) => Math.max(max, row.wait_minutes ?? 0), 0);
  const first = doctor?.name.replace(/^dr\.?\s+/i, '').split(/\s+/)[0] ?? null;
  const nextBooking = schedule?.date === schedule?.today ? schedule?.appointments.find((booking) => ['scheduled', 'confirmed', 'arrived'].includes(booking.status)) : undefined;

  return (
    <div className="mx-auto grid w-full max-w-[1400px] gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div className="min-w-0 space-y-5">
        <header className={cn('relative isolate overflow-hidden rounded-2xl border border-white/80 px-6 py-5 shadow-cx-card sm:px-8', rise(0).className)}>
          <div className="absolute inset-0 -z-10 bg-[linear-gradient(118deg,#E2F6F5_0%,#E8F0FF_50%,#EFE9FE_100%)]" aria-hidden="true" />
          <span className="absolute inset-y-0 left-0 w-1 bg-gradient-to-b from-[#2CC6B4] via-[#4F7FEA] to-[#8B6CF0]" aria-hidden="true" />
          <h1 className="text-[25px] font-medium leading-[1.2] tracking-[-0.02em] text-[#2B3A55] sm:text-[28px]">
            {greetingFor(hour)}
            {first ? (
              <>
                , <span className="bg-gradient-to-r from-[#0B8478] via-[#2B5FD0] to-[#6B48DE] bg-clip-text font-semibold text-transparent">Dr. {first}</span>
              </>
            ) : null}
          </h1>
          <p className="mt-1.5 text-[14px] leading-6 text-cx-text-2">
            {[doctor?.practice, branch.name].filter(Boolean).join(' · ')}
            {today
              ? waiting.length > 0
                ? ` — ${waiting.length} ${waiting.length === 1 ? 'patient is' : 'patients are'} waiting for you.`
                : ' — nobody is waiting for you right now.'
              : ''}
          </p>
          {nextBooking && (
            <p className="mt-1 text-[13px] text-cx-text-2">
              Next booking: <span className="font-semibold text-cx-nav-ink">{clock(nextBooking.start)}</span> · {nextBooking.patient?.name ?? nextBooking.new_case?.name}
            </p>
          )}
        </header>

        {errors?.consultation && (
          <p role="alert" className="rounded-xl bg-cx-rose-bg px-4 py-3 text-[13px] font-medium text-cx-rose-ink">
            {errors.consultation}
          </p>
        )}

        <section aria-label="Your day" className="grid grid-cols-1 gap-3 sm:grid-cols-2 2xl:grid-cols-4">
          <Figure tone="amber" icon={Users} label="Waiting for you" value={waiting.length} detail={longest > 0 ? `Longest ${longest} min` : waiting.length > 0 ? 'Just checked in' : 'No one waiting'} />
          <Figure tone="violet" icon={Stethoscope} label="With you now" value={withMe.length} detail={current ? current.patient?.name : 'Nobody in consultation'} />
          <Figure tone="mint" icon={HeartPulse} label="Seen today" value={seen.length} detail={mine ? `${mine.booked_today} booked today` : undefined} />
          <Figure tone="blue" icon={FileText} label="Notes to finish" value={drafts.length} detail={drafts.length > 0 ? 'Sign before the end of the day' : 'All notes signed'} />
        </section>

        {current && (
          <Panel id="with-you" title="With you now" icon={Stethoscope} tone="violet">
            <ul>
              <QueueCard row={current} onStart={start} busy={busy === current.key} featured />
            </ul>
          </Panel>
        )}

        <Panel
          id="queue"
          title={next ? 'Up next' : 'Waiting for you'}
          caption="Checked in for you, or for any doctor, in calling order"
          icon={Users}
          tone="teal"
        >
          {!isDoctor && <p className="mb-3 text-[12.5px] text-cx-text-2">You are not set up as a practitioner here, so the branch's whole day is shown and nothing can be started.</p>}
          {waiting.length === 0 ? (
            <p className="rounded-xl bg-cx-slate-bg px-4 py-6 text-center text-[13px] text-cx-text-2">Nobody is waiting. New check-ins appear here on their own.</p>
          ) : (
            <ul className="space-y-2.5">
              {next && <QueueCard row={next} onStart={start} busy={busy === next.key} featured />}
              {after.map((row) => (
                <QueueCard key={row.key} row={row} onStart={start} busy={busy === row.key} />
              ))}
            </ul>
          )}
        </Panel>

        <Panel id="seen" title="Seen today" caption="Consultations you finished or still need to sign" icon={ClipboardList} tone="mint">
          {seen.length === 0 && drafts.length === 0 ? (
            <p className="rounded-xl bg-cx-slate-bg px-4 py-6 text-center text-[13px] text-cx-text-2">No consultations finished yet today.</p>
          ) : (
            <ul className="divide-y divide-cx-line">
              {seen.map((row) => (
                <li key={row.key} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                  <span className="min-w-0">
                    <span className="block text-[13.5px] font-semibold text-cx-nav-ink">{row.patient?.name ?? 'Patient'}</span>
                    <span className="block text-[12px] text-cx-text-2">{[row.patient?.uhid, row.visit.reason, row.completed_at ? `finished ${clock(row.completed_at) ?? ''}` : null].filter(Boolean).join(' · ')}</span>
                  </span>
                  <span className="flex items-center gap-2">
                    <span className={cn('rounded-full px-2 py-0.5 text-[11.5px] font-semibold', row.encounter?.status === 'draft' ? 'bg-cx-amber-bg text-cx-amber-ink' : 'bg-cx-green-bg text-cx-green-ink')}>
                      {row.encounter?.status === 'draft' ? 'Note in draft' : 'Signed'}
                    </span>
                    {row.encounter && (
                      <Link href={row.encounter.url} className={QUIET}>
                        {row.encounter.status === 'draft' ? 'Finish note' : 'Open'}
                      </Link>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <aside className="min-w-0 space-y-5 xl:sticky xl:top-4 xl:self-start" aria-label="Appointments and results">
        {schedule ? (
          <AppointmentsPanel schedule={schedule} calendarUrl={links.calendar} selfUrl={links.self} />
        ) : (
          <Panel id="appointments" title="Your appointments" icon={UserRound} tone="violet">
            <p className="text-[13px] text-cx-text-2">Appointments are shown here for practitioners when the clinic uses the calendar.</p>
          </Panel>
        )}
        {results && <ResultsPanel results={results} />}
      </aside>
    </div>
  );
}
