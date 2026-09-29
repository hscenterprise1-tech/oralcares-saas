import React, { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, CalendarPlus, Search, Trophy, XCircle } from 'lucide-react';
import {
  CrmShell,
  CrmPageHead,
  CrmCard,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmDataState,
  CrmValue,
} from '@/pages/Company/design';
import type { TrialListPayload, TrialRow, TrialStatusValue, TrialTelemetry } from './Trials/trial';
import {
  PHASE_TONE,
  isClosed,
  remainingCopy,
  sinceThen,
  writtenDate,
} from './Trials/trialPresentation';
import { TrialExtendModal } from './Trials/components/TrialExtendModal';
import { TrialLostModal } from './Trials/components/TrialLostModal';

/**
 * The trials board.
 *
 * Rebuilt against the server contract rather than extended: the previous list
 * read raw models, computed its own phase from `end_date`, and sorted by date.
 * Sorting a trials board by date buries the customer whose access runs out on
 * Friday underneath twenty that end next month.
 *
 * So the order is urgency, decided server-side: grace first, because that is
 * the only phase where somebody is still working and the clock is running out;
 * then ending soon; then trials that failed to provision, which nobody is
 * evaluating at all; then the running ones; then everything already decided.
 */

/** The groups, in the order a rep should meet them. */
const GROUPS: Array<{ key: string; label: string; phases: TrialStatusValue[]; urgent?: boolean }> = [
  { key: 'grace', label: 'In grace', phases: ['in_grace'], urgent: true },
  { key: 'ending', label: 'Ending soon', phases: ['ending_soon'], urgent: true },
  { key: 'preparing', label: 'Preparing', phases: ['preparing'] },
  { key: 'running', label: 'Running', phases: ['active', 'extended'] },
  { key: 'closed', label: 'Closed', phases: ['completed', 'quotation_requested', 'cancelled', 'expired'] },
];

