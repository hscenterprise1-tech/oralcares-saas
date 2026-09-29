import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowLeft,
  ArrowUpRight,
  CalendarPlus,
  CheckCircle2,
  Circle,
  CircleSlash,
  ExternalLink,
  Lock,
  RefreshCw,
  Send,
  Trophy,
  XCircle,
} from 'lucide-react';
import {
  CrmShell,
  CrmCard,
  CrmIconButton,
  CrmStatusPill,
  CrmButton,
  CrmDataState,
} from '@/pages/Company/design';
import type { TrialPagePayload } from './trial';
import {
  PHASE_TONE,
  isClosed,
  moduleLabel,
  readableBytes,
  remainingCopy,
  sinceThen,
  writtenDate,
  writtenMoment,
} from './trialPresentation';
import { TrialExtendModal } from './components/TrialExtendModal';
import { TrialCompleteModal } from './components/TrialCompleteModal';
import { TrialLostModal } from './components/TrialLostModal';
import { TrialCancelModal } from './components/TrialCancelModal';
import { TrialTelemetryMeter } from './components/TrialTelemetryMeter';

/**
 * A trial, in full.
 *
 * Rebuilt rather than extended. The previous page kept its own local copy of
 * the payload shape — including a `converted` status the enum no longer has —
 * derived the remaining days in the browser from `end_date`, and rendered a
 * checklist nobody could complete. Every one of those is a thing the server now
 * answers.
 *
 * Six zones, in the order the questions actually get asked: where is this, can
 * they get in, are they using it, are they set up, what happens next, and what
 * has happened so far.
 */
export default function TrialShow(payload: TrialPagePayload) {
  const { trial, window: access, provisioning, usage, history, can, convertBlockers, lostReasons } = payload;

  const [extending, setExtending] = useState(false);
  const [completing, setCompleting] = useState(false);
  const [losing, setLosing] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const company = trial.lead?.companyName ?? trial.lead?.contactName ?? 'Trial';

  /*
   * Re-measure in place. The page reloads only the props the measurement
   * changes, so the reader keeps their scroll position and the rest of the page
   * does not flash.
   */
  const refreshTelemetry = () => {
    setRefreshing(true);
    router.post(route('company.crm.trials.sync-usage', trial.id), {}, {
      preserveScroll: true,
      onFinish: () => setRefreshing(false),
    });
  };

  return (
    <CrmShell title={`Trial — ${company}`}>
      <div className="mx-auto max-w-[1480px] pb-16">
        <div className="space-y-5">
          <HeaderZone
            payload={payload}
            company={company}
            onExtend={() => setExtending(true)}
            onComplete={() => setCompleting(true)}
            onMarkLost={() => setLosing(true)}
            onCancel={() => setCancelling(true)}
          />
          <AccessZone payload={payload} />
          {/*
            * Live activity ahead of the nightly engagement figures, because it
            * answers the question the rep actually opened the page with: is
            * this customer using it today.
            */}
          <TrialTelemetryMeter
            telemetry={trial.telemetry}
            onRefresh={refreshTelemetry}
            refreshing={refreshing}
            canRefresh={can.syncUsage}
          />
          <EngagementZone payload={payload} />
          <OnboardingZone payload={payload} />
          <OutcomeZone
            payload={payload}
            onExtend={() => setExtending(true)}
            onComplete={() => setCompleting(true)}
          />
          <HistoryZone history={history} />
        </div>
      </div>

      <TrialExtendModal
        isOpen={extending}
        onClose={() => setExtending(false)}
        trialId={trial.id}
        company={company}
        endDate={trial.endDate}
        extensionCount={trial.extensionCount}
      />

      <TrialCompleteModal
        isOpen={completing}
        onClose={() => setCompleting(false)}
        trialId={trial.id}
        company={company}
      />

      <TrialLostModal
        isOpen={losing}
        onClose={() => setLosing(false)}
        trialId={trial.id}
        company={company}
        reasons={lostReasons}
      />

      <TrialCancelModal
        isOpen={cancelling}
        onClose={() => setCancelling(false)}
        trialId={trial.id}
        company={company}
      />
    </CrmShell>
  );
}

/* ── T0 · Header ─────────────────────────────────────────────────────────── */

/**
 * Who, where, how long — and the way back to the deal.
 *
 * Grace gets the loudest treatment on the page. Those seven days are when the
 * decision is actually made, and a rep who scrolls past the fact that a
 * customer has three days of access left has missed the only thing that
 * mattered.
 */
