import React from 'react';
import { Deferred, router, useForm } from '@inertiajs/react';
import {
  CrmShell,
  CrmCard,
  CrmSection,
  CrmTable,
  CrmTabs,
  CrmStatusPill,
  CrmBadge,
  CrmButton,
  CrmIconButton,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  CrmFormField,
  CrmModal,
  CrmConfirm,
  CrmDataState,
  CrmHairlineBar,
  inr,
  toneHex,
  channelTone,
  specialtyTone,
  toneClasses,
  type CrmColumn,
  type CrmStatusTone,
} from '@/pages/Company/design';
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  Building2,
  CalendarClock,
  CalendarPlus,
  Check,
  CheckCheck,
  CheckCircle2,
  Copy,
  Download,
  FileText,
  Globe,
  History,
  IndianRupee,
  Lock,
  Mail,
  MapPin,
  MessageCircle,
  MoreHorizontal,
  Paperclip,
  Phone,
  RefreshCw,
  RotateCcw,
  Send,
  Stethoscope,
  Target,
  Trash2,
  Upload,
  UserCog,
  Video,
  XCircle,
  Zap,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { LeadScheduleDemoModal } from './components/LeadScheduleDemoModal';
import { LeadStartTrialModal } from './components/LeadStartTrialModal';
import { LeadLostModal } from './components/LeadLostModal';
import { LeadReopenModal } from './components/LeadReopenModal';
import { QuotationReviseModal } from './components/QuotationReviseModal';
import { LeadCallModal } from './components/LeadCallModal';
import { RecordAdvancePaymentModal } from './components/RecordAdvancePaymentModal';
import type {
  Deal,
  DealCan,
  DealBranchSummary,
  DealDemo,
  DealFile,
  DealOutreach,
  DealTrial,
  DealQuotation,
  WhatsAppMessage,
  EmailThread,
  DealCommercial as CommercialData,
  DealEvaluation as EvaluationData,
  DealHistory as HistoryData,
  DealNow as NowData,
  StageTransition,
  DealPageProps,
} from './deal';

/**
 * Deal 360 — one screen, one file.
 *
 * This page has been taken apart twice. The first version was seven zones, each
 * behind `Inertia::defer()`, across six request groups: 91% of the page arrived
 * after first paint in six waves, each replacing a fixed 315px grey rectangle
 * with content of an unrelated height, so the page visibly settled six times
 * before it could be read. The second version fixed the payload but kept the
 * shape — three `cockpit/*` files rendering a stack of same-weight cards, which
 * read as the same patchwork with different filenames.
 *
 * This is the third and it is deliberately one file. Not because large files are
 * good, but because the fragmentation *was* the defect: a deal page assembled
 * from six components nobody owns ends up with six headers, six ideas of
 * spacing, and no single place where the hierarchy of the screen is decided.
 * Everything below is composed from the shared design primitives — `CrmCard`,
 * `CrmSection`, `CrmTable`, `CrmStatusPill`, `CrmButton` — so there is no local
 * styling language either.
 *
 * The shape, top to bottom:
 *
 *   masthead   who, what it is worth, what they practise, how to reach them,
 *              and a six-step rail. Four lines, no card borders between them.
 *   left       the stage hero — whichever of demo / trial / closing / inflow
 *              this deal is actually doing — then the estate, then the money.
 *   right      one chronological stream of everything said and done, with a
 *              composer on top.
 *
 * Every figure comes straight from props. The only deferred thing on the page
 * is the activity stream, which lives in its own column and therefore cannot
 * move anything a rep is reading or clicking.
 */

const money = (n: number) => inr(n, { compact: true });

/* ── The six-step rail ──────────────────────────────────────────────── */

/**
 * The six steps a deal is actually worked in.
 *
 * The rail used to render all ten pipeline statuses flat, which is the right
 * vocabulary for a *record* and the wrong one for a bar somebody reads at a
 * glance: nobody's mental model of a deal has separate steps for "quotation
 * sent" and "quotation approved". Those four commercial statuses are one thing
 * happening, and the two demo statuses are one thing happening too.
 *
 * Collapsing them loses nothing, because the precise status prints under the
 * step that contains it. `entry` is the status a click targets — the group's
 * first status in pipeline order, which is right in both directions: advancing
 * into Demo means booking one, and coming back to Demo from Negotiation means
 * going back to the booking.
 */
const STEPS: { key: string; label: string; entry: string; members: string[] }[] = [
  /*
   * `assigned` is retired and cannot be entered, but rows that still carry it
   * read as intake — so the rail shows them on Inflow rather than nowhere.
   */
  { key: 'inflow', label: 'Inflow', entry: 'new', members: ['new', 'assigned'] },
  { key: 'contacted', label: 'Contacted', entry: 'contacted', members: ['contacted'] },
  { key: 'demo', label: 'Demo', entry: 'demo_scheduled', members: ['demo_scheduled', 'demo_completed'] },
  { key: 'trial', label: 'Trial', entry: 'trial_active', members: ['trial_active'] },
  {
    key: 'closing',
    label: 'Closing',
    entry: 'negotiation',
    members: ['negotiation', 'quotation_sent', 'quotation_approved', 'payment_received'],
  },
  { key: 'won', label: 'Won', entry: 'won', members: ['won'] },
];

/**
 * The facility archetypes, mirroring `App\FacilityType`.
 *
 * Labels are the enum's own `label()` output, so the dropdown, the Deals list
 * badge and the masthead cannot describe the same archetype differently.
 * `UpdateLeadRequest` validates against the enum, so a value outside this list
 * is refused rather than reaching the cast and throwing a `ValueError`.
 */
const FACILITY_TYPES: { value: string; label: string }[] = [
  { value: 'solo_clinic', label: 'Solo practice' },
  { value: 'polyclinic', label: 'Polyclinic' },
  { value: 'daycare_nursing_home', label: 'Daycare / nursing home' },
  { value: 'multi_specialty_hospital', label: 'Multi-specialty hospital' },
  { value: 'diagnostic_center', label: 'Diagnostic centre' },
];

/** Which workflow a stage the rep cannot yet reach should open. */
type Workflow =
  | 'demo'
  | 'trial'
  | 'lost'
  | 'reopen'
  | 'call'
  | 'task'
  | 'reassign'
  | 'delete'
  | 'discovery'
  | 'payment'
  | null;

/**
 * The form that satisfies each unmet requirement.
 *
 * The server names the remedy — `DealStageService::REQUIREMENTS` carries an
 * `action` per stage — and this maps it to the modal that performs it. A stage
 * with no form on this page (raising a quotation, recording a payment) falls
 * back to showing the requirement as a tooltip.
 */
const ACTION_MODAL: Record<string, Exclude<Workflow, null>> = {
  log_contact: 'call',
  schedule_demo: 'demo',
  start_trial: 'trial',
  provide_lost_reason: 'lost',
  complete_commercials: 'payment',
};

function StageRail({
  transitions,
  dealId,
  status,
  statusLabel,
  canUpdate,
  onNeedsAction,
}: {
  transitions: StageTransition[];
  dealId: number;
  status: string;
  statusLabel: string;
  canUpdate: boolean;
  onNeedsAction: (workflow: Exclude<Workflow, null>) => void;
}) {
  const [moving, setMoving] = React.useState<string | null>(null);

  const byValue = React.useMemo(
    () => Object.fromEntries(transitions.map((t) => [t.value, t])),
    [transitions]
  );

  const currentStep = STEPS.findIndex((step) => step.members.includes(status));

  const move = (value: string) => {
    setMoving(value);
    router.post(
      route('company.crm.leads.transition', dealId),
      /*
       * `stage`, which is what `LeadController::transitionStage()` validates.
       * An earlier rail posted `status` and failed validation on every click.
       */
      { stage: value },
      { preserveScroll: true, onFinish: () => setMoving(null) }
    );
  };

  /*
   * A won deal is a live customer, not a position on a rail.
   *
   * The state machine refuses every move out of Won, so six pressable steps
   * with five padlocks would be six controls that do nothing.
   */
  if (status === 'won') {
    return (
      <div className="flex flex-wrap items-center gap-2">
        <span className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-accent-green-soft px-3 py-1 text-[11.5px] font-semibold text-ovs-crm-accent-green-text">
          <Check className="h-3 w-3 shrink-0" aria-hidden="true" />
          Won — active customer
        </span>
        <span className="inline-flex items-center gap-1 text-[11.5px] text-ovs-crm-text-muted">
          <Lock className="h-3 w-3 shrink-0" aria-hidden="true" />
          The pipeline is closed for this deal
        </span>
      </div>
    );
  }

  /*
   * The next step, for the phone.
   *
   * Six steps and five connectors do not fit on a 375px screen: the rail wrapped
   * to three lines with "Won" dangling alone on the last, which reads as a
   * layout accident rather than a pipeline. A phone does not need the whole road
   * anyway — it needs to know where the deal is and what the next move is.
   */
  const nextStep = currentStep >= 0 ? STEPS[currentStep + 1] : undefined;
  const nextTarget = nextStep ? byValue[nextStep.entry] : undefined;
  const nextReady = nextTarget?.allowed ?? false;
  const nextRemedy = !nextReady && nextTarget?.action ? ACTION_MODAL[nextTarget.action] : undefined;
  const canAdvance = Boolean(
    canUpdate && nextStep && !(nextTarget?.blocked ?? false) && (nextReady || nextRemedy) && moving === null
  );

  return (
    <>
      {/*
        * Under 640px: one line. A position and a next move.
        *
        * Rendered as a sibling of the rail rather than swapped in by a
        * matchMedia hook — the breakpoint is a CSS concern, and `display: none`
        * takes the hidden one out of the accessibility tree as well, so there
        * is never a duplicate set of steps for a screen reader to walk.
        */}
      <div className="flex flex-wrap items-center gap-2 sm:hidden">
        <span className="inline-flex min-w-0 items-center gap-1.5 rounded-full bg-ovs-crm-ink px-3 py-1.5 text-[11.5px] font-medium text-white">
          {currentStep >= 0 ? (
            <>
              <span className="tabular-nums opacity-70">
                Step {currentStep + 1}/{STEPS.length}
              </span>
              <span className="truncate">
                {STEPS[currentStep].label}
                {/* The precise status, where the step covers more than one. */}
                {STEPS[currentStep].members.length > 1 && (
                  <span className="opacity-75"> · {statusLabel}</span>
                )}
              </span>
            </>
          ) : (
            <span className="truncate">{statusLabel}</span>
          )}
        </span>

        {canAdvance && nextStep && (
          <CrmButton
            size="sm"
            tone="primary"
            icon={ArrowRight}
            onClick={() => (nextReady ? move(nextStep.entry) : nextRemedy && onNeedsAction(nextRemedy))}
          >
            {/*
              * "Advance to X" whether or not the evidence is there yet: pressing
              * it starts that move either way, by opening the form that unblocks
              * it. Labelling the two cases differently would make the same
              * button read as two different actions — except for Won, where
              * the remedy is specifically a payment the deal is waiting on,
              * and naming that is clearer than a generic "Advance".
              */}
            {nextRemedy === 'payment' ? 'Record Advance Payment' : `Advance to ${nextStep.label}`}
          </CrmButton>
        )}
      </div>

      {/* 640px and up: the whole road. */}
      <ol className="hidden flex-wrap items-center gap-x-1 gap-y-1.5 sm:flex">
      {STEPS.map((step, index) => {
        const target = byValue[step.entry];
        const current = index === currentStep;
        const done = currentStep >= 0 && index < currentStep;

        /*
         * A step is pressable when its entry status can be moved to, or when it
         * cannot *yet* and there is a form that would change that. Blocked
         * steps are locked: no form unblocks them.
         */
        const blocked = target?.blocked ?? false;
        const ready = (target?.allowed ?? false) || done;
        const remedy = !ready && target?.action ? ACTION_MODAL[target.action] : undefined;
        const locked = blocked || !canUpdate || current || moving !== null;

        return (
          <li key={step.key} className="flex items-center">
            <button
              type="button"
              disabled={locked || (!ready && !remedy)}
              onClick={() => {
                if (locked) return;
                if (ready) return move(step.entry);
                if (remedy) onNeedsAction(remedy);
              }}
              title={target?.requirement ?? undefined}
              aria-current={current ? 'step' : undefined}
              className={cn(
                'inline-flex flex-col items-start rounded-[14px] px-3 py-1.5 text-[12px] font-medium transition',
                current && 'bg-ovs-crm-ink text-white',
                done && !current && 'text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset',
                !current && !done && ready && !locked && 'text-ovs-crm-text-muted hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary',
                /* Reachable, but it will ask for something first. */
                !current && !done && !ready && remedy && !locked && 'text-ovs-crm-text-muted ring-1 ring-inset ring-ovs-crm-border hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary',
                !current && !done && !ready && !remedy && 'cursor-not-allowed text-ovs-crm-text-muted opacity-55',
                blocked && 'cursor-not-allowed text-ovs-crm-text-muted opacity-40',
                moving === step.entry && 'opacity-60'
              )}
            >
              <span className="inline-flex items-center gap-1">
                {done && !current && <Check className="h-3 w-3 shrink-0" aria-hidden="true" />}
                {blocked && <Lock className="h-2.5 w-2.5 shrink-0" aria-hidden="true" />}
                {step.label}
              </span>

              {/*
                * The precise status, under the step that contains it.
                * Collapsing four commercial statuses into "Closing" is only
                * honest if the deal can still say which of the four it is on.
                */}
              {current && step.members.length > 1 && (
                <span className="text-[9.5px] font-normal uppercase tracking-[0.08em] opacity-75">
                  {statusLabel}
                </span>
              )}
            </button>

            {index < STEPS.length - 1 && (
              <span
                className={cn('h-px w-4 shrink-0', done ? 'bg-ovs-crm-text-secondary/40' : 'bg-ovs-crm-border')}
                aria-hidden="true"
              />
            )}
          </li>
        );
      })}
      </ol>
    </>
  );
}

