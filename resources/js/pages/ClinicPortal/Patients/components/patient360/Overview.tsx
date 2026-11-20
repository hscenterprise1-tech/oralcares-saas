import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { Activity, ArrowRight, CalendarDays, CheckCircle2, ChevronDown, ClipboardList, Download, Eye, FileText, FlaskConical, FolderOpen, HeartHandshake, IdCard, Pill, Receipt, Scissors, Stethoscope, Wallet } from 'lucide-react';
import { cn } from '@/lib/utils';
import { DsButton, TONE } from '@/clinic/ds';
import { ConfirmSessionDialog, PhysioCareList, type PhysioCare } from '@/clinic/patients/PhysioCare';
import { Card } from '@/clinic/ui/Card';
import { Skeleton } from '@/clinic/ui/Spinner';
import { formatMoney, humanize } from '@/clinic/ui/visuals';
import { shortDate } from '../format';
import type { ActiveCareItem, Patient360Context, Patient360Document, Patient360Overview, Patient360Profile, Patient360State } from './types';

/*
 * The Overview (`docs/design-review/patient-360/product-ux-audit.md`):
 *
 * 1. Treatments · Coming up · Billing — what is under way, what is next,
 *    what is owed. One card each; no second "summary" of the same thing.
 * 2. Recent clinical activity · Diagnoses and medicines (clinical roles).
 * 3. Patient information · Documents and reports.
 *
 * The header carries medical conditions, safety alerts and notes. Every card
 * is the server's data for this member: a card the member may not see is not
 * drawn, one with nothing recorded says so in a line, and a link appears only
 * where a built page or section opens.
 */

type Tone = 'teal' | 'blue' | 'violet' | 'amber' | 'green' | 'rose';

const OVERVIEW_TONES: Record<
  Tone,
  {
    iconBg: string;
    tagBg: string;
    tagText: string;
    tagBorder: string;
  }
> = {
  teal: {
    iconBg: 'bg-gradient-to-br from-[#179B9B] to-[#0E6C79] text-white shadow-sm',
    tagBg: 'bg-[#F0FDFA]',
    tagText: 'text-[#0F766E]',
    tagBorder: 'border-[#99F6E4]',
  },
  blue: {
    iconBg: 'bg-gradient-to-br from-[#1E78C8] to-[#125898] text-white shadow-sm',
    tagBg: 'bg-[#EFF6FF]',
    tagText: 'text-[#1E40AF]',
    tagBorder: 'border-[#BFDBFE]',
  },
  violet: {
    iconBg: 'bg-gradient-to-br from-[#3B5BDB] to-[#2B44A8] text-white shadow-sm',
    tagBg: 'bg-[#FAF5FF]',
    tagText: 'text-[#6D28D9]',
    tagBorder: 'border-[#DDD6FE]',
  },
  amber: {
    iconBg: 'bg-gradient-to-br from-[#E08A1E] to-[#B4620E] text-white shadow-sm',
    tagBg: 'bg-[#FEFCE8]',
    tagText: 'text-[#B45309]',
    tagBorder: 'border-[#FDE68A]',
  },
  green: {
    iconBg: 'bg-gradient-to-br from-[#16A34A] to-[#15803D] text-white shadow-sm',
    tagBg: 'bg-[#F0FDF4]',
    tagText: 'text-[#166534]',
    tagBorder: 'border-[#BBF7D0]',
  },
  rose: {
    iconBg: 'bg-gradient-to-br from-[#DC3545] to-[#A81F2E] text-white shadow-sm',
    tagBg: 'bg-[#FFF1F2]',
    tagText: 'text-[#9F1239]',
    tagBorder: 'border-[#FECDD3]',
  },
};

/** Small tinted tiles and tags matching the pastel palettes. */
const TILE: Record<Tone, string> = {
  teal: 'bg-[#F0FDFA] text-[#0F766E] border border-[#99F6E4]',
  blue: 'bg-[#EFF6FF] text-[#1E40AF] border border-[#BFDBFE]',
  violet: 'bg-[#FAF5FF] text-[#6D28D9] border border-[#DDD6FE]',
  amber: 'bg-[#FEFCE8] text-[#B45309] border border-[#FDE68A]',
  green: 'bg-[#F0FDF4] text-[#166534] border border-[#BBF7D0]',
  rose: 'bg-[#FFF1F2] text-[#9F1239] border border-[#FECDD3]',
};

