import { useEffect, useId, useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Activity, BarChart3, Building2, ClipboardList, Coins, Crown, LineChart, PieChart, ShieldAlert, ShieldCheck, Sparkles, Users } from 'lucide-react';
import { clinicDisplayName, useClinicShared } from '@/clinic/context';
import { refreshLiveProps, useLiveProps } from '@/clinic/live';
import { BranchSwitcher } from '@/clinic/shell/BranchSwitcher';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { PermissionState } from '@/clinic/ui/states';
import { cn } from '@/lib/utils';
import {
  AttentionList,
  BranchComparison,
  CollectionOverview,
  decisionItems,
  FailedPanel,
  FOCUS,
  KpiRow,
  MyDay,
  OperationsToday,
  Panel,
  PeriodSwitch,
  PlanModules,
  RevenueTrend,
  rise,
  ROW,
  SpendingPair,
  SURFACE,
  Timeline,
  useReducedMotion,
  Workforce,
  type AttentionItem,
  type Period,
} from './sections';
import type { BranchesSection, DecisionsSection, Failed, MineSection, MoneySection, PeopleSection, Sections, SpendingSection, TodaySection, TrailSection } from './types';

interface Props {
  sections: Sections;
  scope: {
    branch: { id: number; name: string } | null;
    all_branches: boolean;
    needs_branch: boolean;
  };
}

/** How often the figures are re-read while the page is visible (`useLiveProps`: one request at a time, paused when hidden). */
const LIVE_EVERY_MS = 30_000;

/**
 * The Owner Command Center: the home of whoever runs the clinic — the owner,
 * or an administrator who manages staff. A management view: patient intake
 * stays in the top bar's "+ New" and the patient pages, never here.
 *
 * Reading order: who and where; the money (four figures, then the 30-day
 * trend beside how much of the billing came in); what waits on the owner;
 * the clinic's day and its branches; the workforce and the plan; then the
 * audit trails.
 *
 * The server sends only what this member may see (`OwnerCommandCenter`): the
 * money, people, security and activity sections go to the owner with owner
 * privileges, never to an administrator. Every figure is the count or total
 * of the list it opens. The page computes no figure of its own beyond the
 * collected share (collected ÷ billed) and the trend's range totals, and it
 * decides no access.
 *
 * The figures follow other people's changes by re-reading `sections` every
 * 30 seconds while the page is visible, and at once when it is shown again.
 * There is no broadcasting in this application, so this is polling.
 */
