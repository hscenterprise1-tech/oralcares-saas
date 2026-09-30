import React from 'react';
import { Deferred, Link, router } from '@inertiajs/react';
import {
  CrmAvatar,
  CrmShell,
  CrmPageHead,
  CrmCard,
  CrmCardIcon,
  CrmOpenButton,
  CrmValue,
  CrmSection,
  CrmStatusPill,
  CrmStageBadge,
  CrmButton,
  CrmDataState,
  CrmSparkline,
  CrmHairlineBar,
  CrmGradientCard,
  inr,
  stageTone,
  toneHex,
  type CrmStatusTone,
} from '@/pages/Company/design';
import {
  AlertTriangle,
  ArrowRight,
  Briefcase,
  CheckCircle2,
  FileSpreadsheet,
  IndianRupee,
  Rocket,
  Stethoscope,
  Target,
  TrendingUp,
  Users,
  Video,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/*
 * The CRM Overview — the company's command centre, not a personal dashboard.
 *
 * Built to answer four questions in this order: what needs a person right now,
 * what is my own day, where is the pipeline, and how is each part of the
 * machine performing. Everything below "needs attention" is context for that
 * first answer.
 *
 * Two rules from the audit are enforced structurally here rather than by
 * discipline:
 *
 *   1. A figure appears once per screen. Where a section header and the page
 *      header would both state it, the page header wins and the section says
 *      nothing.
 *   2. A metric renders only when it is non-zero, or when zero is itself the
 *      alarming answer. An empty CRM gets one sentence, not a wall of noughts.
 *
 * The heavy aggregates arrive as deferred props in three parallel groups, so
 * the reader sees their own queue immediately and the analysis fills in behind
 * it. Every deferred block declares a loading state — the first use of one
 * anywhere in this module.
 */

// ── Payload ────────────────────────────────────────────────────────────

interface ScopePayload {
  level: string;
  is_manager: boolean;
  can_view_team_performance: boolean;
}

interface PeriodPayload {
  current: string;
  options: { value: string; label: string }[];
}

interface KpisPayload {
  new_leads: number;
  active_pipeline_count: number;
  active_pipeline_valued_count: number;
  active_pipeline_value: number;
  weighted_pipeline_value: number;
  demos_today: number;
  /** Of today's demos, how many have already happened. */
  demos_today_conducted: number;
  /** Booked for today with no meeting link or address — nobody can join. */
  demos_today_missing_link: number;
  active_trials: number;
  /** Three or more prescriptions printed. See CrmMetricsService. */
  trials_healthy: number;
  trials_at_risk: number;
  trials_expiring_48h: number;
  open_quotations: number;
  won_this_period: number;
  lost_this_period: number;
}

interface AttentionItem {
  key: string;
  title: string;
  count: number;
  value: number;
  severity: 'high' | 'medium' | 'low';
  link: string;
  description: string;
}

interface AttentionPayload {
  overdue_followups: number;
  unassigned_leads: number;
  stale_leads: number;
  demos_missing_link: number;
  trials_expiring_48h: number;
  items: AttentionItem[];
}

interface PipelineStage {
  key: string;
  label: string;
  count: number;
  value: number;
  weighted_value: number;
  average_duration_days: number | null;
  sample_size: number;
  is_terminal: boolean;
}

interface PipelinePayload {
  stages: PipelineStage[];
  funnel: { stage: string; count: number; conversion: number | null; of: string | null }[];
  biggest_drop: { from: string; to: string; conversion: number; reached: number; of: number } | null;
}

interface VelocityPayload {
  bottleneck_stage: { stage: string; label: string; average_days: number | null; sample_size: number } | null;
}

interface ForecastPayload {
  open_deal_count: number;
  expected_pipeline: number;
  weighted_pipeline: number;
  by_stage: { stage: string; label: string; count: number; expected_value: number; weighted_value: number }[];
  by_rep: { user_id: number; name: string; count: number; expected_value: number; weighted_value: number }[];
  by_period: { period: string; count: number; expected_value: number; weighted_value: number }[];
}

interface TrialsPayload {
  running: number;
  ending_soon: number;
  undecided: number;
  converted_this_period: number;
  lapsed_this_period: number;
  conversion_rate: number | null;
  decided_sample_size: number;
}

interface CommercialsPayload {
  open_count: number;
  open_value: number;
  drafts: number;
  expiring_soon: number;
  awaiting_approval: number;
  approval_threshold_percent: number | null;
  approved_count_this_period: number;
  approved_value_this_period: number;
  settled_count_this_period: number;
  settled_value_this_period: number;
  payments_received_this_period: number;
}

/**
 * One rep's day, as three things somebody can act on.
 *
 * Distinct from {@link RepRow}, which is the cross-rep leaderboard still
 * rendered on Sales Teams. This is triage — who is behind, and on what.
 */
interface RepTriageRow {
  user_id: number;
  name: string;
  initials: string;
  demos_today: number;
  demos_conducted: number;
  overdue_followups: number;
  /** Open ARR riding on this rep. */
  pipeline_value: number;
}

interface RepRow {
  user_id: number;
  name: string;
  leads_assigned: number;
  demos_scheduled: number;
  trials_active: number;
  quotations_sent: number;
  wins: number;
  losses: number;
  conversion_rate: number | null;
  average_cycle_time_days: number | null;
  overdue_followups: number;
  active_weighted_pipeline: number;
}

interface TopDealRow {
  id: number;
  company_name: string;
  stage: string;
  stage_label: string;
  value: number;
  probability: number;
  next_action: string | null;
  owner: string | null;
}

/**
 * One badge on a deal row, resolved through the specialty catalogue.
 *
 * Shared shape with the Deals list and Deal 360 — the tone is the category's
 * colour role, decided server-side, so the same specialty cannot be coloured
 * two ways across three pages.
 */
interface SpecialtyBadge {
  key: string;
  name: string;
  category: string | null;
  category_label: string | null;
  tone: string;
}

interface FacilityArchetype {
  archetype: string;
  label: string;
  site_count: number;
  is_multi_site: boolean;
}

/** Open pipeline, split by what the practice actually does. */
interface SpecialtyRevenueRow {
  key: string;
  label: string;
  tone: string;
  count: number;
  value: number;
  /** Share of the open pipeline, so a row reads without dividing in your head. */
  share: number;
}

/** A deal that has sat in one stage past the stale threshold. */
interface StalledRow {
  id: number;
  company_name: string;
  contact_name: string | null;
  stage: string;
  stage_label: string;
  stage_age_days: number;
  owner: string | null;
  value: number;
  specialties: SpecialtyBadge[];
  facility: FacilityArchetype | null;
}

interface StalledPayload {
  threshold_days: number;
  total: number;
  oldest: StalledRow[];
}

interface FocusItem {
  kind: string;
  title: string;
  reason: string;
  detail: string;
  lead_id: number | null;
}

interface TaskItem {
  id: number;
  title: string;
  type: string;
  due_label: string;
  is_overdue: boolean;
  is_due_today: boolean;
}

interface PersonalPayload {
  todays_focus?: { items: FocusItem[]; total: number; is_clear: boolean };
  tasks?: TaskItem[];
  unclaimed?: { leads: { id: number; company_name: string; contact_name: string; assigned_ago: string }[]; total: number };
  /*
   * `counts` used to be declared here. The server has no top-level `counts` in
   * this payload — the figures live at `workload.counts`, which My work reads
   * and this page does not — and nothing here ever read it. Type-only removal:
   * no prop changes hands.
   */
}

interface OverviewProps {
  scope: ScopePayload;
  period: PeriodPayload;
  kpis: KpisPayload;
  attention: AttentionPayload;
  personal: PersonalPayload;
  pipeline?: PipelinePayload;
  velocity?: VelocityPayload;
  forecast?: ForecastPayload;
  trials?: TrialsPayload;
  commercials?: CommercialsPayload;
  rep_triage?: RepTriageRow[] | null;
  top_deals?: TopDealRow[];
  specialty_revenue?: SpecialtyRevenueRow[];
  stalled?: StalledPayload;
}
// ── Colour system ──────────────────────────────────────────────────────

/*
 * One hue per CRM area, drawn from the product's own tone palette rather than
 * invented for this page. The hue is an identity, not a decoration: the same
 * colour marks pipeline wherever pipeline appears, so the eye learns the page
 * once. Status inside a card — good, warning, critical — is a separate scale
 * and never borrows an area hue, because a red card and a red number would
 * then mean two different things.
 */
const AREA = {
  attention: '#E8593C', // coral   — something is wrong
  personal: '#2563EB', // blue    — your own work
  pipeline: '#8B7FE8', // violet  — the deal ladder
  forecast: '#4A9DAB', // teal    — projected money
  trials: '#F5A623', // amber   — time-limited
  commercial: '#2FA96B', // green   — contracted money
  people: '#6B7488', // slate   — reporting on people
} as const;

const SEVERITY_TONE: Record<string, CrmStatusTone> = { high: 'bad', medium: 'warn', low: 'neutral' };

const money = (n: number) => inr(n, { compact: true });

// ── Building blocks ────────────────────────────────────────────────────

/**
 * The headline card.
 *
 * Fixed minimum height with the figure pinned to the bottom, so a row of these
 * forms a baseline the eye can read across instead of a ragged edge. Frosted
 * in the area's hue — the tint is what separates "pipeline" from "trials" at a
 * glance, before either label is read.
 */
function StatCard({
  icon: Icon,
  label,
  value,
  hint,
  href,
  hue,
  spark,
  sparkVariant = 'bars',
  step = 0,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: React.ReactNode;
  hint?: string;
  href?: string;
  hue: string;
  spark?: number[];
  sparkVariant?: 'line' | 'bars';
  step?: number;
}) {
  return (
    <CrmCard className="flex min-h-[124px] flex-col sm:min-h-[168px]" step={step} frostHue={hue}>
      <div className="mb-2 flex items-start justify-between gap-2 sm:mb-4">
        <CrmCardIcon icon={Icon as never} hue={hue} />
        {/*
          * The open affordance is hidden on a phone — the whole card is inside
          * a tap target already, and at two-up the button crowds the icon.
          */}
        {href && (
          <span className="hidden sm:inline">
            <CrmOpenButton href={href} label={`Open ${label}`} />
          </span>
        )}
      </div>

      <p className="text-[12px] leading-snug text-ovs-crm-text-secondary sm:text-[13px]">{label}</p>

      <div className="mt-auto flex items-end justify-between gap-3 pt-2 sm:pt-3">
        <div className="min-w-0">
          <CrmValue value={value} size="major" />
          {hint && (
            <p className="mt-1 text-[11px] leading-snug text-ovs-crm-text-muted sm:text-[11.5px]">{hint}</p>
          )}
        </div>
        {/*
          * The sparkline is a silhouette, not a reading. At two-up on 375px it
          * takes width the value needs, so it waits for a wider screen.
          */}
        {spark && spark.length > 1 && (
          <CrmSparkline points={spark} color={hue} variant={sparkVariant} className="hidden shrink-0 sm:block" />
        )}
      </div>
    </CrmCard>
  );
}

/**
 * A figure inside a section body.
 *
 * Deliberately smaller and quieter than StatCard: reusing the headline
 * treatment inside a section is how every number on a dashboard ends up
 * looking equally important.
 */
function Figure({
  label,
  value,
  caption,
  hue,
}: {
  label: string;
  value: React.ReactNode;
  caption?: string;
  hue?: string;
}) {
  return (
    <div className="min-w-0">
      <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">{label}</p>
      <p
        className="mt-1.5 text-[26px] font-medium leading-none tracking-[-0.025em] tabular-nums"
        style={hue ? { color: hue } : undefined}
      >
        {value}
      </p>
      {caption && <p className="mt-1.5 text-[11.5px] leading-snug text-ovs-crm-text-muted">{caption}</p>}
    </div>
  );
}

/**
 * The pipeline as one bar, segmented by stage.
 *
 * The per-stage rows below it answer "how many and how much"; this answers
 * "what shape is it in" before any number is read — a pipeline bunched at one
 * end looks wrong here even to someone who has not read the labels.
 */
function StageBar({ stages }: { stages: PipelineStage[] }) {
  const total = stages.reduce((sum, s) => sum + s.count, 0);
  if (total === 0) return null;

  return (
    <div className="mb-5 flex h-2.5 w-full overflow-hidden rounded-full bg-ovs-crm-line">
      {stages.map((stage) => (
        <span
          key={stage.key}
          title={`${stage.label} — ${stage.count}`}
          style={{
            width: `${(stage.count / total) * 100}%`,
            background: toneHex(stageTone(stage.key)),
          }}
        />
      ))}
    </div>
  );
}

// ── Page ───────────────────────────────────────────────────────────────

export default function CrmOverview({
  scope,
  period,
  kpis,
  attention,
  personal,
  pipeline,
  velocity,
  forecast,
  trials,
  commercials,
  rep_triage,
  top_deals,
  specialty_revenue,
  stalled,
}: OverviewProps) {
  const focus = personal?.todays_focus;
  const myTasks = personal?.tasks ?? [];
  const unclaimed = personal?.unclaimed;

  const attentionItems = (attention?.items ?? []).filter((item) => item.count > 0);

  /*
   * The three the chip row names, in the order they cost you money: a deal
   * nobody owns, a demo nobody can join, a trial about to lapse. Picked by key
   * from the same `attention.items` the section below renders, so a chip and
   * the card under it can never disagree about a count.
   */
  const bottlenecks = ['unassigned_leads', 'demos_missing_link', 'trials_expiring_48h']
    .map((key) => attentionItems.find((item) => item.key === key))
    .filter((item): item is AttentionItem => Boolean(item));
  const worst = attentionItems.find((item) => item.severity === 'high') ?? attentionItems[0];
  const closed = kpis.won_this_period + kpis.lost_this_period;
  const winRate = closed > 0 ? Math.round((kpis.won_this_period / closed) * 100) : null;
  const coverage =
    kpis.active_pipeline_value > 0
      ? Math.round((kpis.weighted_pipeline_value / kpis.active_pipeline_value) * 100)
      : null;

  /*
   * The stage shape, borrowed for the headline sparkline. It is the same data
   * the pipeline section charts in full — used here as a silhouette, so the
   * top card hints at the shape and the section below explains it.
   */
  const openStages = (pipeline?.stages ?? []).filter((s) => ! s.is_terminal);
  const stageSpark = openStages.map((s) => s.count);

  const setPeriod = (value: string) =>
    router.get(route('company.crm.overview'), { period: value }, { preserveState: true, preserveScroll: true });

  return (
    <CrmShell title="Overview">

      {/*
        * Tighter masthead on a phone. The shared `mb-7` plus `gap-y-6` spent
        * most of the first screen on whitespace before a single figure
        * appeared; overridden here rather than in CrmPageHead, which every
        * other page depends on.
        */}
      <CrmPageHead
        className="mb-4 gap-y-3 sm:mb-7 sm:gap-y-6"
        eyebrow="CRM"
        title="Overview"
        actions={
          <div className="flex items-center gap-1 rounded-full border border-ovs-crm-line bg-white p-1 shadow-ovs-crmPanel">
            {period.options.map((option) => (
              <button
                key={option.value}
                type="button"
                onClick={() => setPeriod(option.value)}
                aria-pressed={period.current === option.value}
                className={cn(
                  'rounded-full px-3.5 py-1.5 text-[12px] font-medium transition',
                  period.current === option.value
                    ? 'bg-ovs-crm-ink text-white shadow-ovs-crmInk'
                    : 'text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary'
                )}
              >
                {option.label}
              </button>
            ))}
          </div>
        }
      />

      <div className="space-y-4 pb-16">

        {/*
          * The three bottlenecks, as chips.
          *
          * Above the metric strip because they are the only things on this page
          * that are wrong right now — everything below describes the business,
          * these describe a job. Rendered with the same CrmStatusPill the rest
          * of the module uses, wrapped in a Link so every chip opens the exact
          * rows it counted. A chip at zero is dropped rather than greyed: an
          * empty queue is not information, and three green zeroes teach people
          * to stop reading the row.
          */}
        {bottlenecks.length > 0 && (
          /*
           * A uniform grid on a phone, an inline strip from `sm`.
           *
           * Wrapping a flex row put two chips on the first line and one on the
           * second at three different widths — the ragged edge that reads as a
           * layout fault rather than a list. Two equal columns make the break
           * deliberate: the third chip is half-width because the grid says so,
           * not because it ran out of room.
           *
           * Deliberately not the horizontal scroller. These are the only things
           * on the page that are wrong right now, and a scroller would put the
           * third one off the right edge of a phone where nobody looks. There
           * is also no `scrollbar-none` in this project's Tailwind build, so it
           * would have meant new CSS for a worse outcome.
           */
          <div className="grid grid-cols-2 gap-1.5 sm:flex sm:flex-wrap sm:items-center sm:gap-2">
            {/*
              * The eyebrow is hidden on a phone. At 375px it consumes a whole
              * line to label three chips that already say what they are, and
              * the strip is the first thing under the title — the context is
              * not in doubt.
              */}
            <span className="hidden text-[11px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted sm:inline">
              Needs a person
            </span>
            {bottlenecks.map((item) => (
              <Link key={item.key} href={item.link} className="crm-lift min-w-0 rounded-full">
                {/*
                  * `w-full` fills the grid cell so every chip is the same
                  * width; `sm:w-auto` hands the sizing back to the content once
                  * the strip is inline again.
                  */}
                <CrmStatusPill
                  tone={item.severity === 'high' ? 'bad' : 'warn'}
                  className="w-full justify-center sm:w-auto sm:justify-start"
                >
                  <span className="truncate">{item.title} · {item.count}</span>
                </CrmStatusPill>
              </Link>
            ))}
          </div>
        )}

        {/*
          * The headline band: the state of the business in one row, and beside
          * it the single most urgent thing, given the weight of a gradient
          * card. Emphasis only works while it is rare, so exactly one thing on
          * this page gets that treatment.
          */}
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {/*
            * Two across on a phone, not one.
            *
            * Stacked, four cards at 168px each pushed the funnel and everything
            * below it roughly three screens down — the page became a scroll
            * before it became a dashboard. Two-up with a tighter gutter fits
            * all four above the fold at 375px. The desktop layout is unchanged.
            */}
          <div className="grid grid-cols-2 gap-2 sm:gap-4 lg:col-span-8">
            <StatCard
              step={0}
              icon={IndianRupee}
              hue={AREA.pipeline}
              label="Open pipeline"
              value={kpis.active_pipeline_value > 0 ? money(kpis.active_pipeline_value) : '—'}
              hint={
                kpis.active_pipeline_valued_count < kpis.active_pipeline_count
                  ? `${kpis.active_pipeline_count} deals · ${kpis.active_pipeline_count - kpis.active_pipeline_valued_count} with no value set`
                  : `Across ${kpis.active_pipeline_count} deals`
              }
              href="/company/crm/deals"
              spark={stageSpark}
            />

            {/*
              * Demos today, as progress through the day rather than a count.
              *
              * "4 demos today" reads identically at nine in the morning and at
              * six in the evening. Conducted against scheduled says how much of
              * the day is left to run, which is the thing a sales manager is
              * actually asking. The weighted forecast this replaced still has
              * its own section below, where it has room to be explained.
              */}
            <StatCard
              step={1}
              icon={Video}
              hue={AREA.people}
              label="Demos today"
              value={
                kpis.demos_today > 0
                  ? `${kpis.demos_today_conducted}/${kpis.demos_today}`
                  : '—'
              }
              hint={
                kpis.demos_today === 0
                  ? 'Nothing booked for today'
                  : kpis.demos_today_missing_link > 0
                    ? `${kpis.demos_today_missing_link} with no way to join`
                    : `${kpis.demos_today - kpis.demos_today_conducted} still to run`
              }
              href="/company/crm/my-workspace"
            />

            <StatCard
              step={2}
              icon={CheckCircle2}
              hue={AREA.commercial}
              label="Won this period"
              value={kpis.won_this_period}
              hint={
                winRate != null
                  ? `${winRate}% win rate · ${kpis.lost_this_period} lost`
                  : 'Nothing closed yet this period'
              }
              href="/company/crm/deals?stage=won"
            />

            {/*
              * Active trials, split by whether anyone is using them.
              *
              * This replaced "new deals this period" — a vanity counter, in the
              * sense that it goes up whether or not anything good happens and
              * nobody does anything differently because of it. A trial nobody
              * has opened is the opposite: it is the most perishable thing in
              * the pipeline and the number moves only when somebody acts.
              */}
            <StatCard
              step={3}
              icon={Rocket}
              hue={AREA.trials}
              label="Active trials"
              value={kpis.active_trials > 0 ? `${kpis.trials_healthy}/${kpis.active_trials}` : '—'}
              hint={
                kpis.active_trials === 0
                  ? 'No evaluations running'
                  : kpis.trials_at_risk > 0
                    ? `${kpis.trials_healthy} healthy · ${kpis.trials_at_risk} at risk`
                    : `${kpis.trials_healthy} printing prescriptions`
              }
              href="/company/crm/trials"
            />
          </div>

          <div className="lg:col-span-4">
            {worst ? (
              <CrmGradientCard
                eyebrow="Needs a person"
                headline={`${worst.count} `}
                emphasis={worst.title.toLowerCase()}
                href={worst.link}
                label="Open them"
                tone={worst.severity === 'high' ? 'urgent' : 'calm'}
                timestamp={worst.value > 0 ? `${money(worst.value)} affected` : undefined}
                className="h-full"
              />
            ) : (
              <CrmCard className="flex h-full min-h-[168px] flex-col justify-between" frostHue={AREA.commercial}>
                <CrmCardIcon icon={CheckCircle2 as never} hue={AREA.commercial} />
                <div>
                  <p className="text-[15px] font-semibold text-ovs-crm-text-primary">Nothing needs chasing</p>
                  <p className="mt-1 text-[12.5px] leading-relaxed text-ovs-crm-text-muted">
                    No overdue follow-ups, unowned deals or stalled work.
                  </p>
                </div>
              </CrmCard>
            )}
          </div>
        </div>

        {/*
          * The full attention list. Every card opens the exact rows it counted,
          * so no figure on this page is a dead end. Severity drives the accent,
          * and cards at zero are dropped rather than greyed — an empty queue is
          * not information.
          */}
        {attentionItems.length > 1 && (
          <CrmSection
            title="Needs a person"
            description="Each figure opens the deals behind it."
            icon={AlertTriangle}
            hue={AREA.attention}
            step={4}
          >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {attentionItems.map((item) => {
                const hue = item.severity === 'high' ? AREA.attention : item.severity === 'medium' ? AREA.trials : AREA.people;

                return (
                  <Link
                    key={item.key}
                    href={item.link}
                    className="crm-lift group relative flex items-start justify-between gap-3 overflow-hidden rounded-[16px] border border-ovs-crm-line/70 bg-white px-4 py-3.5 transition hover:border-transparent hover:shadow-ovs-crmLift"
                  >
                    <span
                      className="absolute inset-y-0 left-0 w-[3px]"
                      style={{ background: hue }}
                      aria-hidden="true"
                    />
                    <span className="min-w-0 pl-1.5">
                      <span className="block text-[13.5px] font-medium text-ovs-crm-text-primary">{item.title}</span>
                      <span className="mt-0.5 block text-[11.5px] leading-snug text-ovs-crm-text-muted">
                        {item.description}
                      </span>
                      {item.value > 0 && (
                        <span className="mt-1.5 block text-[11.5px] font-medium tabular-nums" style={{ color: hue }}>
                          {money(item.value)} affected
                        </span>
                      )}
                    </span>
                    <span
                      className="shrink-0 text-[22px] font-medium leading-none tabular-nums"
                      style={{ color: hue }}
                    >
                      {item.count}
                    </span>
                  </Link>
                );
              })}
            </div>
          </CrmSection>
        )}

        {/*
          * Your own day. It sits under the company-wide alarms because a
          * company problem outranks a personal queue — and above every
          * analytical block, because analysis is not work.
          */}
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          <CrmSection
            title="Your day"
            description={focus?.is_clear ? undefined : 'Most urgent first.'}
            icon={Target}
            hue={AREA.personal}
            step={5}
            className="lg:col-span-8"
          >
            {focus && ! focus.is_clear ? (
              <div className="space-y-2">
                {focus.items.map((item, index) => (
                  <Link
                    key={`${item.kind}-${item.lead_id}-${index}`}
                    href={item.lead_id ? `/company/crm/leads/${item.lead_id}` : '#'}
                    className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-[16px] bg-ovs-crm-surface-inset px-4 py-3 transition hover:bg-white hover:shadow-ovs-crmCard"
                  >
                    <span className="min-w-[190px] flex-1 basis-[190px]">
                      <span className="block truncate text-[13.5px] font-medium text-ovs-crm-text-primary">
                        {item.title}
                      </span>
                      <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">{item.reason}</span>
                    </span>
                    <span className="shrink-0 text-[11.5px] font-medium text-ovs-crm-text-secondary">
                      {item.detail}
                    </span>
                  </Link>
                ))}
              </div>
            ) : (
              <CrmDataState
                kind="empty"
                compact
                icon={CheckCircle2}
                title="Your queue is clear"
                description="Nothing is overdue or due today."
              />
            )}
          </CrmSection>

          <CrmSection
            title="Your tasks"
            icon={CheckCircle2}
            hue={AREA.forecast}
            step={6}
            className="lg:col-span-4"
          >
            {myTasks.length === 0 ? (
              <p className="text-[13px] text-ovs-crm-text-muted">Nothing on your list.</p>
            ) : (
              <div className="space-y-2">
                {myTasks.slice(0, 6).map((task) => (
                  <div
                    key={task.id}
                    className="flex items-center justify-between gap-3 rounded-[16px] bg-ovs-crm-surface-inset px-3.5 py-2.5"
                  >
                    <span className="min-w-0 truncate text-[13px] text-ovs-crm-text-primary">{task.title}</span>
                    <CrmStatusPill tone={task.is_overdue ? 'bad' : task.is_due_today ? 'warn' : 'neutral'}>
                      {task.is_overdue ? 'Overdue' : task.due_label}
                    </CrmStatusPill>
                  </div>
                ))}
                {myTasks.length > 6 && (
                  <p className="pt-1 text-[11.5px] text-ovs-crm-text-muted">
                    {myTasks.length - 6} more on your list.
                  </p>
                )}
              </div>
            )}
          </CrmSection>
        </div>

        {/* Unclaimed deals — shown only when some exist. */}
        {unclaimed && unclaimed.total > 0 && (
          <CrmSection
            title="Nobody is on these"
            description="Unclaimed deals going cold while everyone works their own book."
            icon={Users}
            hue={AREA.trials}
            step={7}
            actions={
              <CrmButton size="sm" icon={ArrowRight} href="/company/crm/deals?unassigned_only=1">
                Open them
              </CrmButton>
            }
          >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {unclaimed.leads.slice(0, 6).map((lead) => (
                <Link
                  key={lead.id}
                  href={`/company/crm/leads/${lead.id}`}
                  className="crm-lift rounded-[16px] bg-ovs-crm-surface-inset px-4 py-3 transition hover:bg-white hover:shadow-ovs-crmCard"
                >
                  <span className="block truncate text-[13.5px] font-medium text-ovs-crm-text-primary">
                    {lead.company_name}
                  </span>
                  <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
                    {[lead.contact_name, lead.assigned_ago].filter(Boolean).join(' · ')}
                  </span>
                </Link>
              ))}
            </div>
          </CrmSection>
        )}

        {/*
          * Everything below arrives after the first paint, in three parallel
          * groups. Each declares a loading state — the module had none before
          * this page, so a slow query used to render as nothing at all.
          */}
        <Deferred
          data={['pipeline', 'velocity', 'forecast', 'specialty_revenue']}
          fallback={
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
              <CrmSection title="Pipeline" icon={Briefcase} hue={AREA.pipeline} className="lg:col-span-8">
                <CrmDataState kind="loading" shape="list" rows={5} />
              </CrmSection>
              <div className="space-y-4 lg:col-span-4">
                <CrmSection title="Forecast" icon={TrendingUp} hue={AREA.forecast}>
                  <CrmDataState kind="loading" shape="cards" rows={2} />
                </CrmSection>
                <CrmSection title="Pipeline by discipline" icon={Stethoscope} hue={AREA.trials}>
                  <CrmDataState kind="loading" shape="list" rows={4} />
                </CrmSection>
              </div>
            </div>
          }
        >
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
            <CrmSection
              title="Pipeline"
              description="Where every open deal is sitting, and what it is worth."
              icon={Briefcase}
              hue={AREA.pipeline}
              step={8}
              href="/company/crm/deals"
              openLabel="Open deals"
              className="lg:col-span-8"
            >
              {openStages.filter((s) => s.count > 0).length === 0 ? (
                <CrmDataState
                  kind="empty"
                  icon={Briefcase}
                  title="No open deals"
                  description="Stages appear here as deals move into them."
                />
              ) : (
                <>
                  <StageBar stages={openStages.filter((s) => s.count > 0)} />

                  <div className="-mx-2 space-y-0.5">
                    {openStages
                      .filter((stage) => stage.count > 0)
                      .map((stage) => {
                        const max = Math.max(...openStages.map((s) => s.count), 1);
                        const hue = toneHex(stageTone(stage.key));

                        return (
                          <Link
                            key={stage.key}
                            href={`/company/crm/deals?stage=${stage.key}`}
                            /*
                              * Narrower label and gutter below `sm`.
                              *
                              * 168 + 110 + 74 plus two 16px gaps is 384px of
                              * fixed width in a card roughly 311px wide at
                              * 375 — the row wrapped and left the money alone
                              * on a second line for every stage. At 120 + 64 +
                              * 74 with 8px gutters it holds one line, and the
                              * desktop measurements are untouched.
                              */
                            className="flex flex-wrap items-center gap-x-2 gap-y-2 rounded-[12px] px-2 py-2 transition hover:bg-ovs-crm-surface-inset sm:gap-x-4"
                          >
                            <span className="flex w-[120px] shrink-0 items-center gap-2 sm:w-[168px] sm:gap-2.5">
                              <span
                                className="h-2 w-2 shrink-0 rounded-full"
                                style={{ background: hue }}
                                aria-hidden="true"
                              />
                              <span className="truncate text-[13px] text-ovs-crm-text-primary">{stage.label}</span>
                            </span>
                            <span className="flex min-w-[64px] flex-1 items-center gap-2 sm:min-w-[110px] sm:gap-3">
                              <CrmHairlineBar value={stage.count} max={max} color={hue} className="flex-1" />
                              <span className="w-6 shrink-0 text-right text-[13px] tabular-nums text-ovs-crm-text-primary">
                                {stage.count}
                              </span>
                            </span>
                            <span className="w-[74px] shrink-0 text-right text-[12.5px] font-medium tabular-nums text-ovs-crm-text-secondary">
                              {stage.value > 0 ? money(stage.value) : '—'}
                            </span>
                          </Link>
                        );
                      })}
                  </div>

                  {/*
                    * Two diagnoses under the ladder, as sentences. A percentage
                    * with no subject tells a reader nothing they can act on.
                    */}
                  <div className="mt-5 grid grid-cols-1 gap-4 border-t border-ovs-crm-line pt-4 sm:grid-cols-2">
                    {pipeline?.biggest_drop && (
                      <div>
                        <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                          Biggest drop
                        </p>
                        <p className="mt-1 text-[13px] text-ovs-crm-text-secondary">
                          <span className="font-medium text-ovs-crm-text-primary">
                            {pipeline.biggest_drop.from} to {pipeline.biggest_drop.to}
                          </span>
                          {' — '}
                          {pipeline.biggest_drop.reached} of {pipeline.biggest_drop.of} got through
                        </p>
                      </div>
                    )}
                    {velocity?.bottleneck_stage?.average_days != null && (
                      <div>
                        <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                          Slowest stage
                        </p>
                        <p className="mt-1 text-[13px] text-ovs-crm-text-secondary">
                          <span className="font-medium text-ovs-crm-text-primary">
                            {velocity.bottleneck_stage.label}
                          </span>
                          {' — '}
                          {velocity.bottleneck_stage.average_days}d across {velocity.bottleneck_stage.sample_size} deals
                        </p>
                      </div>
                    )}
                  </div>
                </>
              )}
            </CrmSection>

            {/*
              * The forecast, finally surfaced.
              *
              * It has been computed and tested since the reporting work and has
              * never had a UI. The ring is coverage — how much of the raw
              * pipeline survives once each deal is weighted by its probability
              * — which is the honest headline for a forecast.
              */}
            {/*
              * The right-hand column holds both planning sections, stacked.
              *
              * The grid stays 8/4 exactly as it was — giving each its own
              * `col-span-4` would have left the second one alone on a new row
              * with two thirds of the width empty.
              */}
            <div className="space-y-4 lg:col-span-4">
            <CrmSection
              title="Forecast"
              description="Weighted by the probability on each deal."
              icon={TrendingUp}
              hue={AREA.forecast}
              step={9}
            >
              {forecast && forecast.open_deal_count > 0 ? (
                <>
                  {/*
                    * The headline figure as a figure, not a ring.
                    *
                    * The ring that stood here plotted one number against a
                    * fixed hundred, could not be clicked, and took a hundred
                    * and forty-eight pixels of the densest column on the page
                    * to say what "63%" says. Figure is the section-level
                    * treatment the rest of the module already uses, so this
                    * reads as native rather than as a chart bolted on.
                    */}
                  <div className="flex items-end justify-between gap-4">
                    <Figure
                      label="Weighted"
                      value={money(kpis.weighted_pipeline_value)}
                      caption={coverage != null ? `${coverage}% of open pipeline` : undefined}
                      hue={AREA.forecast}
                    />
                    <CrmHairlineBar
                      value={coverage ?? 0}
                      max={100}
                      color={AREA.forecast}
                      className="mb-1 w-24 shrink-0"
                    />
                  </div>

                  <div className="mt-4 space-y-2 border-t border-ovs-crm-line pt-4">
                    {forecast.by_stage
                      .filter((row) => row.weighted_value > 0)
                      .slice(0, 4)
                      .map((row) => (
                        <div key={row.stage} className="flex items-baseline justify-between gap-3">
                          <span className="truncate text-[12.5px] text-ovs-crm-text-secondary">{row.label}</span>
                          <span className="shrink-0 text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                            {money(row.weighted_value)}
                          </span>
                        </div>
                      ))}
                  </div>

                  {/*
                    * by_period groups on expected_go_live_date, which no deal
                    * in this database has ever carried. Saying so is more use
                    * than rendering an empty block, because it names the field
                    * somebody has to start filling in.
                    */}
                  {forecast.by_period.length === 0 && (
                    <p className="mt-4 border-t border-ovs-crm-line pt-3 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
                      No deal has an expected go-live date, so this cannot yet be broken down by month.
                    </p>
                  )}
                </>
              ) : (
                <CrmDataState
                  kind="empty"
                  compact
                  icon={TrendingUp}
                  title="Nothing to forecast"
                  description="Open deals with a value appear here."
                />
              )}
            </CrmSection>

            {/*
              * Which part of healthcare is actually buying.
              *
              * A compact list rather than a chart: six rows of "label, bar,
              * money, share" is denser than any pie of the same data, it sorts,
              * and every row can be read exactly. Sits in the right column
              * beneath the forecast because both answer planning questions,
              * while the funnel on the left answers an operational one.
              *
              * Each deal counts once, under its primary specialty — see
              * CrmMetricsService::specialtyRevenue(). A multi-specialty
              * hospital contributing its ARR to four categories would make the
              * split sum to several times the pipeline it splits.
              */}
            <CrmSection
              title="Pipeline by discipline"
              description="Open value, by each practice's primary specialty."
              icon={Stethoscope}
              hue={AREA.trials}
              step={10}
            >
              {(specialty_revenue ?? []).length === 0 ? (
                <CrmDataState
                  kind="empty"
                  compact
                  icon={Stethoscope}
                  title="Nothing to split yet"
                  description="Open deals with a value appear here, grouped by specialty."
                />
              ) : (
                <div className="space-y-2.5">
                  {(specialty_revenue ?? []).map((row) => (
                    /*
                      * Reflows rather than truncates.
                      *
                      * A fixed 104px label column clipped "Super-specialty" and
                      * "Maternal & child" at 375px — the two longest names in
                      * the catalogue, and the ones a reader is least able to
                      * guess from three letters. On a phone the pill and the
                      * money take the first line and the bar spans the second;
                      * from `sm` up it collapses back to the single row it has
                      * always been. `order-*` does the rearranging, so the DOM
                      * order stays label → bar → value for a screen reader.
                      */
                    <div key={row.key} className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                      <span className="order-1 min-w-0 flex-1 sm:w-[112px] sm:flex-none">
                        <CrmStatusPill tone={row.tone}>{row.label}</CrmStatusPill>
                      </span>
                      {/*
                        * The bar takes the section's hue, not the category's.
                        *
                        * The pill beside it already encodes which discipline
                        * this is; colouring the bar the same way says it twice
                        * and makes six rows read as six unrelated charts. One
                        * colour is also what makes the lengths comparable,
                        * which is the only thing a bar is for here.
                        */}
                      <CrmHairlineBar
                        value={row.share}
                        max={100}
                        color={AREA.trials}
                        className="order-3 w-full min-w-0 sm:order-2 sm:w-auto sm:flex-1"
                      />
                      <span className="order-2 shrink-0 text-right sm:order-3">
                        <span className="block text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                          {money(row.value)}
                        </span>
                        <span className="block text-[10.5px] tabular-nums text-ovs-crm-text-muted">
                          {row.count} {row.count === 1 ? 'deal' : 'deals'} · {row.share}%
                        </span>
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </CrmSection>
            </div>
          </div>
        </Deferred>

        {/* Trials and Commercials — the two stages with machinery of their own. */}
        <Deferred
          data={['trials', 'commercials']}
          fallback={
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <CrmSection title="Trials" icon={Rocket} hue={AREA.trials}>
                <CrmDataState kind="loading" shape="cards" rows={2} />
              </CrmSection>
              <CrmSection title="Commercials" icon={FileSpreadsheet} hue={AREA.commercial}>
                <CrmDataState kind="loading" shape="cards" rows={2} />
              </CrmSection>
            </div>
          }
        >
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <CrmSection
              title="Trials"
              description="Evaluations running, and whether they reach a decision."
              icon={Rocket}
              hue={AREA.trials}
              step={10}
              href="/company/crm/trials"
              openLabel="Open trials"
            >
              {trials && (trials.running > 0 || trials.undecided > 0 || trials.decided_sample_size > 0) ? (
                <div className="flex flex-wrap items-start gap-x-10 gap-y-6">
                  {trials.running > 0 && (
                    <Figure
                      label="Running"
                      value={trials.running}
                      hue={AREA.trials}
                      caption={trials.ending_soon > 0 ? `${trials.ending_soon} end within a week` : 'All with time left'}
                    />
                  )}
                  {trials.undecided > 0 && (
                    <Figure
                      label="Past their end date"
                      value={trials.undecided}
                      hue={AREA.attention}
                      caption="Nobody recorded a decision"
                    />
                  )}
                  {trials.decided_sample_size > 0 && (
                    <Figure
                      label="Converted"
                      value={`${trials.conversion_rate ?? 0}%`}
                      hue={AREA.commercial}
                      caption={`${trials.converted_this_period} of ${trials.decided_sample_size} decided`}
                    />
                  )}
                </div>
              ) : (
                <CrmDataState
                  kind="empty"
                  compact
                  icon={Rocket}
                  title="No trials running"
                  description="A trial starts from a deal once the demo has gone well."
                />
              )}
            </CrmSection>

            <CrmSection
              title="Commercials"
              description="What has been quoted, what is blocked, what is signed."
              icon={FileSpreadsheet}
              hue={AREA.commercial}
              step={11}
              href="/company/crm/quotations"
              openLabel="Open commercials"
            >
              {commercials && commercials.open_count > 0 ? (
                <>
                  <div className="flex flex-wrap items-start gap-x-10 gap-y-6">
                    <Figure
                      label="On the table"
                      value={money(commercials.open_value)}
                      hue={AREA.commercial}
                      caption={`${commercials.open_count} open · ${commercials.drafts} never sent`}
                    />
                    {commercials.awaiting_approval > 0 && (
                      <Figure
                        label="Awaiting approval"
                        value={commercials.awaiting_approval}
                        hue={AREA.attention}
                        caption={
                          commercials.approval_threshold_percent != null
                            ? `Discounted past ${commercials.approval_threshold_percent}%`
                            : 'Discounted past the limit'
                        }
                      />
                    )}
                    {commercials.expiring_soon > 0 && (
                      <Figure
                        label="Lapsing"
                        value={commercials.expiring_soon}
                        hue={AREA.trials}
                        caption="Within a week"
                      />
                    )}
                  </div>

                  {/*
                    * Settlement is the end of the chain: a quotation approved
                    * and paid in full raises its invoice and lets the deal
                    * close. Reported apart from what is merely quoted, because
                    * quoted money is not money.
                    */}
                  {commercials.settled_count_this_period > 0 && (
                    <div className="mt-5">
                      <Figure
                        label="Settled"
                        value={money(commercials.settled_value_this_period)}
                        hue={AREA.commercial}
                        caption={`${commercials.settled_count_this_period} invoiced this period`}
                      />
                    </div>
                  )}

                  {commercials.approved_count_this_period > 0 && commercials.settled_count_this_period === 0 && (
                    <div
                      className="mt-5 rounded-[16px] px-4 py-3"
                      style={{ background: `${AREA.attention}12` }}
                    >
                      <p className="text-[11.5px] font-bold uppercase tracking-[0.12em]" style={{ color: AREA.attention }}>
                        Approved, not collected
                      </p>
                      <p className="mt-1 text-[12.5px] leading-relaxed text-ovs-crm-text-secondary">
                        {commercials.approved_count_this_period} quotation
                        {commercials.approved_count_this_period === 1 ? ' has' : 's have'} been approved this period
                        and none has settled. A deal closes on payment, not on approval.
                      </p>
                    </div>
                  )}
                </>
              ) : (
                <CrmDataState
                  kind="empty"
                  compact
                  icon={FileSpreadsheet}
                  title="Nothing quoted"
                  description="A proposal is written against a deal once the plan is agreed."
                />
              )}
            </CrmSection>
          </div>
        </Deferred>

        {/* The last wave: biggest deals, what moved, and — gated — each rep. */}
        <Deferred
          data={['top_deals', 'stalled', 'rep_triage']}
          fallback={
            <CrmSection title="Biggest open deals" icon={Target} hue={AREA.forecast}>
              <CrmDataState kind="loading" shape="list" rows={4} />
            </CrmSection>
          }
        >
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
            {(top_deals ?? []).length > 0 && (
              <CrmSection
                title="Biggest open deals"
                description="Largest by expected annual value."
                icon={Target}
                hue={AREA.forecast}
                step={12}
                className={cn((stalled?.oldest ?? []).length > 0 ? 'lg:col-span-7' : 'lg:col-span-12')}
              >
                <div className="-mx-2 space-y-0.5">
                  {(top_deals ?? []).map((deal) => (
                    <Link
                      key={deal.id}
                      href={`/company/crm/leads/${deal.id}`}
                      className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[12px] px-2 py-2.5 transition hover:bg-ovs-crm-surface-inset"
                    >
                      <span className="min-w-[170px] flex-1 basis-[170px]">
                        <span className="block truncate text-[13.5px] font-medium text-ovs-crm-text-primary">
                          {deal.company_name}
                        </span>
                        <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
                          {deal.next_action ?? deal.owner ?? 'No next action set'}
                        </span>
                      </span>
                      <span className="shrink-0 text-right">
                        <span className="block text-[13.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                          {money(deal.value)}
                        </span>
                        <span className="block text-[11px] tabular-nums text-ovs-crm-text-muted">
                          {deal.probability}% likely
                        </span>
                      </span>
                      <CrmStageBadge stage={deal.stage} label={deal.stage_label} />
                    </Link>
                  ))}
                </div>
              </CrmSection>
            )}

            {/*
              * Stalled deals, with the badges that decide who gets called.
              *
              * This replaced "recent movement" — a feed of every stage change
              * in the period. That is an audit trail: something you consult
              * when you already have a question, not something anyone acts on
              * from a dashboard, and the deal's own timeline already carries it
              * in full.
              *
              * Neglect is the opposite. It is invisible by nature, nobody goes
              * looking for it, and the row is only useful once it says what
              * kind of practice is going quiet — "stuck at Negotiation" is not
              * a priority, "a 62-bed cardiology hospital stuck at Negotiation"
              * is.
              */}
            {(stalled?.oldest ?? []).length > 0 && (
              <CrmSection
                title="Going quiet"
                description={`No stage movement in ${stalled?.threshold_days ?? 14}+ days.`}
                icon={AlertTriangle}
                hue={AREA.attention}
                step={13}
                href="/company/crm/deals?stalled_only=1"
                openLabel="Open all"
                className={cn((top_deals ?? []).length > 0 ? 'lg:col-span-5' : 'lg:col-span-12')}
              >
                <div className="-mx-2 space-y-0.5">
                  {(stalled?.oldest ?? []).slice(0, 6).map((row) => (
                    <Link
                      key={row.id}
                      href={`/company/crm/leads/${row.id}`}
                      className="block rounded-[12px] px-2 py-2 transition hover:bg-ovs-crm-surface-inset"
                    >
                      <span className="flex items-center justify-between gap-3">
                        <span className="min-w-0 truncate text-[13px] font-medium text-ovs-crm-text-primary">
                          {row.contact_name ?? row.company_name}
                        </span>
                        <span
                          className="shrink-0 text-[12px] font-medium tabular-nums"
                          style={{ color: AREA.attention }}
                        >
                          {row.stage_age_days}d
                        </span>
                      </span>

                      <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                        {/*
                          * Two badges at most. This column is five twelfths
                          * wide and a hospital running four departments would
                          * wrap every row to three lines.
                          */}
                        {row.specialties.slice(0, 2).map((specialty) => (
                          <CrmStatusPill key={specialty.key} tone={specialty.tone}>
                            {specialty.name}
                          </CrmStatusPill>
                        ))}
                        {row.facility && (
                          <span className="text-[10.5px] text-ovs-crm-text-muted">
                            {row.facility.archetype}
                          </span>
                        )}
                        <CrmStageBadge stage={row.stage} label={row.stage_label} />
                      </span>
                    </Link>
                  ))}
                </div>
              </CrmSection>
            )}
          </div>

          {/*
            * Cross-rep numbers are the only thing here that shows one person's
            * work to another, so this stays the only gated section.
            *
            * It is no longer collapsed. The disclosure made sense when this was
            * a leaderboard — analysis you open when you want it — but triage is
            * the opposite: it exists to tell a manager who is behind before
            * they think to ask. Behind a toggle it told them nothing, and on a
            * phone the toggle read as the section having failed to load. It is
            * a CrmSection now, open on arrival, matching Stalled Deals
            * immediately above it.
            */}
          {scope.can_view_team_performance && (rep_triage ?? []).length > 0 && (
            <CrmSection
              title="Team triage"
              description="Who is behind, and on what. Most behind first."
              icon={Users}
              hue={AREA.people}
              step={14}
              className="mt-4"
            >
              {/*
                * Cards, not a table.
                *
                * The seven-column leaderboard that stood here needed a 640px
                * minimum and a horizontal scroller, which on a phone meant the
                * only way to read a rep's win rate was to drag the page
                * sideways. It also answered "who is ahead" — a question nobody
                * acts on at nine in the morning. That table is unchanged and
                * still on Sales Teams, where comparing people belongs.
                *
                * One column on a phone, two on a tablet, three on a desktop.
                * Every figure is a link to the rows behind it, filtered to that
                * rep, so "5 overdue" is one tap from the five.
                */}
              <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2 xl:grid-cols-3">
                {(rep_triage ?? []).map((rep) => {
                  const demosLeft = rep.demos_today - rep.demos_conducted;

                  return (
                    <div
                      key={rep.user_id}
                      className="rounded-[16px] border border-ovs-crm-line/70 bg-white px-3.5 py-3"
                    >
                      <div className="flex min-w-0 items-center gap-2.5">
                        <CrmAvatar name={rep.name} size="sm" />
                        <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-ovs-crm-text-primary">
                          {rep.name}
                        </span>
                        {/*
                          * The one number that means somebody is behind, given
                          * the loudest treatment on the card and nothing else
                          * competing with it. Absent entirely at zero.
                          */}
                        {rep.overdue_followups > 0 && (
                          <Link
                            href={`/company/crm/deals?assigned_user_id=${rep.user_id}&overdue_only=1`}
                            className="crm-lift shrink-0 rounded-full"
                          >
                            <CrmStatusPill tone="bad">
                              {rep.overdue_followups} overdue
                            </CrmStatusPill>
                          </Link>
                        )}
                      </div>

                      <div className="mt-2.5 flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                        <Link
                          href={`/company/crm/deals?assigned_user_id=${rep.user_id}&stage=demo_scheduled`}
                          className="min-w-0 rounded-[8px] transition hover:opacity-70"
                        >
                          <span className="block text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                            Demos today
                          </span>
                          <span className="mt-0.5 block text-[15px] font-medium tabular-nums text-ovs-crm-text-primary">
                            {rep.demos_today === 0 ? '—' : `${rep.demos_conducted}/${rep.demos_today}`}
                          </span>
                          {demosLeft > 0 && (
                            <span className="block text-[10.5px] text-ovs-crm-text-muted">
                              {demosLeft} still to run
                            </span>
                          )}
                        </Link>

                        <Link
                          href={`/company/crm/deals?assigned_user_id=${rep.user_id}`}
                          className="min-w-0 rounded-[8px] text-right transition hover:opacity-70"
                        >
                          <span className="block text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                            Pipeline
                          </span>
                          <span className="mt-0.5 block text-[15px] font-medium tabular-nums text-ovs-crm-text-primary">
                            {rep.pipeline_value > 0 ? money(rep.pipeline_value) : '—'}
                          </span>
                        </Link>
                      </div>
                    </div>
                  );
                })}
              </div>
            </CrmSection>
          )}
        </Deferred>

        {/*
          * The whole-page empty state. A brand-new CRM should read as an
          * invitation, not as a grid of noughts.
          */}
        {kpis.active_pipeline_count === 0 && attentionItems.length === 0 && (
          <CrmSection title="Nothing in the pipeline yet" icon={Briefcase} hue={AREA.pipeline}>
            <p className="text-[13px] text-ovs-crm-text-muted">
              Deals appear here as leads arrive and get worked. Everything on this page is derived from them.
            </p>
            <div className="mt-4">
              <CrmButton tone="primary" icon={ArrowRight} href="/company/crm/deals">
                Go to deals
              </CrmButton>
            </div>
          </CrmSection>
        )}

      </div>
    </CrmShell>
  );
}