function HeaderZone({
  payload,
  company,
  onExtend,
  onComplete,
  onMarkLost,
  onCancel,
}: {
  payload: TrialPagePayload;
  company: string;
  onExtend: () => void;
  onComplete: () => void;
  onMarkLost: () => void;
  onCancel: () => void;
}) {
  const { trial, window: access, provisioning, can, convertBlockers } = payload;
  const grace = trial.status === 'in_grace';

  return (
    <CrmCard
      as="header"
      aria-label="Trial summary"
      step={0}
      className={`p-5 sm:p-6 ${grace ? 'ring-2 ring-ovs-crm-accent-coral/50' : ''}`}
    >
      <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex min-w-0 items-start gap-3">
          {/* Beside the title, matching the deal pages. */}
          <CrmIconButton
            icon={ArrowLeft}
            label="Back to trials"
            tone="ghost"
            href={route('company.crm.trials.index')}
          />

        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h1 className="truncate text-xl font-semibold text-ovs-crm-text-primary sm:text-2xl">
              {company}
            </h1>
            <CrmStatusPill tone={PHASE_TONE[trial.status]}>{trial.statusLabel}</CrmStatusPill>
            {/*
              * The package as a badge rather than as a sentence fragment.
              * Which tier somebody is evaluating is the second thing a rep
              * needs after "how long is left" — it sets the whole conversation
              * — and as plain grey text beside a pill it read as a caption.
              */}
            {trial.plan && (
              <CrmStatusPill tone="info">{trial.plan.name}</CrmStatusPill>
            )}
            {/*
              * Why it ended, when it ended badly. The phase pill says
              * "Completed" for a lost trial, which on its own is misleading.
              */}
            {trial.lost && (
              <CrmStatusPill tone="bad">
                Lost{trial.lost.reason ? ` · ${trial.lost.reason}` : ''}
                {trial.lost.competitor ? ` → ${trial.lost.competitor}` : ''}
              </CrmStatusPill>
            )}
          </div>

          {/*
            * State and dates on one line, at the size of supporting text.
            *
            * This was `text-lg font-semibold` — near the weight of the company
            * name — for a phrase that is frequently just "Not started". Two
            * headlines stacked on each other is what made the header read as
            * oversized. Grace still stands out, through colour here and the
            * banner below, which is where the urgency actually belongs.
            */}
          <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
            <span className={`font-semibold ${grace ? 'text-ovs-crm-accent-coral-text' : 'text-ovs-crm-text-primary'}`}>
              {remainingCopy(trial.status, access.daysRemaining, access.state)}
            </span>

            {/*
              * A trial that has not started has no dates worth stating. The end
              * and grace dates are consequences of the start, and the clock only
              * begins when the customer can log in.
              */}
            <span className="text-ovs-crm-text-secondary">
              {trial.status === 'preparing' ? (
                <>· setup still in progress</>
              ) : (
                <>
                  {trial.startDate && <>· started {writtenDate(trial.startDate)}</>}
                  {trial.endDate && <> · ends {writtenDate(trial.endDate)}</>}
                  {trial.graceEndsAt && !isClosed(trial.status) && (
                    <> · grace to {writtenDate(trial.graceEndsAt)}</>
                  )}
                </>
              )}
            </span>
          </p>

          {grace && (
            <p className="mt-3 flex items-start gap-2 rounded-2xl bg-ovs-crm-accent-coral-soft px-4 py-3 text-sm text-ovs-crm-accent-coral-text">
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
              <span>
                The trial period has ended and the customer still has full access for{' '}
                <strong>{access.daysRemaining ?? 0} more {access.daysRemaining === 1 ? 'day' : 'days'}</strong>.
                Extend or close before it lapses.
              </span>
            </p>
          )}
        </div>
        </div>

        <div className="flex shrink-0 flex-col gap-2 lg:items-end">
          {/* Context first, then the actions — two rows, both right-aligned. */}
          <div className="flex flex-wrap items-center gap-2 lg:justify-end">
            <span className="text-xs text-ovs-crm-text-secondary">
              {trial.owner?.name ?? 'Unassigned'}
            </span>

            {trial.lead && (
              <Link
                href={route('company.crm.leads.show', trial.lead.id)}
                className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-sm font-medium text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
              >
                Deal
                <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
              </Link>
            )}

            {provisioning.clinic && (
              <a
                href={`/clinic/login`}
                className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-sm font-medium text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
              >
                {provisioning.clinic.slug}
                <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
              </a>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-2 lg:justify-end">
            {can.extend && !isClosed(trial.status) && (
              <CrmButton tone="ghost" size="sm" onClick={onExtend}>
                <CalendarPlus className="h-3.5 w-3.5" aria-hidden="true" />
                Extend
              </CrmButton>
            )}

            {can.complete && !isClosed(trial.status) && (
              <CrmButton tone="ghost" size="sm" onClick={onComplete}>
                Close trial
              </CrmButton>
            )}

            {/*
              * Losing sits beside closing rather than hiding inside it. They
              * are different outcomes — one records how the evaluation went,
              * the other records that the customer said no — and burying the
              * second is how a loss ends up recorded as a completion with no
              * reason attached.
              */}
            {can.markLost && (
              <CrmButton tone="ghost" size="sm" onClick={onMarkLost}>
                <CircleSlash className="h-3.5 w-3.5" aria-hidden="true" />
                Mark lost
              </CrmButton>
            )}

            {can.cancel && !isClosed(trial.status) && (
              <CrmButton tone="ghost" size="sm" onClick={onCancel}>
                <XCircle className="h-3.5 w-3.5" aria-hidden="true" />
                Cancel
              </CrmButton>
            )}

            <ConvertButton
              can={can.convert}
              blockers={convertBlockers}
              leadId={trial.lead?.id ?? null}
            />
          </div>
        </div>
      </div>
    </CrmCard>
  );
}

function ConvertButton({ can, blockers, leadId }: { can: boolean; blockers: string[]; leadId: number | null }) {
  if (!can || !leadId) return null;

  if (blockers.length > 0) {
    return (
      /*
       * "Convert · blocked", with the reason on hover. Spelling the reason out
       * inline made the pill wider than the three buttons beside it put
       * together, which is what unbalanced the header — and the same action on
       * the list already reads this way.
       */
      <span
        className="cursor-not-allowed whitespace-nowrap rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-xs font-medium text-ovs-crm-text-secondary"
        title={blockers.join(' ')}
      >
        Convert · blocked
      </span>
    );
  }

  return (
    <CrmButton
      tone="primary"
      size="sm"
      onClick={() => router.post(route('company.crm.leads.convert', leadId), {}, { preserveScroll: true })}
    >
      <Trophy className="h-3.5 w-3.5" aria-hidden="true" />
      Convert to customer
    </CrmButton>
  );
}

/* ── T1 · Access ─────────────────────────────────────────────────────────── */

/**
 * Can the customer actually get in?
 *
 * The question a rep asks first and the product could not previously answer.
 * A failed provision shows its real reason and its retry; a handover that was
 * not delivered shows a resend rather than leaving somebody to wonder why the
 * client never logged in.
 */
function AccessZone({ payload }: { payload: TrialPagePayload }) {
  const { trial, provisioning, window: access, usage, can } = payload;
  const failed = provisioning.error !== null;

  return (
    <Zone title="Access" step={1}>
      {failed && (
        <div className="mb-4 rounded-2xl bg-ovs-crm-accent-coral-soft p-4">
          <p className="flex items-start gap-2 text-sm font-medium text-ovs-crm-accent-coral-text">
            <XCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>Setup failed — {provisioning.error}</span>
          </p>
          {can.provision && (
            <CrmButton
              tone="primary"
              size="sm"
              className="mt-3"
              onClick={() => router.post(route('company.crm.trials.retry', trial.id), {}, { preserveScroll: true })}
            >
              <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
              Try setup again
            </CrmButton>
          )}
        </div>
      )}

      <dl className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
        <Fact label="Environment">
          {provisioning.isProvisioned ? (
            <span className="inline-flex items-center gap-1.5 text-ovs-crm-accent-green-text">
              <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
              Ready since {writtenDate(provisioning.provisionedAt)}
            </span>
          ) : (
            <span className="text-ovs-crm-text-secondary">Not built yet</span>
          )}
        </Fact>

        <Fact label="Clinic workspace">
          {provisioning.clinic ? (
            <span className="tabular-nums text-sm">{provisioning.clinic.slug}</span>
          ) : '—'}
        </Fact>

        <Fact label="Access window">
          <span className={access.grantsAccess ? '' : 'text-ovs-crm-accent-coral-text'}>
            {access.grantsAccess ? `Open · ${access.state}` : 'Closed'}
          </span>
        </Fact>

        <Fact label="Handover">
          {provisioning.handover ? (
            provisioning.handover.delivered ? (
              <span className="inline-flex items-center gap-1.5 text-ovs-crm-accent-green-text">
                <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
                Sent on {provisioning.handover.channel} · {sinceThen(provisioning.handover.at)}
              </span>
            ) : (
              <span className="text-ovs-crm-accent-yellow-text">Not delivered</span>
            )
          ) : (
            <span className="text-ovs-crm-text-secondary">Not sent</span>
          )}
        </Fact>

        <Fact label="Users">
          {usage ? `${usage.users.active7d} active of ${usage.users.total}` : (
            <span className="text-ovs-crm-text-secondary">Not measured yet</span>
          )}
        </Fact>

        <Fact label="Seats allowed">
          {access.limits?.users ?? '—'}
        </Fact>
      </dl>

      {can.provision && provisioning.isProvisioned && (
        <div className="mt-4 flex flex-wrap gap-2">
          <CrmButton
            tone="ghost"
            size="sm"
            onClick={() => router.post(route('company.crm.trials.resend-handover', trial.id), {}, { preserveScroll: true })}
          >
            <Send className="h-3.5 w-3.5" aria-hidden="true" />
            Resend setup link
          </CrmButton>
        </div>
      )}

      {/*
        * The boundary, said out loud on the page it applies to. CRM creates the
        * one handover identity; every other account in that clinic is the
        * customer's to make.
        */}
      <p className="mt-4 flex items-start gap-1.5 text-xs text-ovs-crm-text-secondary">
        <Lock className="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />
        Only the initial admin is created here. The customer adds their own staff from inside their clinic.
      </p>
    </Zone>
  );
}

/* ── T2 · Engagement ─────────────────────────────────────────────────────── */

/**
 * What they have actually done, and when we last looked.
 *
 * The measurement date is shown because it changes how the numbers should be
 * read — a figure from three days ago is a different claim from one taken last
 * night. When nothing has been measured, this says so instead of rendering
 * zeros, which would tell a rep the customer did nothing.
 */
function EngagementZone({ payload }: { payload: TrialPagePayload }) {
  const { usage, window: access } = payload;

  if (!usage) {
    return (
      <Zone title="Engagement" step={2}>
        <CrmDataState
          kind="empty"
          title="Not measured yet"
          description="Usage is counted overnight. Figures appear here after the first measurement."
        />
      </Zone>
    );
  }

  return (
    <Zone
      title="Engagement"
      step={2}
      aside={`Measured ${sinceThen(usage.capturedAt)}`}
    >
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <Stat label="Active users" value={`${usage.users.active7d}/${usage.users.total}`} note="last 7 days" />
        <Stat label="Logins" value={usage.users.logins7d} note="last 7 days" />
        <Stat label="Last login" value={sinceThen(usage.users.lastLoginAt)} />
        <Stat label="Branches" value={usage.branches} />
        <Stat label="Patients" value={usage.records.patients} />
        <Stat label="Appointments" value={usage.records.appointments} note={`${usage.records.appointments7d} in last 7 days`} />
        <Stat label="Encounters" value={usage.records.encounters} />
        <Stat label="Invoices" value={usage.records.invoices} />
      </div>

      <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="rounded-2xl bg-ovs-crm-surface-inset p-4">
          <p className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">Storage</p>
          <p className="mt-1 text-lg font-semibold tabular-nums text-ovs-crm-text-primary">
            {readableBytes(usage.storage.bytes)}
            {usage.storage.limitMb !== null && (
              <span className="text-sm font-normal text-ovs-crm-text-secondary">
                {' '}of {readableBytes(usage.storage.limitMb * 1024 * 1024)}
              </span>
            )}
          </p>
        </div>

        <div className="rounded-2xl bg-ovs-crm-surface-inset p-4">
          <p className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">WhatsApp</p>
          <p className="mt-1 text-lg font-semibold tabular-nums text-ovs-crm-text-primary">
            {usage.whatsapp.sent} sent
          </p>
          {/*
            * No plan defines an allowance, so there is no denominator to show.
            * Saying so is more useful than implying an unlimited one.
            */}
          <p className="text-xs text-ovs-crm-text-secondary">
            {usage.whatsapp.quota === null ? 'Quota not defined for this plan' : `of ${usage.whatsapp.quota}`}
          </p>
        </div>
      </div>

      {/*
        * The commercially useful half of this zone: what they are being given
        * and have not touched.
        */}
      {usage.modules.unused.length > 0 && (
        <div className="mt-5">
          <p className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">
            Included but untouched
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            {usage.modules.unused.map((m) => (
              <span key={m} className="rounded-full bg-ovs-crm-surface-inset px-3 py-1 text-xs text-ovs-crm-text-secondary">
                {moduleLabel(m)}
              </span>
            ))}
          </div>
        </div>
      )}
    </Zone>
  );
}

/* ── T3 · Onboarding ─────────────────────────────────────────────────────── */

/**
 * How ready the environment is.
 *
 * The checks are measured from the tenant's own tables, so they cannot be
 * ticked — and they are shown as locked for exactly that reason. The previous
 * page rendered a twelve-item checklist that a rep could tick and that meant
 * nothing, because nothing read it back.
 */
/*
 * Order matters, and it cannot come from the payload. `onboarding_checks` is a
 * MySQL JSON column, and MySQL sorts object keys by length — so the server's
 * deliberate order (set the clinic up, then use it) came back as branch,
 * departments, first invoice, first patient, opening hours, first appointment.
 * This array is the order; the payload supplies only the answers.
 */
const CHECK_ORDER: Array<[string, string]> = [
  ['operating_hours', 'Opening hours set'],
  ['departments', 'Departments created'],
  ['branch', 'Branch configured'],
  ['first_patient', 'First patient registered'],
  ['first_appointment', 'First appointment booked'],
  ['first_invoice', 'First invoice raised'],
];

function OnboardingZone({ payload }: { payload: TrialPagePayload }) {
  const { usage } = payload;

  if (!usage) {
    return (
      <Zone title="Setup" step={3}>
        <p className="text-sm text-ovs-crm-text-secondary">Nothing measured yet.</p>
      </Zone>
    );
  }

  /* Known checks in their intended order, then anything the server adds later. */
  const known = CHECK_ORDER.filter(([key]) => key in usage.onboarding.checks);
  const extra: Array<[string, string]> = Object.keys(usage.onboarding.checks)
    .filter((key) => !CHECK_ORDER.some(([k]) => k === key))
    .map((key) => [key, key.replace(/_/g, ' ')]);
  const checks = [...known, ...extra];

  return (
    <Zone title="Setup" step={3} aside={`${usage.onboarding.readiness}% ready`}>
      <div
        className="mb-4 h-1.5 w-full overflow-hidden rounded-full bg-ovs-crm-surface-inset"
        role="progressbar"
        aria-valuenow={usage.onboarding.readiness}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label="Setup readiness"
      >
        <div
          className="h-full rounded-full bg-ovs-crm-accent-green transition-all"
          style={{ width: `${usage.onboarding.readiness}%` }}
        />
      </div>

      <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        {checks.map(([key, label]) => {
          const done = usage.onboarding.checks[key];

          return (
            <li key={key} className="flex items-center gap-2 text-sm">
              {done ? (
                <CheckCircle2 className="h-4 w-4 shrink-0 text-ovs-crm-accent-green" aria-hidden="true" />
              ) : (
                <Circle className="h-4 w-4 shrink-0 text-ovs-crm-text-secondary" aria-hidden="true" />
              )}
              <span className={done ? 'text-ovs-crm-text-primary' : 'text-ovs-crm-text-secondary'}>
                {label}
              </span>
            </li>
          );
        })}
      </ul>

      <p className="mt-4 flex items-start gap-1.5 text-xs text-ovs-crm-text-secondary">
        <Lock className="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />
        Measured from the customer&rsquo;s own workspace overnight — these cannot be ticked by hand.
      </p>
    </Zone>
  );
}

/* ── T4 · Outcome ────────────────────────────────────────────────────────── */

/**
 * What happens next, and what has already been granted.
 *
 * The extension history is here rather than in the timeline because it is the
 * thing somebody reads when deciding whether to grant another one.
 */
function OutcomeZone({
  payload,
  onExtend,
  onComplete,
}: {
  payload: TrialPagePayload;
  onExtend: () => void;
  onComplete: () => void;
}) {
  const { trial, can, convertBlockers } = payload;
  const closed = isClosed(trial.status);

  return (
    <Zone title="Outcome" step={4}>
      {trial.extensionCount > 0 && (
        <div className="mb-4 rounded-2xl bg-ovs-crm-surface-inset p-4">
          <p className="text-sm font-medium text-ovs-crm-text-primary">
            Extended {trial.extensionCount} {trial.extensionCount === 1 ? 'time' : 'times'}
          </p>
          <p className="mt-1 text-sm text-ovs-crm-text-secondary">
            Originally ended {writtenDate(trial.originalEndDate)}, now {writtenDate(trial.endDate)}.
          </p>
          {trial.extensionReason && (
            <p className="mt-1 text-sm text-ovs-crm-text-secondary">
              Last reason: {trial.extensionReason}
            </p>
          )}
        </div>
      )}

      {trial.outcome && (
        <dl className="mb-4 space-y-2">
          <Fact label="Outcome">{trial.outcome.replace(/_/g, ' ')}</Fact>
          {trial.feedback && <Fact label="Feedback">{trial.feedback}</Fact>}
        </dl>
      )}

      {trial.cancelledAt && (
        <p className="mb-4 rounded-2xl bg-ovs-crm-surface-inset px-4 py-3 text-sm text-ovs-crm-text-secondary">
          Cancelled {writtenDate(trial.cancelledAt)}
          {trial.cancellationReason && <> — {trial.cancellationReason}</>}
        </p>
      )}

      {closed ? (
        <p className="text-sm text-ovs-crm-text-secondary">This trial is closed.</p>
      ) : (
        <div className="flex flex-wrap gap-2">
          {can.extend && (
            <CrmButton tone="ghost" onClick={onExtend}>
              <CalendarPlus className="h-4 w-4" aria-hidden="true" />
              Extend trial
            </CrmButton>
          )}
          {can.complete && (
            <CrmButton tone="ghost" onClick={onComplete}>
              Record outcome
            </CrmButton>
          )}
          <ConvertButton can={can.convert} blockers={convertBlockers} leadId={trial.lead?.id ?? null} />
        </div>
      )}
    </Zone>
  );
}

/* ── T5 · History ────────────────────────────────────────────────────────── */

/**
 * The deal's timeline, filtered to this trial.
 *
 * An unfiltered copy of the deal history would be a second place to scroll past
 * the same forty entries. What is here is what happened to the trial.
 */
function HistoryZone({ history }: { history: TrialPagePayload['history'] }) {
  return (
    <Zone title="History" step={5}>
      {history.length === 0 ? (
        <p className="text-sm text-ovs-crm-text-secondary">Nothing recorded yet.</p>
      ) : (
        <ol className="space-y-3">
          {history.map((entry) => (
            <li key={entry.id} className="flex gap-3">
              <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-ovs-crm-text-muted" aria-hidden="true" />
              <div className="min-w-0">
                <p className="text-sm text-ovs-crm-text-primary">{entry.description}</p>
                <p className="text-xs text-ovs-crm-text-secondary">
                  {writtenMoment(entry.at)}
                  {entry.by && <> · {entry.by}</>}
                </p>
              </div>
            </li>
          ))}
        </ol>
      )}
    </Zone>
  );
}

/* ── Shared bits ─────────────────────────────────────────────────────────── */

function Zone({
  title,
  step,
  aside,
  children,
}: {
  title: string;
  step: number;
  aside?: string;
  children: React.ReactNode;
}) {
  return (
    <CrmCard as="section" aria-label={title} step={step} className="p-5 sm:p-6">
      <div className="mb-4 flex items-baseline justify-between gap-3">
        <h2 className="text-sm font-semibold uppercase tracking-wider text-ovs-crm-text-secondary">
          {title}
        </h2>
        {aside && <span className="text-xs text-ovs-crm-text-secondary">{aside}</span>}
      </div>
      {children}
    </CrmCard>
  );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">{label}</dt>
      <dd className="mt-0.5 text-sm text-ovs-crm-text-primary">{children}</dd>
    </div>
  );
}

function Stat({ label, value, note }: { label: string; value: React.ReactNode; note?: string }) {
  return (
    <div className="rounded-2xl bg-ovs-crm-surface-inset p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">{label}</p>
      <p className="mt-1 text-lg font-semibold tabular-nums text-ovs-crm-text-primary">{value}</p>
      {note && <p className="text-xs text-ovs-crm-text-secondary">{note}</p>}
    </div>
  );
}
