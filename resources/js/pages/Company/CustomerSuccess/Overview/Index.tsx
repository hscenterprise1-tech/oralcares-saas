import React from 'react';
import { Deferred, Link } from '@inertiajs/react';
import {
  AlertTriangle,
  Building2,
  CalendarClock,
  CheckCircle2,
  IndianRupee,
  Layers,
  ListChecks,
  ShieldAlert,
} from 'lucide-react';
import {
  CrmButton,
  CrmCard,
  CrmCardIcon,
  CrmDataState,
  CrmGradientCard,
  CrmHairlineBar,
  CrmOpenButton,
  CrmPageHead,
  CrmSection,
  CrmSparkline,
  CrmStatusPill,
  CrmValue,
  csToneHex,
  type CsStatusTone,
  inr,
  lifecycleStageTone,
} from '@/pages/Company/design';
import { CustomerSuccessShell } from '../components/CustomerSuccessShell';

// ── Payload ────────────────────────────────────────────────────────────

interface Kpis {
  accounts: number;
  paying: number;
  mrr: number;
  arr: number;
  mrr_unrecorded: number;
  renewing_30: number;
  overdue: number;
  at_risk: number;
  mrr_at_risk: number;
}

interface AttentionItem {
  key: string;
  count: number;
  severity: 'high' | 'medium' | 'low';
  label: string;
  detail: string;
  href: string;
}

interface QueueRow {
  id: number;
  name: string;
  owner: string | null;
  mrr: number | null;
  reason: string;
  action: string;
  href: string;
  tone: CsStatusTone;
}

interface Band {
  key: string;
  label: string;
  count: number;
}

interface Distribution {
  health: Band[];
  stages: Band[];
}

interface Props {
  scope: { level: string; is_manager: boolean };
  kpis: Kpis;
  attention: AttentionItem[];
  workQueue?: QueueRow[];
  distribution?: Distribution;
  renewalHorizon?: Array<{ month: string; label: string; accounts: number; mrr: number }>;
  can?: { manageAccount?: boolean };
}

// ── Colour system ──────────────────────────────────────────────────────

/*
 * One hue per area of this module, matching the nav rail and matching CRM's
 * own scheme so the two modules read as one product. The hue is an identity,
 * not decoration: money is green wherever money appears, renewals amber, risk
 * coral. Status inside a row — healthy, watch, in trouble — is a separate scale
 * drawn from the semantic tones, so a coral card and a coral pill never come to
 * mean two different things.
 */
const AREA = {
  accounts: '#2563EB', // blue   — the book itself
  money: '#2FA96B', // green  — contracted revenue
  renewals: '#F5A623', // amber  — time-limited
  risk: '#E8593C', // coral  — something is wrong
  people: '#6B7488', // slate  — reporting on people
} as const;

/*
 * The three health bands, in the semantic vocabulary. Not a tone map of its
 * own: `healthTone()` bands a score and lives in the design kit, while this
 * names the three buckets the server already grouped into.
 */
const HEALTH_BAND_TONE: Record<string, CsStatusTone> = {
  healthy: 'good',
  watch: 'warn',
  poor: 'bad',
};

const money = (value: number) => inr(value, { compact: true });

// ── Building blocks ────────────────────────────────────────────────────

/**
 * The headline card, CRM's exactly.
 *
 * Fixed minimum height with the figure pinned to the bottom, so a row of them
 * forms a baseline the eye reads across rather than a ragged edge. Frosted in
 * the area's hue — that tint is what separates "money" from "renewals" before
 * either label is read.
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
    <CrmCard className="flex min-h-[168px] flex-col" step={step} frostHue={hue}>
      <div className="mb-4 flex items-start justify-between gap-2">
        <CrmCardIcon icon={Icon as never} hue={hue} />
        {href && <CrmOpenButton href={href} label={`Open ${label}`} />}
      </div>

      <p className="text-[13px] text-ovs-crm-text-secondary">{label}</p>

      <div className="mt-auto flex items-end justify-between gap-3 pt-3">
        <div className="min-w-0">
          <CrmValue value={value} size="major" />
          {hint && <p className="mt-1 text-[11.5px] leading-snug text-ovs-crm-text-muted">{hint}</p>}
        </div>
        {spark && spark.length > 1 && (
          <CrmSparkline points={spark} color={hue} variant={sparkVariant} className="shrink-0" />
        )}
      </div>
    </CrmCard>
  );
}

/**
 * The book as one bar, segmented by lifecycle stage.
 *
 * The rows below answer "how many are in each"; this answers "what shape is it
 * in" before a single label is read — a book bunched into onboarding or lapsed
 * looks wrong here even to somebody who has not read the legend.
 */
