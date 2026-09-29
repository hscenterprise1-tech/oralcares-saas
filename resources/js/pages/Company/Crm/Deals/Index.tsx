import React from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
  CrmShell,
  CrmPageHead,
  CrmCard,
  CrmTable,
  CrmFilterBar,
  CrmPagination,
  CrmStatusPill,
  CrmStageBadge,
  CrmBadge,
  CrmButton,
  CrmInput,
  CrmSelect,
  CrmAvatar,
  CrmModal,
  CrmFormField,
  CrmTextarea,
  useCrmConfirm,
  specialtyTone,
  inr,
  type CrmColumn,
  type CrmFilterDef,
  type CrmFilterChip,
} from '@/pages/Company/design';
import {
  AlertTriangle,
  ArrowRight,
  Building2,
  CalendarPlus,
  Check,
  Clock,
  Download,
  ExternalLink,
  MapPin,
  MessageCircle,
  MoreHorizontal,
  Phone,
  Plus,
  Target,
  Upload,
  UserCog,
  Users,
  Video,
  X,
} from 'lucide-react';
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import {
  TriageModal,
  WinbackModal,
  type TriageTarget,
  type LostReasonOption,
} from './components/LostTriage';
import { QuickCreateLeadModal } from './components/QuickCreateLeadModal';
import { LeadImportModal } from './components/LeadImportModal';

/**
 * Deals — one full-width, high-density list, and deliberately not a board.
 *
 * A twelve-lane kanban stood here and was thrown away. Twelve columns do not
 * fit on any screen, so the page was a horizontal scroll: reaching Negotiation
 * meant dragging past four lanes, nothing was comparable to anything you could
 * not see beside it, and at a hundred thousand deals every lane was a capped
 * sample of its stage presenting itself as the stage.
 *
 * Two things replaced it, and neither is new:
 *
 *   1. Four queues instead of twelve stages. Nobody's day is organised around
 *      the difference between `quotation_sent` and `quotation_approved`.
 *      Inflow, Demos, Trials, Closing — with won and lost in an archive —
 *      rendered through `CrmFilterBar`'s segment strip, the same control every
 *      other CRM list filters with. The count on a tab is the count of what
 *      pressing it produces.
 *
 *   2. `CrmTable`, full width. It carries its own card layout under 768px from
 *      the same column definitions, so the phone view cannot drift away from
 *      the desktop one, and neither can scroll sideways.
 *
 * A row opens the deal's own page. An embedded detail pane was tried here and
 * rejected: half a screen is not enough for a trial watchtower or a quotation
 * history, and `Leads/Show.tsx` is already built to hold them.
 */

// ── Payload ────────────────────────────────────────────────────────────

/**
 * A specialty, ready to render.
 *
 * `tone` is the category's colour role rather than a hex value, so a badge that
 * reads clearly in light mode does not vanish in dark. A specialty with no
 * catalogue entry still arrives here, under the neutral tone — showing fewer
 * badges than the record holds would be worse than an uncoloured one.
 */
interface SpecialtyBadge {
  key: string;
  name: string;
  category: string | null;
  category_label: string | null;
  tone: string;
}

/**
 * The facility archetype: what kind of place this is, and how big.
 *
 * `archetype` is composed server-side because which figure leads depends on
 * the facility type — beds for a hospital, doctors for a polyclinic, sites for
 * a group — and that decision belongs in one place rather than in every
 * surface that renders it.
 */
interface FacilityArchetype {
  type: string | null;
  label: string;
  site_count: number;
  is_multi_site: boolean;
  doctors: number | null;
  beds: number | null;
  theatres: number | null;
  stations: number | null;
  archetype: string;
}

/** The newest demo, reduced to what a row can act on. */
interface DealRowDemo {
  id: number;
  scheduled_at: string | null;
  scheduled_label: string | null;
  /** "Today at 4:30 PM" — computed server-side so nothing here re-derives "today". */
  relative_label: string | null;
  is_today: boolean;
  is_upcoming: boolean;
  status: string;
  platform: string | null;
  platform_label: string | null;
  is_field_visit: boolean;
  meeting_link: string | null;
  location: string | null;
  /** A booked demo with no way to join it — invisible until the morning of. */
  missing_join_detail: boolean;
}

/** The current trial: which package, and whether anybody is using it. */
interface DealRowTrial {
  id: number;
  status: string;
  package: { id: number; key: string | null; name: string } | null;
  end_date: string | null;
  days_remaining: number | null;
  /** Which day of the evaluation this is, and how long it runs — "Day 12 of 14". */
  day_number: number | null;
  total_days: number | null;
  /** The same threshold the trial's own lifecycle service uses. */
  is_ending_soon: boolean;
  extension_count: number;
  /** Null means nobody has measured it — never render that as zeros. */
  telemetry: {
    patients: number;
    prescriptions: number;
    invoices: number;
    is_active_today: boolean;
    is_inactive: boolean;
    hours_since_login: number | null;
  } | null;
}

interface DealRow {
  id: number;
  company_name: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  status: string;
  status_label: string;
  priority: string;
  source: string | null;
  source_label: string | null;
  expected_mrr: number;
  expected_arr: number;
  /** The cycle the ARR is actually billed on. Null when no plan is chosen yet. */
  billing_period: string | null;
  billing_period_label: string | null;
  probability: number;
  stage_age_days: number | null;
  last_activity_at: string | null;
  next_best_action: string | null;
  next_followup_at: string | null;
  next_followup_label: string | null;
  /** "Tomorrow at 2:00 PM" — the same relative wording the demo card uses. */
  next_followup_relative_label: string | null;
  /** The note behind the callback, trimmed to a scannable snippet. */
  followup_notes_snippet: string | null;
  is_overdue: boolean;
  /** The canonical stalled definition — `crm.stale_lead_days`, currently 14. */
  is_stalled: boolean;
  /**
   * The earlier, softer signal: more than four days in one stage.
   *
   * A separate field from `is_stalled` on purpose. They answer different
   * questions and the list shows them differently — amber for "sitting a
   * while", red for "stalled by the definition the Stale view lists".
   */
  needs_attention: boolean;
  next_stage: string | null;
  next_stage_label: string | null;
  city: string | null;
  state: string | null;
  postal_code: string | null;
  area: string | null;
  specialties: SpecialtyBadge[];
  facility: FacilityArchetype | null;
  demo: DealRowDemo | null;
  trial: DealRowTrial | null;
  lost_reason: string | null;
  lost_at: string | null;
  lost_competitor: string | null;
  winback_scheduled_at: string | null;
  assigned_user: { id: number; name: string; initials: string } | null;
  updated_at: string | null;
  url: string;
}