/* ── Masthead ───────────────────────────────────────────────────────── */

/**
 * The capacity footprint, as one string.
 *
 * Only figures that were actually recorded. A physiotherapy chain has no beds
 * and a solo clinic has no theatres — printing "0 beds" against either implies
 * somebody asked and the answer was none, which is a different and wronger
 * claim than saying nothing.
 */
function footprint(deal: Deal): string | null {
  const f = deal.facility;

  const parts = [
    { label: 'sites', value: f?.site_count ?? deal.branches.length },
    { label: 'doctors', value: f?.doctors ?? null },
    { label: 'beds', value: f?.beds ?? null },
    { label: 'theatres', value: f?.theatres ?? null },
    { label: 'bays', value: f?.stations ?? null },
  ].filter((p) => p.value !== null && p.value > 0);

  return parts.length === 0 ? null : parts.map((p) => `${p.value} ${p.label}`).join(' · ');
}

function ActionsMenu({
  deal,
  can,
  onMarkLost,
  onReassign,
  onDelete,
}: {
  deal: Deal;
  can: DealCan;
  onMarkLost: () => void;
  onReassign: () => void;
  onDelete: () => void;
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          aria-label="More actions"
          className="crm-lift inline-flex h-9 items-center gap-1.5 rounded-full bg-white px-3.5 text-[13px] font-medium text-ovs-crm-text-secondary shadow-ovs-crmPanel transition hover:text-ovs-crm-text-primary"
        >
          <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
          Actions
        </button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-52">
        <DropdownMenuItem onSelect={onReassign} disabled={!can.update}>
          <UserCog className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
          Reassign owner
        </DropdownMenuItem>

        <DropdownMenuItem onSelect={() => router.visit(route('company.crm.leads.profile', deal.id))}>
          <FileText className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
          Edit full record
        </DropdownMenuItem>

        <DropdownMenuSeparator />

        {/*
          * Won is terminal — the state machine refuses every move out of it, so
          * offering "mark lost" on a live customer would be a button that
          * answers 422.
          */}
        <DropdownMenuItem
          onSelect={onMarkLost}
          disabled={!can.update || deal.status === 'won' || deal.status === 'lost'}
          className="text-ovs-crm-accent-coral-text"
        >
          <XCircle className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
          Mark as lost
        </DropdownMenuItem>

        <DropdownMenuItem
          onSelect={onDelete}
          disabled={!can.delete}
          className="text-ovs-crm-accent-coral-text"
        >
          <Trash2 className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
          Delete deal
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function Masthead({
  deal,
  can,
  outreach,
  transitions,
  onWorkflow,
}: {
  deal: Deal;
  can: DealCan;
  outreach: DealOutreach;
  transitions: StageTransition[];
  onWorkflow: (workflow: Exclude<Workflow, null>) => void;
}) {
  const location = [deal.area, deal.city].filter(Boolean).join(', ');
  const capacity = footprint(deal);

  return (
    <CrmCard as="header" aria-label="Deal summary" className="space-y-3.5 p-5 sm:p-6">
      <CrmIconButton
        icon={ArrowLeft}
        label="Back to deals"
        tone="ghost"
        href={route('company.crm.deals.index')}
        className="mb-2"
      />

      {/* 1 — who, and what it is worth. */}
      <div className="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-[22px] font-semibold leading-tight text-ovs-crm-text-primary sm:text-[26px]">
            {deal.contact_name || deal.company_name}
          </h1>
          {deal.contact_name && (
            <p className="truncate text-[14px] text-ovs-crm-text-secondary">{deal.company_name}</p>
          )}
        </div>

        <div className="shrink-0 text-right">
          <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Annual value
          </p>
          <p className="text-[24px] font-medium tabular-nums leading-tight text-ovs-crm-text-primary">
            {deal.expected_arr > 0 ? money(deal.expected_arr) : '—'}
          </p>
          <p className="text-[11px] text-ovs-crm-text-muted">
            {deal.probability_to_close}% · {money(deal.weighted_value)} weighted
          </p>
        </div>
      </div>

      {/* 2 — what they practise, how big, and where. */}
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
        {deal.specialties.map((s) => (
          <CrmBadge key={s.key} tone={specialtyTone(s.key)}>
            {s.name}
          </CrmBadge>
        ))}

        {(deal.facility || capacity) && (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-surface-inset px-2.5 py-0.5 text-[11.5px] font-medium text-ovs-crm-text-secondary">
            <Building2 className="h-3 w-3 shrink-0" aria-hidden="true" />
            {[deal.facility?.label, capacity].filter(Boolean).join(' · ')}
          </span>
        )}

        {location && (
          <span className="inline-flex items-center gap-1 text-[11.5px] text-ovs-crm-text-muted">
            <MapPin className="h-3 w-3 shrink-0" aria-hidden="true" />
            {location}
          </span>
        )}

        {deal.phone && (
          <span className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">{deal.phone}</span>
        )}
      </div>

      {/*
        * 3 — the action bar.
        *
        * WhatsApp and Call are real anchors, not Inertia visits: `wa.me` and
        * `tel:` belong to the browser, and an earlier version of this bar posted
        * an empty body to an endpoint requiring a message, answering 422 on
        * every press.
        */}
      <div className="flex flex-wrap items-center gap-2 border-t border-ovs-crm-border pt-3.5">
        {outreach.wa_number && (
          <CrmButton
            size="sm"
            tone="primary"
            icon={MessageCircle}
            href={`https://wa.me/${outreach.wa_number}`}
            external
            target="_blank"
          >
            WhatsApp
          </CrmButton>
        )}

        {outreach.phone && (
          <CrmButton size="sm" icon={Phone} href={`tel:${outreach.phone.replace(/\s/g, '')}`} external>
            Call
          </CrmButton>
        )}

        {can.update && (
          <CrmButton size="sm" icon={FileText} onClick={() => onWorkflow('call')}>
            Log a call
          </CrmButton>
        )}

        {/*
          * The one thing a rep learns on the call that the intake form
          * deliberately does not ask for: what kind of place this is and how
          * big it is. Until this existed, `facility_type` and the four
          * `lead_branches` capacity columns had no writer anywhere in the
          * product — every footprint badge in the CRM was seed data.
          */}
        {can.update && (
          <CrmButton size="sm" icon={Stethoscope} onClick={() => onWorkflow('discovery')}>
            Clinical discovery
          </CrmButton>
        )}

        <span className="ml-auto">
          <ActionsMenu
            deal={deal}
            can={can}
            onMarkLost={() => onWorkflow('lost')}
            onReassign={() => onWorkflow('reassign')}
            onDelete={() => onWorkflow('delete')}
          />
        </span>
      </div>

      {/* 4 — the rail. */}
      <div className="border-t border-ovs-crm-border pt-3.5">
        <StageRail
          transitions={transitions}
          dealId={deal.id}
          status={deal.status}
          statusLabel={deal.status_label}
          canUpdate={can.update}
          onNeedsAction={onWorkflow}
        />
      </div>
    </CrmCard>
  );
}

/* ── The stage hero ─────────────────────────────────────────────────── */

type Focus = 'demo' | 'trial' | 'closing' | 'inflow';

function focusFor(status: string): Focus {
  switch (status) {
    case 'demo_scheduled':
    case 'demo_completed':
      return 'demo';
    case 'trial_active':
      return 'trial';
    case 'negotiation':
    case 'quotation_sent':
    case 'quotation_approved':
    case 'payment_received':
    case 'won':
      return 'closing';
    default:
      return 'inflow';
  }
}

const FOCUS_TITLE: Record<Focus, string> = {
  demo: 'Demo operations',
  trial: 'Trial watchtower',
  closing: 'Closing',
  inflow: 'What happens next',
};

const FOCUS_ICON: Record<Focus, typeof Zap> = {
  demo: Video,
  trial: Target,
  closing: IndianRupee,
  inflow: Zap,
};

/** "in 2h 15m" / "in 3d" / "started 12m ago" — never a bare number of milliseconds. */
function countdownLabel(iso: string | null, now: number): { label: string; tone: 'good' | 'warn' | 'neutral' } | null {
  if (!iso) return null;

  const target = new Date(iso).getTime();
  if (Number.isNaN(target)) return null;

  const diffMinutes = Math.round((target - now) / 60000);

  if (diffMinutes <= 0 && diffMinutes > -60) {
    return { label: 'starting now', tone: 'warn' };
  }

  if (diffMinutes <= -60) {
    const hoursAgo = Math.round(-diffMinutes / 60);

    if (hoursAgo < 24) {
      return { label: `started ${hoursAgo}h ago`, tone: 'neutral' };
    }

    return { label: `started ${Math.round(hoursAgo / 24)}d ago`, tone: 'neutral' };
  }

  if (diffMinutes < 60) {
    return { label: `in ${diffMinutes}m`, tone: 'warn' };
  }

  const hours = Math.floor(diffMinutes / 60);
  if (hours < 24) {
    const minutes = diffMinutes % 60;
    return { label: `in ${hours}h ${minutes}m`, tone: 'good' };
  }

  const days = Math.floor(hours / 24);
  return { label: `in ${days}d`, tone: 'good' };
}

function DemoHero({
  demo,
  can,
  onComplete,
  onReschedule,
  onSchedule,
}: {
  demo: DealDemo | undefined;
  can: DealCan;
  onComplete: (id: number) => void;
  onReschedule: (id: number) => void;
  onSchedule: () => void;
}) {
  /*
   * Ticks every 30s rather than every second — a countdown badge that says
   * "in 2h 14m" and updates every second is doing arithmetic nobody reads
   * that granularly. A demo hero re-rendering every second is the cost of
   * precision this page does not need.
   */
  const [now, setNow] = React.useState(() => Date.now());
  React.useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 30000);
    return () => clearInterval(id);
  }, []);

  if (!demo) {
    return (
      <>
        <p className="text-[13.5px] text-ovs-crm-text-secondary">
          This deal is at the demo stage with nothing booked.
        </p>
        {can.update && (
          <div className="mt-3">
            <CrmButton size="sm" tone="primary" icon={CalendarPlus} onClick={onSchedule}>
              Schedule a demo
            </CrmButton>
          </div>
        )}
      </>
    );
  }

  const isScheduled = demo.status === 'scheduled';
  const countdown = isScheduled ? countdownLabel(demo.scheduled_at_iso, now) : null;

  const reminders = [
    { label: 'Rep told', done: demo.reminders.rep_notified },
    { label: '24h reminder', done: demo.reminders.customer_24h },
    { label: '2h reminder', done: demo.reminders.customer_2h },
  ];

  return (
    <>
      <div className="flex flex-wrap items-baseline gap-x-5 gap-y-1">
        <p className="text-[18px] font-medium text-ovs-crm-text-primary">
          {demo.scheduled_at ?? 'Not scheduled'}
        </p>
        {countdown && (
          <CrmBadge tone={countdown.tone === 'good' ? 'emerald' : countdown.tone === 'warn' ? 'amber' : 'slate'} size="sm">
            {countdown.label}
          </CrmBadge>
        )}
        <p className="text-[12.5px] text-ovs-crm-text-muted">
          {demo.presenter_name ? `Presented by ${demo.presenter_name}` : 'No presenter named'}
          {demo.meeting_platform_label ? ` · ${demo.meeting_platform_label}` : ''}
        </p>
      </div>

      {demo.location && (
        <p className="mt-1 text-[12.5px] text-ovs-crm-text-secondary">{demo.location}</p>
      )}

      <div className="mt-3 flex flex-wrap items-center gap-2">
        {demo.meeting_link ? (
          <CrmButton size="sm" tone="primary" icon={Video} href={demo.meeting_link} external target="_blank">
            Join {demo.meeting_platform_label ?? 'the meeting'}
          </CrmButton>
        ) : (
          <CrmStatusPill tone="warn">No join link on this booking</CrmStatusPill>
        )}

        {demo.meeting_id && (
          <span className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">
            ID {demo.meeting_id}
            {demo.passcode ? ` · ${demo.passcode}` : ''}
          </span>
        )}
      </div>

      {/* What the customer has actually been told, and when. */}
      <div className="mt-3 flex flex-wrap items-center gap-1.5 border-t border-ovs-crm-border pt-3">
        {reminders.map((r) => (
          <CrmStatusPill key={r.label} tone={r.done ? 'good' : 'neutral'}>
            {r.done ? r.label : `${r.label} pending`}
          </CrmStatusPill>
        ))}
      </div>

      {can.update && isScheduled && (
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-ovs-crm-border pt-3">
          <CrmButton size="sm" tone="primary" icon={CheckCircle2} onClick={() => onComplete(demo.id)}>
            Demo Completed → Start Trial
          </CrmButton>
          <CrmButton size="sm" icon={CalendarClock} onClick={() => onReschedule(demo.id)}>
            Reschedule
          </CrmButton>
          <CrmButton
            size="sm"
            icon={XCircle}
            onClick={() => router.post(route('company.crm.demos.no-show', demo.id), {}, { preserveScroll: true })}
          >
            No-show
          </CrmButton>
        </div>
      )}
    </>
  );
}