export default function CommandCenter({ sections, scope }: Props) {
  const { auth, clinic, portalLinks } = useClinicShared();
  const [period, setPeriod] = useState<Period>('today');
  const reducedMotion = useReducedMotion();
  useLiveProps(['sections'], { pollMs: LIVE_EVERY_MS });

  // The branch the figures cover, named as the sidebar names it: its display name, else its name, but a name
  // provisioning gave a clinic's only branch ("Main Branch", `name_is_default`) is no name at all, so that clinic's
  // figures are simply the clinic's. With several branches every branch is named; the owner's all-branches view says so.
  const active = clinic?.active_branch ?? null;
  const branchCount = clinic?.accessible_branch_count ?? 0;
  const where = scope.all_branches ? 'All branches' : active && (branchCount > 1 || !active.name_is_default) ? active.display_name?.trim() || active.name : null;
  const visible = Object.keys(sections).length > 0;
  const ready = <T,>(section: T | Failed | undefined): T | null => (section && (section as Failed).state !== 'error' ? (section as T) : null);

  const money = ready<MoneySection>(sections.money);
  const decisions = ready<DecisionsSection>(sections.decisions);
  const spending = ready<SpendingSection>(sections.spending);
  const today = ready<TodaySection>(sections.today);
  const branches = ready<BranchesSection>(sections.branches);
  const people = ready<PeopleSection>(sections.people);
  const security = ready<TrailSection>(sections.security);
  const activity = ready<TrailSection>(sections.activity);
  const mine = ready<MineSection>(sections.mine);

  // What waits on the owner: the server's decisions, then the alerts only this list raises. The amount still due
  // is the Outstanding card's, and the plan's state is the Plan card's (and the portal's plan banner), so neither
  // is repeated here.
  const attention = useMemo<AttentionItem[]>(() => {
    const items = decisionItems(decisions ?? undefined);
    const full = people?.seats.filter((seat) => seat.allowed !== null && seat.used >= seat.allowed) ?? [];
    if (full.length > 0) {
      items.push({
        id: 'seats',
        tone: 'rose',
        icon: Users,
        primary: `${full.map((seat) => seat.label).join(', ')} at the plan's limit`,
        secondary: 'No more can be added until a seat frees up or the plan changes',
        href: null,
      });
    }
    if (security && security.total > 0) {
      items.push({
        id: 'security',
        tone: 'rose',
        icon: ShieldAlert,
        primary: `${security.total} failed sign-in or Owner Mode ${security.total === 1 ? 'check' : 'checks'}`,
        secondary: 'In the last 7 days; see Security below',
        href: null,
      });
    }

    return items;
  }, [decisions, people, security]);

  const failed = (key: keyof Sections, title: string) => (sections[key] && (sections[key] as Failed).state === 'error' ? <FailedPanel id={key} title={title} onRetry={refreshLiveProps} /> : null);

  // Every two-column row shares one grid (`ROW`), so right-hand columns line up down the page; a row with one card spans it.
  const pair = (both: boolean) => cn(ROW, !both && 'lg:grid-cols-1 xl:grid-cols-1');

  return (
    <ClinicPortalShell title="Command Center">
      <div className="space-y-5 xl:space-y-6">
        <Welcome name={auth.user?.name ?? null} clinicName={clinic ? clinicDisplayName(clinic) : null} waiting={decisions ? attention.length : null} />

        {scope.needs_branch && clinic && portalLinks && (
          <div className={cn('flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between', SURFACE)}>
            <p className="text-[13.5px] text-cx-text-2">You work at several branches. Choose one to see its day.</p>
            <BranchSwitcher clinic={clinic} links={portalLinks} variant="block" />
          </div>
        )}

        {!visible ? (
          <PermissionState title="Nothing here for your role yet" description="The Command Center shows what your access allows. Ask the clinic owner if you need more." />
        ) : (
          <>
            {mine && (
              <Panel id="mine" title="Your own patients" caption="You treat patients too: your queue today" icon={Sparkles} tone="violet" {...rise(1)}>
                <MyDay mine={mine} />
              </Panel>
            )}
            {failed('mine', 'Your own patients')}

            {/* Money: four figures, then the trend beside the collected share. */}
            {money && (
              <section aria-labelledby="money-title" className="space-y-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                  <div className="min-w-0">
                    <h2 id="money-title" className="text-[16px] font-semibold leading-6 text-cx-nav-ink">
                      Financial position
                    </h2>
                    <p className="text-[12px] leading-4 text-cx-text-3">
                      {scope.all_branches ? 'Billing across all branches' : where ? `Billing at ${where}` : 'Clinic billing'} · every amount opens its list
                    </p>
                  </div>
                  <PeriodSwitch period={period} onChange={setPeriod} />
                </div>
                <KpiRow money={money} period={period} />
              </section>
            )}
            {money && (
              <div className={ROW}>
                <Panel
                  id="trend"
                  title="Revenue trend"
                  caption="Billed vs collected, daily"
                  icon={LineChart}
                  tone="blue"
                  aside={
                    money.summary_href ? (
                      <Link href={money.summary_href} className={cn('inline-flex min-h-11 items-center text-[13px] font-medium text-cx-primary hover:text-cx-primary-strong', FOCUS)}>
                        Financial summary
                      </Link>
                    ) : undefined
                  }
                  {...rise(2)}
                >
                  <RevenueTrend money={money} reducedMotion={reducedMotion} />
                </Panel>
                <Panel id="collection" title="Collection" caption={period === 'today' ? 'Today' : 'This month'} icon={PieChart} tone="teal" {...rise(3)}>
                  <CollectionOverview money={money} period={period} reducedMotion={reducedMotion} />
                </Panel>
              </div>
            )}
            {failed('money', 'Financial position')}

            {/* What waits on the owner, beside spending waiting on someone. */}
            {(decisions || attention.length > 0 || spending) && (
              <div className={pair(spending !== null)}>
                <Panel
                  id="attention"
                  title="Needs your attention"
                  caption={attention.length > 0 ? `${attention.length} ${attention.length === 1 ? 'item' : 'items'}` : 'All clear'}
                  icon={ClipboardList}
                  tone="amber"
                  {...rise(4)}
                >
                  <AttentionList items={attention} />
                </Panel>
                {spending && (
                  <Panel id="spending" title="Expenses" caption="Spent this month, and what waits" icon={Coins} tone="violet" {...rise(5)}>
                    <SpendingPair spending={spending} />
                  </Panel>
                )}
              </div>
            )}
            {failed('decisions', 'Needs your attention')}
            {failed('spending', 'Spending')}

            {/* The clinic's day, and how its branches compare (only when it has several). */}
            {(today || branches) && (
              <div className={pair(today !== null && branches !== null)}>
                {today && (
                  <Panel id="today" title={`${where ?? 'Clinic'} today`} caption="Bookings and the queue, now" icon={Activity} tone="teal" {...rise(5)}>
                    <OperationsToday figures={today.figures} beside={branches !== null} />
                  </Panel>
                )}
                {branches && (
                  <Panel id="branches" title="Branch performance" caption="Each branch's day on one scale" icon={Building2} tone="blue" {...rise(6)}>
                    <BranchComparison branches={branches} />
                  </Panel>
                )}
              </div>
            )}
            {failed('today', 'Clinic today')}
            {failed('branches', 'Branch performance')}

            {/* Workforce and access, beside the plan and what it allows. */}
            {people && (
              <div className={ROW}>
                <Panel id="workforce" title="Workforce & access" caption="Staff, and logins as the plan counts them" icon={Users} tone="violet" {...rise(6)}>
                  <Workforce people={people} beside />
                </Panel>
                <Panel id="plan" title="Plan & modules" caption="Managed by OralCares" icon={Crown} tone="mint" {...rise(7)}>
                  <PlanModules people={people} />
                </Panel>
              </div>
            )}
            {failed('people', 'Workforce & access')}

            {/* The audit trails. */}
            {(activity || security) && (
              <div className={pair(activity !== null && security !== null)}>
                {activity && (
                  <Panel id="activity" title="Recent activity" caption="Money and access · last 7 days" icon={BarChart3} tone="blue" {...rise(7)}>
                    <Timeline trail={activity} empty="No refunds, adjustments, approvals or access changes in the last 7 days." />
                  </Panel>
                )}
                {security && (
                  <Panel id="security" title="Security" caption="Sign-in checks · last 7 days" icon={ShieldCheck} tone={security.total > 0 ? 'rose' : 'mint'} {...rise(8)}>
                    <Timeline
                      trail={security}
                      totalCountsRows={false}
                      status={
                        security.total > 0
                          ? { tone: 'rose', text: `${security.total} failed ${security.total === 1 ? 'check' : 'checks'} in 7 days` }
                          : { tone: 'mint', text: 'No failed checks in 7 days' }
                      }
                      empty="No sign-in or Owner Mode checks in the last 7 days."
                    />
                  </Panel>
                )}
              </div>
            )}
            {failed('activity', 'Recent activity')}
            {failed('security', 'Security')}
          </>
        )}
      </div>
    </ClinicPortalShell>
  );
}

