import { useEffect, useRef, useState } from 'react';
import { Deferred, router } from '@inertiajs/react';
import { Activity, CalendarCheck2, CalendarPlus, CircleCheck, Hourglass, Search, Stethoscope, UserPlus, Users } from 'lucide-react';
import { useClinic, useClinicShared } from '@/clinic/context';
import { DsButton, DsButtonLink, StatTile } from '@/clinic/ds';
import { useLiveProps } from '@/clinic/live';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { useIntake } from '@/clinic/shell/IntakeContext';
import { formatNumber } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { deskMode } from './components/desk';
import { QueueBoard } from './components/QueueBoard';
import { ArrivalsPanel, AttentionPanel, BillingSummaryPanel, DoctorsPanel, FollowUpsFailed, FollowUpsPanel, attentionTotal } from './components/SidePanels';
import type { Desk, DeskBilling, DeskFollowUps, ListKey } from './components/types';

interface Props {
  branch: { id: number; name: string };
  desk: Desk;
  /** Sent only to members with billing rights (`FrontDesk::billing`). */
  billing: DeskBilling | null;
  /** Deferred: undefined until it arrives; null when this person does not manage follow-ups. */
  follow_ups?: DeskFollowUps | null;
  intake: { mode: 'new' | 'repeat'; query: string } | null;
  can: { register: boolean; appointments: boolean; book: boolean; queue: boolean };
  links: { lists: Record<ListKey, string>; intake: string; book: string | null; queue: string | null; appointments: string | null };
}

/** How often the desk re-reads its queue and money (`useLiveProps`: one request at a time, paused while hidden). */
const LIVE_EVERY_MS = 15_000;