/**
 * Live usage, as three bars.
 *
 * Scaled against the largest of the three, not against a target — there is no
 * target. A clinic that has seen forty patients and written two prescriptions is
 * telling you which module landed, and the relative shape is the readable part.
 * The absolute numbers sit beside them so nothing can be mistaken for a
 * percentage of anything.
 */
function Telemetry({ trial }: { trial: DealTrial }) {
  const t = trial.telemetry;

  if (!t) {
    return (
      <div className="rounded-[16px] bg-ovs-crm-surface-inset px-4 py-3">
        <p className="text-[12.5px] text-ovs-crm-text-secondary">
          Nobody has measured this trial yet.
        </p>
        <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
          Usage syncs from the clinic's own tenant — an unmeasured trial and an unused one are
          different things, so no counts are shown.
        </p>
      </div>
    );
  }

  const rows = [
    { label: 'Patients', value: t.patients, hue: toneHex('blue') },
    { label: 'Prescriptions', value: t.prescriptions, hue: toneHex('violet') },
    { label: 'Bills', value: t.invoices, hue: toneHex('teal') },
  ];

  const max = Math.max(1, ...rows.map((r) => r.value));

  return (
    <div className="space-y-2.5">
      {rows.map((r) => (
        <div key={r.label} className="min-w-0">
          <div className="flex items-baseline justify-between gap-3">
            <span className="text-[11.5px] text-ovs-crm-text-secondary">{r.label}</span>
            <span className="text-[15px] font-medium tabular-nums text-ovs-crm-text-primary">
              {r.value}
            </span>
          </div>
          <CrmHairlineBar value={r.value} max={max} color={r.hue} className="mt-1" />
        </div>
      ))}
    </div>
  );
}

function TrialHero({
  trial,
  conversion,
  can,
  dealId,
  onExtend,
  onStart,
  onMarkLost,
  onApproveExtension,
  onRejectExtension,
}: {
  trial: DealTrial | undefined;
  conversion: CommercialData['conversion'];
  can: DealCan;
  dealId: number;
  onExtend: (id: number) => void;
  onStart: () => void;
  onMarkLost: () => void;
  onApproveExtension: (trialId: number, requestId: number) => void;
  onRejectExtension: (trialId: number, requestId: number) => void;
}) {
  const [copiedTenantUrl, setCopiedTenantUrl] = React.useState(false);
  const [approvingExtension, setApprovingExtension] = React.useState(false);

  if (!trial) {
    return (
      <>
        <p className="text-[13.5px] text-ovs-crm-text-secondary">No trial has been provisioned yet.</p>
        {can.update && (
          <div className="mt-3">
            <CrmButton size="sm" tone="primary" icon={Target} onClick={onStart}>
              Start a trial
            </CrmButton>
          </div>
        )}
      </>
    );
  }

  const t = trial.telemetry;

  const health = t === null
    ? { tone: 'neutral' as const, label: 'Not measured' }
    : t.is_inactive
      ? { tone: 'bad' as const, label: `Idle${t.hours_since_login ? ` ${Math.round(t.hours_since_login)}h` : ''}` }
      : t.is_active_today
        ? { tone: 'good' as const, label: 'Active today' }
        : { tone: 'warn' as const, label: 'Quiet' };

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        {trial.package && <CrmStatusPill tone="info">{trial.package.name}</CrmStatusPill>}
        <CrmStatusPill tone={health.tone}>{health.label}</CrmStatusPill>
      </div>

      <div className="mt-2 flex flex-wrap items-baseline gap-x-5 gap-y-1">
        <p className="text-[18px] font-medium text-ovs-crm-text-primary">
          {trial.days_remaining === null
            ? 'No end date'
            : trial.days_remaining <= 0
              ? 'Ends today'
              : `${trial.days_remaining} days left`}
        </p>
        <p className="text-[12.5px] text-ovs-crm-text-muted">
          {[trial.start_date, trial.end_date].filter(Boolean).join(' → ')}
          {trial.extension_count > 0 ? ` · extended ${trial.extension_count}×` : ''}
        </p>
      </div>

      {trial.provisioning && (() => {
        const provisioning = trial.provisioning;

        return (
          <div className="mt-3 space-y-1 rounded-[14px] bg-ovs-crm-surface-inset px-3.5 py-3">
            <p className="flex items-center gap-1.5 text-[12.5px] font-medium text-ovs-crm-text-primary">
              <Globe className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
              <span className="truncate">{provisioning.tenant_url}</span>
              <button
                type="button"
                className="ml-auto inline-flex shrink-0 items-center gap-1 rounded-full px-1.5 py-0.5 text-[10.5px] font-medium text-ovs-crm-text-muted transition hover:bg-white hover:text-ovs-crm-text-primary"
                onClick={() => {
                  navigator.clipboard?.writeText(provisioning.tenant_url);
                  setCopiedTenantUrl(true);
                  setTimeout(() => setCopiedTenantUrl(false), 1500);
                }}
              >
                <Copy className="h-3 w-3 shrink-0" aria-hidden="true" />
                {copiedTenantUrl ? 'Copied' : 'Copy'}
              </button>
            </p>
            {provisioning.admin_email && (
              <p className="flex items-center gap-1.5 text-[11.5px] text-ovs-crm-text-secondary">
                <Mail className="h-3 w-3 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                <span className="truncate">{provisioning.admin_email}</span>
              </p>
            )}
            <CrmStatusPill tone={provisioning.credentials_sent ? 'good' : 'warn'}>
              {provisioning.credentials_sent ? 'Admin credentials sent' : 'Credentials not yet sent'}
            </CrmStatusPill>
          </div>
        );
      })()}

      <div className="mt-3.5 border-t border-ovs-crm-border pt-3.5">
        <Telemetry trial={trial} />
      </div>

      {trial.pending_extension_request && (
        <div className="mt-3.5 space-y-2 rounded-[14px] border border-ovs-crm-accent-yellow-border bg-ovs-crm-accent-yellow-soft px-3.5 py-3">
          <p className="text-[12.5px] font-medium text-ovs-crm-accent-yellow-text">
            Extension request pending (+{trial.pending_extension_request.additional_days} days)
          </p>
          <p className="text-[11.5px] text-ovs-crm-text-secondary">
            {trial.pending_extension_request.requested_by ?? 'A rep'} asked: “
            {trial.pending_extension_request.reason}”
          </p>
          {can.approve_trial_extension && (
            <div className="flex flex-wrap items-center gap-2 pt-0.5">
              <CrmButton
                size="sm"
                tone="primary"
                icon={CheckCircle2}
                loading={approvingExtension}
                disabled={approvingExtension}
                onClick={() => {
                  setApprovingExtension(true);
                  onApproveExtension(trial.id, trial.pending_extension_request!.id);
                }}
              >
                Approve
              </CrmButton>
              <CrmButton
                size="sm"
                icon={XCircle}
                disabled={approvingExtension}
                onClick={() => onRejectExtension(trial.id, trial.pending_extension_request!.id)}
              >
                Reject
              </CrmButton>
            </div>
          )}
        </div>
      )}

      {can.update && (
        <div className="mt-3.5 flex flex-wrap items-center gap-2 border-t border-ovs-crm-border pt-3.5">
          {!trial.pending_extension_request && (
            <CrmButton size="sm" icon={CalendarClock} onClick={() => onExtend(trial.id)}>
              {can.approve_trial_extension ? 'Extend trial' : 'Request extension'}
            </CrmButton>
          )}

          <CrmButton
            size="sm"
            tone={conversion.can_convert ? 'primary' : 'secondary'}
            icon={CheckCircle2}
            disabled={!conversion.can_convert}
            title={conversion.can_convert ? undefined : conversion.requirements.find((r) => !r.met)?.hint}
            onClick={() => router.post(route('company.crm.leads.convert', dealId), {}, { preserveScroll: true })}
          >
            Convert to customer
          </CrmButton>

          <CrmButton size="sm" icon={XCircle} onClick={onMarkLost}>
            Mark lost
          </CrmButton>

          {!conversion.can_convert && !conversion.is_converted && (
            <p className="w-full text-[11.5px] text-ovs-crm-text-muted">
              {conversion.requirements.find((r) => !r.met)?.hint}
            </p>
          )}
        </div>
      )}
    </>
  );
}