/** The greeting for the hour on this device's clock. */
function greetingFor(hour: number): string {
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

/**
 * The Command Center's opening card: the clinic it covers, the greeting and
 * the owner's name, and one line on what waits for them — the count of the
 * attention list below, nothing invented. The date and time stay the top
 * bar's, the branch stays in each section's caption, and the money stays in
 * the cards, so none of them is repeated here.
 *
 * Its look is layered, not loud: a cyan-to-violet wash, two soft glows, a
 * gradient edge, and a quiet line drawing (rings and a pulse trace) filling
 * the right side, all decorative and hidden from assistive technology. The
 * greeting follows the hour as the page stays open.
 */
function Welcome({ name, clinicName, waiting }: { name: string | null; clinicName: string | null; waiting: number | null }) {
  const [hour, setHour] = useState(() => new Date().getHours());
  useEffect(() => {
    const timer = window.setInterval(() => setHour(new Date().getHours()), 60_000);

    return () => window.clearInterval(timer);
  }, []);
  const first = name?.trim().split(/\s+/)[0] ?? null;
  const art = useId().replace(/:/g, '');

  return (
    <header className={cn('relative isolate overflow-hidden rounded-2xl border border-white/80 shadow-cx-card', rise(0).className)}>
      {/* The layers: the wash, a teal glow top right, a violet glow below it, and the gradient edge on the left. */}
      <div className="absolute inset-0 -z-10 bg-[linear-gradient(118deg,#E2F6F5_0%,#E8F0FF_50%,#EFE9FE_100%)]" aria-hidden="true" />
      <div className="absolute -right-20 -top-28 -z-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,rgba(44,198,180,0.26),transparent_68%)]" aria-hidden="true" />
      <div className="absolute -bottom-36 right-24 -z-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,rgba(107,72,222,0.18),transparent_68%)]" aria-hidden="true" />
      <span className="absolute inset-y-0 left-0 w-1 bg-gradient-to-b from-[#2CC6B4] via-[#4F7FEA] to-[#8B6CF0]" aria-hidden="true" />

      <svg viewBox="0 0 420 180" className="pointer-events-none absolute -right-8 top-1/2 -z-10 hidden h-[180px] w-[420px] -translate-y-1/2 md:block" aria-hidden="true">
        <defs>
          <linearGradient id={`${art}-line`} x1="0" x2="1" y1="0" y2="0">
            <stop offset="0" stopColor="#2CC6B4" stopOpacity="0" />
            <stop offset="0.35" stopColor="#2CC6B4" />
            <stop offset="0.7" stopColor="#4F7FEA" />
            <stop offset="1" stopColor="#8B6CF0" />
          </linearGradient>
          <radialGradient id={`${art}-core`}>
            <stop offset="0" stopColor="#FFFFFF" stopOpacity="0.9" />
            <stop offset="1" stopColor="#FFFFFF" stopOpacity="0" />
          </radialGradient>
        </defs>
        <circle cx="318" cy="90" r="62" fill={`url(#${art}-core)`} />
        {[34, 58, 82, 106].map((radius, index) => (
          <circle key={radius} cx="318" cy="90" r={radius} fill="none" stroke={`url(#${art}-line)`} strokeOpacity={0.5 - index * 0.1} strokeWidth="1.25" />
        ))}
        <polyline
          points="40,98 170,98 188,98 200,64 214,128 228,78 240,98 300,98 312,90 324,98 420,98"
          fill="none"
          stroke={`url(#${art}-line)`}
          strokeOpacity="0.7"
          strokeWidth="1.75"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <circle cx="318" cy="90" r="5" fill="#4F7FEA" fillOpacity="0.85" />
        <circle cx="376" cy="40" r="3" fill="#8B6CF0" fillOpacity="0.6" />
        <circle cx="250" cy="150" r="2.5" fill="#2CC6B4" fillOpacity="0.7" />
      </svg>

      <div className="relative max-w-[640px] px-6 py-5 sm:px-8 sm:py-6">
        {clinicName && (
          <p className="inline-flex max-w-full items-center gap-1.5 rounded-full bg-white/75 px-3 py-1 text-[12.5px] font-medium text-cx-teal-ink shadow-[inset_0_0_0_1px_rgba(14,163,148,0.16)]">
            <Building2 className="h-3.5 w-3.5 shrink-0" strokeWidth={1.75} aria-hidden="true" />
            <span className="truncate">{clinicName}</span>
          </p>
        )}
        <h1 className={cn('text-[25px] font-medium leading-[1.2] tracking-[-0.02em] text-[#2B3A55] sm:text-[28px]', clinicName && 'mt-2.5')}>
          {greetingFor(hour)}
          {first ? (
            <>
              , <span className="bg-gradient-to-r from-[#0B8478] via-[#2B5FD0] to-[#6B48DE] bg-clip-text font-semibold text-transparent">{first}</span>
            </>
          ) : null}
        </h1>
        <p className="mt-1.5 flex flex-wrap items-center gap-x-2 text-[14px] leading-6 text-cx-text-2">
          {waiting === null ? (
            'Your clinic at a glance, as it runs today.'
          ) : waiting === 0 ? (
            <>
              <span className="h-2 w-2 rounded-full bg-cx-green-ink/70" aria-hidden="true" />
              Nothing needs your decision right now.
            </>
          ) : (
            <>
              <span className="h-2 w-2 rounded-full bg-[#E9821A]" aria-hidden="true" />
              {waiting} {waiting === 1 ? 'item needs' : 'items need'} your attention.
              <a href="#attention-title" className={cn('-my-2.5 inline-flex min-h-11 items-center rounded-md font-medium text-cx-primary hover:text-cx-primary-strong', FOCUS)}>
                Review
              </a>
            </>
          )}
        </p>
      </div>
    </header>
  );
}