/** In a two-column row, an odd card at the end spans the row instead of leaving half of it empty. */
const ODD_LAST_SPANS = 'md:[&>*:last-child:nth-child(odd)]:col-span-2';

export function Overview({
  overview,
  state,
  context,
  profile,
  onOpenSection,
  visitsBuilt,
  billsUrl,
  openable,
}: {
  overview: Patient360Overview;
  state: Patient360State;
  context: Patient360Context;
  profile: Patient360Profile;
  onOpenSection: (key: string) => void;
  visitsBuilt: boolean;
  billsUrl: string | null;
  /** Sections this member may open here (built and allowed): only these are linked. */
  openable: string[];
}) {
  const { appointments, finance } = overview.summary;
  // Coming up needs appointment access, or at least a follow-up to show (a member who may not see appointments is never told "none booked").
  const comingUp = appointments !== null || overview.next.follow_up !== null;
  const firstRow = 1 + (comingUp ? 1 : 0) + (finance ? 1 : 0);

  return (
    <div className="grid gap-4 sm:gap-5">
      {/* 1 · Under way, next, owed — two per row until there is room for three. */}
      <div className={cn('grid gap-4 sm:gap-5', firstRow > 1 && 'md:grid-cols-2', ODD_LAST_SPANS, firstRow === 3 && 'xl:grid-cols-3 xl:[&>*:last-child:nth-child(odd)]:col-span-1')}>
        <TreatmentsCard care={overview.current.active_care} open={overview.current.open_consultation} />
        {comingUp && <ComingUpCard next={overview.next} counts={appointments} clinical={context.clinical} onOpenVisits={visitsBuilt ? () => onOpenSection('visits') : null} onOpenFollowUps={openable.includes('follow-ups') ? () => onOpenSection('follow-ups') : null} />}
        {finance && <BillingCard finance={finance} outstanding={state.outstanding ?? finance.outstanding} bills={overview.next.payments} billsUrl={billsUrl} />}
      </div>

      {/* Physiotherapy at a glance, in physiotherapy clinics, when there is a treatment or a session to confirm. */}
      {overview.physio_care && (overview.physio_care.treatments.length > 0 || overview.physio_care.confirm) && <PhysioCareCard care={overview.physio_care} />}

      {/* 2 · The clinical record, for clinical roles; side by side only where the page (beside the sidebar) has room. */}
      {context.clinical && (
        <div className="grid gap-4 sm:gap-5 xl:grid-cols-[minmax(0,1.38fr)_minmax(0,1fr)]">
          <RecentActivity recent={overview.recent} />
          <DiagnosesAndMedicines context={context} />
        </div>
      )}

      {/* 3 · Details and files. */}
      <div className={cn('grid gap-4 sm:gap-5', overview.documents !== null && 'xl:grid-cols-2')}>
        <PatientInformation profile={profile} />
        {overview.documents !== null && <Documents documents={overview.documents} onViewAll={openable.includes('documents') ? () => onOpenSection('documents') : null} />}
      </div>
    </div>
  );
}

/** The Overview's own shape while it loads (it is sent after the header). */
export function OverviewSkeleton() {
  return (
    <div role="status" aria-label="Loading the overview" className="grid gap-4">
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {[0, 1, 2].map((card) => (
          <div key={card} className="space-y-3 rounded-2xl border border-cx-line bg-white p-4">
            <div className="flex items-center gap-2.5">
              <Skeleton className="h-9 w-9 rounded-[11px]" />
              <Skeleton className="h-4 w-28" />
            </div>
            <Skeleton className="h-3.5 w-3/4" />
            <Skeleton className="h-3.5 w-1/2" />
          </div>
        ))}
      </div>
      <Card as="div" className="space-y-3 p-5">
        <Skeleton className="h-4 w-48" />
        <Skeleton className="h-3.5 w-3/4" />
        <Skeleton className="h-3.5 w-2/3" />
      </Card>
    </div>
  );
}

/**
 * Physiotherapy: open treatments, sessions done, package balances and what
 * is owed (read-only), and — for the desk, when the patient is in today's
 * queue here — confirming that today's session took place.
 */