export default function TrialsWorkspace({ rows = [], counts, health, filters, lostReasons = [] }: TrialListPayload) {
  const [search, setSearch] = useState(filters?.search ?? '');
  const [extending, setExtending] = useState<TrialRow | null>(null);
  const [losing, setLosing] = useState<TrialRow | null>(null);

  const visible = useMemo(() => {
    const term = search.trim().toLowerCase();

    if (!term) return rows;

    return rows.filter((row) =>
      `${row.company} ${row.owner ?? ''} ${row.planName ?? ''}`.toLowerCase().includes(term));
  }, [rows, search]);

  const grouped = useMemo(
    () => GROUPS.map((group) => ({
      ...group,
      rows: visible.filter((row) => group.phases.includes(row.phase)),
    })).filter((group) => group.rows.length > 0),
    [visible],
  );

  return (
    <CrmShell title="Trials">
      <div className="pb-16">
        <CrmPageHead
          eyebrow="Clinic evaluation watchtower"
          title="Trials"
        />

        {/*
          * Engagement first, lifecycle second.
          *
          * These four answer "who is actually using it, and who is about to
          * run out" — the questions a renewal conversation turns on. The phase
          * tallies below answer "where is everything", which matters less at
          * eight in the morning. Only live trials are counted, so a completed
          * one is never reported as at risk.
          */}
        <div className="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <HealthCard
            label="Active trials"
            value={health.active}
            tone="neutral"
            hint={health.unmeasured > 0 ? `${health.unmeasured} not measured yet` : undefined}
          />
          <HealthCard
            label="Healthy"
            value={health.healthy}
            tone="good"
            hint="3+ prescriptions printed"
          />
          <HealthCard
            label="At risk"
            value={health.at_risk}
            tone="bad"
            hint="No sign-in for 48h+"
          />
          <HealthCard
            label="Expiring in 48h"
            value={health.expiring_48h}
            tone="warn"
            hint="Access ends within two days"
          />
        </div>

        {/*
          * Counts, not a dashboard. Four numbers a rep actually acts on, with
          * the two that mean "somebody is about to lose access" first.
          */}
        <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          <Tally label="In grace" value={counts.grace} tone="bad" />
          <Tally label="Ending soon" value={counts.endingSoon} tone="warn" />
          <Tally label="Setup failed" value={counts.failed} tone="bad" />
          <Tally label="Running" value={counts.running} tone="good" />
          {/*
            * Lost is counted apart from Closed. Every lost trial is closed, and
            * folding them together hides the only number in this row that says
            * anything about how the product is landing.
            */}
          <Tally label="Lost" value={counts.lost} tone="bad" />
          <Tally label="Closed" value={counts.closed} tone="neutral" />
        </div>

        <div className="mb-5">
          <label className="sr-only" htmlFor="trial-search">Search trials</label>
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ovs-crm-text-secondary" aria-hidden="true" />
            <CrmInput
              id="trial-search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by company, owner or plan"
              className="pl-9"
            />
          </div>
        </div>

        {grouped.length === 0 ? (
          <CrmDataState
            kind="empty"
            title={search ? 'Nothing matches that search' : 'No trials yet'}
            description={search
              ? 'Try a different company or owner.'
              : 'Trials appear here once one is started from a deal.'}
          />
        ) : (
          <div className="space-y-8">
            {grouped.map((group) => (
              <section key={group.key} aria-labelledby={`group-${group.key}`}>
                <h2
                  id={`group-${group.key}`}
                  className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-ovs-crm-text-secondary"
                >
                  {group.urgent && <AlertTriangle className="h-3.5 w-3.5 text-ovs-crm-accent-coral" aria-hidden="true" />}
                  {group.label}
                  <span className="font-normal text-ovs-crm-text-secondary">{group.rows.length}</span>
                </h2>

                <div className="space-y-3">
                  {group.rows.map((row, index) => (
                    <TrialListRow
                      key={row.id}
                      row={row}
                      step={index}
                      onExtend={() => setExtending(row)}
                      onMarkLost={() => setLosing(row)}
                    />
                  ))}
                </div>
              </section>
            ))}
          </div>
        )}
      </div>

      {losing && (
        <TrialLostModal
          isOpen
          trialId={losing.id}
          company={losing.company}
          reasons={lostReasons}
          onClose={() => setLosing(null)}
        />
      )}

      {extending && (
        <TrialExtendModal
          isOpen
          trialId={extending.id}
          company={extending.company}
          endDate={extending.endDate}
          extensionCount={extending.extensionCount}
          onClose={() => setExtending(null)}
        />
      )}
    </CrmShell>
  );
}

function Tally({ label, value, tone }: { label: string; value: number; tone: string }) {
  return (
    <CrmCard className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">{label}</p>
      <p className="mt-1 flex items-baseline gap-2">
        <CrmValue value={value} size="minor" />
        {value > 0 && tone !== 'neutral' && (
          <span className={`h-1.5 w-1.5 rounded-full ${tone === 'bad' ? 'bg-ovs-crm-accent-coral' : tone === 'warn' ? 'bg-ovs-crm-accent-yellow' : 'bg-ovs-crm-accent-green'}`} aria-hidden="true" />
        )}
      </p>
    </CrmCard>
  );
}

/**
 * One trial.
 *
 * Everything a rep needs to decide whether to open it: who, what phase, how
 * long they have, whether they are actually using it, and who owns it. The
 * actions are the two that move a trial forward — extend it, or close it.
 */
