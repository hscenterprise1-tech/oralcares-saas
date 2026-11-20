import { useEffect, useRef, useState } from 'react';
import { Deferred, Link, router } from '@inertiajs/react';
import { CalendarCheck2, CalendarPlus, CircleCheck, ClipboardCheck, Hourglass, RotateCcw, Stethoscope, UserPlus, Users, Wallet } from 'lucide-react';
import { clinicDisplayName, useClinic, useClinicShared } from '@/clinic/context';
import { DsButton, DsButtonLink } from '@/clinic/ds';
import { useLiveProps } from '@/clinic/live';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { useIntake } from '@/clinic/shell/IntakeContext';
import { formatMoney, formatNumber } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { FOCUS, KpiCard, rise, SURFACE, Welcome } from '@/pages/ClinicPortal/CommandCenter/sections';
import { deskMode } from './components/desk';
import { QueueBoard, type BoardLane } from './components/QueueBoard';
import { ArrivalsPanel, AttentionPanel, DoctorsPanel, FollowUpsFailed, FollowUpsPanel, LabCasesPanel, PaymentsPanel, attentionTotal } from './components/SidePanels';
import type { BookingRow, Desk, DeskBilling, DeskFollowUps, DeskLab, ListKey } from './components/types';

interface Props {
  branch: { id: number; name: string };
  desk: Desk;
  /** Sent only to members with billing rights (`FrontDesk::billing`). */
  billing: DeskBilling | null;
  /** Deferred: undefined until it arrives; null when this person does not manage follow-ups. */
  follow_ups?: DeskFollowUps | null;
  /** Dental lab cases to send, chase or fit; null when there are none. */
  lab?: DeskLab | null;
  intake: { mode: 'new' | 'repeat'; query: string } | null;
  can: { register: boolean; appointments: boolean; book: boolean; queue: boolean };
  links: { lists: Record<ListKey, string>; intake: string; book: string | null; queue: string | null; appointments: string | null };
}

/** How often the desk re-reads its queue and money (`useLiveProps`: one request at a time, paused while hidden). */
const LIVE_EVERY_MS = 15_000;

/**
 * Reception's workspace for the day at one branch (`ReceptionController@index`,
 * built by `FrontDesk`): start or find a case, work the patient flow lane by
 * lane, and see what else needs the desk. What it shows follows the clinic
 * (Dental, Physio or both), the member's permissions and the data the server
 * sent; every figure and row is the server's, re-read while the page is open.
 */
export default function FrontDeskIndex(props: Props) {
  return (
    <ClinicPortalShell title="Front desk">
      <FrontDesk {...props} />
    </ClinicPortalShell>
  );
}