function ClosingHero({
  commercial,
  can,
  dealId,
  onRecordPayment,
}: {
  commercial: CommercialData;
  can: DealCan;
  dealId: number;
  onRecordPayment: () => void;
}) {
  const [showHistory, setShowHistory] = React.useState(false);
  const [advancing, setAdvancing] = React.useState(false);
  const [revising, setRevising] = React.useState(false);

  /*
   * The active version, not merely "not rejected" — a superseded or expired
   * quote that happens to not be rejected is still not the one being worked.
   * `quotations` arrives newest-first, so the first active one is current.
   */
  const live = commercial.quotations.find((q) => q.is_active) ?? commercial.quotations[0];
  const paid = commercial.payments
    .filter((p) => p.status === 'successful')
    .reduce((sum, p) => sum + p.amount, 0);
  const priorVersions = commercial.quotations.filter((q) => q.id !== live?.id);

  if (!live) {
    return (
      <p className="text-[13.5px] text-ovs-crm-text-secondary">
        This deal is at a closing stage with no quotation raised against it.
      </p>
    );
  }

  return (
    <>
      <div className="flex flex-wrap items-baseline gap-x-5 gap-y-1">
        <p className="text-[18px] font-medium tabular-nums text-ovs-crm-text-primary">
          {inr(live.grand_total)}
        </p>
        <CrmBadge tone="violet" size="sm">
          {live.billing_cycle_label}
        </CrmBadge>
        <p className="text-[12.5px] text-ovs-crm-text-muted">
          {live.plan_name ? `${live.plan_name} · ` : ''}
          {live.quotation_number} · v{live.version} · {live.status.replace(/_/g, ' ')}
          {live.valid_until ? ` · valid to ${live.valid_until}` : ''}
        </p>
      </div>

      <p className="mt-1 text-[11.5px] text-ovs-crm-text-muted">
        {inr(live.subtotal)} gross
        {live.discount > 0 ? ` − ${inr(live.discount)} discount` : ''} = {inr(live.grand_total)} net ·{' '}
        {inr(live.normalized_arr)} ARR
      </p>

      {live.rejection_reason && (
        <p className="mt-1.5 text-[12.5px] text-ovs-crm-accent-coral-text">{live.rejection_reason}</p>
      )}

      <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 border-t border-ovs-crm-border pt-3 sm:grid-cols-4">
        {[
          ['Quoted', inr(live.grand_total)],
          ['Received', inr(paid)],
          ['Outstanding', inr(Math.max(0, live.grand_total - paid))],
          ['Invoice', live.invoice_number ?? '—'],
        ].map(([label, value]) => (
          <div key={label} className="min-w-0">
            <dt className="text-[10px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
              {label}
            </dt>
            <dd className="truncate text-[14px] font-medium tabular-nums text-ovs-crm-text-primary">
              {value}
            </dd>
          </div>
        ))}
      </dl>

      <div className="mt-3 flex flex-wrap items-center gap-1.5 border-t border-ovs-crm-border pt-3">
        {commercial.lifecycle.steps.map((step) => (
          <CrmStatusPill key={step.key} tone={step.done ? 'good' : 'neutral'}>
            {step.label}
          </CrmStatusPill>
        ))}

      </div>

      {can.update && (
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-ovs-crm-border pt-3">
          {live.is_settled ? (
            <CrmButton
              size="sm"
              tone="primary"
              icon={CheckCircle2}
              loading={advancing}
              onClick={() => {
                setAdvancing(true);
                router.post(
                  route('company.crm.leads.transition', dealId),
                  { stage: 'won' },
                  { preserveScroll: true, onFinish: () => setAdvancing(false) }
                );
              }}
            >
              Advance to Won
            </CrmButton>
          ) : (
            <CrmButton size="sm" tone="primary" icon={IndianRupee} onClick={onRecordPayment}>
              Record Advance Payment
            </CrmButton>
          )}

          <CrmButton size="sm" icon={FileText} onClick={() => setRevising(true)}>
            Revise Quote
          </CrmButton>

          {priorVersions.length > 0 && (
            <CrmButton size="sm" icon={History} onClick={() => setShowHistory(true)}>
              Version history ({priorVersions.length})
            </CrmButton>
          )}
        </div>
      )}

      <QuotationVersionHistoryModal
        open={showHistory}
        onClose={() => setShowHistory(false)}
        quotations={commercial.quotations}
      />

      <QuotationReviseModal
        isOpen={revising}
        onClose={() => setRevising(false)}
        quotationId={live.id}
        quotationNumber={live.quotation_number}
      />
    </>
  );
}

/**
 * Every version this deal's quotation has been through, not just the four the
 * standing Commercials list has room for. The backend already sends every
 * row — this is the one place on the page that shows all of them.
 */
function QuotationVersionHistoryModal({
  open,
  onClose,
  quotations,
}: {
  open: boolean;
  onClose: () => void;
  quotations: DealQuotation[];
}) {
  const columns: CrmColumn<DealQuotation>[] = [
    {
      key: 'version',
      header: 'Version',
      primary: true,
      cell: (q) => (
        <span className="font-medium text-ovs-crm-text-primary">
          v{q.version}
          {q.is_active && (
            <CrmBadge tone="emerald" size="xs" className="ml-1.5">
              Active
            </CrmBadge>
          )}
        </span>
      ),
    },
    { key: 'date', header: 'Date', cell: (q) => q.created_at ?? '—' },
    { key: 'author', header: 'Prepared by', cell: (q) => q.prepared_by_name ?? '—' },
    {
      key: 'cycle',
      header: 'Cycle',
      cell: (q) => (
        <CrmBadge tone="violet" size="xs">
          {q.billing_cycle_label}
        </CrmBadge>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (q) => <CrmStatusPill tone={q.is_settled ? 'good' : q.status === 'rejected' ? 'bad' : 'neutral'}>{q.status.replace(/_/g, ' ')}</CrmStatusPill>,
    },
    { key: 'total', header: 'Final amount', align: 'right', cell: (q) => inr(q.grand_total) },
  ];

  return (
    <CrmModal open={open} onClose={onClose} title="Quotation version history" size="lg">
      <CrmTable columns={columns} rows={quotations} rowKey={(q) => q.id} />
    </CrmModal>
  );
}

function InflowHero({
  now,
  can,
  dealId,
  onSchedule,
  onAddTask,
}: {
  now: NowData;
  can: DealCan;
  dealId: number;
  onSchedule: () => void;
  onAddTask: () => void;
}) {
  const action = now.next_action;
  const open = now.tasks.filter((t) => !t.is_completed);

  return (
    <>
      <p className="text-[18px] font-medium text-ovs-crm-text-primary">
        {action.has_action ? action.title : (action.recommendation ?? 'Nothing scheduled')}
      </p>
      <p className="mt-1 text-[12.5px] text-ovs-crm-text-muted">
        {action.has_action
          ? [action.due_label, action.owner].filter(Boolean).join(' · ')
          : 'The stage suggests it. Nothing is on the calendar yet.'}
      </p>
      {action.has_action && action.is_overdue && (
        <span className="mt-2 inline-block">
          <CrmStatusPill tone="bad">Overdue</CrmStatusPill>
        </span>
      )}

      {open.length > 0 && (
        <ul className="mt-3 space-y-1.5 border-t border-ovs-crm-border pt-3">
          {open.slice(0, 4).map((task) => (
            <li key={task.id} className="flex min-w-0 items-center gap-3">
              <span className="min-w-0 flex-1 truncate text-[12.5px] text-ovs-crm-text-primary">
                {task.title}
              </span>
              <span className="shrink-0 text-[11.5px] text-ovs-crm-text-muted">{task.due_at ?? '—'}</span>
              {can.update && (
                <CrmButton
                  size="sm"
                  icon={CheckCircle2}
                  aria-label={`Complete ${task.title}`}
                  className="w-8 px-0"
                  onClick={() =>
                    router.post(
                      route('company.crm.leads.tasks.complete', [dealId, task.id]),
                      {},
                      { preserveScroll: true }
                    )
                  }
                />
              )}
            </li>
          ))}
        </ul>
      )}

      {can.update && (
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-ovs-crm-border pt-3">
          <CrmButton size="sm" tone="primary" icon={CalendarPlus} onClick={onSchedule}>
            Book a demo
          </CrmButton>
          <CrmButton size="sm" icon={Zap} onClick={onAddTask}>
            Add a task
          </CrmButton>
        </div>
      )}
    </>
  );
}

/* ── The estate ─────────────────────────────────────────────────────── */

type CapacityKey = 'doctor_count' | 'bed_count' | 'operation_theatre_count' | 'treatment_station_count';

function BranchTable({ branches }: { branches: DealBranchSummary[] }) {
  /* A column nobody recorded is not shown as a column of dashes. */
  const capacity: { key: CapacityKey; label: string }[] = [
    { key: 'doctor_count', label: 'Doctors' },
    { key: 'bed_count', label: 'Beds' },
    { key: 'operation_theatre_count', label: 'Theatres' },
    { key: 'treatment_station_count', label: 'Bays' },
  ].filter((c) => branches.some((b) => b[c.key as CapacityKey] !== null)) as { key: CapacityKey; label: string }[];

  const columns: CrmColumn<DealBranchSummary>[] = [
    {
      key: 'name',
      header: 'Branch',
      primary: true,
      cell: (branch) => (
        <span className="flex min-w-0 items-center gap-2">
          <span className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
            {branch.name}
          </span>
          {branch.is_main && <CrmStatusPill tone="info">Main</CrmStatusPill>}
        </span>
      ),
    },
    {
      key: 'area',
      header: 'Area',
      mobileLabel: 'Area',
      cell: (branch) => (
        <span className="block truncate text-[12.5px] text-ovs-crm-text-muted">
          {branch.location || branch.city || '—'}
        </span>
      ),
    },
    ...capacity.map((c) => ({
      key: c.key,
      header: c.label,
      mobileLabel: c.label,
      align: 'right' as const,
      width: '86px',
      cell: (branch: DealBranchSummary) =>
        branch[c.key] === null ? (
          <span className="text-ovs-crm-text-muted">—</span>
        ) : (
          <span className="tabular-nums text-ovs-crm-text-primary">{branch[c.key]}</span>
        ),
    })),
  ];

  return (
    <CrmTable<DealBranchSummary>
      variant="ruled"
      columns={columns}
      rows={branches}
      rowKey={(branch) => branch.id}
      empty={{ icon: Building2, title: 'No sites recorded' }}
    />
  );
}

/* ── The activity column ────────────────────────────────────────────── */

type Composer = 'note' | 'call';

/** Which view the right column shows — the live thread, the email history, or everything else. */
type RightPanel = 'whatsapp' | 'email' | 'activity';

/** Sent → one grey tick. Delivered → two. Read → two, coloured. Failed → a warning, never silent. */
function DeliveryTick({ status }: { status: string }) {
  if (status === 'failed') {
    return <AlertTriangle className="h-3 w-3 shrink-0 text-ovs-crm-accent-coral-text" aria-hidden="true" />;
  }

  if (status === 'read') {
    return <CheckCheck className="h-3 w-3 shrink-0 text-ovs-crm-stage-blue-text" aria-hidden="true" />;
  }

  if (status === 'delivered') {
    return <CheckCheck className="h-3 w-3 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />;
  }

  return <Check className="h-3 w-3 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />;
}

/**
 * The deal's live WhatsApp thread — the same conversation the Marketing
 * inbox reads and writes, not a second, disconnected "send a message" box.
 *
 * A rep reading this panel sees exactly what the customer sees: their own
 * replies on the right, the doctor's on the left, in the order they actually
 * happened. `DealConversationService::sendWhatsApp()` is what a Send here
 * calls — the identical path a reply from the inbox takes.
 */
function WhatsAppChatPanel({
  dealId,
  conversation,
  canSend,
  onSent,
}: {
  dealId: number;
  conversation: { conversation_id: number | null; phone: string | null; unread: number; inbox_url: string | null; messages: WhatsAppMessage[] };
  canSend: boolean;
  onSent: () => void;
}) {
  const form = useForm({ message: '' });
  const scrollRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const el = scrollRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [conversation.messages.length]);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.data.message.trim()) return;

    form.post(route('company.crm.leads.whatsapp', dealId), {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        onSent();
      },
    });
  };

  return (
    <CrmCard className="flex flex-col overflow-hidden !p-0">
      {/* Header — who this is, and whether the channel is live. */}
      <div className="flex items-center gap-2.5 border-b border-ovs-crm-border px-4 py-3">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ovs-crm-stage-emerald-soft text-ovs-crm-stage-emerald-text">
          <MessageCircle className="h-4 w-4" aria-hidden="true" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">
            {conversation.phone ?? 'No number on file'}
          </p>
          <p className="text-[11px] text-ovs-crm-text-muted">Sales · WhatsApp channel</p>
        </div>
        {conversation.unread > 0 && (
          <CrmBadge tone="coral" size="sm">
            {conversation.unread} unread
          </CrmBadge>
        )}
      </div>

      {/* The thread. */}
      <div ref={scrollRef} className="min-h-[260px] max-h-[380px] flex-1 space-y-2 overflow-y-auto bg-ovs-crm-surface-inset px-3.5 py-3">
        {conversation.messages.length === 0 ? (
          <div className="flex h-full min-h-[230px] items-center justify-center">
            <p className="max-w-[220px] text-center text-[12px] text-ovs-crm-text-muted">
              No messages yet on this thread. Send the first one below.
            </p>
          </div>
        ) : (
          conversation.messages.map((m) => (
            <div key={m.id} className={cn('flex', m.direction === 'outbound' ? 'justify-end' : 'justify-start')}>
              <div
                className={cn(
                  'max-w-[80%] rounded-2xl px-3 py-2 text-[12.5px] leading-relaxed shadow-sm',
                  m.direction === 'outbound'
                    ? 'rounded-br-sm border border-emerald-200 bg-emerald-50 text-slate-900'
                    : 'rounded-bl-sm border border-ovs-crm-border bg-white text-ovs-crm-text-primary'
                )}
              >
                <p className="whitespace-pre-wrap break-words">{m.body}</p>
                <div className="mt-1 flex items-center justify-end gap-1">
                  <span className="text-[10px] text-ovs-crm-text-muted">{m.at_label}</span>
                  {m.direction === 'outbound' && <DeliveryTick status={m.status} />}
                </div>
              </div>
            </div>
          ))
        )}
      </div>

      {/* Sticky composer. */}
      {canSend ? (
        <form onSubmit={submit} className="flex items-end gap-2 border-t border-ovs-crm-border px-3 py-2.5">
          <CrmTextarea
            rows={1}
            value={form.data.message}
            onChange={(e) => form.setData('message', e.target.value)}
            placeholder="Message on WhatsApp…"
            aria-label="WhatsApp message"
            className="flex-1 resize-none"
          />
          <CrmButton
            size="sm"
            tone="primary"
            icon={Send}
            type="submit"
            loading={form.processing}
            disabled={!form.data.message.trim()}
          >
            Send
          </CrmButton>
        </form>
      ) : (
        <p className="border-t border-ovs-crm-border px-4 py-2.5 text-[11.5px] text-ovs-crm-text-muted">
          Read-only — you don't have permission to message from this deal.
        </p>
      )}
      {form.errors.message && (
        <p className="border-t border-ovs-crm-border px-4 py-2 text-[11.5px] text-ovs-crm-accent-coral-text">
          {form.errors.message}
        </p>
      )}
    </CrmCard>
  );
}