function TrialListRow({
  row,
  step,
  onExtend,
  onMarkLost,
}: {
  row: TrialRow;
  step: number;
  onExtend: () => void;
  onMarkLost: () => void;
}) {
  const failed = row.provisioning.error !== null;
  const grace = row.phase === 'in_grace';

  return (
    <CrmCard
      step={step}
      className={`p-4 sm:p-5 ${grace ? 'ring-1 ring-ovs-crm-accent-coral/40' : ''}`}
    >
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        {/* Who, and where they are */}
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <Link
              href={route('company.crm.trials.show', row.id)}
              className="truncate text-base font-semibold text-ovs-crm-text-primary hover:underline"
            >
              {row.company}
            </Link>
            <CrmStatusPill tone={PHASE_TONE[row.phase]}>{row.phaseLabel}</CrmStatusPill>
            {/*
              * Which package they are evaluating. A row that says "ending soon"
              * without saying "on Enterprise" leaves out the number the rep is
              * about to quote.
              */}
            {row.package && <CrmStatusPill tone="info">{row.package.name}</CrmStatusPill>}
            {/*
              * Live activity, as one chip. The detailed counters are further
              * along the row; this is the verdict — the thing that decides
              * whether this row gets a call today.
              */}
            <HealthBadge telemetry={row.telemetry} />
            {/* Why it ended, when the phase pill alone would say only "Completed". */}
            {row.lost && (
              <CrmStatusPill tone="bad">
                Lost{row.lost.competitor ? ` → ${row.lost.competitor}` : ''}
              </CrmStatusPill>
            )}
            {row.extensionCount > 0 && (
              <span className="text-xs text-ovs-crm-text-secondary">
                extended {row.extensionCount}&times;
              </span>
            )}
          </div>

          <p className={`mt-1 text-sm ${grace ? 'font-semibold text-ovs-crm-accent-coral-text' : 'text-ovs-crm-text-secondary'}`}>
            {remainingCopy(row.phase, row.daysRemaining, row.windowState)}
            {/*
              * No end date while the trial has not started. Until an
              * environment exists the date is a plan, not a fact — the clock
              * starts when the customer can actually log in, so "Not started ·
              * ends 25 Aug" was stating an ending for something with no
              * beginning.
              */}
            {row.endDate && !isClosed(row.phase) && row.phase !== 'preparing' && (
              <span className="text-ovs-crm-text-secondary"> · ends {writtenDate(row.endDate)}</span>
            )}
          </p>

          {/*
            * A failed setup is the loudest thing on a row. Nobody is evaluating
            * anything, and the trial clock is running while they wait.
            */}
          {failed && (
            <p className="mt-2 flex items-start gap-1.5 rounded-2xl bg-ovs-crm-accent-coral-soft px-3 py-2 text-xs text-ovs-crm-accent-coral-text">
              <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
              <span>Setup failed — {row.provisioning.error}</span>
            </p>
          )}
        </div>

        {/*
          * What they have actually done, as three bars.
          *
          * This replaced a list of nightly-snapshot figures — active users,
          * patients, last login. Those are facts about the tenant; these are
          * facts about adoption, which is the question a trial is actually
          * asking. The bars read proportionally, so "barely started" and
          * "running the practice on it" are distinguishable at a glance rather
          * than by comparing three numbers to a scale held in your head.
          */}
        <div className="lg:w-60 lg:shrink-0">
          <TelemetryBars telemetry={row.telemetry} />
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {row.can.extend && !isClosed(row.phase) && (
            <CrmButton tone="ghost" size="sm" onClick={onExtend}>
              <CalendarPlus className="h-3.5 w-3.5" aria-hidden="true" />
              Extend
            </CrmButton>
          )}
          {/*
            * Losing sits beside extending rather than behind the detail page.
            * The moment a rep knows is the moment they are looking at the
            * board, and a loss recorded three clicks later is a loss recorded
            * without its reason.
            */}
          {row.can.markLost && (
            <CrmButton tone="ghost" size="sm" onClick={onMarkLost}>
              <XCircle className="h-3.5 w-3.5" aria-hidden="true" />
              Mark lost
            </CrmButton>
          )}
          <ConvertAction row={row} />
        </div>
      </div>
    </CrmCard>
  );
}

/**
 * Close the deal behind the trial.
 *
 * The convert endpoint refuses without an approved quotation settled in full.
 * That is a commercial fact rather than a permission, so it is shown on the
 * disabled button — discovering it by pressing the button and reading an error
 * flash is a worse way to learn the same thing.
 */
function ConvertAction({ row }: { row: TrialRow }) {
  if (!row.can.convert) return null;

  const blocked = row.convertBlockers.length > 0;

  if (blocked) {
    return (
      <span
        className="cursor-not-allowed rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-xs font-medium text-ovs-crm-text-secondary"
        title={row.convertBlockers.join(' ')}
      >
        Convert · blocked
      </span>
    );
  }

  return (
    <CrmButton
      tone="primary"
      size="sm"
      onClick={() => router.post(route('company.crm.leads.convert', row.leadId), {}, { preserveScroll: true })}
    >
      <Trophy className="h-3.5 w-3.5" aria-hidden="true" />
      Convert
      <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
    </CrmButton>
  );
}