/** One operational queue, with its true size under the current filters. */
interface QueueTab {
  key: string;
  label: string;
  hint: string;
  is_archive: boolean;
  count: number;
  value_total: number;
}

interface StageRow {
  value: string;
  label: string;
  count: number;
  total_value: number;
  weighted_value: number;
  is_open: boolean;
}

interface SavedView {
  key: string;
  label: string;
  hint: string;
}

interface Option {
  value: string;
  label: string;
  position?: number;
  is_terminal?: boolean;
}

interface CustomView {
  id: number;
  name: string;
  filters: Record<string, string>;
}

interface DealsProps {
  view: string;
  /** The active queue, or `all` when a named view is driving the cut. */
  queue: string;
  queues: QueueTab[];
  savedViews: SavedView[];
  customViews: CustomView[];
  filters: Record<string, string | undefined>;
  counts: Record<string, number>;
  stageBreakdown: StageRow[];
  deals: {
    data: DealRow[];
    meta: {
      current_page: number;
      last_page: number;
      total: number;
      from: number | null;
      to: number | null;
      links: { url: string | null; label: string; active: boolean }[];
    };
  };
  assignableUsers: { id: number; name: string }[];
  assignableTeams: { id: number; name: string }[];
  leadSources: Record<string, string>;
  specialties: { value: string; label: string; category: string | null }[];
  intakeSpecialties: { value: string; label: string; requires_more: boolean }[];
  stages: Option[];
  priorities: Option[];
  lostReasons: LostReasonOption[];
  can: { bulk_assign: boolean; export: boolean; triage: boolean };
}

const AREA = {
  people: '#2563EB',
} as const;

const money = (n: number) => inr(n, { compact: true });

/** High-value threshold for the attention cut, in rupees of ARR. */
const HIGH_VALUE_FLOOR = 100000;

/**
 * The four one-click cuts, behind one control.
 *
 * These were four loose toggle chips sitting beside the filter bar — a second
 * filter pattern competing with the bar's own. They are alternatives in
 * practice (nobody asks for stalled AND unowned AND overdue), so they collapse
 * into a single select that the bar renders like every other filter.
 */
const ATTENTION: { value: string; label: string; key: string; on: unknown }[] = [
  { value: 'stalled_only', label: 'Stalled', key: 'stalled_only', on: '1' },
  { value: 'unassigned_only', label: 'No owner', key: 'unassigned_only', on: '1' },
  { value: 'overdue_only', label: 'Overdue follow-up', key: 'overdue_only', on: '1' },
  { value: 'value_min', label: 'High value', key: 'value_min', on: HIGH_VALUE_FLOOR },
];

// ── Cells ──────────────────────────────────────────────────────────────

/**
 * Amber for slowing, red for stalled — two facts, never merged.
 *
 * `is_stalled` is the definition the Stale view lists (fourteen days by
 * config). `needs_attention` is four days in one stage: earlier, softer, and a
 * different question. One badge doing both would have the list calling a deal
 * stalled that the Stale view does not contain.
 */
/**
 * Stage and health, one line each.
 *
 * The pill this replaced only appeared when a deal needed attention, so a
 * healthy row's stage column carried no time-in-stage figure at all — the one
 * piece of context every row has and most rows never showed. This is always
 * present, and the dot in front of it is the "SLA at a glance" the pill used
 * to convey with a colour and nothing else: green on track, amber sitting a
 * while, red stalled or overdue.
 */
function StageHealthCell({ row }: { row: DealRow }) {
  const critical = row.is_stalled || row.is_overdue;

  const dotClass = critical
    ? 'bg-ovs-crm-accent-coral'
    : row.needs_attention
      ? 'bg-ovs-crm-accent-yellow'
      : 'bg-ovs-crm-accent-green';

  const label = row.is_stalled
    ? `Stalled · ${row.stage_age_days ?? 0}d`
    : row.is_overdue
      ? 'Follow-up overdue'
      : row.stage_age_days !== null
        ? `${row.stage_age_days}d in stage`
        : 'Just entered';

  return (
    <div className="flex min-w-0 flex-col items-start gap-1">
      <CrmStageBadge stage={row.status} label={row.status_label} />
      <span className="inline-flex min-w-0 items-center gap-1.5 text-[11px] text-ovs-crm-text-muted">
        <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', dotClass)} aria-hidden="true" />
        <span className="truncate">{label}</span>
      </span>
    </div>
  );
}

/**
 * The next chronological commitment — the column that used to end in a dash.
 *
 * Three real cases, in the order they take priority: a demo already booked, an
 * active trial running its clock, a callback somebody promised. A row with none
 * of the three states that plainly and offers the one action that would give it
 * one, which is the same fix `DomainCell` makes for a footprint nobody has
 * recorded yet — a prompt, never a dash.
 */