function PhysioCareCard({ care }: { care: PhysioCare }) {
  const [confirming, setConfirming] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const canConfirm = care.confirm !== null && care.treatments.length > 0;

  return (
    <WhiteCard
      title="Physiotherapy"
      icon={HeartHandshake}
      tone="teal"
      aside={
        canConfirm ? (
          <DsButton size="sm" variant="secondary" icon={CheckCircle2} onClick={() => setConfirming(true)}>
            Confirm session
          </DsButton>
        ) : undefined
      }
    >
      {done && (
        <p role="status" className="mb-3 rounded-xl bg-[#E9F7EF] px-3 py-2 text-[13px] font-medium text-[#166534]">
          {done}
        </p>
      )}
      {care.treatments.length === 0 && care.confirm ? (
        <Nothing>No physiotherapy treatment under way. The therapist starts one before a session can be confirmed.</Nothing>
      ) : (
        <PhysioCareList care={care} />
      )}
      {canConfirm && (
        <ConfirmSessionDialog
          care={care}
          open={confirming}
          onOpenChange={setConfirming}
          onDone={(message) => {
            setDone(message);
            router.reload({ only: ['overview', 'state'] });
          }}
        />
      )}
    </WhiteCard>
  );
}

/* ------------------------------------------------------------ building blocks */

/**
 * An elevated section card matching Edit.tsx SectionCard aesthetic:
 * rounded-2xl, border-t tone accent, gradient icon box, crisp title,
 * and optional bottom-pinned actions.
 */
function WhiteCard({ title, icon: Icon, tone, aside, footer, children }: { title: string; icon: typeof Activity; tone: Tone; aside?: ReactNode; footer?: ReactNode; children: ReactNode }) {
  const id = `overview-${title.toLowerCase().replace(/\W+/g, '-')}`;
  const styles = OVERVIEW_TONES[tone];

  return (
    <section
      aria-labelledby={id}
      className="flex h-full min-w-0 flex-col rounded-2xl border border-cx-line/80 bg-white p-5 shadow-cx-card"
    >
      <div className="flex items-center justify-between gap-3">
        <h2 id={id} className="flex min-w-0 items-center gap-2.5 text-[16px] font-semibold leading-6 text-[#173B60]">
          <span className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-[10px] shadow-sm', styles.iconBg)} aria-hidden="true">
            <Icon className="h-4 w-4" strokeWidth={1.75} />
          </span>
          {title}
        </h2>
        {aside}
      </div>
      <div className="mt-3.5 min-w-0 flex-1">{children}</div>
      {footer && <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-cx-line/80 pt-3">{footer}</div>}
    </section>
  );
}

function Nothing({ children }: { children: ReactNode }) {
  return <p className="text-[13.5px] leading-5 text-slate-500">{children}</p>;
}

function Tag({ tone, children }: { tone: Tone; children: ReactNode }) {
  const styles = OVERVIEW_TONES[tone];

  return (
    <span
      className={cn(
        'inline-flex h-6 shrink-0 items-center whitespace-nowrap rounded-full px-2.5 text-[11.5px] font-bold border',
        styles.tagBg,
        styles.tagText,
        styles.tagBorder,
      )}
    >
      {children}
    </span>
  );
}

/** A small heading inside a card ("Next appointment", "Follow-up"). */
function Label({ children }: { children: ReactNode }) {
  return <p className="text-[12px] font-bold uppercase tracking-wider text-slate-500">{children}</p>;
}

const linkClass =
  'inline-flex min-h-8 items-center gap-1.5 rounded-lg text-[13px] font-bold text-blue-600 hover:text-blue-800 hover:underline transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500';

function CardLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className={linkClass}>
      {children} <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
    </Link>
  );
}

function CardButton({ onClick, children }: { onClick: () => void; children: ReactNode }) {
  return (
    <button type="button" onClick={onClick} className={linkClass}>
      {children} <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
    </button>
  );
}

/** "Show all N …" / "Show fewer" under a list cut to its preview. */
function ShowAll({ open, total, noun, onToggle }: { open: boolean; total: number; noun: string; onToggle: () => void }) {
  return (
    <button type="button" onClick={onToggle} aria-expanded={open} className={cn(linkClass, 'mt-1')}>
      {open ? 'Show fewer' : `Show all ${total} ${noun}`}
      <ChevronDown className={cn('h-3.5 w-3.5 transition-transform', open && 'rotate-180')} aria-hidden="true" />
    </button>
  );
}