function StageBar({ stages }: { stages: Band[] }) {
  const total = stages.reduce((sum, stage) => sum + stage.count, 0);
  if (total === 0) return null;

  return (
    <div className="mb-5 flex h-2.5 w-full overflow-hidden rounded-full bg-ovs-crm-surface-muted">
      {stages.map((stage) => (
        <span
          key={stage.key}
          title={`${stage.label} — ${stage.count}`}
          style={{
            width: `${(stage.count / total) * 100}%`,
            background: csToneHex(lifecycleStageTone(stage.key)),
          }}
        />
      ))}
    </div>
  );
}

// ── Page ───────────────────────────────────────────────────────────────

/**
 * The state of the customer book, and the work it is asking for.
 *
 * The module opened on a list of accounts sorted by name. At a hundred and
 * twenty thousand of them that is not a starting point: nobody browses a book
 * that size, and no list answers "is this month in trouble".
 *
 * Built around one rule learned the hard way: an aggregate says how many, and
 * nobody acts on a how-many. The figures at the top set the scene; the queue
 * under them names the customers and the single next step for each, and every
 * one of those steps lands on the tab that performs it.
 */
export default function CustomerSuccessOverview({
  kpis,
  attention = [],
  workQueue,
  distribution,
  renewalHorizon,
}: Props) {
  const worst = attention.find((item) => item.severity === 'high') ?? attention[0];

  /* The busiest month ahead, so the bars below have something to scale against. */
  const peakRenewals = Math.max(1, ...(renewalHorizon ?? []).map((month) => month.accounts));

  const health = distribution?.health ?? [];
  const stages = distribution?.stages ?? [];
  const peakStage = Math.max(1, ...stages.map((stage) => stage.count));

  return (
    <CustomerSuccessShell title="Overview">
      <CrmPageHead
        eyebrow="Customer success"
        title="Overview"
        description="The book you are responsible for, and what it needs today."
      />

      <div className="space-y-4 pb-16">
        {/*
          * The headline band: the state of the book in one row, and beside it
          * the single most urgent thing, given the weight of a gradient card.
          * Emphasis only works while it is rare, so exactly one thing on this
          * page gets that treatment.
          */}
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:col-span-8">
            <StatCard
              step={0}
              icon={Building2}
              hue={AREA.accounts}
              label="Customers"
              value={kpis.accounts}
              hint={
                kpis.paying < kpis.accounts
                  ? `${kpis.paying} on a contract · ${kpis.accounts - kpis.paying} without one`
                  : 'All on a contract'
              }
              href="/company/customer-success/accounts"
              spark={stages.map((stage) => stage.count)}
            />

            <StatCard
              step={1}
              icon={IndianRupee}
              hue={AREA.money}
              label="Recurring revenue"
              value={kpis.mrr > 0 ? money(kpis.mrr) : '—'}
              hint={
                /*
                 * Said out loud rather than hidden. An unrecorded MRR is
                 * unknown, not zero, so the figure above is the part of the
                 * book that can actually be measured.
                 */
                kpis.mrr_unrecorded > 0
                  ? `${money(kpis.arr)} a year · ${kpis.mrr_unrecorded} unrecorded`
                  : `${money(kpis.arr)} a year`
              }
              href="/company/customer-success/subscriptions"
            />

            <StatCard
              step={2}
              icon={CalendarClock}
              hue={AREA.renewals}
              label="Renewing in 30 days"
              value={kpis.renewing_30}
              hint={kpis.overdue > 0 ? `${kpis.overdue} already past their date` : 'Nothing overdue'}
              href="/company/customer-success/renewals"
              spark={(renewalHorizon ?? []).map((month) => month.accounts)}
            />

            <StatCard
              step={3}
              icon={ShieldAlert}
              hue={AREA.risk}
              label="At risk"
              value={kpis.at_risk}
              hint={
                kpis.mrr_at_risk > 0
                  ? `${money(kpis.mrr_at_risk)} a month exposed`
                  : 'No recorded value exposed'
              }
              href="/company/customer-success/accounts?view=at_risk"
            />
          </div>

          <div className="lg:col-span-4">
            {worst ? (
              <CrmGradientCard
                eyebrow="Needs a person"
                headline={`${worst.count} `}
                emphasis={worst.label}
                timestamp={worst.detail}
                href={worst.href}
                label="Open them"
                tone={worst.severity === 'high' ? 'urgent' : 'calm'}
                className="h-full"
              />
            ) : (
              <CrmGradientCard
                eyebrow="All clear"
                headline="Nothing needs"
                emphasis="a person today"
                timestamp="No overdue renewals, nobody flagged, every account owned."
                tone="calm"
                className="h-full"
              />
            )}
          </div>
        </div>

        {/*
          * The full queue as counts. Every card opens the exact rows it
          * counted, so no figure on this page is a dead end. Severity drives
          * the accent, and anything at zero never arrives — an empty queue is
          * not information.
          */}
        {attention.length > 1 && (
          <CrmSection
            title="Needs a person"
            description="Each figure opens the accounts behind it."
            icon={AlertTriangle}
            hue={AREA.risk}
            step={4}
          >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {attention.map((item) => {
                const hue =
                  item.severity === 'high'
                    ? AREA.risk
                    : item.severity === 'medium'
                      ? AREA.renewals
                      : AREA.people;

                return (
                  <Link
                    key={item.key}
                    href={item.href}
                    className="crm-lift group relative flex items-start justify-between gap-3 overflow-hidden rounded-[16px] border border-ovs-crm-border bg-white px-4 py-3.5 transition hover:border-transparent hover:shadow-ovs-crmLift"
                  >
                    <span className="absolute inset-y-0 left-0 w-[3px]" style={{ background: hue }} aria-hidden="true" />
                    <span className="min-w-0 pl-1.5">
                      <span className="block text-[13px] font-medium text-ovs-crm-text-primary">{item.label}</span>
                      <span className="mt-0.5 block text-[11.5px] leading-snug text-ovs-crm-text-muted">
                        {item.detail}
                      </span>
                    </span>
                    <span
                      className="shrink-0 text-[26px] font-light leading-none tracking-[-0.03em] tabular-nums"
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
          * The one place this page names customers.
          *
          * Everything above is an aggregate — it says how many, never which,
          * and nobody acts on "four at risk". Each row here is a named account,
          * the reason it is on the list, and the single thing to do about it,
          * which lands the reader on the exact tab that does it.
          */}
        <Deferred data="workQueue" fallback={<CrmDataState kind="loading" shape="table" rows={5} />}>
          <CrmSection
            title="Do this next"
            description="Named accounts, worst first. Each row opens the tab that fixes it."
            icon={ListChecks}
            hue={AREA.risk}
            step={5}
          >
            {(workQueue ?? []).length === 0 ? (
              <CrmDataState
                kind="empty"
                icon={CheckCircle2}
                title="Nothing waiting"
                description="No overdue renewal, nobody flagged, nothing over capacity and no account without an owner."
              />
            ) : (
              <div className="space-y-2.5">
                {(workQueue ?? []).map((row) => (
                  <div
                    key={row.id}
                    className="crm-lift relative flex flex-wrap items-center gap-x-4 gap-y-2 overflow-hidden rounded-[16px] border border-ovs-crm-border bg-white px-4 py-3 transition hover:shadow-ovs-crmLift"
                  >
                    <span
                      className="absolute inset-y-0 left-0 w-[3px]"
                      style={{ background: csToneHex(row.tone) }}
                      aria-hidden="true"
                    />

                    <Link
                      href={`/company/customer-success/accounts/${row.id}`}
                      className="min-w-[150px] flex-1 truncate pl-1.5 text-[13.5px] font-medium text-ovs-crm-text-primary hover:text-ovs-crm-brand-text"
                    >
                      {row.name}
                    </Link>

                    <CrmStatusPill tone={row.tone}>{row.reason}</CrmStatusPill>

                    <span className="w-[86px] shrink-0 text-right text-[12.5px] font-medium tabular-nums text-ovs-crm-text-secondary">
                      {row.mrr !== null ? `${money(row.mrr)}/mo` : 'Not recorded'}
                    </span>

                    <span className="w-[110px] shrink-0 truncate text-right text-[12px] text-ovs-crm-text-muted">
                      {row.owner ?? 'No owner'}
                    </span>

                    <CrmButton tone="secondary" size="sm" href={row.href} className="shrink-0">
                      {row.action}
                    </CrmButton>
                  </div>
                ))}
              </div>
            )}
          </CrmSection>
        </Deferred>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {/*
            * Forward load. This is the one question the queue cannot answer: it
            * shows today, not what today is building towards.
            */}
          <Deferred
            data="renewalHorizon"
            fallback={<CrmDataState kind="loading" shape="table" rows={4} className="lg:col-span-7" />}
          >
            <CrmSection
              title="What is renewing"
              description="The next six months, so a heavy one is visible before it arrives."
              icon={CalendarClock}
              hue={AREA.renewals}
              step={6}
              href="/company/customer-success/renewals"
              openLabel="Open renewals"
              className="lg:col-span-7"
            >
              {(renewalHorizon ?? []).length === 0 ? (
                <CrmDataState
                  kind="empty"
                  icon={CalendarClock}
                  title="Nothing due"
                  description="No contract comes up for renewal in the next six months."
                />
              ) : (
                <div className="-mx-2 space-y-0.5">
                  {(renewalHorizon ?? []).map((month) => (
                    <Link
                      key={month.month}
                      href="/company/customer-success/renewals"
                      className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[12px] px-2 py-2 transition hover:bg-ovs-crm-surface-inset"
                    >
                      <span className="w-[72px] shrink-0 text-[12.5px] text-ovs-crm-text-secondary">{month.label}</span>
                      <span className="flex min-w-[110px] flex-1 items-center gap-3">
                        <CrmHairlineBar
                          value={month.accounts}
                          max={peakRenewals}
                          color={AREA.renewals}
                          className="flex-1"
                        />
                        <span className="w-8 shrink-0 text-right text-[13px] tabular-nums text-ovs-crm-text-primary">
                          {month.accounts}
                        </span>
                      </span>
                      <span className="w-[74px] shrink-0 text-right text-[12.5px] font-medium tabular-nums text-ovs-crm-text-secondary">
                        {month.mrr > 0 ? money(month.mrr) : '—'}
                      </span>
                    </Link>
                  ))}
                </div>
              )}
            </CrmSection>
          </Deferred>

          {/*
            * Where the book sits, as a ladder. Kept because each rung opens the
            * accounts on it — "nine lapsed" is only worth printing if clicking
            * it produces the nine.
            */}
          <Deferred
            data="distribution"
            fallback={<CrmDataState kind="loading" shape="table" rows={4} className="lg:col-span-5" />}
          >
            <CrmSection
              title="Where everybody is"
              description="Each stage opens the accounts sitting in it."
              icon={Layers}
              hue={AREA.accounts}
              step={7}
              className="lg:col-span-5"
            >
              {stages.length === 0 ? (
                <CrmDataState
                  kind="empty"
                  icon={Layers}
                  title="No accounts yet"
                  description="Stages appear here as customers are provisioned."
                />
              ) : (
                <>
                  <StageBar stages={stages} />

                  <div className="-mx-2 space-y-0.5">
                    {stages.map((stage) => {
                      const hue = csToneHex(lifecycleStageTone(stage.key));

                      return (
                        <Link
                          key={stage.key}
                          href={`/company/customer-success/accounts?stage=${stage.key}`}
                          className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[12px] px-2 py-2 transition hover:bg-ovs-crm-surface-inset"
                        >
                          <span className="flex w-[130px] shrink-0 items-center gap-2.5">
                            <span
                              className="h-2 w-2 shrink-0 rounded-full"
                              style={{ background: hue }}
                              aria-hidden="true"
                            />
                            <span className="truncate text-[13px] text-ovs-crm-text-primary">{stage.label}</span>
                          </span>
                          <span className="flex min-w-[90px] flex-1 items-center gap-3">
                            <CrmHairlineBar value={stage.count} max={peakStage} color={hue} className="flex-1" />
                            <span className="w-8 shrink-0 text-right text-[13px] tabular-nums text-ovs-crm-text-primary">
                              {stage.count}
                            </span>
                          </span>
                        </Link>
                      );
                    })}
                  </div>

                  {/*
                    * Health as three numbers rather than a chart. A donut of
                    * the same three counts looked considered and told a reader
                    * nothing they could do anything about.
                    */}
                  <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-ovs-crm-border pt-4">
                    {health.map((band) => (
                      <span key={band.key} className="flex items-center gap-2">
                        <span
                          className="h-2 w-2 rounded-full"
                          style={{ background: csToneHex(HEALTH_BAND_TONE[band.key] ?? 'neutral') }}
                          aria-hidden="true"
                        />
                        <span className="text-[12.5px] text-ovs-crm-text-secondary">{band.label}</span>
                        <span className="text-[13px] font-medium tabular-nums text-ovs-crm-text-primary">
                          {band.count}
                        </span>
                      </span>
                    ))}
                  </div>
                </>
              )}
            </CrmSection>
          </Deferred>
        </div>
      </div>
    </CustomerSuccessShell>
  );
}