const EMAIL_STATUS_TONE: Record<EmailThread['status'], CrmStatusTone> = {
  sent: 'good',
  failed: 'bad',
  queued: 'neutral',
};

const EMAIL_STATUS_LABEL: Record<EmailThread['status'], string> = {
  sent: 'Sent',
  failed: 'Failed',
  queued: 'Queued',
};

/**
 * The deal's email history and composer — `CrmEmailService` already sends
 * synchronously and records every attempt with a status, a failure reason
 * and a retry count; this is the surface that was missing, not a new send
 * path. A failed send stays visible with its reason rather than vanishing,
 * and Retry re-uses the same thread row (`leads.email.retry`), so retrying
 * never creates a second record of the same attempt.
 */
function EmailPanel({
  dealId,
  email,
  canSend,
  onSent,
}: {
  dealId: number;
  email: { can_send: boolean; reason?: string | null; default_to: string | null; threads: EmailThread[] };
  canSend: boolean;
  onSent: () => void;
}) {
  const form = useForm({ to: email.default_to ?? '', subject: '', body: '' });
  // Tracks the one thread currently being retried, so a second click before
  // the first request finishes cannot fire a duplicate retry — the button
  // for every other thread disables too while any retry is in flight.
  const [retryingId, setRetryingId] = React.useState<number | null>(null);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.data.subject.trim() || !form.data.body.trim()) return;

    form.post(route('company.crm.leads.email.store', dealId), {
      preserveScroll: true,
      onSuccess: () => {
        form.setData('body', '');
        form.setData('subject', '');
        onSent();
      },
    });
  };

  const retry = (threadId: number) => {
    if (retryingId !== null) return;
    setRetryingId(threadId);
    router.post(route('company.crm.leads.email.retry', [dealId, threadId]), {}, {
      preserveScroll: true,
      onSuccess: onSent,
      onFinish: () => setRetryingId(null),
    });
  };

  return (
    <CrmCard className="flex flex-col overflow-hidden !p-0">
      <div className="flex items-center gap-2.5 border-b border-ovs-crm-border px-4 py-3">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ovs-crm-stage-blue-soft text-ovs-crm-stage-blue-text">
          <Mail className="h-4 w-4" aria-hidden="true" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">
            {email.default_to ?? 'No email on file'}
          </p>
          <p className="text-[11px] text-ovs-crm-text-muted">Sales · Email channel</p>
        </div>
      </div>

      <div className="min-h-[220px] max-h-[360px] flex-1 space-y-2 overflow-y-auto bg-ovs-crm-surface-inset px-3.5 py-3">
        {email.threads.length === 0 ? (
          <div className="flex h-full min-h-[190px] items-center justify-center">
            <p className="max-w-[220px] text-center text-[12px] text-ovs-crm-text-muted">
              No emails sent yet on this deal. Send the first one below.
            </p>
          </div>
        ) : (
          email.threads.map((thread) => (
            <div key={thread.id} className="rounded-2xl border border-ovs-crm-border bg-white px-3.5 py-3 shadow-sm">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate text-[12.5px] font-semibold text-ovs-crm-text-primary">{thread.subject}</p>
                  <p className="truncate text-[11px] text-ovs-crm-text-muted">
                    To {thread.to_email ?? '—'} · {thread.time}
                  </p>
                </div>
                <CrmStatusPill tone={EMAIL_STATUS_TONE[thread.status]}>
                  {EMAIL_STATUS_LABEL[thread.status]}
                </CrmStatusPill>
              </div>

              {thread.status === 'failed' && thread.failure_reason && (
                <p className="mt-2 flex items-start gap-1.5 rounded-xl bg-ovs-crm-accent-coral-soft px-2.5 py-2 text-[11.5px] text-ovs-crm-accent-coral-text">
                  <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                  <span>{thread.failure_reason}</span>
                </p>
              )}

              {thread.status === 'failed' && thread.is_retryable && canSend && (
                <div className="mt-2">
                  <CrmButton
                    size="sm"
                    tone="ghost"
                    icon={RefreshCw}
                    loading={retryingId === thread.id}
                    disabled={retryingId !== null}
                    onClick={() => retry(thread.id)}
                  >
                    {thread.attempts > 1 ? `Retry (attempt ${thread.attempts + 1})` : 'Retry'}
                  </CrmButton>
                </div>
              )}
            </div>
          ))
        )}
      </div>

      {canSend && email.can_send ? (
        <form onSubmit={submit} className="space-y-2 border-t border-ovs-crm-border px-3.5 py-3">
          <CrmInput
            value={form.data.to}
            onChange={(e) => form.setData('to', e.target.value)}
            placeholder="Recipient email"
            aria-label="Recipient email"
          />
          <CrmInput
            value={form.data.subject}
            onChange={(e) => form.setData('subject', e.target.value)}
            placeholder="Subject"
            aria-label="Subject"
          />
          <CrmTextarea
            rows={3}
            value={form.data.body}
            onChange={(e) => form.setData('body', e.target.value)}
            placeholder="Write the email…"
            aria-label="Email body"
          />
          <div className="flex items-center justify-between gap-2">
            {(form.errors.to || form.errors.subject || form.errors.body) && (
              <p className="text-[11.5px] text-ovs-crm-accent-coral-text">
                {form.errors.to || form.errors.subject || form.errors.body}
              </p>
            )}
            <CrmButton
              size="sm"
              tone="primary"
              icon={Send}
              type="submit"
              loading={form.processing}
              disabled={!form.data.subject.trim() || !form.data.body.trim()}
              className="ml-auto"
            >
              Send
            </CrmButton>
          </div>
        </form>
      ) : (
        <p className="border-t border-ovs-crm-border px-4 py-2.5 text-[11.5px] text-ovs-crm-text-muted">
          {!canSend ? "Read-only — you don't have permission to email from this deal." : email.reason}
        </p>
      )}
    </CrmCard>
  );
}

function QuickComposer({
  dealId,
  onLogged,
}: {
  dealId: number;
  onLogged: () => void;
}) {
  const [channel, setChannel] = React.useState<Composer>('note');

  const note = useForm({ type: 'note', description: '' });
  const call = useForm({ disposition: 'follow_up_required', duration_seconds: '', notes: '', next_followup_at: '' });

  /*
   * "Connected, they want a follow-up" or "busy" logged with no date is a
   * promise the rep made and the CRM didn't capture — the two dispositions
   * this cadence exists to close. `demo_requested` and `not_interested` both
   * describe an outcome with nothing left to schedule.
   */
  const callFollowupRequired = call.data.disposition === 'follow_up_required' || call.data.disposition === 'busy';
  const canLogCall = !callFollowupRequired || call.data.next_followup_at.trim() !== '';

  const done = (form: { reset: () => void }) => () => {
    form.reset();
    onLogged();
  };

  const tabs: { key: Composer; label: string }[] = [
    { key: 'note', label: 'Note' },
    { key: 'call', label: 'Call' },
  ];

  return (
    <CrmCard className="px-4 py-3.5">
      <CrmTabs<Composer> value={channel} onChange={setChannel} tabs={tabs} className="mb-3" />

      {channel === 'note' && (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            note.post(route('company.crm.leads.activities.store', dealId), {
              preserveScroll: true,
              onSuccess: done(note),
            });
          }}
          className="flex flex-col gap-2.5"
        >
          <CrmTextarea
            rows={2}
            value={note.data.description}
            onChange={(e) => note.setData('description', e.target.value)}
            placeholder="What happened?"
            aria-label="Note"
          />
          {note.errors.description && (
            <p className="text-[11.5px] text-ovs-crm-accent-coral-text">{note.errors.description}</p>
          )}
          <CrmButton
            size="sm"
            tone="primary"
            icon={Send}
            loading={note.processing}
            disabled={!note.data.description.trim()}
            type="submit"
          >
            Log it
          </CrmButton>
        </form>
      )}

      {channel === 'call' && (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            if (!canLogCall) return;

            call.post(route('company.crm.leads.call-disposition', dealId), {
              preserveScroll: true,
              onSuccess: done(call),
            });
          }}
          className="flex flex-col gap-2.5"
        >
          <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
            <CrmFormField label="How it went" error={call.errors.disposition}>
              <CrmSelect
                value={call.data.disposition}
                onChange={(e) => call.setData('disposition', e.target.value)}
              >
                <option value="follow_up_required">Connected — Follow-up Requested</option>
                <option value="demo_requested">Connected — Demo Agreed</option>
                <option value="busy">Busy / No Answer</option>
                <option value="not_interested">Not Interested</option>
              </CrmSelect>
            </CrmFormField>

            <CrmFormField label="Seconds" error={call.errors.duration_seconds}>
              <CrmInput
                type="number"
                min={0}
                value={call.data.duration_seconds}
                onChange={(e) => call.setData('duration_seconds', e.target.value)}
              />
            </CrmFormField>
          </div>

          <CrmTextarea
            rows={2}
            value={call.data.notes}
            onChange={(e) => call.setData('notes', e.target.value)}
            placeholder="What was said"
            aria-label="Call notes"
          />

          <CrmFormField
            label="Next follow-up"
            required={callFollowupRequired}
            error={call.errors.next_followup_at}
            hint={callFollowupRequired ? 'Mandatory for this outcome.' : undefined}
          >
            <CrmInput
              type="datetime-local"
              value={call.data.next_followup_at}
              onChange={(e) => call.setData('next_followup_at', e.target.value)}
            />
          </CrmFormField>

          <CrmButton
            size="sm"
            tone="primary"
            icon={Send}
            loading={call.processing}
            disabled={!canLogCall}
            type="submit"
          >
            Log the call
          </CrmButton>
        </form>
      )}

    </CrmCard>
  );
}