/* ------------------------------------------------------------ row 1 */

const TREATMENTS_SHOWN = 3;

/**
 * What is under way: the open consultation and the active treatments. A
 * progress bar only where a treatment counts sessions or stages (more than
 * one); otherwise its status — a single-step item is not "0% done".
 */
function TreatmentsCard({ care, open }: { care: ActiveCareItem[]; open: Patient360Overview['current']['open_consultation'] }) {
  return (
    <WhiteCard title="Treatments" icon={Activity} tone="teal" aside={care.length > 0 ? <Tag tone="teal">{care.length} active</Tag> : undefined}>
      {open && (
        <p className="mb-2.5 flex items-start gap-1.5 rounded-[8px] bg-cx-m-teal-bg/70 px-2.5 py-1.5 text-[12.5px] font-semibold leading-[18px] text-cx-blue-ink">
          <Stethoscope className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          <span className="min-w-0">
            Consultation <span className="whitespace-nowrap">{open.encounter_number ?? ''}</span> in progress
          </span>
        </p>
      )}
      {care.length === 0 ? (
        !open && <Nothing>No treatment under way.</Nothing>
      ) : (
        <ul className="space-y-2.5">
          {care.slice(0, TREATMENTS_SHOWN).map((item) => {
            const counted = item.progress.total !== null && item.progress.total > 1;
            const percent = counted ? Math.min(100, Math.round((item.progress.done / (item.progress.total as number)) * 100)) : null;

            return (
              <li key={item.id}>
                <p className="text-[14px] font-semibold leading-5 text-cx-text [overflow-wrap:anywhere]">{item.title}</p>
                <p className="text-[12.5px] leading-[18px] text-cx-text-2">
                  {[counted ? `${item.progress.done} of ${item.progress.total} ${item.progress.unit}` : humanize(item.status), item.started_at ? (item.status === 'planned' ? `since ${shortDate(item.started_at)}` : `started ${shortDate(item.started_at)}`) : null, item.practitioner?.name].filter(Boolean).join(' · ')}
                </p>
                {percent !== null && (
                  <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-cx-slate-bg" role="progressbar" aria-valuenow={item.progress.done} aria-valuemin={0} aria-valuemax={item.progress.total ?? undefined} aria-label={`${item.title}: ${item.progress.done} of ${item.progress.total} ${item.progress.unit}`}>
                    <div className="h-full rounded-full bg-gradient-to-r from-[#14B8A6] to-[#2B86E8]" style={{ width: `${percent}%` }} />
                  </div>
                )}
              </li>
            );
          })}
          {care.length > TREATMENTS_SHOWN && <li className="text-[12.5px] text-cx-text-2">and {care.length - TREATMENTS_SHOWN} more under way</li>}
        </ul>
      )}
    </WhiteCard>
  );
}

/**
 * What comes next: the booked appointment and, separately, a follow-up task
 * (a follow-up is a reminder to book, not an appointment). Counts and the
 * links to the visit history and the Appointments page.
 */