/** The page body; inside the shell, which hosts the intake (`useIntake`). */
function FrontDesk({ desk, billing, follow_ups, lab, intake, can, links }: Props) {
  useLiveProps(['desk', 'billing', 'lab'], { pollMs: LIVE_EVERY_MS });
  const { auth } = useClinicShared();
  const clinic = useClinic();
  const mode = deskMode(clinic);
  const { available: intakeAvailable, canRegister, open } = useIntake();
  const [lane, setLane] = useState<BoardLane>('waiting');
  const queueRef = useRef<HTMLDivElement>(null);

  // A quick action elsewhere that asked for New Case / Repeat Case (`?intake=`) opens it once on arrival.
  const opened = useRef(false);

  // The deferred follow-ups request: when one ends and the prop is still missing (failed or cancelled), say so instead of waiting forever.
  const [followUpsFailed, setFollowUpsFailed] = useState(false);
  // One retry at a time: a second click while one is running would send the same request again.
  const retryingFollowUps = useRef(false);
  useEffect(
    () =>
      router.on('finish', (event) => {
        if (event.detail.visit.only.includes('follow_ups')) {
          retryingFollowUps.current = false;
          setFollowUpsFailed(true);
        }
      }),
    [],
  );
  const retryFollowUps = () => {
    if (retryingFollowUps.current) {
      return;
    }
    retryingFollowUps.current = true;
    setFollowUpsFailed(false);
    router.reload({ only: ['follow_ups'] });
  };
  useEffect(() => {
    if (intake && !opened.current) {
      opened.current = true;
      open(intake.mode);
    }
  }, [intake, open]);

  const counts = desk.counts;
  // A physio desk keeps two lists: the patients checked in (a session under way stays with them) and the completed.
  const arrivedRows = mode.physioOnly ? [...desk.with_doctor, ...desk.waiting] : desk.waiting;
  const arrivedCount = mode.physioOnly ? counts.waiting + counts.with_doctor : counts.waiting;
  const showLane = (next: BoardLane) => {
    setLane(next);
    queueRef.current?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  };
  const newCase = intakeAvailable && canRegister && can.register;
  const calendar = links.appointments !== null;
  // Today's bookings stay on the side all day (an empty day says so), with the calendar a click away.
  const showArrivals = calendar;
  const showFollowUps = (follow_ups !== null && follow_ups !== undefined && follow_ups.rows.length > 0) || (follow_ups === undefined && followUpsFailed);
  // How many secondary panels have something to show: two or more sit side by side below xl.
  const sidePanels = [attentionTotal(desk.attention, mode) > 0, billing !== null && billing.can.collect, (lab?.rows.length ?? 0) > 0, showArrivals, desk.doctors.length > 0, showFollowUps].filter(Boolean).length;
  const longWaits = desk.attention.long_wait.count;
  // Today's finished visits are collected from their rows; the Payments panel keeps what is left from earlier.
  const onRows = {
    encounters: desk.done.map((row) => row.collect?.encounter_id).filter((id): id is number => id !== undefined),
    bills: desk.done.map((row) => row.collect?.bill_id).filter((id): id is number => typeof id === 'number'),
  };

  const plural = (n: number, one: string, many: string) => `${formatNumber(n)} ${n === 1 ? one : many}`;
  // The day on the Owner Dashboard's own cards; each of the middle three shows its lane below. A physio desk keeps no waiting queue.
  const tiles = mode.physioOnly
    ? [
        <KpiCard key="today" {...rise(1)} tone="blue" icon={Users} label="Patients" period="Today" description="Patients today" value={formatNumber(counts.today)} note={plural(counts.walk_ins, 'walk-in', 'walk-ins')} href={null} />,
        <KpiCard
          key="arrived"
          {...rise(2)}
          tone="teal"
          icon={ClipboardCheck}
          label={mode.arrived}
          period="Now"
          description={mode.arrived}
          value={formatNumber(arrivedCount)}
          note={counts.with_doctor > 0 ? `${formatNumber(counts.with_doctor)} in session · ${formatNumber(counts.waiting)} to start` : 'Session still to start'}
          href={null}
          active={lane === 'waiting'}
          onSelect={() => showLane('waiting')}
        />,
        <KpiCard key="done" {...rise(3)} tone="mint" icon={CircleCheck} label="Completed" period="Today" description="Completed today" value={formatNumber(counts.done)} note="Sessions finished" href={null} active={lane === 'done'} onSelect={() => showLane('done')} />,
      ]
    : [
        <KpiCard key="today" {...rise(1)} tone="blue" icon={Users} label="Patients" period="Today" description="Patients today" value={formatNumber(counts.today)} note={plural(counts.walk_ins, 'walk-in', 'walk-ins')} href={null} />,
        <KpiCard
          key="arrived"
          {...rise(2)}
          tone="amber"
          icon={Hourglass}
          label={mode.arrived}
          period="Now"
          description={mode.arrived}
          value={formatNumber(counts.waiting)}
          note={longWaits > 0 ? `${formatNumber(longWaits)} over ${desk.attention.long_wait.minutes} min` : counts.not_present > 0 ? `${formatNumber(counts.not_present)} not present` : 'In the queue now'}
          href={null}
          active={lane === 'waiting'}
          onSelect={() => showLane('waiting')}
        />,
        <KpiCard key="active" {...rise(3)} tone="violet" icon={Stethoscope} label={mode.active} period="Now" description={mode.active} value={formatNumber(counts.with_doctor)} note={mode.activeCaption} href={null} active={lane === 'with_doctor'} onSelect={() => showLane('with_doctor')} />,
        <KpiCard key="done" {...rise(4)} tone="mint" icon={CircleCheck} label="Completed" period="Today" description="Completed today" value={formatNumber(counts.done)} note="Visits finished" href={null} active={lane === 'done'} onSelect={() => showLane('done')} />,
      ];
  // The greeting's one line: what the desk has to act on now.
  const line = (
    <>
      <span className={cn('h-2 w-2 rounded-full', counts.waiting > 0 ? 'bg-[#0E9F87]' : 'bg-cx-green-ink/70')} aria-hidden="true" />
      {counts.waiting > 0
        ? mode.physioOnly
          ? `${plural(counts.waiting, 'patient is', 'patients are')} checked in; start the session from their row.`
          : `${plural(counts.waiting, 'patient is', 'patients are')} waiting.`
        : mode.physioOnly
          ? 'Nobody is checked in and waiting to start.'
          : 'Nobody is waiting right now.'}
    </>
  );

  if (mode.physioOnly) {
    // Today's bookings still to come, the late ones first.
    const bookings: BookingRow[] = [...desk.attention.late_bookings.rows, ...desk.upcoming.filter((row) => !desk.attention.late_bookings.rows.some((late) => late.id === row.id))];
    const toCollect = desk.done.reduce((sum, row) => sum + (row.collect?.amount ?? 0), 0);
    const collectCount = desk.done.filter((row) => row.collect).length;
    // Balances left from earlier visits (today's are collected from their rows).
    const earlier = billing && billing.can.collect
      ? [
          ...(billing.can.bill ? billing.ready.filter((row) => !onRows.encounters.includes(row.encounter_id)).map((row) => row.total) : []),
          ...billing.unpaid.filter((bill) => !onRows.bills.includes(bill.id)).map((bill) => bill.outstanding),
        ]
      : [];
    const money = (value: number) => formatMoney(value, billing?.currency ?? 'INR');
    const hour = Number(desk.now.slice(11, 13));
    const first = auth.user?.name.trim().split(/\s+/)[0] ?? null;
    const today = new Intl.DateTimeFormat('en-IN', { weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(desk.now.slice(0, 10) + 'T00:00:00'));
    const followUpsDue = follow_ups ? follow_ups.count.today + follow_ups.count.overdue : 0;

    return (
      <div className="space-y-4">
        {/* The greeting and the two ways a patient comes in, on one slim row. */}
        <header className={cn('flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between', SURFACE)}>
          <div className="min-w-0">
            <h1 className="text-[20px] font-semibold leading-7 tracking-[-0.01em] text-cx-nav-ink">
              {hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'}
              {first ? (
                <>
                  , <span className="bg-gradient-to-r from-[#0B8478] via-[#2B5FD0] to-[#6B48DE] bg-clip-text text-transparent">{first}</span>
                </>
              ) : null}
            </h1>
            <p className="text-[12.5px] leading-5 text-cx-text-3">{[clinic ? clinicDisplayName(clinic) : null, today].filter(Boolean).join(' · ')}</p>
          </div>
          {intakeAvailable && (
            <div className="flex shrink-0 gap-2.5">
              <DsButton variant="secondary" icon={RotateCcw} onClick={() => open('repeat')}>
                Repeat
              </DsButton>
              {newCase && (
                <DsButton variant="primary" icon={UserPlus} onClick={() => open('new')}>
                  Registration
                </DsButton>
              )}
            </div>
          )}
        </header>

        {/* Four counts; the first three show their list below. */}
        <section aria-label="Today at this branch" className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:gap-4">
          <KpiCard
            {...rise(1)}
            tone="blue"
            icon={CalendarCheck2}
            label="Appointments"
            period="Today"
            description="Appointments today"
            value={formatNumber(counts.appointments)}
            note={counts.booked_not_arrived > 0 ? `${formatNumber(counts.booked_not_arrived)} still to come` : 'Everyone booked has come'}
            href={null}
            active={lane === 'appointments'}
            onSelect={() => showLane('appointments')}
          />
          <KpiCard
            {...rise(2)}
            tone="teal"
            icon={ClipboardCheck}
            label="Checked in"
            period="Now"
            description="Checked in"
            value={formatNumber(arrivedCount)}
            note={counts.with_doctor > 0 ? `${formatNumber(counts.with_doctor)} in session` : arrivedCount > 0 ? 'Session to start' : 'Nobody waiting'}
            href={null}
            active={lane === 'waiting'}
            onSelect={() => showLane('waiting')}
          />
          <KpiCard
            {...rise(3)}
            tone="mint"
            icon={CircleCheck}
            label="Completed"
            period="Today"
            description="Completed today"
            value={formatNumber(counts.done)}
            note={collectCount > 0 ? `${formatNumber(collectCount)} to collect` : 'Nothing to collect'}
            href={null}
            active={lane === 'done'}
            onSelect={() => showLane('done')}
          />
          <KpiCard
            {...rise(4)}
            tone="violet"
            icon={Wallet}
            label={billing?.collected_today ? 'Collected' : 'To collect'}
            period="Today"
            description={billing?.collected_today ? 'Collected today' : 'To collect today'}
            value={billing?.collected_today ? money(billing.collected_today.total) : money(toCollect)}
            note={billing?.collected_today ? (toCollect > 0 ? `${money(toCollect)} still due` : 'Nothing due') : 'From today’s visits'}
            href={null}
            onSelect={collectCount > 0 ? () => showLane('done') : undefined}
            active={false}
          />
        </section>

        {/* Who is working, and what else needs the desk: one line, chips only when there is something. */}
        <div className="flex flex-wrap items-center gap-2 px-1 text-[13px]">
          {desk.doctors.length > 0 && <span className="font-medium text-cx-text-3">On duty</span>}
          {desk.doctors.map((doctor) => (
            <span key={doctor.id} className="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 font-medium text-cx-nav-ink ring-1 ring-inset ring-cx-line">
              <span aria-hidden="true" className={cn('h-2 w-2 rounded-full', doctor.with_patient ? 'bg-[#7356D8]' : 'bg-[#12A877]')} />
              {doctor.name}
              <span className="text-cx-text-3">· {doctor.with_patient ? 'With patient' : 'Free'}</span>
            </span>
          ))}
          <span className="ml-auto flex flex-wrap gap-2">
            {earlier.length > 0 && (
              <a href="#desk-payments-title" className={cn('inline-flex items-center gap-1.5 rounded-full bg-cx-amber-bg px-3 py-1 font-semibold text-cx-amber-ink', FOCUS)}>
                <Wallet className="h-3.5 w-3.5" aria-hidden="true" />
                {formatNumber(earlier.length)} earlier {earlier.length === 1 ? 'balance' : 'balances'} · {money(earlier.reduce((sum, amount) => sum + amount, 0))}
              </a>
            )}
            {follow_ups && followUpsDue > 0 && (
              <Link href={follow_ups.links.all} className={cn('inline-flex items-center gap-1.5 rounded-full bg-cx-blue-bg px-3 py-1 font-semibold text-cx-blue-ink', FOCUS)}>
                {formatNumber(followUpsDue)} follow-{followUpsDue === 1 ? 'up' : 'ups'} due
              </Link>
            )}
          </span>
        </div>

        <div ref={queueRef} className="min-w-0 scroll-mt-20">
          <QueueBoard
            mode={mode}
            lane={lane}
            onLaneChange={setLane}
            rows={{ waiting: arrivedRows, with_doctor: desk.with_doctor, done: desk.done, not_present: desk.attention.not_present.rows }}
            counts={{ waiting: arrivedCount, with_doctor: counts.with_doctor, done: counts.done, not_present: counts.not_present, appointments: bookings.length }}
            listHref={(key) => links.lists[key] ?? null}
            canQueue={can.queue}
            longWaitMinutes={desk.attention.long_wait.minutes}
            doctors={desk.doctors}
            billing={billing}
            bookings={calendar ? bookings : null}
            onRegister={newCase ? () => open('new') : undefined}
            calendarHref={links.appointments}
          />
        </div>

        {/* Only when there is something left from earlier visits: never an empty box. */}
        {billing && earlier.length > 0 && <PaymentsPanel billing={billing} onRows={onRows} />}
      </div>
    );
  }

  return (
    <div className="space-y-5 xl:space-y-6">
      <Welcome
        name={auth.user?.name ?? null}
        clinicName={clinic ? clinicDisplayName(clinic) : null}
        line={line}
        actions={
          intakeAvailable || (links.book && can.book && !mode.physioOnly) ? (
            <>
              {intakeAvailable && (
                <DsButton variant="secondary" size="lg" icon={RotateCcw} onClick={() => open('repeat')}>
                  {mode.physioOnly ? 'Repeat' : 'Repeat case'}
                </DsButton>
              )}
              {newCase && (
                <DsButton variant="primary" size="lg" icon={UserPlus} onClick={() => open('new')}>
                  {mode.physioOnly ? 'Registration' : 'New case'}
                </DsButton>
              )}
              {links.book && can.book && !mode.physioOnly && (
                <DsButtonLink href={links.book} variant="secondary" size="lg" icon={CalendarPlus}>
                  Book
                </DsButtonLink>
              )}
            </>
          ) : undefined
        }
      />

      <section aria-label="Today at this branch" className={cn('grid grid-cols-2 gap-3 xl:gap-4', tiles.length === 3 ? 'md:grid-cols-3' : 'md:grid-cols-4')}>
        {tiles}
      </section>

      <div className={sidePanels > 0 ? 'grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]' : ''}>
        <div ref={queueRef} className="min-w-0 scroll-mt-20">
          <QueueBoard
            mode={mode}
            lane={lane}
            onLaneChange={setLane}
            rows={{ waiting: arrivedRows, with_doctor: desk.with_doctor, done: desk.done, not_present: desk.attention.not_present.rows }}
            counts={{ waiting: arrivedCount, with_doctor: counts.with_doctor, done: counts.done, not_present: counts.not_present }}
            listHref={(key) => links.lists[key] ?? null}
            canQueue={can.queue}
            longWaitMinutes={desk.attention.long_wait.minutes}
            doctors={desk.doctors}
            billing={billing}
          />
        </div>

        {sidePanels > 0 && (
          <aside aria-label="More for the desk" className="grid min-w-0 content-start gap-4">
            {/* Money first: the desk takes the payment as soon as a treatment ends. */}
            {billing && billing.can.collect && <PaymentsPanel billing={billing} onRows={onRows} />}
            <LabCasesPanel lab={lab} />
            {showArrivals && <ArrivalsPanel upcoming={desk.upcoming} stillToArrive={counts.booked_not_arrived} calendarHref={links.appointments} bookHref={links.book && can.book ? links.book : null} />}
            <DoctorsPanel doctors={desk.doctors} mode={mode} />
            <AttentionPanel attention={desk.attention} mode={mode} onShowLane={showLane} />
            {/* Follow-ups arrive in a second request. While it runs nothing is drawn (the panel shows only when follow-ups are due); a request that ended without them offers a retry. */}
            {follow_ups !== null && (
              <Deferred data="follow_ups" fallback={() => (followUpsFailed ? <FollowUpsFailed onRetry={retryFollowUps} /> : null)}>
                <FollowUpsPanel followUps={follow_ups ?? null} />
              </Deferred>
            )}
          </aside>
        )}
      </div>
    </div>
  );
}