/** "Good morning" by the clinic's own clock (`desk.now`, the clinic's wall-clock time from the server). */
function greetingFor(now: string): string {
  const hour = Number(now.slice(11, 13));

  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

/** The care mark behind the greeting (a soft ring and cross with faint glows), purely decorative. */
function HeroArt() {
  return (
    <svg aria-hidden="true" viewBox="0 0 320 160" className="pointer-events-none absolute -right-6 -top-6 hidden h-[190px] w-[380px] opacity-90 md:block" fill="none">
      <circle cx="250" cy="70" r="88" fill="#FFFFFF" fillOpacity="0.45" />
      <circle cx="250" cy="70" r="58" stroke="#1769AA" strokeOpacity="0.12" strokeWidth="14" />
      <circle cx="120" cy="140" r="34" fill="#7356D8" fillOpacity="0.08" />
      <circle cx="305" cy="150" r="22" fill="#14917F" fillOpacity="0.12" />
      <rect x="238" y="44" width="24" height="52" rx="7" fill="#1769AA" fillOpacity="0.16" />
      <rect x="224" y="58" width="52" height="24" rx="7" fill="#1769AA" fillOpacity="0.16" />
    </svg>
  );
}

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
function FrontDesk({ desk, billing, follow_ups, intake, can, links }: Props) {
  useLiveProps(['desk', 'billing'], { pollMs: LIVE_EVERY_MS });
  const { auth } = useClinicShared();
  const clinic = useClinic();
  const mode = deskMode(clinic);
  const { available: intakeAvailable, canRegister, open } = useIntake();
  const [lane, setLane] = useState<ListKey>('waiting');
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
  const showLane = (next: ListKey) => {
    setLane(next);
    queueRef.current?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  };
  const firstName = auth.user?.name.split(/\s+/)[0] ?? null;
  const newCase = intakeAvailable && canRegister && can.register;
  const calendar = links.appointments !== null;
  const showArrivals = calendar && desk.upcoming.length > 0;
  const showFollowUps = (follow_ups !== null && follow_ups !== undefined && follow_ups.rows.length > 0) || (follow_ups === undefined && followUpsFailed);
  // How many secondary panels have something to show: two or more sit side by side below xl.
  const sidePanels = [attentionTotal(desk.attention) > 0, billing !== null, showArrivals, desk.doctors.length > 0, showFollowUps].filter(Boolean).length;
  const longWaits = desk.attention.long_wait.count;

  const tiles = [
    { key: 'today', node: <StatTile label="Patients today" value={formatNumber(counts.today)} caption={`${formatNumber(counts.walk_ins)} walk-in${counts.walk_ins === 1 ? '' : 's'}`} tone="blue" icon={Users} /> },
    {
      key: 'waiting',
      node: <StatTile label="Waiting" value={formatNumber(counts.waiting)} caption={longWaits > 0 ? `${formatNumber(longWaits)} over ${desk.attention.long_wait.minutes} min` : counts.not_present > 0 ? `${formatNumber(counts.not_present)} not present` : 'In the queue now'} tone="amber" icon={Hourglass} active={lane === 'waiting'} onSelect={() => showLane('waiting')} />,
    },
    { key: 'active', node: <StatTile label={mode.active} value={formatNumber(counts.with_doctor)} caption={mode.activeCaption} tone="violet" icon={mode.physio && !mode.dental ? Activity : Stethoscope} active={lane === 'with_doctor'} onSelect={() => showLane('with_doctor')} /> },
    { key: 'done', node: <StatTile label="Completed" value={formatNumber(counts.done)} caption={mode.physio && !mode.dental ? 'Sessions finished' : 'Visits finished'} tone="green" icon={CircleCheck} active={lane === 'done'} onSelect={() => showLane('done')} /> },
    ...(calendar ? [{ key: 'bookings', node: <StatTile label="Bookings" value={formatNumber(counts.appointments)} caption={`${formatNumber(counts.booked_not_arrived)} still to arrive`} tone="cyan" icon={CalendarCheck2} /> }] : []),
  ];
  const five = tiles.length === 5;

  return (
    <div className="space-y-5 motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-300">
      {/* One row from md (a tablet): greeting on the left, the case actions on the right; stacked on a phone. */}
      <section aria-labelledby="desk-greeting" className="relative overflow-hidden rounded-3xl border border-[#DCE6F3] bg-gradient-to-br from-[#EAF2FF] via-[#F5F1FF] to-[#E6F6F2] px-5 py-5 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_18px_40px_-28px_rgba(23,59,96,0.45)] sm:px-7 sm:py-6">
        <HeroArt />
        <div className="relative flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
          <div className="min-w-0">
            <h1 id="desk-greeting" className="text-[24px] font-bold leading-8 tracking-tight text-[#173B60] sm:text-[28px] sm:leading-9 md:whitespace-nowrap lg:text-[24px] lg:leading-8 xl:text-[28px] xl:leading-9">
              {greetingFor(desk.now)}
              {firstName ? `, ${firstName}` : ''}
            </h1>
            <p className="mt-1 text-[14px] leading-[22px] text-[#475569]">Here&apos;s what&apos;s happening at your clinic today.</p>
          </div>
          {(intakeAvailable || (links.book && can.book)) && (
            <div className="grid grid-cols-2 gap-2.5 sm:flex sm:flex-wrap sm:items-center md:shrink-0 md:flex-nowrap">
              {intakeAvailable && (
                <DsButton variant="secondary" size="lg" icon={Search} onClick={() => open('repeat')} className="shadow-[0_1px_2px_rgba(15,23,42,0.06)]">
                  Repeat case
                </DsButton>
              )}
              {newCase && (
                <DsButton variant="primary" size="lg" icon={UserPlus} onClick={() => open('new')}>
                  New case
                </DsButton>
              )}
              {links.book && can.book && (
                <DsButtonLink href={links.book} variant="secondary" size="lg" icon={CalendarPlus} className="col-span-2 shadow-[0_1px_2px_rgba(15,23,42,0.06)] sm:col-span-1">
                  <span className="xl:hidden">Book</span>
                  <span className="hidden xl:inline">Book appointment</span>
                </DsButtonLink>
              )}
            </div>
          )}
        </div>
      </section>

      {/* Four tiles: two a row on a phone, one row from md (two rows beside the sidebar at lg). Five: two a row on a phone, three then two on a tablet, one row from xl. */}
      <section aria-label="Today at this branch" className={cn('grid gap-3', five ? 'grid-cols-6 xl:grid-cols-5' : 'grid-cols-2 md:grid-cols-4 lg:grid-cols-2 xl:grid-cols-4')}>
        {tiles.map((tile, index) => (
          <div key={tile.key} className={cn('min-w-0', five && (index < 3 ? 'col-span-3 md:col-span-2 xl:col-span-1' : index === 3 ? 'col-span-3 xl:col-span-1' : 'col-span-6 md:col-span-3 xl:col-span-1'))}>
            {tile.node}
          </div>
        ))}
      </section>

      <div className={sidePanels > 0 ? 'grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]' : ''}>
        <div ref={queueRef} className="min-w-0 scroll-mt-20">
          <QueueBoard
            mode={mode}
            lane={lane}
            onLaneChange={setLane}
            rows={{ waiting: desk.waiting, with_doctor: desk.with_doctor, done: desk.done, not_present: desk.attention.not_present.rows }}
            counts={{ waiting: counts.waiting, with_doctor: counts.with_doctor, done: counts.done, not_present: counts.not_present }}
            listHref={(key) => links.lists[key] ?? null}
            canQueue={can.queue}
            longWaitMinutes={desk.attention.long_wait.minutes}
            doctors={desk.doctors}
          />
        </div>

        {sidePanels > 0 && (
          <aside aria-label="More for the desk" className={cn('grid min-w-0 content-start gap-4 xl:grid-cols-1', sidePanels > 1 && 'md:grid-cols-2')}>
            <AttentionPanel attention={desk.attention} mode={mode} onShowLane={showLane} />
            {billing && <BillingSummaryPanel billing={billing} />}
            {showArrivals && <ArrivalsPanel upcoming={desk.upcoming} stillToArrive={counts.booked_not_arrived} calendarHref={links.appointments} />}
            <DoctorsPanel doctors={desk.doctors} mode={mode} />
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