function ComingUpCard({
  next,
  counts,
  clinical,
  onOpenVisits,
  onOpenFollowUps,
}: {
  next: Patient360Overview['next'];
  counts: Patient360Overview['summary']['appointments'];
  clinical: boolean;
  onOpenVisits: (() => void) | null;
  onOpenFollowUps: (() => void) | null;
}) {
  const { appointment, follow_up: followUp, investigations } = next;
  const canOpenAppointments = counts !== null;

  return (
    <WhiteCard
      title="Coming up"
      icon={CalendarDays}
      tone="green"
      aside={appointment?.is_today ? <Tag tone="teal">Today</Tag> : undefined}
      footer={
        (onOpenVisits || canOpenAppointments || (followUp && onOpenFollowUps)) && (
          <>
            {onOpenVisits && <CardButton onClick={onOpenVisits}>Visits &amp; history</CardButton>}
            {canOpenAppointments && <CardLink href={route('clinic.appointments.index')}>Appointments</CardLink>}
            {followUp && onOpenFollowUps && <CardButton onClick={onOpenFollowUps}>Follow-ups</CardButton>}
          </>
        )
      }
    >
      <div className="space-y-2.5">
        {canOpenAppointments && (
        <div>
          <Label>Next appointment</Label>
          {appointment ? (
            <>
              <p className="text-[14px] font-semibold leading-5 text-cx-text">
                {appointment.date_label} · {appointment.time_label}
              </p>
              {(appointment.practitioner || appointment.branch) && <p className="text-[12.5px] leading-[18px] text-cx-text-2">{[appointment.practitioner, appointment.branch].filter(Boolean).join(' · ')}</p>}
            </>
          ) : (
            <p className="text-[13.5px] leading-5 text-cx-text-2">None booked</p>
          )}
        </div>
        )}
        {followUp && (
          <div>
            <Label>Follow-up task</Label>
            <p className="flex flex-wrap items-center gap-x-2 text-[13.5px] font-semibold leading-5 text-cx-text">
              Due {shortDate(followUp.due_on)}
              {followUp.overdue && <Tag tone="amber">Overdue</Tag>}
            </p>
            {followUp.reason && <p className="line-clamp-2 text-[12.5px] leading-[18px] text-cx-text-2 [overflow-wrap:anywhere]" title={followUp.reason}>{followUp.reason}</p>}
          </div>
        )}
        {clinical && investigations.length > 0 && (
          <p className="text-[12.5px] leading-[18px] text-cx-text-2">
            {investigations.length} {investigations.length === 1 ? 'investigation' : 'investigations'} awaited
          </p>
        )}
        {counts && (
          <p className="flex flex-wrap gap-1.5">
            <Tag tone="blue">{counts.upcoming} upcoming</Tag>
            <Tag tone="teal">
              {counts.attended} {counts.attended === 1 ? 'visit' : 'visits'}
            </Tag>
            {counts.missed > 0 && <Tag tone="amber">{counts.missed} missed</Tag>}
          </p>
        )}
      </div>
    </WhiteCard>
  );
}

/** What is owed, with the totals it comes from and the bills still open (cancelled bills excluded on the server). */
function BillingCard({ finance, outstanding, bills, billsUrl }: { finance: NonNullable<Patient360Overview['summary']['finance']>; outstanding: number; bills: Patient360Overview['next']['payments']; billsUrl: string | null }) {
  const owed = outstanding > 0;

  return (
    <WhiteCard title="Billing" icon={owed ? Wallet : Receipt} tone={owed ? 'amber' : 'green'} footer={billsUrl ? <CardLink href={billsUrl}>View bills</CardLink> : undefined}>
      <Label>Outstanding</Label>
      <p className={cn('whitespace-nowrap text-[22px] font-bold leading-8 tabular-nums tracking-tight', owed ? 'text-cx-text' : 'text-cx-green-ink')}>{formatMoney(outstanding)}</p>
      <dl className="mt-2 space-y-1 text-[13px]">
        <div className="flex items-center justify-between gap-3">
          <dt className="text-cx-text-2">Total billed</dt>
          <dd className="font-semibold tabular-nums text-cx-text">{formatMoney(finance.billed)}</dd>
        </div>
        <div className="flex items-center justify-between gap-3">
          <dt className="text-cx-text-2">Total paid</dt>
          <dd className="font-semibold tabular-nums text-cx-green-ink">{formatMoney(finance.paid)}</dd>
        </div>
      </dl>
      <p className="mt-2 text-[12.5px] leading-[18px] text-cx-text-2 [overflow-wrap:anywhere]">
        {bills.length > 0 ? `Unpaid: ${bills.map((bill) => bill.number ?? `Bill ${bill.id}`).join(', ')}` : finance.bills === 0 ? 'No bills yet.' : 'All bills paid.'}
      </p>
    </WhiteCard>
  );
}

/* ------------------------------------------------------------ row 2 */

type ActivityItem = { key: string; date: string | null; icon: typeof Activity; tone: Tone; title: string; detail: string | null; who: string | null; aside?: ReactNode };

/**
 * The latest of each kind of clinical record as a dated timeline, newest
 * first: date, a dot on the connecting line, the record's icon, what it was;
 * status and clinician follow the title on its own wrapping line.
 */