/**
 * One chronological stream.
 *
 * This replaced four panels that each rendered a slice of the same truth: a
 * WhatsApp thread, an email list, a call log and a history timeline, side by
 * side, disagreeing about ordering. Every one of those events is an activity,
 * and the activity table already holds them in one order.
 */
function ActivityStream({ history, onPage }: { history: HistoryData; onPage: (page: number) => void }) {
  const [kind, setKind] = React.useState<string>('all');

  const events = kind === 'all' ? history.events : history.events.filter((e) => e.type === kind);

  return (
    <CrmCard className="px-4 py-3.5">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-[11px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
          Activity
        </h2>
        {history.kinds.length > 1 && (
          <CrmSelect
            value={kind}
            onChange={(e) => setKind(e.target.value)}
            aria-label="Filter the activity stream"
            className="h-8 w-auto min-w-[120px] py-0 text-[11.5px]"
          >
            <option value="all">Everything ({history.total})</option>
            {history.kinds.map((k) => (
              <option key={k.value} value={k.value}>
                {k.label}
              </option>
            ))}
          </CrmSelect>
        )}
      </div>

      {events.length === 0 ? (
        <CrmDataState
          kind="empty"
          compact
          icon={FileText}
          title="Nothing recorded yet"
          description="Calls, notes, demos and stage changes appear here."
        />
      ) : (
        <ol className="space-y-0">
          {events.map((event, index) => {
            const tone = toneClasses(channelTone(event.type));

            return (
              <li key={event.id} className="flex min-w-0 gap-3">
                {/* The rail: a dot per event, a line between them. */}
                <span className="flex flex-col items-center" aria-hidden="true">
                  <span className={cn('mt-1.5 h-2 w-2 shrink-0 rounded-full', tone.solid)} />
                  {index < events.length - 1 && <span className="w-px flex-1 bg-ovs-crm-line" />}
                </span>

                <div className="min-w-0 flex-1 pb-3.5">
                  <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                    <span className="text-[12.5px] font-medium text-ovs-crm-text-primary">
                      {event.type_label}
                    </span>
                    <span className="shrink-0 text-[11px] text-ovs-crm-text-muted">{event.at_label}</span>
                  </div>
                  <p className="mt-0.5 break-words text-[12.5px] leading-relaxed text-ovs-crm-text-secondary">
                    {event.description}
                  </p>
                  <p className="mt-0.5 text-[11px] text-ovs-crm-text-muted">{event.actor}</p>
                </div>
              </li>
            );
          })}
        </ol>
      )}

      {history.has_more && (
        <div className="border-t border-ovs-crm-border pt-3">
          <CrmButton size="sm" onClick={() => onPage(history.page + 1)}>
            Older activity
          </CrmButton>
        </div>
      )}
    </CrmCard>
  );
}

function Attachments({ dealId, files, can }: { dealId: number; files: DealFile[]; can: DealCan }) {
  const form = useForm<{ file: File | null; title: string }>({ file: null, title: '' });
  const [deleting, setDeleting] = React.useState<DealFile | null>(null);
  const [removing, setRemoving] = React.useState(false);

  const confirmDelete = () => {
    if (!deleting) return;
    setRemoving(true);
    router.delete(route('company.crm.leads.documents.destroy', [dealId, deleting.id]), {
      preserveScroll: true,
      onSuccess: () => {
        setDeleting(null);
        router.reload({ only: ['files'] });
      },
      onFinish: () => setRemoving(false),
    });
  };

  return (
    <CrmCard className="px-4 py-3.5">
      <h2 className="mb-3 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
        <Paperclip className="h-3.5 w-3.5" aria-hidden="true" />
        Attachments
        {files.length > 0 && <span className="text-ovs-crm-text-secondary">({files.length})</span>}
      </h2>

      {files.length === 0 ? (
        <p className="text-[12px] text-ovs-crm-text-muted">Nothing attached to this deal.</p>
      ) : (
        <ul className="space-y-1.5">
          {files.map((file) => (
            <li key={file.id} className="flex min-w-0 items-center gap-2">
              <FileText className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
              <span className="min-w-0 flex-1 truncate text-[12.5px] text-ovs-crm-text-primary">
                {file.name}
              </span>
              <span className="shrink-0 text-[11px] text-ovs-crm-text-muted">{file.uploaded_at}</span>
              {file.download_url && (
                <CrmButton
                  size="sm"
                  icon={Download}
                  aria-label={`Download ${file.name}`}
                  href={file.download_url}
                  external
                  className="w-8 px-0"
                />
              )}
              {can.update && (
                <CrmButton
                  size="sm"
                  tone="danger"
                  icon={Trash2}
                  aria-label={`Delete ${file.name}`}
                  onClick={() => setDeleting(file)}
                  className="w-8 px-0"
                />
              )}
            </li>
          ))}
        </ul>
      )}

      {can.update && (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            form.post(route('company.crm.leads.documents.store', dealId), {
              preserveScroll: true,
              forceFormData: true,
              onSuccess: () => {
                form.reset();
                router.reload({ only: ['files'] });
              },
            });
          }}
          className="mt-3 flex flex-wrap items-center gap-2 border-t border-ovs-crm-border pt-3"
        >
          <input
            type="file"
            aria-label="Choose a file"
            accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
            onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
            className="min-w-0 flex-1 text-[11.5px] text-ovs-crm-text-secondary file:mr-2 file:rounded-full file:border-0 file:bg-ovs-crm-surface-inset file:px-3 file:py-1.5 file:text-[11.5px] file:text-ovs-crm-text-primary"
          />
          <CrmButton size="sm" icon={Upload} loading={form.processing} disabled={!form.data.file} type="submit">
            Attach
          </CrmButton>
        </form>
      )}

      <CrmConfirm
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        onConfirm={confirmDelete}
        title={`Delete ${deleting?.name ?? 'this file'}?`}
        description="This cannot be undone."
        confirmLabel="Delete file"
        loading={removing}
      />
    </CrmCard>
  );
}

/* ── Lost banner ────────────────────────────────────────────────────── */

function LostBanner({ deal, canUpdate, onReopen }: { deal: Deal; canUpdate: boolean; onReopen: () => void }) {
  return (
    <CrmCard className="flex flex-wrap items-center gap-x-5 gap-y-2 px-5 py-3.5">
      <CrmStatusPill tone="bad">Lost</CrmStatusPill>

      <span className="text-[13px] text-ovs-crm-text-primary">
        {deal.lost_reason ? deal.lost_reason.replace(/_/g, ' ') : 'No reason recorded'}
      </span>

      {deal.lost_competitor && (
        <span className="text-[12.5px] text-ovs-crm-text-muted">to {deal.lost_competitor}</span>
      )}

      {deal.lost_at && <span className="text-[12.5px] text-ovs-crm-text-muted">on {deal.lost_at}</span>}

      {deal.winback_scheduled_at && (
        <CrmStatusPill tone="info">Win-back {deal.winback_scheduled_at}</CrmStatusPill>
      )}

      {canUpdate && (
        <CrmButton size="sm" icon={RotateCcw} onClick={onReopen} className="ml-auto">
          Reopen
        </CrmButton>
      )}
    </CrmCard>
  );
}

/* ── The page ───────────────────────────────────────────────────────── */