function CadenceCell({ row }: { row: DealRow }) {
  const demo = row.demo;

  if (demo && demo.status === 'scheduled') {
    return (
      <div className="min-w-0 space-y-1">
        <p className="flex items-center gap-1 truncate text-[12px] font-medium text-ovs-crm-text-primary">
          <Video className="h-3 w-3 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
          <span className="truncate">{demo.relative_label ?? 'Demo booked'}</span>
        </p>

        {demo.meeting_link ? (
          <a
            href={demo.meeting_link}
            target="_blank"
            rel="noreferrer"
            onClick={(e) => e.stopPropagation()}
            className="inline-flex max-w-full items-center gap-1 truncate rounded-full bg-ovs-crm-channel-demo-soft px-2 py-0.5 text-[11px] font-medium text-ovs-crm-channel-demo-text transition hover:opacity-80"
          >
            Join {demo.platform_label ?? 'meeting'}
          </a>
        ) : demo.is_field_visit ? (
          <span className="block truncate text-[11px] text-ovs-crm-text-muted">
            {demo.location || 'Site visit'}
          </span>
        ) : (
          <CrmStatusPill tone="warn">No join link</CrmStatusPill>
        )}
      </div>
    );
  }

  const trial = row.trial;

  if (trial && trial.status === 'active') {
    const t = trial.telemetry;

    return (
      <div className="min-w-0 space-y-1">
        <p className="flex items-center gap-1 truncate text-[12px] font-medium text-ovs-crm-text-primary">
          {trial.is_ending_soon ? (
            <AlertTriangle className="h-3 w-3 shrink-0 text-ovs-crm-accent-coral-text" aria-hidden="true" />
          ) : (
            <Target className="h-3 w-3 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
          )}
          <span className="truncate">
            {trial.day_number && trial.total_days
              ? `Day ${trial.day_number} of ${trial.total_days}`
              : trial.days_remaining !== null
                ? `${trial.days_remaining}d left`
                : 'Trial running'}
          </span>
          {trial.is_ending_soon && (
            <span className="shrink-0 text-[10.5px] font-semibold text-ovs-crm-accent-coral-text">
              Expiring soon
            </span>
          )}
        </p>

        <div className="flex min-w-0 flex-wrap items-center gap-1">
          {t === null ? (
            <CrmStatusPill tone="neutral">Not measured</CrmStatusPill>
          ) : t.is_inactive ? (
            <CrmStatusPill tone="bad">Unused</CrmStatusPill>
          ) : t.is_active_today ? (
            <CrmStatusPill tone="good">Active today</CrmStatusPill>
          ) : (
            <CrmStatusPill tone="warn">Quiet</CrmStatusPill>
          )}
          {/* Counts only where they were measured. Zeros would be a claim. */}
          {t && (
            <span className="truncate text-[11px] text-ovs-crm-text-muted">
              {t.patients} pt · {t.prescriptions} Rx · {t.invoices} bills
            </span>
          )}
        </div>
      </div>
    );
  }

  if (row.next_followup_at) {
    return (
      <div className="min-w-0 space-y-0.5">
        <p className="flex items-center gap-1 truncate text-[12px] font-medium text-ovs-crm-text-primary">
          <Phone className="h-3 w-3 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
          <span className="truncate">
            {row.next_followup_relative_label ?? row.next_followup_label}
          </span>
        </p>
        {row.followup_notes_snippet && (
          <p className="truncate text-[11px] text-ovs-crm-text-muted">{row.followup_notes_snippet}</p>
        )}
      </div>
    );
  }

  /*
   * Nothing booked is a fact worth stating, and a route worth offering.
   *
   * This column printed a bare em-dash down the whole Inflow queue — the one
   * queue where every row is a deal nobody has scheduled anything for, which
   * makes the dash both the least informative and the most repeated thing on
   * the page. Saying so and offering the booking is the same width.
   */
  return (
    <span className="inline-flex min-w-0 flex-col items-start gap-0.5">
      <span className="text-[12px] text-ovs-crm-text-muted">Nothing scheduled</span>
      <Link
        href={row.url}
        onClick={(event) => event.stopPropagation()}
        className="inline-flex items-center gap-1 text-[11px] font-medium text-ovs-crm-brand-text hover:underline"
      >
        <CalendarPlus className="h-3 w-3" aria-hidden="true" />
        Book a demo
      </Link>
    </span>
  );
}

/**
 * What they practise, what kind of place it is, and how big — the column
 * `[+ Add Setup]` exists for.
 *
 * `facility` is null for most rows today: every lead created since the fast
 * intake form shipped has exactly one bootstrapped branch and no recorded
 * archetype, because intake deliberately does not ask. This cell is the row's
 * half of the fix the Clinical Discovery form supplies the other half of — a
 * prompt to go capture it, not a dash pretending nothing is missing.
 */
function PracticeCell({ row }: { row: DealRow }) {
  const f = row.facility;

  const capacity = f
    ? ([
        f.doctors !== null ? [f.doctors, 'Doctor'] : null,
        f.beds !== null ? [f.beds, 'Bed'] : null,
        f.stations !== null ? [f.stations, 'Chair'] : null,
        f.theatres !== null ? [f.theatres, 'OT'] : null,
      ].filter((x): x is [number, string] => x !== null))
    : [];

  const capacityLabel = capacity
    .map(([count, noun]) => `${count} ${noun}${count === 1 ? '' : 's'}`)
    .join(' · ');

  return (
    <div className="min-w-0 max-w-[190px] space-y-1">
      {row.specialties.length > 0 && (
        <div className="flex min-w-0 flex-wrap items-center gap-1">
          {row.specialties.slice(0, 2).map((s) => (
            <CrmBadge key={s.key} tone={specialtyTone(s.key)}>
              {s.name}
            </CrmBadge>
          ))}
          {row.specialties.length > 2 && (
            <span className="text-[10.5px] text-ovs-crm-text-muted">+{row.specialties.length - 2}</span>
          )}
        </div>
      )}

      {f && (
        <p className="flex min-w-0 items-center gap-1 truncate text-[11.5px] text-ovs-crm-text-secondary">
          <Building2 className="h-3 w-3 shrink-0" aria-hidden="true" />
          <span className="truncate">
            {f.label} · {f.site_count} {f.site_count === 1 ? 'site' : 'sites'}
          </span>
        </p>
      )}

      {capacity.length > 0 ? (
        <p className="truncate text-[11px] text-ovs-crm-text-muted">{capacityLabel}</p>
      ) : (
        <Link
          href={`${row.url}?open=discovery`}
          onClick={(event) => event.stopPropagation()}
          className="inline-flex items-center gap-1 text-[11px] font-medium text-ovs-crm-brand-text hover:underline"
        >
          <Plus className="h-3 w-3 shrink-0" aria-hidden="true" />
          Add setup
        </Link>
      )}
    </div>
  );
}
/**
 * The digits WhatsApp wants.
 *
 * `wa.me` takes an international number and nothing else — no spaces, no plus,
 * no dashes. An Indian ten-digit number is prefixed with 91, because that is
 * what every number in this book is; anything already carrying a country code
 * is left alone.
 */
function waNumber(phone: string | null): string | null {
  if (! phone) return null;

  const digits = phone.replace(/\D/g, '');

  if (digits.length === 10) return `91${digits}`;

  return digits.length >= 11 ? digits : null;
}

/**
 * What this row's owner would actually do next.
 *
 * The row used to carry one button, "Mark lost", for every deal in every
 * queue — the single least likely thing a rep wants from a list of live
 * pipeline, given top billing on all of it. The three things they do instead
 * are: message the doctor, act on the evaluation in flight, and call. Losing a
 * deal is an administrative act and lives in the overflow with the other ones.
 */