function RecentActivity({ recent }: { recent: Patient360Overview['recent'] }) {
  const { consultation, prescription, investigation, procedure } = recent;
  const items = (
    [
      consultation && {
        key: 'consultation',
        date: consultation.date,
        icon: FileText,
        tone: 'violet',
        title: `Consultation${consultation.number ? ` ${consultation.number}` : ''}`,
        detail: [consultation.complaint, consultation.diagnosis ? `Diagnosis: ${consultation.diagnosis}` : null].filter(Boolean).join(' · ') || null,
        who: consultation.practitioner,
        aside: <Tag tone={consultation.signed ? 'green' : 'amber'}>{consultation.signed ? 'Signed' : 'Not signed'}</Tag>,
      },
      prescription && {
        key: 'prescription',
        date: prescription.date,
        icon: Pill,
        tone: 'blue',
        title: `Prescription${prescription.number ? ` ${prescription.number}` : ''}`,
        detail: prescription.medicines.join(', ') || null,
        who: prescription.practitioner,
        aside: prescription.print_url ? (
          <a href={prescription.print_url} target="_blank" rel="noopener" className="inline-flex h-6 items-center gap-1 rounded-full px-2 text-[12.5px] font-semibold text-cx-blue-ink hover:bg-cx-m-blue-bg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan">
            <FileText className="h-3.5 w-3.5" aria-hidden="true" />
            Print
          </a>
        ) : undefined,
      },
      investigation && {
        key: 'investigation',
        date: investigation.date,
        icon: FlaskConical,
        tone: investigation.is_abnormal ? 'rose' : 'teal',
        title: investigation.name,
        detail: investigation.summary,
        who: null,
        aside: investigation.is_abnormal ? <Tag tone="rose">Abnormal</Tag> : <Tag tone="teal">{humanize(investigation.status)}</Tag>,
      },
      procedure && {
        key: 'procedure',
        date: procedure.date,
        icon: Scissors,
        tone: 'green',
        title: procedure.name,
        detail: procedure.tooth ? `Tooth ${procedure.tooth}` : null,
        who: null,
      },
    ].filter(Boolean) as ActivityItem[]
  ).sort((a, b) => (b.date ?? '').localeCompare(a.date ?? ''));

  return (
    <WhiteCard title="Recent clinical activity" icon={ClipboardList} tone="violet">
      {items.length === 0 ? (
        <Nothing>No consultations recorded yet.</Nothing>
      ) : (
        <ol className="relative">
          {items.map((item, index) => (
            <li key={item.key} className="relative grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 pb-4 last:pb-0 sm:grid-cols-[100px_16px_auto_minmax(0,1fr)]">
              <span className="col-span-2 mb-1.5 justify-self-start whitespace-nowrap rounded-[8px] bg-cx-slate-bg px-2 py-0.5 text-[12px] font-semibold tabular-nums leading-5 text-cx-slate-ink sm:col-span-1 sm:mb-0 sm:self-start sm:justify-self-stretch sm:text-center">
                {shortDate(item.date) ?? 'No date'}
              </span>
              {/* The dot on the line; the line runs to the next item. */}
              <span aria-hidden="true" className="relative hidden justify-center sm:flex">
                <span className="mt-2 h-2 w-2 rounded-full bg-cx-m-purple ring-4 ring-cx-m-purple-bg" />
                {index < items.length - 1 && <span className="absolute bottom-[-16px] top-5 w-0.5 bg-cx-m-purple-ring" />}
              </span>
              <span className={cn('grid h-8 w-8 place-items-center rounded-[10px]', TILE[item.tone])} aria-hidden="true">
                <item.icon className="h-4 w-4" strokeWidth={1.9} />
              </span>
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <p className="min-w-0 text-[14px] font-semibold leading-5 text-cx-text [overflow-wrap:anywhere]">{item.title}</p>
                  {item.aside}
                  {item.who && <span className="inline-flex h-6 max-w-full items-center truncate rounded-full bg-cx-m-blue-bg px-2.5 text-[12px] font-semibold text-cx-blue-ink">{item.who}</span>}
                </div>
                {item.detail && <p className="mt-0.5 line-clamp-3 text-[13px] leading-5 text-cx-text-2 [overflow-wrap:anywhere]" title={item.detail}>{item.detail}</p>}
              </div>
            </li>
          ))}
        </ol>
      )}
    </WhiteCard>
  );
}

/** How many diagnoses, and medicines, show before "Show all". */
const LIST_PREVIEW = 3;