/**
 * One headline figure, with the rule that produced it written underneath.
 *
 * The hint is not decoration. "Healthy: 2" invites the reader to invent a
 * definition; "3+ prescriptions printed" tells them the one the service used,
 * so nobody has to guess whether logins counted.
 */
function HealthCard({
  label,
  value,
  tone,
  hint,
}: {
  label: string;
  value: number;
  tone: 'good' | 'warn' | 'bad' | 'neutral';
  hint?: string;
}) {
  const accent: Record<string, string> = {
    good: 'text-ovs-crm-accent-green-text',
    warn: 'text-ovs-crm-accent-yellow-text',
    bad: 'text-ovs-crm-accent-coral-text',
    neutral: 'text-ovs-crm-text-primary',
  };

  return (
    <CrmCard className="p-4">
      <p className="text-[11.5px] font-medium uppercase tracking-wide text-ovs-crm-text-muted">{label}</p>
      <p className={`mt-1 text-[26px] font-semibold tabular-nums ${accent[tone]}`}>{value}</p>
      {hint && <p className="mt-0.5 text-[11px] text-ovs-crm-text-muted">{hint}</p>}
    </CrmCard>
  );
}

/**
 * Three bars: patients added, prescriptions printed, invoices generated.
 *
 * Bars rather than bare numbers because the useful reading is proportional —
 * "barely started" versus "running the practice on it" — and a number alone
 * makes the reader hold a scale in their head. The denominators below are
 * what a fortnight of ordinary use looks like in a small practice, so a full
 * bar means "they have adopted it", not "they hit a quota".
 */
function TelemetryBars({ telemetry }: { telemetry: TrialTelemetry | null }) {
  if (!telemetry) {
    /*
     * Not three empty bars. Empty bars claim the customer did nothing, which is
     * a different and far more damaging thing to tell a salesperson than "we
     * have not measured them".
     */
    return (
      <p className="text-[11.5px] text-ovs-crm-text-muted">Usage not measured yet</p>
    );
  }

  const bars = [
    { label: 'Patients', value: telemetry.patients, target: 25 },
    { label: 'Rx', value: telemetry.prescriptions, target: 10 },
    { label: 'Invoices', value: telemetry.invoices, target: 10 },
  ];

  return (
    <div className="space-y-1.5">
      {bars.map((bar) => {
        const pct = Math.min(100, Math.round((bar.value / bar.target) * 100));

        return (
          <div key={bar.label} className="flex items-center gap-2">
            <span className="w-14 shrink-0 text-[10.5px] text-ovs-crm-text-muted">{bar.label}</span>
            <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-ovs-crm-surface-inset">
              <span
                className="block h-full rounded-full bg-ovs-crm-brand"
                style={{ width: `${pct}%` }}
              />
            </span>
            <span className="w-7 shrink-0 text-right text-[11px] font-semibold tabular-nums text-ovs-crm-text-primary">
              {bar.value}
            </span>
          </div>
        );
      })}
    </div>
  );
}

/**
 * Green / amber / red, from the same derived flags every other surface reads.
 */
function HealthBadge({ telemetry }: { telemetry: TrialTelemetry | null }) {
  if (!telemetry) {
    return <CrmStatusPill tone="neutral">Not measured</CrmStatusPill>;
  }

  if (telemetry.is_active_today) {
    return <CrmStatusPill tone="good">Active today</CrmStatusPill>;
  }

  if (telemetry.is_inactive) {
    const hours = telemetry.hours_since_login;

    return (
      <CrmStatusPill tone="bad">
        {hours === null ? 'Never signed in' : `Inactive ${Math.floor(hours / 24)}d`}
      </CrmStatusPill>
    );
  }

  return <CrmStatusPill tone="warn">Low usage</CrmStatusPill>;
}
