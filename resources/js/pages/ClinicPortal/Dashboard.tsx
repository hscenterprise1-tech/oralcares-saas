import { useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';
import { clinicDisplayName } from '@/clinic/context';
import { BranchSwitcher } from '@/clinic/shell/BranchSwitcher';
import { useIntake } from '@/clinic/shell/IntakeContext';
import { useLiveProps } from '@/clinic/live';
import { SURFACE } from '@/clinic/ui/hues';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import type { ClinicSharedProps, DashboardAction, DashboardVisibilitySettings, DashboardWidget, FactsData, KpiData, ListData, StatsData, WidgetData, WidgetZone } from '@/clinic/types';
import { ActionTile, AppointmentList, AttentionList, DuesTable, Failed, FigureCard, FollowUpList, Panel, PatientCounts, PatientsPreview, Skeleton } from './Dashboard/cards';
import type { AppointmentsData, AttentionData, DuesData, FollowUpsData, PatientsData } from './Dashboard/types';
import { VisibilityDialog } from './Dashboard/VisibilityDialog';
import { FactsBody, ListBody, StatsBody } from './Dashboard/WidgetCard';

interface DashboardProps {
  /** The composition for this user, zone by zone and in order (`DashboardWidgets::compose`). */
  widgets: DashboardWidget[];
  /** The quick actions whose destination works (`ClinicActions::forDashboard`). */
  actions: DashboardAction[];
  widgetData?: Record<string, WidgetData>;
  scope: { branch: { id: number; name: string } | null; all_branches: boolean; needs_branch: boolean };
  visibility: DashboardVisibilitySettings | null;
}

function greeting(name: string | undefined): string {
  const hour = new Date().getHours();
  const part = hour < 12 ? 'Good Morning' : hour < 17 ? 'Good Afternoon' : 'Good Evening';
  const first = name?.trim().split(/\s+/)[0];

  return first ? `${part}, ${first}!` : `${part}!`;
}

/**
 * The Clinic Portal's one Dashboard.
 *
 * The server composes it (`DashboardWidgets`): each role's dashboard is a
 * composition by workspace (Reception, Doctor, Billing, Owner/Admin), a user
 * gets the union of theirs, and every widget is then checked against their
 * permissions, plan modules, specialty extensions, branch scope and Owner
 * Mode. This page only draws what arrives, by zone: the day's figures (60%)
 * beside one Quick Actions card (40%); today's patients and appointments
 * (60/40); today's pending payments; what needs attention; and the
 * Owner/Admin management widgets. It never decides access and never computes
 * a figure; a zone with nothing in it is not drawn.
 */
export default function Dashboard({ widgets, actions, widgetData, scope, visibility }: DashboardProps) {
  const { auth, clinic, portalLinks } = usePage<ClinicSharedProps>().props;
  const [staffDashboards, setStaffDashboards] = useState(false);
  // The figures are live: actions refresh just these, and other staff's changes arrive within 45 seconds.
  useLiveProps(['widgetData'], { pollMs: 45_000 });
  const zone = (name: WidgetZone) => widgets.filter((widget) => widget.zone === name);
  const figures = zone('figures');
  const work = zone('work');
  const payments = zone('payments');
  const side = zone('side');
  const management = zone('management');

  return (
    <ClinicPortalShell title="Dashboard">
      {/* Welcome: the greeting, the clinic, and a restrained identity band. */}
      <div className="relative mb-6 overflow-hidden rounded-2xl bg-gradient-to-r from-white via-white to-[#DCEBFA] px-6 py-6 sm:px-8 sm:py-7">
        <div className="relative z-10 max-w-2xl">
          <h1 className="text-[26px] font-semibold leading-tight tracking-tight text-cx-text sm:text-[32px]">
            {greeting(auth.user?.name)} <span aria-hidden="true">👋</span>
          </h1>
          <p className="mt-2 text-[15px] text-cx-text-2 sm:text-[16px]">
            Here's what's happening at {clinic ? clinicDisplayName(clinic) : 'your clinic'}
            {scope.branch ? `, ${scope.branch.name}` : scope.all_branches ? ', across all branches' : ''} today.
          </p>
        </div>
        <div className="pointer-events-none absolute right-8 top-1/2 hidden -translate-y-1/2 text-right lg:block" aria-hidden="true">
          <p className="text-[19px] font-semibold leading-snug text-cx-navy">
            Better Smiles
            <br />
            Healthier Lives
          </p>
          <span className="ml-auto mt-2 block h-1 w-12 rounded-full bg-cx-cyan" />
        </div>
      </div>

      {scope.needs_branch && clinic && portalLinks ? (
        <section className="mx-auto max-w-lg rounded-2xl border border-cx-line bg-white p-6 text-center shadow-cx-card">
          <h2 className="text-[17px] font-semibold text-cx-text">Choose the branch you're working at</h2>
          <p className="mt-1.5 text-[13px] text-cx-text-2">You work at more than one branch. Your dashboard, queue and figures follow the branch you choose.</p>
          <div className="mt-4 text-left">
            <BranchSwitcher clinic={clinic} links={portalLinks} variant="block" />
          </div>
        </section>
      ) : (
        <>
          {/* The day's figures (60%) beside the one Quick Actions card (40%), the same height. On a phone the actions come first. */}
          {(figures.length > 0 || actions.length > 0) && (
            <div data-dashboard-row="overview" className={cn('mb-4 grid gap-4', figures.length > 0 && actions.length > 0 && 'lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]')}>
              {figures.length > 0 && (
                <section aria-label="Today in figures" className="order-2 grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 lg:order-1">
                  {figures.map((widget) => (
                    <FigureCard key={widget.key} widget={widget} data={widgetData?.[widget.key] as KpiData | { error: true } | undefined} />
                  ))}
                </section>
              )}
              {actions.length > 0 && <QuickActions actions={actions} />}
            </div>
          )}

          {/* Today's patients (60%) and today's appointments (40%). */}
          {work.length > 0 && <ZoneRow widgets={work} data={widgetData} label="Today's work" split="lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]" />}

          {/* What today's visits still owe. */}
          {payments.length > 0 && <ZoneRow widgets={payments} data={widgetData} label="Today's pending payments" />}

          {/* Needs attention and follow-ups, only when there is something to act on. */}
          {side.length > 0 && <ZoneRow widgets={side} data={widgetData} label="Needs attention" />}

          {/* The Owner/Admin dashboard's clinic-wide widgets; only that composition has them. */}
          {management.length > 0 && <ZoneRow widgets={management} data={widgetData} label="Clinic management" />}

          {visibility && (
            <div className="mt-6 flex justify-end">
              <button
                type="button"
                onClick={() => setStaffDashboards(true)}
                className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[#E6EBF3] bg-white px-3.5 text-[13px] font-semibold text-cx-text-2 hover:bg-[#F8FAFD] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-primary"
              >
                <SlidersHorizontal className="h-4 w-4" aria-hidden="true" /> Staff dashboards
              </button>
              <VisibilityDialog open={staffDashboards} onOpenChange={setStaffDashboards} settings={visibility} />
            </div>
          )}

          {widgets.length === 0 && actions.length === 0 && (
            <section className="rounded-2xl border border-cx-line bg-white p-8 text-center shadow-cx-card">
              <p className="text-[15px] font-semibold text-cx-text">Nothing to show here yet</p>
              <p className="mt-1 text-[13px] text-cx-text-2">Your role doesn't include any dashboard figures. Ask the clinic owner if you need access.</p>
            </section>
          )}
        </>
      )}
    </ClinicPortalShell>
  );
}

/**
 * The one Quick Actions card. Rendered inside the shell, so New Case and
 * Repeat Case reach the shell's intake rather than the default no-op.
 */
function QuickActions({ actions }: { actions: DashboardAction[] }) {
  const intake = useIntake();
  const open = (mode: 'new' | 'repeat') => intake.open(mode);
  // The server says each action's size: large tiles share a row, two by two; row tiles take a line each.
  const large = actions.filter((action) => action.tile === 'large');
  const rows = actions.filter((action) => action.tile !== 'large');

  return (
    <section aria-labelledby="quick-actions-title" className={cn('order-1 flex flex-col p-5 lg:order-2', SURFACE.card)}>
      <h2 id="quick-actions-title" className={cn('mb-3 text-[15px] font-semibold tracking-[-0.01em]', SURFACE.primary)}>Quick actions</h2>
      <div className="flex flex-col gap-3">
        {large.length > 0 && (
          <div data-tiles="large" className={cn('grid gap-3', large.length > 1 && 'grid-cols-2')}>
            {large.map((action) => (
              <ActionTile key={action.key} action={action} onIntake={open} />
            ))}
          </div>
        )}
        {rows.map((action) => (
          <ActionTile key={action.key} action={action} onIntake={open} />
        ))}
      </div>
    </section>
  );
}

/**
 * A zone's widgets side by side (one alone takes the full width), each card
 * the row's height. A widget the server marked `hide_when_empty` is left out
 * once its data shows nothing to act on.
 */
function ZoneRow({ widgets, data, label, split = 'lg:grid-cols-2' }: { widgets: DashboardWidget[]; data: Record<string, WidgetData> | undefined; label: string; split?: string }) {
  const shown = widgets.filter((widget) => !(widget.hide_when_empty && isEmpty(data?.[widget.key])));
  if (shown.length === 0) {
    return null;
  }

  return (
    <section aria-label={label} data-zone-row={label} className={cn('mb-4 grid items-start gap-4', shown.length > 1 && split)}>
      {shown.map((widget) => (
        <ZoneWidget key={widget.key} widget={widget} data={data?.[widget.key]} />
      ))}
    </section>
  );
}

/** Loaded, and nothing in it. */
function isEmpty(data: WidgetData | undefined): boolean {
  return data !== undefined && !('error' in data) && 'items' in data && Array.isArray(data.items) && data.items.length === 0;
}

/** One composed widget in its card, drawn by its kind. */
function ZoneWidget({ widget, data }: { widget: DashboardWidget; data: WidgetData | undefined }) {
  const id = `widget-${widget.key}`;
  const empty = widget.empty ?? 'Nothing here right now.';
  // Lists that show only the first few carry their full count beside the title; the previews say it themselves.
  const total = data && !('error' in data) && 'total' in data && !['patients', 'appointments', 'dues', 'attention'].includes(widget.kind) ? (data as { total: number }).total : null;

  return (
    <Panel
      labelledBy={id}
      title={widget.title}
      icon={widget.icon}
      tone={widget.tone}
      count={total}
      // Today's patients carries the day's counts on its heading line, not in a row of their own.
      aside={widget.kind === 'patients' && data && !('error' in data) ? <PatientCounts data={data as unknown as PatientsData} /> : null}
      action={widget.action}
    >
      <Slot data={data}>
        {(value) => {
          switch (widget.kind) {
            case 'patients':
              return <PatientsPreview data={value as PatientsData} empty={empty} fullList={widget.action} />;
            case 'dues':
              return <DuesTable data={value as DuesData} empty={empty} fullList={widget.action} />;
            case 'appointments':
              return <AppointmentList data={value as AppointmentsData} empty={empty} fullList={widget.action} />;
            case 'follow_ups':
              return <FollowUpList rows={(value as FollowUpsData).items} total={(value as FollowUpsData).total} empty={empty} />;
            case 'attention':
              return <AttentionList items={(value as AttentionData).items} empty={empty} />;
            case 'stats':
              return <StatsBody data={value as StatsData} />;
            case 'facts':
              return <FactsBody data={value as FactsData} />;
            default:
              return <ListBody data={value as ListData} empty={empty} />;
          }
        }}
      </Slot>
    </Panel>
  );
}

/** A widget's body: a skeleton until its data arrives, a plain failure if its source failed. */
function Slot({ data, children }: { data: WidgetData | undefined; children: (value: unknown) => ReactNode }) {
  if (data === undefined) {
    return <Skeleton rows={3} />;
  }
  if ('error' in data) {
    return <Failed />;
  }

  return <>{children(data)}</>;
}