/**
 * What clinicians concluded at visits (`clinical_diagnoses`, the last year)
 * and the medicines the patient is on: prescribed here in the last 30 days,
 * then those the patient reported. The medical history's conditions are in
 * the header, not repeated here.
 */
function DiagnosesAndMedicines({ context }: { context: Patient360Context }) {
  const [allDiagnoses, setAllDiagnoses] = useState(false);
  const [allMedicines, setAllMedicines] = useState(false);
  const diagnosed = context.conditions.diagnosed;
  const medicines = [
    ...context.medications.prescribed.map((medicine) => ({
      key: `p-${medicine.name}`,
      name: medicine.name,
      detail: [[medicine.dosage, medicine.frequency, medicine.duration].filter(Boolean).join(' · ') || 'As prescribed', medicine.prescribed_on ? `prescribed ${shortDate(medicine.prescribed_on)}` : null].filter(Boolean).join(' · '),
      reported: false,
    })),
    ...context.medications.reported.map((medicine) => ({ key: `r-${medicine}`, name: medicine, detail: 'Reported by the patient', reported: true })),
  ];

  return (
    <WhiteCard title="Diagnoses and medicines" icon={Stethoscope} tone="rose">
      <p className="text-[12.5px] font-semibold uppercase tracking-[0.04em] text-cx-slate-ink">Diagnoses</p>
      {diagnosed.length === 0 ? (
        <p className="mt-1 text-[13.5px] text-cx-text-2">No diagnoses in the last year.</p>
      ) : (
        <ul className="mt-1 divide-y divide-cx-line">
          {(allDiagnoses ? diagnosed : diagnosed.slice(0, LIST_PREVIEW)).map((diagnosis) => (
            <li key={`d-${diagnosis.description}`} className="flex items-start justify-between gap-3 py-2">
              <div className="min-w-0">
                <p className="text-[14px] font-semibold leading-5 text-cx-text [overflow-wrap:anywhere]">{diagnosis.description}</p>
                <p className="text-[12.5px] leading-[18px] text-cx-text-2">{[diagnosis.noted_on ? `Noted ${shortDate(diagnosis.noted_on)}` : null, diagnosis.code].filter(Boolean).join(' · ')}</p>
              </div>
              <Tag tone={diagnosis.status === 'provisional' ? 'amber' : 'green'}>{diagnosis.status === 'provisional' ? 'Provisional' : 'Confirmed'}</Tag>
            </li>
          ))}
        </ul>
      )}
      {diagnosed.length > LIST_PREVIEW && <ShowAll open={allDiagnoses} total={diagnosed.length} noun="diagnoses" onToggle={() => setAllDiagnoses((open) => !open)} />}

      <p className="mt-3 border-t border-cx-line pt-3 text-[12.5px] font-semibold uppercase tracking-[0.04em] text-cx-slate-ink">Current medicines</p>
      {medicines.length === 0 ? (
        <p className="mt-1 text-[13.5px] text-cx-text-2">None recorded.</p>
      ) : (
        <ul className="mt-1 divide-y divide-cx-line">
          {(allMedicines ? medicines : medicines.slice(0, LIST_PREVIEW)).map((medicine) => (
            <li key={medicine.key} className="flex items-start gap-2.5 py-2">
              <Pill className={cn('mt-0.5 h-4 w-4 shrink-0', medicine.reported ? 'text-cx-m-blue' : 'text-cx-rose-ink')} aria-hidden="true" />
              <div className="min-w-0">
                <p className="text-[14px] font-semibold leading-5 text-cx-text [overflow-wrap:anywhere]">{medicine.name}</p>
                <p className="text-[12.5px] leading-[18px] text-cx-text-2">{medicine.detail}</p>
              </div>
            </li>
          ))}
        </ul>
      )}
      {medicines.length > LIST_PREVIEW && <ShowAll open={allMedicines} total={medicines.length} noun="medicines" onToggle={() => setAllMedicines((open) => !open)} />}
    </WhiteCard>
  );
}

/* ------------------------------------------------------------ row 3 */

const CHANNEL_LABEL: Record<string, string> = { phone: 'Phone call', sms: 'SMS', whatsapp: 'WhatsApp', email: 'Email' };