export default function DealDetail({
  deal,
  can,
  now,
  evaluation,
  commercial,
  assignable_users,
  outreach,
  conversation,
  history,
  files,
}: DealPageProps) {
  /*
   * A deep link into a specific workflow. The Deals list's "+ Add setup"
   * prompt opens straight into Clinical Discovery with `?open=discovery` —
   * read once, on mount, so a later re-render (a save, a reload) does not
   * reopen a form the rep already closed.
   */
  const [workflow, setWorkflow] = React.useState<Workflow>(() => {
    if (typeof window === 'undefined') return null;

    const requested = new URLSearchParams(window.location.search).get('open');

    return requested === 'discovery' ? 'discovery' : null;
  });
  const close = () => setWorkflow(null);

  /* The three forms the stage hero can open. */
  const [completing, setCompleting] = React.useState<number | null>(null);
  const completeForm = useForm({ outcome: 'interested', feedback: '' });

  const [rescheduling, setRescheduling] = React.useState<number | null>(null);
  const rescheduleForm = useForm({ scheduled_at: '', notes: '' });

  /*
   * `additional_days` and `reason`, matching what
   * `trials.extension-requests.store` validates. Posting to that endpoint
   * rather than the old `trials.extend` is what makes this governed: a rep
   * without approval authority gets a pending row, and a manager gets the
   * same route applying the extension in the same request.
   */
  const [extending, setExtending] = React.useState<number | null>(null);
  const extendForm = useForm({ additional_days: '7', reason: '' });

  /* Rejecting a pending extension request asks for a reason, same as a lost trial does. */
  const [rejectingExtension, setRejectingExtension] = React.useState<{ trialId: number; requestId: number } | null>(null);
  const rejectExtensionForm = useForm({ rejection_reason: '' });

  /* Which of the two views the right column shows — chat is the default when there's someone to message. */
  const [rightPanel, setRightPanel] = React.useState<RightPanel>('whatsapp');

  const focus = focusFor(deal.status);
  const Icon = FOCUS_ICON[focus];

  const demo = evaluation.demos[0];
  const trial = evaluation.trials.find((t) => t.status === 'active') ?? evaluation.trials[0];

  const received = commercial.payments
    .filter((p) => p.status === 'successful')
    .reduce((sum, p) => sum + p.amount, 0);

  return (
    <CrmShell title={deal.company_name}>
      {/*
        * One overflow guard for the page. Everything inside wraps or truncates;
        * this is the backstop that turns a mistake into a clipped word rather
        * than a page that scrolls sideways.
        */}
      <div className="mx-auto min-w-0 max-w-[1480px] space-y-4 overflow-x-hidden pb-16">
        <Masthead
          deal={deal}
          can={can}
          outreach={outreach}
          transitions={now.stage_control.transitions}
          onWorkflow={setWorkflow}
        />

        {deal.status === 'lost' && (
          <LostBanner deal={deal} canUpdate={can.update} onReopen={() => setWorkflow('reopen')} />
        )}

        <div className="grid min-w-0 grid-cols-1 items-start gap-6 lg:grid-cols-12">
          {/* ── Left: the operational hub ─────────────────────────── */}
          <div className="min-w-0 space-y-4 lg:col-span-8">
            <CrmSection title={FOCUS_TITLE[focus]} icon={Icon} hue={toneHex('violet')}>
              {focus === 'demo' && (
                <DemoHero
                  demo={demo}
                  can={can}
                  onComplete={setCompleting}
                  onReschedule={setRescheduling}
                  onSchedule={() => setWorkflow('demo')}
                />
              )}

              {focus === 'trial' && (
                <TrialHero
                  trial={trial}
                  conversion={commercial.conversion}
                  can={can}
                  dealId={deal.id}
                  onExtend={setExtending}
                  onStart={() => setWorkflow('trial')}
                  onMarkLost={() => setWorkflow('lost')}
                  onApproveExtension={(trialId, requestId) =>
                    router.post(
                      route('company.crm.trials.extension-requests.approve', [trialId, requestId]),
                      {},
                      { preserveScroll: true }
                    )
                  }
                  onRejectExtension={(trialId, requestId) => setRejectingExtension({ trialId, requestId })}
                />
              )}

              {focus === 'closing' && (
                <ClosingHero
                  commercial={commercial}
                  can={can}
                  dealId={deal.id}
                  onRecordPayment={() => setWorkflow('payment')}
                />
              )}

              {focus === 'inflow' && (
                <InflowHero
                  now={now}
                  can={can}
                  dealId={deal.id}
                  onSchedule={() => setWorkflow('demo')}
                  onAddTask={() => setWorkflow('task')}
                />
              )}
            </CrmSection>

            {deal.branches.length > 0 && (
              <CrmSection
                title="Sites & infrastructure"
                icon={Building2}
                hue={toneHex('teal')}
                description={`${deal.branches.length} ${deal.branches.length === 1 ? 'site' : 'sites'} on file`}
                flush
              >
                <BranchTable branches={deal.branches} />
              </CrmSection>
            )}

            {/*
              * The closing hero already renders this deal's live quotation in
              * full, so the standing summary would repeat it. Shown everywhere
              * else, where the money is context rather than the focus.
              */}
            {focus !== 'closing' && (
              <CrmSection title="Commercials" icon={IndianRupee} hue={toneHex('lime')}>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
                  {[
                    ['Monthly', inr(deal.expected_mrr)],
                    ['Annual', inr(deal.expected_arr)],
                    ['Quoted', commercial.quotations.length > 0 ? inr(commercial.quotations.reduce((s, q) => s + q.grand_total, 0)) : '—'],
                    ['Received', received > 0 ? inr(received) : '—'],
                  ].map(([label, value]) => (
                    <div key={label} className="min-w-0">
                      <dt className="text-[10px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                        {label}
                      </dt>
                      <dd className="truncate text-[14px] font-medium tabular-nums text-ovs-crm-text-primary">
                        {value}
                      </dd>
                    </div>
                  ))}
                </dl>

                {deal.selected_plan && (
                  <p className="mt-3 text-[12.5px] text-ovs-crm-text-muted">
                    {deal.selected_plan.name} · billed {deal.selected_plan.billing_period}
                  </p>
                )}

                {commercial.quotations.length > 0 && (
                  <ul className="mt-3 space-y-1.5 border-t border-ovs-crm-border pt-3">
                    {commercial.quotations.slice(0, 4).map((q) => (
                      <li key={q.id} className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="min-w-0 flex-1 truncate text-[12.5px] text-ovs-crm-text-primary">
                          {q.quotation_number}
                          <span className="text-ovs-crm-text-muted"> · v{q.version}</span>
                        </span>
                        <span className="shrink-0 text-[12.5px] tabular-nums text-ovs-crm-text-secondary">
                          {inr(q.grand_total)}
                        </span>
                        <CrmStatusPill tone={q.is_settled ? 'good' : q.status === 'approved' ? 'warn' : 'neutral'}>
                          {q.is_settled ? 'Settled' : q.status.replace(/_/g, ' ')}
                        </CrmStatusPill>
                      </li>
                    ))}
                  </ul>
                )}

                <div className="mt-3 flex flex-wrap items-center gap-1.5 border-t border-ovs-crm-border pt-3">
                  {commercial.lifecycle.steps.map((step) => (
                    <CrmStatusPill key={step.key} tone={step.done ? 'good' : 'neutral'}>
                      {step.label}
                    </CrmStatusPill>
                  ))}
                </div>
              </CrmSection>
            )}
          </div>

          {/* ── Right: everything said and done ───────────────────── */}
          <div className="min-w-0 space-y-4 lg:col-span-4">
            <CrmTabs<RightPanel>
              value={rightPanel}
              onChange={setRightPanel}
              tabs={[
                { key: 'whatsapp', label: '💬 WhatsApp' },
                { key: 'email', label: '✉️ Email' },
                { key: 'activity', label: '📋 Activity' },
              ]}
            />

            {rightPanel === 'whatsapp' && (
              outreach.phone ? (
                <WhatsAppChatPanel
                  dealId={deal.id}
                  conversation={conversation.whatsapp}
                  canSend={can.update}
                  onSent={() => router.reload({ only: ['conversation', 'history'] })}
                />
              ) : (
                <CrmCard className="px-4 py-3">
                  <p className="text-[12px] text-ovs-crm-text-muted">
                    No phone number on file — WhatsApp isn't reachable for this deal.
                  </p>
                </CrmCard>
              )
            )}

            {rightPanel === 'email' && (
              <EmailPanel
                dealId={deal.id}
                email={conversation.email}
                canSend={can.update}
                onSent={() => router.reload({ only: ['conversation', 'history'] })}
              />
            )}

            {rightPanel === 'activity' && (
              <>
                {can.update && (
                  <QuickComposer
                    dealId={deal.id}
                    onLogged={() => router.reload({ only: ['history'] })}
                  />
                )}

                <Deferred
                  data="history"
                  fallback={
                    <CrmCard className="px-4 py-3.5">
                      <p className="mb-3 text-[11px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                        Activity
                      </p>
                      <CrmDataState kind="loading" shape="list" rows={4} />
                    </CrmCard>
                  }
                >
                  {history ? (
                    <ActivityStream
                      history={history}
                      onPage={(page) => router.reload({ only: ['history'], data: { history_page: page } })}
                    />
                  ) : null}
                </Deferred>
              </>
            )}

            <Deferred data="files" fallback={<div />}>
              <Attachments dealId={deal.id} files={files ?? []} can={can} />
            </Deferred>
          </div>
        </div>
      </div>

      {/* ── The forms ────────────────────────────────────────────── */}

      <LeadCallModal
        isOpen={workflow === 'call'}
        onClose={close}
        leadId={deal.id}
        companyName={deal.company_name}
      />

      <LeadScheduleDemoModal
        isOpen={workflow === 'demo'}
        onClose={close}
        lead={deal}
        assignableUsers={assignable_users}
      />

      <LeadStartTrialModal
        isOpen={workflow === 'trial'}
        onClose={close}
        lead={deal}
        packages={commercial.packages}
      />

      <RecordAdvancePaymentModal
        isOpen={workflow === 'payment'}
        onClose={close}
        quotations={commercial.quotations}
      />

      <LeadLostModal
        isOpen={workflow === 'lost'}
        onClose={close}
        leadId={deal.id}
        companyName={deal.company_name}
        lostReasons={now.stage_control.lost_reasons}
      />

      <LeadReopenModal
        isOpen={workflow === 'reopen'}
        onClose={close}
        leadId={deal.id}
        companyName={deal.company_name}
      />

      <TaskModal open={workflow === 'task'} onClose={close} dealId={deal.id} />

      <DiscoveryModal open={workflow === 'discovery'} onClose={close} deal={deal} />

      <ReassignModal
        open={workflow === 'reassign'}
        onClose={close}
        dealId={deal.id}
        users={assignable_users}
        currentOwnerId={deal.assigned_user?.id ?? null}
      />

      <DeleteModal open={workflow === 'delete'} onClose={close} deal={deal} />

      <CrmModal
        open={completing !== null}
        onClose={() => setCompleting(null)}
        title="How did the demo go?"
        description="The outcome is what moves the deal on, so it is asked for rather than assumed."
        footer={
          <>
            <CrmButton onClick={() => setCompleting(null)}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              loading={completeForm.processing}
              onClick={() =>
                completeForm.post(route('company.crm.demos.complete', completing as number), {
                  preserveScroll: true,
                  onSuccess: () => {
                    /*
                     * "Wants a trial" is the one outcome with somewhere left to
                     * go — completing the demo and starting the trial are two
                     * calls the rep is already thinking of as one action.
                     */
                    const wantsTrial = completeForm.data.outcome === 'trial_requested';
                    completeForm.reset();
                    setCompleting(null);
                    if (wantsTrial) setWorkflow('trial');
                  },
                })
              }
            >
              Mark it done
            </CrmButton>
          </>
        }
      >
        <div className="flex flex-col gap-3.5">
          <CrmFormField label="Outcome" required error={completeForm.errors.outcome}>
            <CrmSelect
              value={completeForm.data.outcome}
              onChange={(e) => completeForm.setData('outcome', e.target.value)}
            >
              <option value="highly_interested">Very interested</option>
              <option value="interested">Interested</option>
              <option value="trial_requested">Wants a trial</option>
              <option value="quotation_requested">Wants a quotation</option>
              <option value="needs_followup">Needs another conversation</option>
              <option value="no_decision">No decision yet</option>
              <option value="not_interested">Not interested</option>
            </CrmSelect>
          </CrmFormField>

          <CrmFormField label="What they said" error={completeForm.errors.feedback}>
            <CrmTextarea
              rows={3}
              value={completeForm.data.feedback}
              onChange={(e) => completeForm.setData('feedback', e.target.value)}
            />
          </CrmFormField>
        </div>
      </CrmModal>

      <CrmModal
        open={rescheduling !== null}
        onClose={() => setRescheduling(null)}
        title="Move this demo"
        description="The presenter's other bookings are checked, so a clash is refused rather than double-booked."
        footer={
          <>
            <CrmButton onClick={() => setRescheduling(null)}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              loading={rescheduleForm.processing}
              onClick={() => {
                /*
                 * Only the new time is sent. Everything else the endpoint
                 * accepts is already on the demo, and resending it blank would
                 * clear the meeting link on every move.
                 */
                rescheduleForm.transform((data) => ({
                  scheduled_at: data.scheduled_at,
                  notes: data.notes || null,
                }));
                rescheduleForm.post(route('company.crm.demos.reschedule', rescheduling as number), {
                  preserveScroll: true,
                  onSuccess: () => {
                    rescheduleForm.reset();
                    setRescheduling(null);
                  },
                });
              }}
            >
              Move it
            </CrmButton>
          </>
        }
      >
        <div className="flex flex-col gap-3.5">
          <CrmFormField label="New date and time" required error={rescheduleForm.errors.scheduled_at}>
            <CrmInput
              type="datetime-local"
              value={rescheduleForm.data.scheduled_at}
              onChange={(e) => rescheduleForm.setData('scheduled_at', e.target.value)}
            />
          </CrmFormField>

          <CrmFormField label="Why it moved" error={rescheduleForm.errors.notes}>
            <CrmTextarea
              rows={2}
              value={rescheduleForm.data.notes}
              onChange={(e) => rescheduleForm.setData('notes', e.target.value)}
            />
          </CrmFormField>
        </div>
      </CrmModal>

      <CrmModal
        open={extending !== null}
        onClose={() => setExtending(null)}
        title={can.approve_trial_extension ? 'Extend the trial' : 'Request an extension'}
        description={
          can.approve_trial_extension
            ? 'How much longer do they need? This applies immediately.'
            : 'How much longer do they need? A manager decides this one.'
        }
        footer={
          <>
            <CrmButton onClick={() => setExtending(null)}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              loading={extendForm.processing}
              onClick={() =>
                extendForm.post(route('company.crm.trials.extension-requests.store', extending as number), {
                  preserveScroll: true,
                  onSuccess: () => setExtending(null),
                })
              }
            >
              {can.approve_trial_extension ? 'Extend' : 'Send request'}
            </CrmButton>
          </>
        }
      >
        <div className="flex flex-col gap-3.5">
          <CrmFormField label="Extra days" required error={extendForm.errors.additional_days}>
            <CrmSelect
              value={extendForm.data.additional_days}
              onChange={(e) => extendForm.setData('additional_days', e.target.value)}
            >
              <option value="3">3 days</option>
              <option value="7">7 days</option>
              <option value="14">14 days</option>
              <option value="30">30 days</option>
            </CrmSelect>
          </CrmFormField>

          <CrmFormField
            label="Why"
            required
            error={extendForm.errors.reason}
            hint="The endpoint asks for at least a few words, so the next person knows why the window moved."
          >
            <CrmTextarea
              rows={2}
              value={extendForm.data.reason}
              onChange={(e) => extendForm.setData('reason', e.target.value)}
            />
          </CrmFormField>
        </div>
      </CrmModal>

      <CrmModal
        open={rejectingExtension !== null}
        onClose={() => setRejectingExtension(null)}
        title="Reject the extension request"
        description="The rep who asked will see why."
        footer={
          <>
            <CrmButton onClick={() => setRejectingExtension(null)}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              loading={rejectExtensionForm.processing}
              onClick={() => {
                if (!rejectingExtension) return;

                rejectExtensionForm.post(
                  route('company.crm.trials.extension-requests.reject', [
                    rejectingExtension.trialId,
                    rejectingExtension.requestId,
                  ]),
                  {
                    preserveScroll: true,
                    onSuccess: () => setRejectingExtension(null),
                  }
                );
              }}
            >
              Reject
            </CrmButton>
          </>
        }
      >
        <CrmFormField label="Reason" required error={rejectExtensionForm.errors.rejection_reason}>
          <CrmTextarea
            rows={2}
            value={rejectExtensionForm.data.rejection_reason}
            onChange={(e) => rejectExtensionForm.setData('rejection_reason', e.target.value)}
          />
        </CrmFormField>
      </CrmModal>
    </CrmShell>
  );
}