function RowActions({
  row,
  isArchive,
  onTriage,
  onWinback,
  onReassign,
}: {
  row: DealRow;
  isArchive: boolean;
  onTriage: () => void;
  onWinback: () => void;
  onReassign: () => void;
}) {
  const wa = waNumber(row.phone);
  const demo = row.demo;
  const inTrial = row.trial?.status === 'active';

  return (
    <span className="inline-flex items-center gap-1">
      {wa && (
        <CrmButton
          size="sm"
          icon={MessageCircle}
          aria-label={`WhatsApp ${row.contact_name ?? row.company_name}`}
          title="WhatsApp"
          href={`https://wa.me/${wa}`}
          external
          target="_blank"
          className="w-8 px-0 !border-ovs-crm-stage-emerald-border !bg-ovs-crm-stage-emerald-soft !text-ovs-crm-stage-emerald-text !shadow-none transition-colors hover:!border-transparent hover:!bg-ovs-crm-stage-emerald hover:!text-white"
        />
      )}

      {/* Whatever is in flight, or the way to start something. */}
      {demo && demo.status === 'scheduled' && demo.meeting_link ? (
        <CrmButton
          size="sm"
          tone="primary"
          icon={Video}
          href={demo.meeting_link}
          external
          target="_blank"
        >
          Join
        </CrmButton>
      ) : inTrial ? (
        <CrmButton size="sm" icon={Target} href={row.url}>
          Trial 360
        </CrmButton>
      ) : row.phone ? (
        <CrmButton
          size="sm"
          icon={Phone}
          aria-label={`Call ${row.contact_name ?? row.company_name}`}
          title="Call"
          href={`tel:${row.phone.replace(/\s/g, '')}`}
          external
          className="w-8 px-0 !border-ovs-crm-stage-blue-border !bg-ovs-crm-stage-blue-soft !text-ovs-crm-stage-blue-text !shadow-none transition-colors hover:!border-transparent hover:!bg-ovs-crm-stage-blue hover:!text-white"
        />
      ) : (
        <CrmButton size="sm" icon={CalendarPlus} href={row.url}>
          Book demo
        </CrmButton>
      )}

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button
            type="button"
            aria-label="More actions"
            className="inline-flex h-8 w-8 items-center justify-center rounded-full border border-ovs-crm-border text-ovs-crm-text-secondary transition hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary"
          >
            <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-48">
          <DropdownMenuItem onSelect={() => router.visit(row.url)}>
            <ExternalLink className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
            Open the deal
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => router.visit(`${row.url}/profile`)}>
            <Users className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
            Edit details
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={onReassign}>
            <UserCog className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
            Reassign owner
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          {isArchive ? (
            <DropdownMenuItem onSelect={onWinback}>
              <ArrowRight className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
              Schedule a win-back
            </DropdownMenuItem>
          ) : (
            <DropdownMenuItem onSelect={onTriage} className="text-ovs-crm-accent-coral-text">
              <AlertTriangle className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
              Mark as lost
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </span>
  );
}

// ── The page ───────────────────────────────────────────────────────────

export default function DealsIndex({
  view,
  queue,
  queues,
  savedViews,
  customViews,
  filters,
  counts,
  stageBreakdown,
  deals,
  assignableUsers,
  assignableTeams,
  leadSources,
  specialties,
  intakeSpecialties,
  stages,
  priorities,
  lostReasons,
  can,
}: DealsProps) {
  const [search, setSearch] = React.useState(filters.search ?? '');
  const [selected, setSelected] = React.useState<React.Key[]>([]);
  const [savingView, setSavingView] = React.useState(false);
  const [viewName, setViewName] = React.useState('');

  /*
   * Loss triage, opened from the row it belongs to. One piece of state holds
   * both the deal and which of the two forms is open, so a triage and a
   * win-back can never both be up over the same deal.
   */
  const [reassignTarget, setReassignTarget] = React.useState<DealRow | null>(null);

  const [lostAction, setLostAction] = React.useState<
    { deal: TriageTarget; kind: 'triage' | 'winback' } | null
  >(null);

  const [quickCreateOpen, setQuickCreateOpen] = React.useState(false);
  const [importOpen, setImportOpen] = React.useState(false);
  const [toast, setToast] = React.useState<string | null>(null);
  const { confirm, dialog } = useCrmConfirm();

  /*
   * Read from the flash session key every controller already writes to on a
   * successful save, rather than a callback threaded up from the modal.
   * `leads.store` redirects through the retired `leads.index` alias before
   * landing back here, and each hop is a fresh Inertia visit — a callback
   * closed over the component instance that made the request has no
   * guarantee that instance is still the one mounted when the response
   * lands. Reading the prop on whatever instance ends up mounted has no such
   * gap.
   */
  const flashSuccess = (usePage().props as { flash?: { success?: string | null } }).flash?.success;

  React.useEffect(() => {
    if (!flashSuccess) return;

    setToast(flashSuccess);
    const id = setTimeout(() => setToast(null), 4000);

    return () => clearTimeout(id);
  }, [flashSuccess]);

  const go = (next: Record<string, unknown>) =>
    router.get(
      route('company.crm.deals.index'),
      Object.fromEntries(
        Object.entries({ queue, view, ...filters, ...next }).filter(
          ([, value]) => value !== undefined && value !== null && value !== '' && value !== 'all'
        )
      ),
      { preserveState: true, preserveScroll: true, replace: true }
    );

  /* Debounced search, so typing does not fire a request per keystroke. */
  React.useEffect(() => {
    if ((filters.search ?? '') === search) return;

    /*
     * A search widens to the whole book. Looking for a doctor by name and
     * being told there is no such deal, because they are in Closing and you
     * were standing in Inflow, is the page lying to you.
     */
    const id = setTimeout(
      () => go({ search: search || undefined, queue: search ? 'all' : undefined, page: undefined }),
      320
    );

    return () => clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  const openTotals = React.useMemo(() => {
    const open = stageBreakdown.filter((row) => row.is_open);

    return {
      count: open.reduce((sum, r) => sum + r.count, 0),
      value: open.reduce((sum, r) => sum + r.total_value, 0),
      weighted: open.reduce((sum, r) => sum + r.weighted_value, 0),
    };
  }, [stageBreakdown]);

  const isFiltered = Object.entries(filters).some(
    ([key, value]) => key !== 'sort' && key !== 'direction' && Boolean(value)
  );

  const clearAll = () => {
    setSearch('');
    router.get(route('company.crm.deals.index'), { queue }, { preserveScroll: true });
  };

  /** The attention cut currently applied, as one value. */
  const attention = ATTENTION.find((a) => Boolean(filters[a.key]))?.value ?? 'all';

  const filterDefs: CrmFilterDef[] = [
    {
      key: 'view',
      label: 'View',
      value: view,
      emptyValue: 'all',
      options: savedViews.map((v) => ({ value: v.key, label: v.label })),
      /* Behind More: the queue tabs are the navigation, this is the power cut. */
      advanced: true,
    },
    {
      key: 'specialty',
      label: 'Specialty',
      value: filters.specialty ?? 'all',
      options: [
        { value: 'all', label: 'Any specialty' },
        ...specialties.map((s) => ({ value: s.value, label: s.label })),
      ],
    },
    {
      key: 'assigned_user_id',
      label: 'Owner',
      value: filters.assigned_user_id ?? 'all',
      options: [
        { value: 'all', label: 'Anyone' },
        { value: 'unassigned', label: 'Unassigned' },
        ...assignableUsers.map((u) => ({ value: String(u.id), label: u.name })),
      ],
    },
    {
      key: 'stage',
      label: 'Exact stage',
      value: filters.stage ?? 'all',
      options: [
        { value: 'all', label: 'Every stage in this queue' },
        ...stages.map((s) => ({ value: s.value, label: s.label })),
      ],
      advanced: true,
    },
    {
      key: 'assigned_team_id',
      label: 'Team',
      value: filters.assigned_team_id ?? 'all',
      options: [
        { value: 'all', label: 'Every team' },
        ...assignableTeams.map((t) => ({ value: String(t.id), label: t.name })),
      ],
      advanced: true,
    },
    {
      key: 'source',
      label: 'Source',
      value: filters.source ?? 'all',
      options: [
        { value: 'all', label: 'Every source' },
        ...Object.entries(leadSources).map(([value, label]) => ({ value, label })),
      ],
      advanced: true,
    },
    {
      key: 'priority',
      label: 'Priority',
      value: filters.priority ?? 'all',
      options: [
        { value: 'all', label: 'Any priority' },
        ...priorities.map((p) => ({ value: p.value, label: p.label })),
      ],
      advanced: true,
    },
    {
      key: 'attention',
      label: 'Needs attention',
      value: attention,
      options: [
        { value: 'all', label: 'Any deal' },
        ...ATTENTION.map((a) => ({ value: a.value, label: a.label })),
      ],
      advanced: true,
    },
    {
      key: 'trial',
      label: 'Trial',
      value: filters.trial ?? 'all',
      options: [
        { value: 'all', label: 'Any trial state' },
        { value: 'active', label: 'Trial running' },
        { value: 'none', label: 'No trial' },
      ],
      advanced: true,
    },
    {
      key: 'quotation',
      label: 'Quotation',
      value: filters.quotation ?? 'all',
      options: [
        { value: 'all', label: 'Any quotation state' },
        { value: 'approved', label: 'Approved' },
        { value: 'none', label: 'None raised' },
      ],
      advanced: true,
    },
  ];

  const onFilterChange = (key: string, value: string) => {
    /* A named view is a cut of the whole book, so it clears the queue with it. */
    if (key === 'view') {
      return go({ view: value === 'all' ? undefined : value, page: undefined });
    }

    /*
     * The attention select is four different filters behind one control, so
     * changing it clears whichever of them was set before applying the next.
     */
    if (key === 'attention') {
      const cleared = Object.fromEntries(ATTENTION.map((a) => [a.key, undefined]));
      const picked = ATTENTION.find((a) => a.value === value);

      return go({ ...cleared, ...(picked ? { [picked.key]: picked.on } : {}), page: undefined });
    }

    return go({ [key]: value === 'all' ? undefined : value, page: undefined });
  };

  /* The filters the bar cannot express as a select still earn the same chip. */
  const chips: CrmFilterChip[] = [];

  if (filters.city) {
    chips.push({
      key: 'city',
      group: 'City',
      label: filters.city,
      onRemove: () => go({ city: undefined, page: undefined }),
    });
  }

  const columns: CrmColumn<DealRow>[] = [
    /*
     * Col 1 — Doctor & Facility identity.
     *
     * Three lines, deliberately: who to ask for, where they practise, how to
     * reach them. The owner avatar rides in the top-right of the name line —
     * folded in rather than kept as its own column, since a table earns a
     * horizontal scroll one column at a time and who owns a deal is still one
     * glance away.
     *
     * `max-w` here is load-bearing rather than decorative. A table cell is
     * sized by its content's minimum, so `truncate` alone does nothing — it
     * needs a width to truncate against, or a long clinic name silently widens
     * the whole table.
     */
    {
      key: 'deal',
      header: 'Deal',
      primary: true,
      cell: (row) => (
        <div className="min-w-0 max-w-[190px] space-y-0.5">
          <div className="flex min-w-0 items-start justify-between gap-1.5">
            <p className="min-w-0 truncate text-[13.5px] font-semibold text-ovs-crm-text-primary group-hover:text-ovs-crm-brand-text">
              {row.contact_name || row.company_name}
            </p>
            {row.assigned_user ? (
              <span title={row.assigned_user.name} className="shrink-0">
                <CrmAvatar name={row.assigned_user.name} size="xs" />
              </span>
            ) : (
              <span
                title="Unowned"
                className="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-ovs-crm-accent-yellow"
                aria-label="Unowned"
              />
            )}
          </div>

          {row.contact_name && (
            <p className="truncate text-[12px] text-ovs-crm-text-secondary">{row.company_name}</p>
          )}

          {(row.city || row.phone) && (
            <p className="flex min-w-0 items-center gap-1 truncate text-[11px] text-ovs-crm-text-muted">
              <MapPin className="h-2.5 w-2.5 shrink-0" aria-hidden="true" />
              <span className="truncate">{[row.city, row.phone].filter(Boolean).join(' · ')}</span>
            </p>
          )}
        </div>
      ),
    },

    /* Col 2 — practice, specialty and clinical footprint. See `PracticeCell`. */
    {
      key: 'practice',
      header: 'Practice',
      mobileLabel: 'Practice',
      width: '186px',
      cell: (row) => <PracticeCell row={row} />,
    },

    /* Col 3 — stage and health. See `StageHealthCell`. */
    {
      key: 'stage',
      header: 'Stage',
      mobileLabel: 'Stage',
      width: '118px',
      cell: (row) => <StageHealthCell row={row} />,
    },

    /* Col 4 — the next chronological commitment. See `CadenceCell`. */
    {
      key: 'cadence',
      header: 'Next up',
      mobileLabel: 'Next up',
      width: '186px',
      cell: (row) => <CadenceCell row={row} />,
    },

    /*
     * Col 5 — commercials: ARR, the cycle it bills on, and win confidence as a
     * pill rather than plain grey text sitting under the money like a
     * discount.
     */
    {
      key: 'commercials',
      header: 'Commercials',
      mobileLabel: 'Commercials',
      align: 'right',
      width: '104px',
      cell: (row) => (
        <div className="min-w-0 whitespace-nowrap text-right">
          <p className="text-[13px] font-medium tabular-nums text-ovs-crm-text-primary">
            {row.expected_arr > 0 ? money(row.expected_arr) : 'Not quoted'}
          </p>

          {row.billing_period_label && (
            <p className="truncate text-[10.5px] text-ovs-crm-text-muted">{row.billing_period_label}</p>
          )}

          {row.expected_arr > 0 && (
            <span className="mt-1 inline-block">
              <CrmStatusPill tone={row.probability >= 70 ? 'good' : row.probability >= 40 ? 'info' : 'neutral'}>
                {row.probability}% win
              </CrmStatusPill>
            </span>
          )}
        </div>
      ),
    },
  ];

  /*
   * The archive asks different questions of a deal — why, to whom, when, and
   * is there a way back — so it swaps in the columns that answer them rather
   * than showing an evaluation and a probability that no longer mean anything.
   */
  const byKey = (key: string) => columns.find((c) => c.key === key)!;

  const lostColumns: CrmColumn<DealRow>[] = [
    byKey('deal'),
    byKey('practice'),
    {
      key: 'reason',
      header: 'Why',
      mobileLabel: 'Why',
      cell: (row) => (
        <div className="min-w-0">
          <p className="truncate text-[12.5px] text-ovs-crm-text-secondary">
            {row.lost_reason ? row.lost_reason.replace(/_/g, ' ') : 'Not recorded'}
          </p>
          {row.lost_competitor && (
            <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
              Lost to {row.lost_competitor}
            </p>
          )}
        </div>
      ),
    },
    {
      key: 'lost_at',
      header: 'Closed',
      mobileLabel: 'Closed',
      width: '120px',
      cell: (row) => (
        <span className="whitespace-nowrap text-[12px] text-ovs-crm-text-muted">
          {row.lost_at ?? 'Not recorded'}
        </span>
      ),
    },
    {
      key: 'winback',
      header: 'Win-back',
      mobileLabel: 'Win-back',
      width: '140px',
      cell: (row) =>
        row.winback_scheduled_at ? (
          <CrmStatusPill tone="info">{row.winback_scheduled_at}</CrmStatusPill>
        ) : (
          <span className="text-[12px] text-ovs-crm-text-muted">Not scheduled</span>
        ),
    },
  ];

  const isArchive = view === 'lost' || queue === 'archive';

  return (
    <CrmShell title="Deals">
      {/*
        * One overflow guard for the page. Every cell inside truncates or wraps;
        * this is the backstop that turns a mistake into a clipped word rather
        * than a page that scrolls sideways.
        */}
      <div className="min-w-0 overflow-x-hidden">
        <CrmPageHead
          className="mb-4 gap-y-3 sm:mb-5 sm:gap-y-5"
          eyebrow="Acquisition"
          title="Deals"
          stats={
            openTotals.count > 0 ? (
              <span className="flex items-baseline gap-4">
                <span>
                  <span className="block text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                    Open pipeline
                  </span>
                  <span className="text-[17px] font-medium tabular-nums text-ovs-crm-text-primary">
                    {money(openTotals.value)}
                  </span>
                </span>
                <span>
                  <span className="block text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                    Weighted
                  </span>
                  <span className="text-[17px] font-medium tabular-nums text-ovs-crm-text-primary">
                    {money(openTotals.weighted)}
                  </span>
                </span>
              </span>
            ) : undefined
          }
          actions={
            <div className="flex flex-wrap items-center gap-2">
              {can.export && (
                <CrmButton icon={Download} href={route('company.crm.leads.export')}>
                  Export
                </CrmButton>
              )}
              <CrmButton icon={Upload} onClick={() => setImportOpen(true)}>
                Import
              </CrmButton>
              <CrmButton tone="primary" icon={Plus} onClick={() => setQuickCreateOpen(true)}>
                New deal
              </CrmButton>
            </div>
          }
        />

        <div className="space-y-3 pb-16">
          {/*
            * The queues, the search and every filter in one bar — the same
            * control Teams, Renewals, Invoices and Accounts filter with. The
            * segment strip stands exactly where the twelve-lane board did.
            */}
          <CrmFilterBar
            segments={queues.map((q) => ({ key: q.key, label: q.label, count: q.count }))}
            segmentValue={queue}
            onSegmentChange={(key) =>
              /*
               * Picking a queue drops any named view — the two are alternative
               * cuts of the same book, and the server refuses to apply both.
               */
              go({ queue: key, view: undefined, page: undefined })
            }
            searchValue={search}
            onSearchChange={setSearch}
            searchPlaceholder="Doctor, practice, phone or email"
            inlineSegments
            filters={filterDefs}
            onFilterChange={onFilterChange}
            chips={chips}
            onClearAll={isFiltered ? clearAll : undefined}
            actions={
              /*
                * The one quick cut that earns a permanent control. Stalled is
                * what a manager opens this page to find; the other three
                * attention cuts sit in the dropdown beside it.
                */
              <button
                type="button"
                onClick={() =>
                  go({
                    ...Object.fromEntries(ATTENTION.map((a) => [a.key, undefined])),
                    stalled_only: filters.stalled_only ? undefined : '1',
                    page: undefined,
                  })
                }
                aria-pressed={Boolean(filters.stalled_only)}
                className={cn(
                  'crm-lift inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full px-3.5 text-[13px] font-medium transition-colors',
                  filters.stalled_only
                    ? 'bg-ovs-crm-ink text-white'
                    : 'bg-white text-ovs-crm-text-secondary shadow-ovs-crmPanel hover:text-ovs-crm-text-primary'
                )}
              >
                <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />
                Stalled
              </button>
            }
          />

          {/*
            * A person's own named cuts.
            *
            * The built-in views moved into the toolbar's "View" dropdown. They
            * were a second tab strip under the queue strip under the filter
            * bar — three stacked rows of controls above a table, each of them
            * a different way of narrowing the same list, and between them they
            * took a quarter of the screen before a single deal appeared.
            */}
          <div className="flex min-w-0 flex-wrap items-center gap-2 empty:hidden">
            {customViews.map((custom) => (
              <span key={custom.id} className="inline-flex shrink-0 items-center gap-1">
                <button
                  type="button"
                  onClick={() =>
                    router.get(route('company.crm.deals.index'), { queue: 'all', ...custom.filters })
                  }
                  className="rounded-full px-2.5 py-1 text-[12px] font-medium text-ovs-crm-text-muted transition hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary"
                >
                  {custom.name}
                </button>
                <button
                  type="button"
                  aria-label={`Delete the ${custom.name} view`}
                  onClick={() =>
                    confirm({
                      title: `Delete "${custom.name}"?`,
                      description: 'This saved cut of the pipeline will be gone for good.',
                      confirmLabel: 'Delete view',
                      destructive: true,
                      onConfirm: () =>
                        router.delete(route('company.crm.deals.views.destroy', custom.id), {
                          preserveScroll: true,
                        }),
                    })
                  }
                  className="text-ovs-crm-text-muted transition hover:text-ovs-crm-accent-coral"
                >
                  <X className="h-3 w-3" aria-hidden="true" />
                </button>
              </span>
            ))}

            {isFiltered && !savingView && (
              <CrmButton size="sm" icon={Plus} onClick={() => setSavingView(true)}>
                Save this cut
              </CrmButton>
            )}

            {savingView && (
              <span className="inline-flex min-w-0 flex-wrap items-center gap-1.5">
                <CrmInput
                  value={viewName}
                  onChange={(e) => setViewName(e.target.value)}
                  placeholder="Name this view"
                  className="h-8 w-[150px]"
                />
                <CrmButton
                  size="sm"
                  tone="primary"
                  icon={Check}
                  onClick={() =>
                    router.post(
                      route('company.crm.deals.views.store'),
                      { name: viewName, filters },
                      {
                        preserveScroll: true,
                        onSuccess: () => {
                          setSavingView(false);
                          setViewName('');
                        },
                      }
                    )
                  }
                >
                  Save
                </CrmButton>
                <CrmButton
                  size="sm"
                  icon={X}
                  onClick={() => {
                    setSavingView(false);
                    setViewName('');
                  }}
                >
                  Cancel
                </CrmButton>
              </span>
            )}
          </div>

          {/* The list itself, and a row opens that deal's page. */}
          <CrmTable<DealRow>
            columns={isArchive ? lostColumns : columns}
            rows={deals.data}
            rowKey={(row) => row.id}
            onRowClick={(row) => router.visit(row.url)}
            selectable={can.bulk_assign}
            selectedKeys={selected}
            onSelectionChange={setSelected}
            isFiltered={isFiltered}
            empty={{
              icon: Target,
              title: 'This queue is clear',
              description: 'Nothing is waiting here. Try another queue.',
            }}
            emptyFiltered={{
              title: 'Nothing matches this cut',
              description: 'Widen the filters, or switch queue.',
              action: (
                <CrmButton icon={X} onClick={clearAll}>
                  Clear filters
                </CrmButton>
              ),
            }}
            variant="ruled"
            rowActions={(row) => (
              <RowActions
                row={row}
                isArchive={isArchive}
                onTriage={() => setLostAction({ deal: row, kind: 'triage' })}
                onWinback={() => setLostAction({ deal: row, kind: 'winback' })}
                onReassign={() => setReassignTarget(row)}
              />
            )}
            footer={
              deals.meta.last_page > 1 ? (
                <CrmPagination
                  links={deals.meta.links}
                  from={deals.meta.from}
                  to={deals.meta.to}
                  total={deals.meta.total}
                />
              ) : undefined
            }
          />
        </div>

        {selected.length > 0 && (
          <div className="fixed inset-x-0 bottom-0 z-40 mx-auto max-w-[1480px] px-3 pb-3">
            <BulkBar
              count={selected.length}
              ids={selected.map(Number)}
              users={assignableUsers}
              stages={stages}
              onClear={() => setSelected([])}
              onDone={() => setSelected([])}
            />
          </div>
        )}

        <ReassignModal
          deal={reassignTarget}
          users={assignableUsers}
          onClose={() => setReassignTarget(null)}
        />

        <TriageModal
          deal={lostAction?.kind === 'triage' ? lostAction.deal : null}
          reasons={lostReasons}
          onClose={() => setLostAction(null)}
        />
        <WinbackModal
          deal={lostAction?.kind === 'winback' ? lostAction.deal : null}
          onClose={() => setLostAction(null)}
        />

        <QuickCreateLeadModal
          open={quickCreateOpen}
          onClose={() => setQuickCreateOpen(false)}
          specialties={intakeSpecialties}
        />

        <LeadImportModal open={importOpen} onClose={() => setImportOpen(false)} />

        {dialog}

        {toast && (
          <div className="fixed bottom-6 right-6 z-[60]" role="status">
            <div className="flex items-center gap-2.5 rounded-full bg-ovs-crm-ink px-4 py-2.5 text-[12.5px] font-medium text-white shadow-ovs-crmLift">
              <Check className="h-3.5 w-3.5 shrink-0 text-ovs-crm-stage-emerald" aria-hidden="true" />
              {toast}
            </div>
          </div>
        )}
      </div>
    </CrmShell>
  );
}

/**
 * Act on several deals at once.
 *
 * Reassignment, a stage move, a task and a loss are the four things worth doing
 * to twenty deals at a time, and none of them is reachable one row at a time.
 */
/**
 * Reassign a deal from the pipeline row, without opening it.
 *
 * The same endpoint and shape Deal 360's own Actions menu uses — a rep who
 * reassigns from the list and a rep who reassigns from the deal page get the
 * identical handover, written to the same activity trail.
 */
function ReassignModal({
  deal,
  users,
  onClose,
}: {
  deal: DealRow | null;
  users: { id: number; name: string }[];
  onClose: () => void;
}) {
  const form = useForm({ assigned_user_id: '' });

  React.useEffect(() => {
    if (deal) {
      form.setData('assigned_user_id', deal.assigned_user ? String(deal.assigned_user.id) : '');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deal?.id]);

  return (
    <CrmModal
      open={deal !== null}
      onClose={onClose}
      title={deal ? `Reassign ${deal.contact_name ?? deal.company_name}` : 'Reassign'}
      description="The new owner is told, and the handover is written to the deal's history."
      footer={
        <>
          <CrmButton onClick={onClose}>Cancel</CrmButton>
          <CrmButton
            tone="primary"
            loading={form.processing}
            disabled={!form.data.assigned_user_id || !deal}
            onClick={() => {
              if (! deal) return;

              form.post(route('company.crm.leads.assign', deal.id), {
                preserveScroll: true,
                onSuccess: onClose,
              });
            }}
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
function BulkBar({
  count,
  ids,
  users,
  stages,
  onClear,
  onDone,
}: {
  count: number;
  ids: number[];
  users: { id: number; name: string }[];
  stages: Option[];
  onClear: () => void;
  onDone: () => void;
}) {
  const [panel, setPanel] = React.useState<'owner' | 'stage' | 'task' | 'lost' | null>(null);
  const [owner, setOwner] = React.useState('');
  const [stage, setStage] = React.useState('');
  const [reason, setReason] = React.useState('');
  const [title, setTitle] = React.useState('');
  const [dueAt, setDueAt] = React.useState('');
  const [busy, setBusy] = React.useState(false);

  const finish = () => {
    setBusy(false);
    setPanel(null);
    setOwner('');
    setStage('');
    setReason('');
    setTitle('');
    setDueAt('');
    onDone();
  };

  const post = (url: string, data: Record<string, unknown>) => {
    setBusy(true);
    router.post(url, { lead_ids: ids, ...data }, { preserveScroll: true, onFinish: finish });
  };

  return (
    <CrmCard step={0} className="border border-ovs-crm-line" frostHue={AREA.people}>
      <div className="flex min-w-0 flex-wrap items-center gap-3">
        <span className="flex items-center gap-2 text-[13px] font-medium text-ovs-crm-text-primary">
          <Check className="h-4 w-4" style={{ color: AREA.people }} aria-hidden="true" />
          {count} selected
        </span>

        <span className="flex flex-wrap items-center gap-2">
          <CrmButton size="sm" icon={Users} onClick={() => setPanel(panel === 'owner' ? null : 'owner')}>
            Assign owner
          </CrmButton>
          <CrmButton size="sm" icon={ArrowRight} onClick={() => setPanel(panel === 'stage' ? null : 'stage')}>
            Change stage
          </CrmButton>
          <CrmButton size="sm" icon={Clock} onClick={() => setPanel(panel === 'task' ? null : 'task')}>
            Add task
          </CrmButton>
          <CrmButton size="sm" icon={X} onClick={() => setPanel(panel === 'lost' ? null : 'lost')}>
            Mark lost
          </CrmButton>
        </span>

        <button
          type="button"
          onClick={onClear}
          className="ml-auto text-[12px] text-ovs-crm-text-muted underline underline-offset-2 hover:text-ovs-crm-text-primary"
        >
          Clear selection
        </button>
      </div>

      {panel && (
        <div className="mt-4 flex min-w-0 flex-wrap items-end gap-3 border-t border-ovs-crm-line pt-4">
          {panel === 'owner' && (
            <>
              <Field label="Give them to">
                <CrmSelect value={owner} onChange={(e) => setOwner(e.target.value)} className="min-w-[180px]">
                  <option value="">Pick a person</option>
                  {users.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.name}
                    </option>
                  ))}
                </CrmSelect>
              </Field>
              <CrmButton
                tone="primary"
                disabled={!owner || busy}
                onClick={() => post(route('company.crm.leads.batch-reassign'), { assigned_user_id: owner })}
              >
                Assign {count}
              </CrmButton>
            </>
          )}

          {panel === 'stage' && (
            <>
              <Field label="Move to">
                <CrmSelect value={stage} onChange={(e) => setStage(e.target.value)} className="min-w-[180px]">
                  <option value="">Pick a stage</option>
                  {stages
                    .filter((s) => s.value !== 'lost')
                    .map((s) => (
                      <option key={s.value} value={s.value}>
                        {s.label}
                      </option>
                    ))}
                </CrmSelect>
              </Field>
              <CrmButton
                tone="primary"
                disabled={!stage || busy}
                onClick={() => post(route('company.crm.leads.batch-stage'), { status: stage })}
              >
                Move {count}
              </CrmButton>
              <p className="w-full text-[11.5px] text-ovs-crm-text-muted">
                Deals missing what a stage requires — a quotation, a demo, a trial — are skipped and counted.
              </p>
            </>
          )}

          {panel === 'task' && (
            <>
              <Field label="Task">
                <CrmInput
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="What needs doing"
                  className="min-w-[200px]"
                />
              </Field>
              <Field label="Due">
                <CrmInput type="date" value={dueAt} onChange={(e) => setDueAt(e.target.value)} />
              </Field>
              <CrmButton
                tone="primary"
                disabled={!title.trim() || busy}
                onClick={() => post(route('company.crm.leads.batch-task'), { title, due_at: dueAt || null })}
              >
                Add to {count}
              </CrmButton>
            </>
          )}

          {panel === 'lost' && (
            <>
              <Field label="Why they were lost">
                <CrmInput
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="Required — it is what makes the loss useful later"
                  className="min-w-[240px]"
                />
              </Field>
              <CrmButton
                tone="primary"
                disabled={!reason.trim() || busy}
                onClick={() => post(route('company.crm.leads.batch-stage'), { status: 'lost', reason })}
              >
                Mark {count} lost
              </CrmButton>
            </>
          )}
        </div>
      )}
    </CrmCard>
  );
}

/** A label above a control, at the size the rest of the page uses. */
function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block min-w-0">
      <span className="mb-1 block text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
        {label}
      </span>
      {children}
    </label>
  );
}