function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-0.5 py-2 first:pt-0 last:pb-0 sm:grid-cols-[140px_minmax(0,1fr)] sm:gap-3">
      <dt className="text-[13px] font-medium text-cx-text-2">{label}</dt>
      <dd className="min-w-0 text-[13.5px] text-cx-text [overflow-wrap:anywhere]">{children}</dd>
    </div>
  );
}

/**
 * The patient's details beyond the header (registration is in the header).
 * Addresses and full numbers follow the contact rule: a member without it
 * sees the last digits and a note, never a blank presented as "not recorded".
 */
function PatientInformation({ profile }: { profile: Patient360Profile }) {
  const emergency = profile.emergency_contact;

  return (
    <WhiteCard title="Patient information" icon={IdCard} tone="violet">
      <dl className="divide-y divide-cx-line">
        <InfoRow label="Address">{profile.address ?? <span className="text-cx-text-2">{profile.contact_visible ? 'Not recorded' : 'Shown to staff who contact patients'}</span>}</InfoRow>
        {profile.alternate_phone && <InfoRow label="Alternate mobile">{profile.alternate_phone}</InfoRow>}
        <InfoRow label="Preferred contact">{profile.preferred_channel ? (CHANNEL_LABEL[profile.preferred_channel] ?? profile.preferred_channel) : <span className="text-cx-text-2">No preference</span>}</InfoRow>
        <InfoRow label="Emergency contact">
          {emergency ? (
            <span className="inline-flex flex-wrap items-center gap-x-2">
              <HeartHandshake className="h-4 w-4 text-cx-m-orange" aria-hidden="true" />
              <span className="font-semibold">{emergency.name ?? 'Not named'}</span>
              {(emergency.phone || emergency.phone_last_digits) && <span className="tabular-nums text-cx-text-2">{emergency.phone ?? `•••••• ${emergency.phone_last_digits}`}</span>}
            </span>
          ) : (
            <span className="text-cx-text-2">Not recorded</span>
          )}
        </InfoRow>
      </dl>
    </WhiteCard>
  );
}

/** The latest documents this member may open, from the Documents section's own list; files open only through their authorised route. */
function Documents({ documents, onViewAll }: { documents: Patient360Document[]; onViewAll: (() => void) | null }) {
  return (
    <WhiteCard title="Documents and reports" icon={FolderOpen} tone="green" footer={onViewAll ? <CardButton onClick={onViewAll}>View all documents</CardButton> : undefined}>
      {documents.length === 0 ? (
        <Nothing>No documents yet.</Nothing>
      ) : (
        <ul className="divide-y divide-cx-line">
          {documents.map((document) => (
            <li key={document.id} className="flex items-center gap-3 py-2 first:pt-0 last:pb-0">
              <span className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-[10px] text-[10px] font-bold', TILE.green)} aria-hidden="true">
                {document.file_type || <FileText className="h-4 w-4" />}
              </span>
              <div className="min-w-0 flex-1">
                <p className="line-clamp-3 text-[13.5px] font-semibold leading-5 text-cx-text [overflow-wrap:anywhere] sm:line-clamp-2" title={document.name}>
                  {document.name}
                </p>
                <p className="text-[12.5px] leading-[18px] text-cx-text-2">{[document.category, shortDate(document.date)].filter(Boolean).join(' · ')}</p>
              </div>
              {document.status !== 'clean' ? (
                <Tag tone="amber">{document.status === 'pending' ? 'Being checked' : humanize(document.status)}</Tag>
              ) : (
                <span className="flex shrink-0 gap-1">
                  {document.preview_url && (
                    <a href={document.preview_url} target="_blank" rel="noopener" aria-label={`Open ${document.name}`} className="grid h-9 w-9 place-items-center rounded-[10px] text-cx-text-2 hover:bg-cx-m-blue-bg hover:text-cx-blue-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan">
                      <Eye className="h-4 w-4" aria-hidden="true" />
                    </a>
                  )}
                  {document.download_url && (
                    <a href={document.download_url} aria-label={`Download ${document.name}`} className="grid h-9 w-9 place-items-center rounded-[10px] text-cx-text-2 hover:bg-cx-m-blue-bg hover:text-cx-blue-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan">
                      <Download className="h-4 w-4" aria-hidden="true" />
                    </a>
                  )}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </WhiteCard>
  );
}