/* ── Small forms ────────────────────────────────────────────────────── */

function TaskModal({ open, onClose, dealId }: { open: boolean; onClose: () => void; dealId: number }) {
  const form = useForm({
    title: '',
    description: '',
    type: 'follow_up',
    priority: 'medium',
    due_at: '',
  });

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title="Schedule a task"
      description="It becomes this deal's next action."
      footer={
        <>
          <CrmButton onClick={onClose}>Cancel</CrmButton>
          <CrmButton
            tone="primary"
            loading={form.processing}
            disabled={!form.data.title.trim()}
            onClick={() =>
              form.post(route('company.crm.leads.tasks.store', dealId), {
                preserveScroll: true,
                onSuccess: () => {
                  form.reset();
                  onClose();
                },
              })
            }
          >
            Schedule it
          </CrmButton>
        </>
      }
    >
      <div className="flex flex-col gap-3.5">
        <CrmFormField label="What needs doing" required error={form.errors.title}>
          <CrmInput value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
        </CrmFormField>

        <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
          <CrmFormField label="Kind" error={form.errors.type}>
            <CrmSelect value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
              <option value="follow_up">Follow up</option>
              <option value="call">Call</option>
              <option value="email">Email</option>
              <option value="meeting">Meeting</option>
              <option value="demo">Demo</option>
            </CrmSelect>
          </CrmFormField>

          <CrmFormField label="Priority" error={form.errors.priority}>
            <CrmSelect value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </CrmSelect>
          </CrmFormField>
        </div>

        <CrmFormField label="Due" error={form.errors.due_at}>
          <CrmInput
            type="datetime-local"
            value={form.data.due_at}
            onChange={(e) => form.setData('due_at', e.target.value)}
          />
        </CrmFormField>

        <CrmFormField label="Detail" error={form.errors.description}>
          <CrmTextarea
            rows={2}
            value={form.data.description}
            onChange={(e) => form.setData('description', e.target.value)}
          />
        </CrmFormField>
      </div>
    </CrmModal>
  );
}

/**
 * Clinical discovery — the questions intake deliberately does not ask.
 *
 * `leads.facility_type` and the four capacity columns on `lead_branches` have
 * existed since the healthcare architecture work. They are summed into the
 * footprint badge by `DealDomainPresenter::facility()` and rendered as the
 * branch matrix on this page — and until this form, nothing in the application
 * wrote either. The only rows carrying them came from the preview seeder, which
 * meant every capacity figure a rep had ever seen in this product was fiction.
 *
 * Two things worth knowing about the submit:
 *
 *   1. It sends the *whole* branch array, not just the main site. `updateLead()`
 *      routes branches through `syncLeadBranches()`, which deletes every row and
 *      recreates from the payload — so posting one branch to a three-site group
 *      would silently destroy the other two. The untouched sites are passed
 *      through exactly as they arrived.
 *
 *   2. Blank means unknown, and is sent as null rather than zero. A
 *      physiotherapy chain has no beds and a solo clinic has no theatres;
 *      `DealDomainPresenter` renders "nobody asked" and "the answer was none"
 *      differently, and typing zero into an empty box would erase that.
 */
function DiscoveryModal({
  open,
  onClose,
  deal,
}: {
  open: boolean;
  onClose: () => void;
  deal: Deal;
}) {
  /* The main site, or the first one — whichever this lead actually has. */
  const main = deal.branches.find((b) => b.is_main) ?? deal.branches[0];

  const asText = (value: number | null | undefined) => (value === null || value === undefined ? '' : String(value));

  const form = useForm({
    facility_type: deal.facility?.type ?? '',
    doctor_count: asText(main?.doctor_count),
    treatment_station_count: asText(main?.treatment_station_count),
    bed_count: asText(main?.bed_count),
    operation_theatre_count: asText(main?.operation_theatre_count),
  });

  /* Re-seed when a different deal, or a fresh payload, arrives underneath. */
  React.useEffect(() => {
    if (! open) return;

    form.setDefaults({
      facility_type: deal.facility?.type ?? '',
      doctor_count: asText(main?.doctor_count),
      treatment_station_count: asText(main?.treatment_station_count),
      bed_count: asText(main?.bed_count),
      operation_theatre_count: asText(main?.operation_theatre_count),
    });
    form.reset();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, deal.id]);

  const numberOrNull = (value: string) => (value.trim() === '' ? null : Number(value));

  const submit = () => {
    const capacity = {
      doctor_count: numberOrNull(form.data.doctor_count),
      treatment_station_count: numberOrNull(form.data.treatment_station_count),
      bed_count: numberOrNull(form.data.bed_count),
      operation_theatre_count: numberOrNull(form.data.operation_theatre_count),
    };

    const branches = deal.branches.map((branch) => ({
      name: branch.name,
      is_main: branch.is_main,
      area: branch.area,
      city: branch.city,
      postal_code: branch.postal_code,
      contact_name: branch.contact_name,
      phone: branch.phone,
      /* Only the site being described takes the new figures. */
      doctor_count: branch.id === main?.id ? capacity.doctor_count : branch.doctor_count,
      bed_count: branch.id === main?.id ? capacity.bed_count : branch.bed_count,
      treatment_station_count:
        branch.id === main?.id ? capacity.treatment_station_count : branch.treatment_station_count,
      operation_theatre_count:
        branch.id === main?.id ? capacity.operation_theatre_count : branch.operation_theatre_count,
    }));

    router.put(
      route('company.crm.leads.update', deal.id),
      {
        /*
         * `company_name` and `contact_name` ride along because
         * `UpdateLeadRequest` requires them; this form does not edit them, so
         * it echoes what the deal already holds.
         */
        company_name: deal.company_name,
        contact_name: deal.contact_name,
        facility_type: form.data.facility_type || null,
        branches,
      },
      { preserveScroll: true, onSuccess: onClose }
    );
  };

  const field = (
    key: 'doctor_count' | 'treatment_station_count' | 'bed_count' | 'operation_theatre_count',
    label: string,
    hint: string
  ) => (
    <CrmFormField label={label} hint={hint} error={form.errors[key]}>
      <CrmInput
        type="number"
        min={0}
        value={form.data[key]}
        onChange={(e) => form.setData(key, e.target.value)}
        placeholder="—"
      />
    </CrmFormField>
  );

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title="Clinical discovery"
      description="What kind of place this is, and how big it is. Leave a box empty if you did not ask."
      footer={
        <>
          <CrmButton onClick={onClose}>Cancel</CrmButton>
          <CrmButton tone="primary" icon={Check} loading={form.processing} onClick={submit}>
            Save the footprint
          </CrmButton>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <CrmFormField
          label="Facility archetype"
          error={form.errors.facility_type}
          hint="Decides which capacity figure leads the badge — beds for a hospital, chairs for a clinic."
        >
          <CrmSelect
            value={form.data.facility_type}
            onChange={(e) => form.setData('facility_type', e.target.value)}
          >
            <option value="">Not recorded</option>
            {FACILITY_TYPES.map((type) => (
              <option key={type.value} value={type.value}>
                {type.label}
              </option>
            ))}
          </CrmSelect>
        </CrmFormField>

        <div>
          <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            {main ? `Capacity — ${main.name}` : 'Capacity'}
          </p>

          <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
            {field('doctor_count', 'Doctors', 'Practising at this site.')}
            {field('treatment_station_count', 'Chairs / treatment bays', 'Dental chairs, physio bays.')}
            {field('bed_count', 'Inpatient beds', 'Leave empty for an outpatient practice.')}
            {field('operation_theatre_count', 'Operation theatres', 'Leave empty if there are none.')}
          </div>
        </div>

        {deal.branches.length > 1 && (
          <p className="text-[11.5px] text-ovs-crm-text-muted">
            This group has {deal.branches.length} sites. These figures describe{' '}
            {main?.name ?? 'the main site'}; the others keep what they already hold.
          </p>
        )}
      </div>
    </CrmModal>
  );
}
function ReassignModal({
  open,
  onClose,
  dealId,
  users,
  currentOwnerId,
}: {
  open: boolean;
  onClose: () => void;
  dealId: number;
  users: { id: number; name: string }[];
  currentOwnerId: number | null;
}) {
  const form = useForm({ assigned_user_id: currentOwnerId ? String(currentOwnerId) : '' });

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title="Reassign this deal"
      description="The new owner is told, and the handover is written to the deal's history."
      footer={
        <>
          <CrmButton onClick={onClose}>Cancel</CrmButton>
          <CrmButton
            tone="primary"
            loading={form.processing}
            disabled={!form.data.assigned_user_id}
            onClick={() =>
              form.post(route('company.crm.leads.assign', dealId), {
                preserveScroll: true,
                onSuccess: onClose,
              })
            }
          >
            Reassign
          </CrmButton>
        </>
      }
    >
      <CrmFormField label="New owner" required error={form.errors.assigned_user_id}>
        <CrmSelect
          value={form.data.assigned_user_id}
          onChange={(e) => form.setData('assigned_user_id', e.target.value)}
        >
          <option value="">Pick a person</option>
          {users.map((user) => (
            <option key={user.id} value={user.id}>
              {user.name}
            </option>
          ))}
        </CrmSelect>
      </CrmFormField>
    </CrmModal>
  );
}

function DeleteModal({ open, onClose, deal }: { open: boolean; onClose: () => void; deal: Deal }) {
  const [busy, setBusy] = React.useState(false);

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title={`Delete ${deal.company_name}?`}
      description="The deal and everything recorded against it go with it. This cannot be undone."
      footer={
        <>
          <CrmButton onClick={onClose}>Keep it</CrmButton>
          <CrmButton
            tone="danger"
            loading={busy}
            onClick={() => {
              setBusy(true);
              router.delete(route('company.crm.leads.destroy', deal.id), {
                onFinish: () => setBusy(false),
              });
            }}
          >
            Delete permanently
          </CrmButton>
        </>
      }
    >
      <p className="text-[13px] leading-relaxed text-ovs-crm-text-secondary">
        Demos, trials, quotations and the activity history attached to this deal will no longer be
        reachable. If the deal simply did not close, mark it lost instead — that keeps the record and
        the reason.
      </p>
    </CrmModal>
  );
}
