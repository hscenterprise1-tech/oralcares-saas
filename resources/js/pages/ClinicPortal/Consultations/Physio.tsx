import { useMemo, useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
  Activity,
  ArrowLeft,
  CalendarCheck2,
  CalendarDays,
  Check,
  ClipboardList,
  Clock,
  History,
  PackageCheck,
  Pencil,
  Plus,
  Repeat,
  SlidersHorizontal,
  Stethoscope,
  UserRound,
  Wallet,
  type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { CollectDialog } from '@/pages/ClinicPortal/FrontDesk/components/SidePanels';
import { Button } from '@/clinic/ui/Button';
import { Field, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { BTN, BTN_PRIMARY, FOCUS, IconTile, Pill, type Tone } from '../Patients/components/patient360/v2/ui';

type Status = 'performed' | 'partial' | 'not_performed';
type Given = { key: string; label: string; minutes: number; therapist_id: number | null; status?: Status };
/** `price` as the server sends it is the fixed (bundle) price or null; on the page it is what a visit costs, `bundle` saying which. */
type SessionType = { key: string; label: string; treatments: string[]; price: number; bundle: boolean };
type ServerProblem = Omit<Problem, 'types'> & { types: (Omit<SessionType, 'price' | 'bundle'> & { price: number | null })[] };
type PackageOffer = { id: string; name: string; sessions: number; price: number; valid_days: number; types: string[] };
type Problem = { key: string; label: string; sided: boolean; types: SessionType[]; packages: PackageOffer[]; diagnoses: string[]; exercises: string[] };
/** One exercise of the clinic's library. */
type LibraryExercise = { key: string; label: string; category: string | null; instructions: string | null; precautions: string | null; level: string | null; equipment: string | null; sets: number | null; reps: number | null; hold: number | null };
/** One exercise as prescribed (copied from the library). */
type Prescribed = { id: number; name: string; sets: number | null; reps: number | null; hold: number | null; per_day: number | null; instructions: string | null };
type ExerciseStatus = 'performed' | 'partial' | 'skipped';
type Complaint = { duration: string | null; severity: string | null; difficulties: string[]; previous: string[]; description: string | null; remarks: string | null };
type Choice = { key: string; label: string };
type Choices = { durations: Choice[]; severities: Choice[]; difficulties: Choice[]; previous: Choice[]; categories: Choice[]; exercise_categories: Choice[] };
type Course = {
  id: number;
  title: string;
  problem: string | null;
  side: string | null;
  type: string | null;
  status: 'suggested' | 'active';
  frequency: string | null;
  started: string | null;
  done: number;
  planned: number | null;
  known_problem: boolean;
  package: { id: number; name: string; total: number; left: number; valid_until: string | null; types: string[] } | null;
  /** With no package running: the last one, used up or out of date. */
  package_ended: { name: string; total: number; used: number; expired: boolean } | null;
  details: { complaint: Complaint | null; plan: { category: string | null; instructions: string | null } | null; recorded_at: string | null; recorded_by: string | null; revisions: number };
  diagnosis: string | null;
  goals: string | null;
  exercises: Prescribed[];
  /** Where the problem stands, from what was recorded only. */
  progress: {
    initial: { complaint: Complaint; on: string | null } | null;
    now: Complaint | null;
    goals: { text: string; done_on: string | null }[];
    notes: { on: string; text: string; by: string; review: boolean }[];
    review_on: string | null;
    feels: { date: string | null; feel: string | null }[];
    last_session: string | null;
  };
  /** Every package of this problem, newest first. */
  packages: { id: number; name: string; total: number; used: number; left: number; state: string; sold_on: string | null; valid_until: string | null; bill: { number: string; amount: number; paid: number; due: number; print: string } | null }[];
  today: { status: string; type: string | null; given: Given[]; addons: { key: string; label: string; price: number }[]; charge: number | null; feel: string | null; note: string | null } | null;
};
type Treatment = { key: string; label: string; minutes: number; price: number };
type Addon = { key: string; label: string; minutes: number; price: number };
type HistoryRow = {
  id: number;
  date: string | null;
  course_id: number;
  course: string | null;
  type: string | null;
  session: number | null;
  items: { label: string; minutes: number | null; by: string | null; status?: Status }[];
  addons: string[];
  exercises: { name: string; status: ExerciseStatus; sets: number | null; reps: number | null }[];
  by: string[];
  feel: string | null;
  note: string | null;
  package: string | null;
  /** "3 of 10": which session of its package this was. */
  package_use: string | null;
  charged: number | null;
  payment: 'paid' | 'due' | 'not_billed' | null;
};
type Consultation = { complaint: string | null; history: string | null; findings: string | null; advice: string | null; diagnoses: string[] };

/** `ConsultationController::show` for a physiotherapy visit (`PhysioConsultation::payload`): the server is the contract. */
/** One payable thing: this visit's charges not yet billed (`bills`), or a bill with a balance. */
type DeskPaymentItem = { key: string; label: string; detail: string | null; amount: number; url: string; bills: boolean };
type DeskPayment = { currency: string; methods: { value: string; label: string }[]; items: DeskPaymentItem[] };

type Props = {
  encounter: { id: number; number: string; started_at: string | null; doctor: string | null; branch: string | null; case_number: string | null };
  patient: { id: number; name: string; uhid: string | null; age: number | null; gender: string | null; phone: string | null; registered_on: string | null };
  /** Why the patient came today, as the desk recorded it; null outside a checked-in visit. */
  visit: { specialty: string | null; type: string | null; reason: string | null } | null;
  physio: {
    consultation: Consultation;
    courses: Course[];
    problems: ServerProblem[];
    treatments: Treatment[];
    addons: Addon[];
    sides: Record<string, string>;
    frequencies: { key: string; label: string }[];
    choices: Choices;
    /** The clinic's exercise library. */
    exercises: LibraryExercise[];
    follow_ups: { id: number; due_on: string | null; reason: string | null; status: string }[];
    can_sell_package: boolean;
    therapists: { id: number; name: string }[];
    me: number | null;
    history: HistoryRow[];
    stats: { visits: number; missed: number; due: number };
    booking: { duration: number; days: { date: string; times: string[] }[]; upcoming: { date: string; time: string }[] };
    finished: boolean;
    /** What a finished visit still has to bill (0 while it runs). */
    to_bill: number;
  };
  can_edit: boolean;
  /** What the desk collects from this page once the visit is finished (`FrontDesk::visitPayment`); null on the therapist's own page. */
  payment?: DeskPayment | null;
  /** The desk working the visit for a therapist who has no login; the work is in the therapist's name. */
  desk?: { therapist: string } | null;
  links: {
    finish: string;
    patient: string;
    plan: string;
    package: string;
    sheet: string;
    progress: string;
    /** One problem's report, with the course id in place of 0 and the kind last. */
    report: string;
    documents: string;
    follow_up: string | null;
    calendar: string;
    today: string;
    checkout: string | null;
  };
};

/** A problem's reports (`PhysioConsultation::REPORTS`), each printed from its record. */
const REPORTS = [
  { key: 'assessment', label: 'Assessment & diagnosis' },
  { key: 'plan', label: 'Treatment plan' },
  { key: 'session', label: 'Last session' },
  { key: 'progress', label: 'Progress' },
  { key: 'package', label: 'Packages' },
];

const STATUSES: { key: Status; label: string; short: string; bg: string; fg: string }[] = [
  { key: 'performed', label: 'Performed', short: '✓', bg: '#0E9F87', fg: '#FFFFFF' },
  { key: 'partial', label: 'Partly', short: '½', bg: '#E9821A', fg: '#FFFFFF' },
  { key: 'not_performed', label: 'Not done', short: '✕', bg: '#94A3B8', fg: '#FFFFFF' },
];

/** Days between visits, as `PhysioConsultation::FREQUENCIES` books them. */
const STEPS: Record<string, number> = { daily: 1, alternate: 2, thrice_weekly: 2, twice_weekly: 3, weekly: 7 };
const COURSE_TONES: [string, string][] = [
  ['#F1EDFD', '#6B48DE'],
  ['#FDF3E7', '#E9821A'],
  ['#EAF1FD', '#2B6FD8'],
  ['#E6F6F3', '#0E9F87'],
  ['#FDECEC', '#E5484D'],
];
const FEELS = [
  { key: 'better', label: 'Better', color: '#0E9F87' },
  { key: 'same', label: 'Same', color: '#64748B' },
  { key: 'worse', label: 'Worse', color: '#E5484D' },
];
// The portal's one surface (the Owner Dashboard's `SURFACE`): every card on this page reads as on the others.
const CARD = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';

const rupee = (n: number) => `₹${Math.round(n).toLocaleString('en-IN')}`;
/** "Dr. Neha Rao" → "Neha": a title is not a name. */
const shortName = (name: string) => name.replace(/^(dr|mr|mrs|ms|miss)\.?\s+/i, '').split(' ')[0] || name;
const initials = (name: string) => name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('');
const addDays = (iso: string, days: number) => {
  const date = new Date(`${iso}T00:00:00`);
  date.setDate(date.getDate() + days);

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};
/** Today on the clinic's own calendar (the device's), never the UTC date: after midnight in India UTC is still yesterday. */
const localToday = () => {
  const date = new Date();

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};
const dayLabel =(iso: string | null, today: string) => {
  if (!iso) return '—';
  const date = new Date(`${iso}T00:00:00`);
  const diff = Math.round((new Date(`${today}T00:00:00`).getTime() - date.getTime()) / 86_400_000);
  const short = date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });

  return diff === 0 ? `Today · ${short}` : diff === 1 ? `Yesterday · ${short}` : diff === -1 ? `Tomorrow · ${short}` : `${date.toLocaleDateString('en-IN', { weekday: 'short' })} · ${short}`;
};
const time12 = (time: string) => {
  const [h, m] = time.split(':').map(Number);

  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
};
/** A price the owner has not set yet reads as such, not as free. */
const priceLabel = (price: number) => (price > 0 ? rupee(price) : 'price not set');


/** Today's session for one started course, as the therapist leaves it. */
type Row = { done: boolean; type: string | null; given: Record<string, number> | null; status: Record<string, Status>; exercises: Record<number, { status: ExerciseStatus; sets: number | null }>; addons: string[]; therapist: number | null; feel: string | null; note: string; noteOpen: boolean; editing: boolean };

export default function PhysioConsultation(props: Props) {
  return (
    <ClinicPortalShell title={`${props.patient.name} · Physio`}>
      <PhysioPage {...props} />
    </ClinicPortalShell>
  );
}

type Tab = 'today' | 'history' | 'billing';
/** The plan flow: a new problem from the start, a found one started, or a running one's plan changed. */
type Wizard = { mode: 'new' } | { mode: 'start' | 'edit'; course: Course };

/**
 * The physio workspace (2026-11-05 rebuild, the doctor's feedback): Patient
 * 360's header, three tabs across the top. Today is home: every running
 * treatment with its one-tap session, the problems found but not started,
 * "New problem" — one guided flow: the problem from a list, the assessment
 * by tapping, the plan filled from the clinic's setup, then Start — and the
 * bill and the coming visits. History is every session and every problem's
 * assessment; Packages & Billing is the money. Nothing to type unless the
 * doctor wants to.
 */
function PhysioPage({ encounter, patient, visit, physio, can_edit: canEdit, links, desk, payment = null }: Props) {
  // The shell shows the success message; this page shows only what went wrong.
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const today = localToday();
  const readOnly = !canEdit || physio.finished;
  // Everything the patient owes now: what this finished visit still has to bill, and the balance of earlier bills.
  const owed = payment ? Math.round(payment.items.reduce((n, item) => n + item.amount, 0) * 100) / 100 : Math.round((physio.to_bill + physio.stats.due) * 100) / 100;

  const treatments = useMemo(() => new Map(physio.treatments.map((t) => [t.key, t])), [physio.treatments]);
  // A type without a fixed price costs its treatments: shown as their sum, charged as those actually given.
  const problems = useMemo(
    () =>
      new Map<string, Problem>(
        physio.problems.map((p) => [
          p.key,
          { ...p, types: p.types.map((t) => ({ ...t, bundle: t.price !== null, price: t.price ?? t.treatments.reduce((n, k) => n + (treatments.get(k)?.price ?? 0), 0) })) },
        ]),
      ),
    [physio.problems, treatments],
  );
  const addons = useMemo(() => new Map(physio.addons.map((a) => [a.key, a])), [physio.addons]);
  const active = physio.courses.filter((c) => c.status === 'active');
  const suggested = physio.courses.filter((c) => c.status === 'suggested');
  const tone = (id: number) => COURSE_TONES[Math.max(0, physio.courses.findIndex((c) => c.id === id)) % COURSE_TONES.length];
  const typeOf = (c: Course, key: string | null) => problems.get(c.problem ?? '')?.types.find((t) => t.key === key) ?? null;
  const covers = (c: Course, typeKey: string | null) => !!c.package && (c.package.types.length === 0 || (typeKey !== null && c.package.types.includes(typeKey)));
  const therapistName = (id: number | null) => physio.therapists.find((t) => t.id === id)?.name ?? null;
  const label = (list: Choice[], key: string | null | undefined) => list.find((o) => o.key === key)?.label ?? null;

  const [rows, setRows] = useState<Record<number, Row>>({});
  const blank = (c: Course): Row => ({ done: false, type: c.type, given: null, status: {}, exercises: {}, addons: [], therapist: physio.me, feel: null, note: '', noteOpen: false, editing: false });
  const row = (c: Course) => rows[c.id] ?? blank(c);
  // Always from the latest state, so quick taps never undo each other.
  const patch = (c: Course, change: Partial<Row> | ((r: Row) => Partial<Row>)) =>
    setRows((all) => {
      const current = all[c.id] ?? blank(c);

      return { ...all, [c.id]: { ...current, ...(typeof change === 'function' ? change(current) : change) } };
    });

  const [tab, setTab] = useState<Tab>(() => (physio.finished && links.checkout ? 'billing' : 'today'));
  const [wizard, setWizard] = useState<Wizard | null>(null);
  const [history, setHistory] = useState<'all' | number>('all');
  const [busy, setBusy] = useState(false);

  const doneToday = active.filter((c) => row(c).done);
  /** The treatments of today's session for a course: the type's, unless today was different. */
  const givenKeys = (c: Course, r: Row) => (r.given ? Object.keys(r.given) : (typeOf(c, r.type)?.treatments ?? []));
  /**
   * What one course's session costs today (`PhysioCatalogue::visitPrice`): nothing when the package covers
   * the type, else its fixed price or each treatment given (not "not done") — plus add-ons.
   */
  const chargeFor = (c: Course) => {
    const r = row(c);
    const type = typeOf(c, r.type);
    const given = givenKeys(c, r).filter((k) => r.status[k] !== 'not_performed');
    const visitPrice = covers(c, r.type) || given.length === 0 ? 0 : type?.bundle ? type.price : given.reduce((n, k) => n + (treatments.get(k)?.price ?? 0), 0);

    return visitPrice + r.addons.reduce((n, key) => n + (addons.get(key)?.price ?? 0), 0);
  };
  const total = doneToday.reduce((n, c) => n + chargeFor(c), 0);

  // The coming visits: by the first course done today (or the first running), from its frequency and what is left.
  const lead = doneToday[0] ?? active[0] ?? null;
  // Sessions still to come after today's: a running visit counts today's as given, a finished one already holds it.
  const leftBefore = lead ? (lead.planned ? lead.planned - lead.done : lead.package ? lead.package.left : physio.finished ? 0 : 7) : 0;
  const leftAfterToday = Math.max(0, leftBefore - (physio.finished ? 0 : 1));
  const booked = physio.booking.upcoming.length;
  // What is still to book: those sessions less the visits already booked. Never one extra after the last session,
  // and a package bought mid-course (more sessions than visits booked) turns booking on by itself.
  const toBook = Math.max(0, leftAfterToday - booked);
  const [booking, setBooking] = useState(() => {
    const frequency = lead?.frequency ?? 'daily';
    // New visits follow the ones already booked.
    const lastBooked = physio.booking.upcoming[booked - 1]?.date;
    const from = lastBooked && lastBooked > today ? lastBooked : today;
    const first = physio.booking.days.find((d) => d.date >= addDays(from, STEPS[frequency] ?? 1) && d.times.length > 0) ?? physio.booking.days.find((d) => d.times.length > 0);
    const usual = physio.booking.upcoming[0]?.time;

    return { on: toBook > 0, frequency, date: first?.date ?? '', time: first ? (usual && first.times.includes(usual) ? usual : first.times[0]) : '', count: '' };
  });
  const count = Math.max(1, Math.min(30, Number(booking.count) || Math.max(1, Math.min(30, toBook))));
  const dayTimes = physio.booking.days.find((d) => d.date === booking.date)?.times ?? [];
  const plannedDates = useMemo(() => {
    if (!booking.date || !booking.time) return [];
    const taken = new Set(physio.booking.upcoming.map((u) => u.date));
    const step = STEPS[booking.frequency] ?? 1;
    const lastKnown = physio.booking.days[physio.booking.days.length - 1]?.date ?? booking.date;
    const out: string[] = [];
    let next = booking.date;
    for (let guard = 0; out.length < count && guard < 120; guard++) {
      const known = physio.booking.days.find((d) => d.date === next);
      if (next > lastKnown || (known && known.times.includes(booking.time) && !taken.has(next))) {
        out.push(next);
        next = addDays(next, step);
      } else {
        next = addDays(next, 1);
      }
    }

    return out;
  }, [booking.date, booking.time, booking.frequency, count, physio.booking]);
  const booksVisits = booking.on && active.length > 0 && !!booking.date && !!booking.time;
  // The header's sessions fact: the course's progress, or the visits so far; what is due only when something is.
  const sessionsFact = {
    title: lead?.planned ? `${lead.done} of ${lead.planned} done` : `${physio.stats.visits} ${physio.stats.visits === 1 ? 'visit' : 'visits'} done`,
    sub: [lead?.planned ? `${Math.max(0, lead.planned - lead.done)} left` : null, physio.stats.missed > 0 ? `${physio.stats.missed} missed` : null, owed > 0 ? `${rupee(owed)} due` : null].filter(Boolean).join(' · ') || null,
  };

  // A consultation only is enough once a problem is recorded: its assessment says what was found.
  const recorded = physio.courses.some((c) => c.details.recorded_at);
  const canFinish = doneToday.length > 0 || recorded || !!physio.consultation.complaint;
  const post = (url: string, data: Record<string, unknown>, onDone?: () => void) =>
    router.post(url, data as never, { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onDone?.() });

  const finish = () =>
    router.post(
      links.finish,
      {
        courses: doneToday.map((c) => {
          const r = row(c);

          return {
            id: c.id,
            done: true,
            type: r.type !== c.type ? r.type : null,
            // What was actually given, each confirmed: performed, partly, or not done.
            given: givenKeys(c, r).map((key) => ({ key, minutes: r.given?.[key] ?? treatments.get(key)?.minutes ?? 15, status: r.status[key] ?? 'performed' })),
            addons: r.addons,
            // Only the exercises not done as prescribed; the rest were.
            exercises: Object.entries(r.exercises).map(([id, e]) => ({ id: Number(id), status: e.status, sets: e.status === 'partial' ? e.sets : null })),
            therapist_id: r.therapist,
            feel: r.feel,
            note: r.note.trim() || null,
          };
        }),
        booking: booksVisits ? { date: booking.date, time: booking.time, frequency: booking.frequency, count } : null,
      } as never,
      { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
    );

  const next = physio.booking.upcoming[0] ?? null;
  const finishLabel = busy ? 'Saving…' : doneToday.length === 0 ? 'Finish consultation' : `Finish visit · ${rupee(total)}`;
  const shownHistory = physio.history.filter((h) => history === 'all' || h.course_id === history);
  const charged = physio.history.filter((h) => h.charged !== null || h.package);

  /** Today's bill, line by line: package-covered and charged items told apart, then Finish. */
  const thisVisitCard = !readOnly && (
    <section className={cn(CARD, 'p-4 sm:p-5')}>
      <div className="mb-3 flex items-center gap-2.5"><IconTile icon={Wallet} tone="mint" /><h2 className="text-[15px] font-semibold leading-5 text-cx-nav-ink">This visit</h2></div>
      <ul className="space-y-2 text-[13px]">
        {doneToday.map((c) => {
          const r = row(c);
          const type = typeOf(c, r.type);
          const covered = covers(c, r.type);
          const given = givenKeys(c, r).filter((k) => r.status[k] !== 'not_performed');

          return (
            <li key={c.id}>
              <div className="flex justify-between gap-2">
                <span className="min-w-0 font-semibold text-[#1D2E4A]">{c.title}<span className="block text-[12px] font-medium text-cx-text-2">{type?.label}{therapistName(r.therapist) ? ` · ${shortName(therapistName(r.therapist) as string)}` : ''}</span></span>
                <strong className={cn('shrink-0', covered && 'text-cx-teal-ink')}>{covered ? 'Package' : type?.bundle ? rupee(type.price) : ''}</strong>
              </div>
              {!covered && !type?.bundle && given.map((k) => (
                <div key={k} className="flex justify-between gap-2 pl-3 text-cx-text-2"><span>{treatments.get(k)?.label}{r.status[k] === 'partial' ? ' (partly)' : ''}</span><span>{rupee(treatments.get(k)?.price ?? 0)}</span></div>
              ))}
              {r.addons.map((key) => (
                <div key={key} className="flex justify-between gap-2 pl-3 text-cx-amber-ink"><span>+ {addons.get(key)?.label}</span><span>{rupee(addons.get(key)?.price ?? 0)}</span></div>
              ))}
            </li>
          );
        })}
        {doneToday.length === 0 && <li className="text-cx-text-2">{canFinish ? 'No session today: the consultation is saved and signed, nothing charged.' : 'Add the patient’s problem, or tap “Session done”.'}</li>}
        {booksVisits && <li className="flex items-center gap-1.5 font-semibold text-cx-blue-ink"><CalendarCheck2 className="h-4 w-4" aria-hidden="true" /> {plannedDates.length} {plannedDates.length === 1 ? 'visit' : 'visits'} booked at {time12(booking.time)}</li>}
      </ul>
      <div className="mt-3 flex justify-between border-t border-[#EEF2F7] pt-3 text-[15px] font-bold text-[#1D2E4A]">To pay <span>{rupee(total)}</span></div>
      <button type="button" disabled={!canFinish || busy} onClick={finish} className={cn('mt-3.5 h-12 w-full rounded-full bg-[#0E9F87] text-[15px] font-bold text-white disabled:opacity-60', FOCUS)}>
        {finishLabel}
      </button>
      <p className="mt-2 text-center text-[12px] text-cx-text-2">{desk ? 'Then collect the payment on this page.' : links.checkout ? 'Then take the payment here, or leave it for the desk.' : 'The desk takes the payment.'}</p>
    </section>
  );

  const todayTab = (
    <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div className="flex min-w-0 flex-col gap-4">
        {physio.courses.length === 0 && (
          <section className={cn(CARD, 'flex flex-col items-center gap-3 px-6 py-10 text-center')}>
            <IconTile icon={Stethoscope} tone="violet" size="lg" />
            <div>
              <h2 className="text-[18px] font-bold text-[#1D2E4A]">What brings the patient in?</h2>
              <p className="mt-1 text-[13px] text-cx-text-2">Choose the problem, tap what you find, and the plan fills itself from your clinic’s setup.</p>
            </div>
            {!readOnly && (
              <button type="button" onClick={() => setWizard({ mode: 'new' })} className={cn(BTN_PRIMARY, 'h-12 px-6 text-[14px]')}>
                <Plus className="h-4 w-4" aria-hidden="true" /> Add the problem
              </button>
            )}
          </section>
        )}

        {active.map((c) => (
          <SessionCard
            key={c.id}
            course={c}
            tone={tone(c.id)}
            row={row(c)}
            readOnly={readOnly}
            types={problems.get(c.problem ?? '')?.types ?? []}
            physio={physio}
            treatments={treatments}
            covers={(key) => covers(c, key)}
            charge={chargeFor(c)}
            typeOf={(key) => typeOf(c, key)}
            onPatch={(change) => patch(c, change)}
            onChangeType={() => setWizard({ mode: 'edit', course: c })}
            packages={problems.get(c.problem ?? '')?.packages ?? []}
            canSell={physio.can_sell_package}
            busy={busy}
            onBuy={(id) => post(links.package.replace(/\/0\/package$/, `/${c.id}/package`), { package: id })}
            sheetUrl={links.sheet.replace(/\/0\/exercise-sheet$/, `/${c.id}/exercise-sheet`)}
          />
        ))}

        {suggested.map((c) => {
          const [bg, fg] = tone(c.id);
          const complaint = c.details.complaint;
          const facts = [c.diagnosis, label(physio.choices.durations, complaint?.duration), label(physio.choices.severities, complaint?.severity)].filter(Boolean);
          const type = typeOf(c, c.type);

          return (
            <section key={c.id} className="flex flex-wrap items-center gap-3 rounded-[22px] border-2 border-dashed bg-white p-4" style={{ borderColor: `${fg}66` }}>
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-[14px] text-[14px] font-bold" style={{ background: bg, color: fg }}>{initials(c.title)}</span>
              <div className="min-w-0 flex-[1_1_220px]">
                <div className="text-[15px] font-semibold leading-5 text-cx-nav-ink">{c.title}</div>
                <div className="text-[12.5px] font-semibold text-cx-text-2">
                  Not started{facts.length > 0 ? ` · ${facts.join(' · ')}` : ''}{type ? ` · planned ${type.label}${c.planned ? `, ${c.planned} sessions` : ''}` : ''}
                </div>
              </div>
              {!readOnly && c.known_problem && (
                <button type="button" disabled={busy} onClick={() => setWizard({ mode: 'start', course: c })} className={cn('inline-flex h-11 items-center gap-1.5 rounded-full px-5 text-[14px] font-bold text-white disabled:opacity-60', FOCUS)} style={{ background: fg }}>
                  <Activity className="h-4 w-4" aria-hidden="true" /> Start treatment
                </button>
              )}
            </section>
          );
        })}

        {!readOnly && physio.courses.length > 0 && (
          <button type="button" onClick={() => setWizard({ mode: 'new' })} className={cn('flex h-14 items-center justify-center gap-2 rounded-[22px] border-2 border-dashed border-[#C9D3E1] bg-white/60 text-[14px] font-bold text-[#1769AA] hover:bg-white', FOCUS)}>
            <Plus className="h-4 w-4" aria-hidden="true" /> Another problem
          </button>
        )}
      </div>

      <div className="flex min-w-0 flex-col gap-4 md:grid md:grid-cols-2 md:items-stretch xl:flex xl:flex-col">
        {!readOnly && active.length > 0 && (
          <BookingCard booking={booking} setBooking={setBooking} physio={physio} dayTimes={dayTimes} count={count} plannedDates={plannedDates} today={today} calendar={links.calendar} toBook={toBook} />
        )}
        {readOnly && physio.finished && <ComingVisitsCard upcoming={physio.booking.upcoming} followUps={physio.follow_ups} toBook={toBook} calendar={links.calendar} today={today} />}
        {physio.finished && payment && <PaymentCard payment={payment} patientName={patient.name} />}
        {thisVisitCard}
        {links.follow_up && !readOnly && (
          <FollowUpCard
            followUps={physio.follow_ups}
            url={links.follow_up}
            suggestion={active[0] ? `${active[0].title} review` : suggested[0] ? `${suggested[0].title} review` : ''}
            today={today}
            readOnly={readOnly}
          />
        )}
        {readOnly && !physio.finished && (
          <section className={cn(CARD, 'p-5 text-center')}>
            <p className="text-[14px] font-bold text-[#1D2E4A]">You can see this visit but not change it.</p>
          </section>
        )}
      </div>
    </div>
  );

  /** Every problem's assessment and plan, then every session: what was given (and not), by whom, and the money. */
  const historyTab = (
    <div className="flex flex-col gap-4">
      {physio.courses.length > 0 && (
        <Panel
          icon={ClipboardList}
          tone="teal"
          title="Problems & progress"
          detail="What was found, what was planned, and how it is going — only what was recorded."
          aside={<Link href={links.documents} className={cn(BTN, 'h-9 w-full sm:w-auto')}>Upload a document</Link>}
        >
          <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
            {physio.courses.map((c) => {
              const [bg, fg] = tone(c.id);
              const complaint = c.details.complaint;
              const type = typeOf(c, c.type);

              return (
                <div key={c.id} className="rounded-[18px] p-4" style={{ background: bg }}>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-[15px] font-bold text-[#1D2E4A]">{c.title}</span>
                    <Pill tone={c.status === 'active' ? 'mint' : 'amber'}>{c.status === 'active' ? `Active · ${c.done}${c.planned ? `/${c.planned}` : ''}` : 'Not started'}</Pill>
                    <span className="flex-1" />
                    {!readOnly && <button type="button" onClick={() => setWizard({ mode: c.status === 'active' ? 'edit' : 'start', course: c })} className={cn('rounded-full bg-white px-3 py-1 text-[12px] font-bold', FOCUS)} style={{ color: fg }}>Change</button>}
                  </div>
                  <dl className="mt-2 grid grid-cols-12 gap-x-2 gap-y-1 text-[12.5px]">
                    {[
                      ['Diagnosis', c.diagnosis],
                      ['Since', label(physio.choices.durations, complaint?.duration)],
                      ['Pain', label(physio.choices.severities, complaint?.severity)],
                      ['Difficulty', complaint?.difficulties.map((k) => label(physio.choices.difficulties, k)).join(', ') || null],
                      ['Tried', complaint?.previous.map((k) => label(physio.choices.previous, k)).join(', ') || null],
                      ['Plan', type ? `${type.label}${c.frequency ? ` · ${physio.frequencies.find((f) => f.key === c.frequency)?.label}` : ''}` : null],
                      ['Aim', label(physio.choices.categories, c.details.plan?.category)],
                      ['Advice', c.details.plan?.instructions ?? null],
                    ]
                      .filter(([, value]) => value)
                      .map(([name, value]) => (
                        <div key={name} className="col-span-12 grid grid-cols-12 gap-2">
                          <dt className="col-span-4 font-bold text-cx-text-2 sm:col-span-3">{name}</dt>
                          <dd className="col-span-8 font-semibold text-[#1D2E4A] sm:col-span-9">{value}</dd>
                        </div>
                      ))}
                  </dl>
                  {c.details.recorded_at && (
                    <p className="mt-2 text-[11.5px] text-cx-text-2">
                      {c.details.recorded_by} · {new Date(c.details.recorded_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}
                      {c.details.revisions > 0 ? ` · ${c.details.revisions} earlier ${c.details.revisions === 1 ? 'version' : 'versions'} kept` : ''}
                    </p>
                  )}
                  <ProgressBlock
                    course={c}
                    tone={[bg, fg]}
                    choices={physio.choices}
                    readOnly={readOnly}
                    busy={busy}
                    today={today}
                    onPost={(data) => post(links.progress.replace(/\/0\/progress$/, `/${c.id}/progress`), data)}
                  />
                  {/* Reports made from this problem's record: preview, print or download. */}
                  <div className="mt-3 flex flex-wrap items-center gap-1.5">
                    <span className="mr-1 text-[11px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Reports</span>
                    {REPORTS.filter((r) => r.key !== 'session' || physio.history.some((h) => h.course_id === c.id)).map((r) => (
                      <a key={r.key} href={links.report.replace(/\/0\/reports\/assessment$/, `/${c.id}/reports/${r.key}`)} target="_blank" rel="noopener" className={cn('inline-flex h-8 items-center rounded-full bg-white px-3 text-[12px] font-bold hover:opacity-90', FOCUS)} style={{ color: fg }}>
                        {r.label}
                      </a>
                    ))}
                    {c.exercises.length > 0 && (
                      <a href={links.sheet.replace(/\/0\/exercise-sheet$/, `/${c.id}/exercise-sheet`)} target="_blank" rel="noopener" className={cn('inline-flex h-8 items-center rounded-full bg-white px-3 text-[12px] font-bold hover:opacity-90', FOCUS)} style={{ color: fg }}>
                        Exercise sheet
                      </a>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </Panel>
      )}

      <Panel
        icon={History}
        tone="violet"
        title="Every session"
        detail={`${physio.history.length} ${physio.history.length === 1 ? 'session' : 'sessions'} so far`}
        aside={
          physio.courses.length > 1 && (
            <Select aria-label="Show sessions of" value={String(history)} onChange={(e) => setHistory(e.target.value === 'all' ? 'all' : Number(e.target.value))} options={[{ value: 'all', label: 'All problems' }, ...physio.courses.map((c) => ({ value: String(c.id), label: c.title }))]} />
          )
        }
      >
        {shownHistory.length === 0 ? (
          <p className="rounded-2xl bg-[#F7F8FC] px-4 py-6 text-center text-[13px] text-cx-text-2">No completed sessions yet. Each session done appears here with who gave it and the payment.</p>
        ) : (
          <div className="overflow-hidden rounded-[18px] border border-[#EEF2F7]">
            <div className="hidden gap-3 bg-[#F7F8FC] px-4 py-2.5 text-[11.5px] font-bold uppercase tracking-[0.05em] text-cx-text-2 md:grid md:grid-cols-12">
              <span className="md:col-span-2">Date</span>
              <span className="md:col-span-3">Problem · type</span>
              <span className="md:col-span-3">Given</span>
              <span className="md:col-span-2">Given by</span>
              <span className="md:col-span-2 md:text-right">Payment</span>
            </div>
            <ul className="divide-y divide-[#EEF2F7]">
              {shownHistory.map((h) => {
                const [bg, fg] = tone(h.course_id);
                const feel = FEELS.find((f) => f.key === h.feel);

                return (
                  <li key={h.id} className="grid grid-cols-12 gap-x-3 gap-y-1.5 px-4 py-3 text-[13px]">
                    <div className="col-span-6 md:col-span-2">
                      <div className="font-bold text-[#1D2E4A]">{dayLabel(h.date, today)}</div>
                      <div className="text-[12px] text-cx-text-2">Session {h.session}</div>
                    </div>
                    <div className="col-span-6 text-right md:hidden"><Payment row={h} /></div>
                    <div className="col-span-12 md:col-span-3">
                      <span className="inline-block rounded-full px-2.5 py-0.5 text-[12px] font-bold" style={{ background: bg, color: fg }}>{h.course}</span>
                      <div className="mt-0.5 font-semibold text-[#1D2E4A]">
                        {h.type ?? '—'}
                        {feel && <span className="ml-1.5 text-[12px] font-bold" style={{ color: feel.color }}>· {feel.label}</span>}
                      </div>
                    </div>
                    <div className="col-span-12 md:col-span-3">
                      <div className="flex flex-wrap gap-1">
                        {h.items.map((item, i) => (
                          <span
                            key={i}
                            className={cn(
                              'rounded-lg px-2 py-0.5 text-[12px] font-semibold',
                              item.status === 'not_performed' ? 'bg-[#F1F5F9] text-[#64748B] line-through' : item.status === 'partial' ? 'bg-cx-amber-bg text-cx-amber-ink' : 'bg-cx-teal-bg text-cx-teal-ink',
                            )}
                          >
                            {item.status === 'partial' ? '½ ' : ''}{item.label}{item.minutes && item.status !== 'not_performed' ? ` ${item.minutes}′` : ''}
                          </span>
                        ))}
                        {h.addons.map((a) => <span key={a} className="rounded-lg bg-cx-amber-bg px-2 py-0.5 text-[12px] font-semibold text-cx-amber-ink">+ {a}</span>)}
                      </div>
                      {h.exercises.length > 0 && (
                        <div className="mt-1 flex flex-wrap gap-1">
                          {h.exercises.map((e, i) => (
                            <span key={i} className={cn('rounded-lg px-2 py-0.5 text-[11.5px] font-semibold', e.status === 'skipped' ? 'bg-[#F1F5F9] text-[#64748B] line-through' : e.status === 'partial' ? 'bg-cx-amber-bg text-cx-amber-ink' : 'bg-cx-blue-bg text-cx-blue-ink')}>
                              {e.name}{e.status !== 'skipped' && e.sets ? ` ${e.sets}×${e.reps ?? ''}` : ''}
                            </span>
                          ))}
                        </div>
                      )}
                      {h.note && <div className="mt-1 text-[12px] text-cx-text-2">{h.note}</div>}
                    </div>
                    <div className="col-span-12 flex items-center gap-1.5 md:col-span-2">
                      <UserRound className="h-3.5 w-3.5 shrink-0 text-cx-text-2" aria-hidden="true" />
                      <span className="font-semibold text-[#1D2E4A]">{h.by.join(', ') || '—'}</span>
                    </div>
                    <div className="hidden md:col-span-2 md:block md:text-right"><Payment row={h} /></div>
                  </li>
                );
              })}
            </ul>
          </div>
        )}
      </Panel>
    </div>
  );

  /** Each problem's package (what is left, what it covers, buying or renewing), then what the patient owes. */
  const billingTab = (
    <div className="grid gap-4 lg:grid-cols-2">
      <Panel icon={PackageCheck} tone="mint" title="Packages" detail={physio.can_sell_package ? 'Sell or renew here; the bill goes with this visit.' : 'Packages are sold by the owner, billing or a doctor allowed to.'}>
        {active.length === 0 ? (
          <p className="rounded-2xl bg-[#F7F8FC] px-4 py-6 text-center text-[13px] text-cx-text-2">Start a treatment to offer its packages.</p>
        ) : (
          <div className="space-y-3">
            {active.map((c) => {
              const offers = problems.get(c.problem ?? '')?.packages ?? [];
              const type = typeOf(c, c.type);

              return (
                <div key={c.id} className="overflow-hidden rounded-[18px] border border-[#EEF2F7]">
                  <div className="flex flex-wrap items-center gap-2 px-4 py-3">
                    <span className="text-[14.5px] font-bold text-[#1D2E4A]">{c.title}</span>
                    <span className="flex-1" />
                    {c.package ? (
                      <Pill tone="mint">{c.package.name} · {c.package.left} of {c.package.total} left{c.package.valid_until ? ` · till ${new Date(`${c.package.valid_until}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}` : ''}</Pill>
                    ) : (
                      <Pill tone="slate">Pays per visit</Pill>
                    )}
                  </div>
                  {c.package && (
                    <div className="grid gap-1 px-4 pb-3" style={{ gridTemplateColumns: `repeat(${Math.min(c.package.total, 20)}, minmax(0, 1fr))` }} role="img" aria-label={`${c.package.total - c.package.left} of ${c.package.total} sessions used`}>
                      {Array.from({ length: Math.min(c.package.total, 20) }, (_, i) => <span key={i} className="h-2.5 rounded" style={{ background: i < c.package!.total - c.package!.left ? '#0E9F87' : '#CBEFE6' }} />)}
                    </div>
                  )}
                  {!readOnly && <PackageStrip course={c} packages={offers} canSell={physio.can_sell_package} busy={busy} perVisit={type?.price ?? 0} onBuy={(id) => post(links.package.replace(/\/0\/package$/, `/${c.id}/package`), { package: id })} />}
                  {c.packages.length > 0 && (
                    <ul className="divide-y divide-[#EEF2F7] border-t border-[#EEF2F7]">
                      {c.packages.map((p) => (
                        <li key={p.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-[12.5px]">
                          <span className="min-w-[150px] flex-1">
                            <strong className="text-[#1D2E4A]">{p.name}</strong>
                            <span className="block text-cx-text-2">
                              Bought {p.sold_on ? dayLabel(p.sold_on, today) : '—'} · {p.used} used · {p.left} left{p.valid_until ? ` · till ${new Date(`${p.valid_until}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}` : ''}
                            </span>
                          </span>
                          <Pill tone={p.state === 'active' ? 'mint' : p.state === 'used' ? 'blue' : 'slate'}>{p.state === 'active' ? 'Running' : p.state === 'used' ? 'Used up' : p.state === 'expired' ? 'Expired' : 'Cancelled'}</Pill>
                          {p.bill && (
                            <span className="flex items-center gap-2">
                              <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-bold', p.bill.due > 0 ? 'bg-cx-rose-bg text-cx-rose-ink' : 'bg-cx-teal-bg text-cx-teal-ink')}>
                                {p.bill.due > 0 ? `${rupee(p.bill.due)} due` : `${rupee(p.bill.paid)} paid`}
                              </span>
                              <a href={p.bill.print} target="_blank" rel="noopener" className={cn('rounded font-bold text-[#1769AA]', FOCUS)}>Invoice {p.bill.number}</a>
                            </span>
                          )}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </Panel>

      <Panel icon={Wallet} tone="amber" title="Billing" detail={owed > 0 ? `${rupee(owed)} to collect` : 'Nothing to collect'}>
        {charged.length === 0 ? (
          <p className="rounded-2xl bg-[#F7F8FC] px-4 py-6 text-center text-[13px] text-cx-text-2">No charged sessions yet.</p>
        ) : (
          <ul className="divide-y divide-[#EEF2F7] rounded-[18px] border border-[#EEF2F7]">
            {charged.map((h) => (
              <li key={h.id} className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-[13px]">
                <span className="min-w-[96px] font-bold text-[#1D2E4A]">{dayLabel(h.date, today)}</span>
                <span className="min-w-0 flex-1 text-[#475569]">{h.course} · {h.type}</span>
                <span className="text-right"><Payment row={h} /></span>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  );

  const TABS: { key: Tab; label: string; icon: LucideIcon; hint?: string }[] = [
    { key: 'today', label: 'Today', icon: Activity, hint: doneToday.length > 0 ? `${doneToday.length} done` : active.length > 0 ? `${active.length} running` : undefined },
    { key: 'history', label: 'Progress & History', icon: History, hint: physio.history.length > 0 ? String(physio.history.length) : undefined },
    { key: 'billing', label: 'Packages & Billing', icon: Wallet, hint: owed > 0 ? `${rupee(owed)} due` : undefined },
  ];

  return (
    <div className="mx-auto w-full max-w-[1440px] space-y-4 pb-24 lg:pb-12">
      <PhysioHeader
        patient={patient}
        encounter={encounter}
        backHref={links.today}
        backLabel={desk ? 'Front desk' : 'My day'}
        patientHref={links.patient}
        problem={{ title: active.map((c) => c.title).join(', ') || (suggested.length > 0 ? suggested.map((c) => c.title).join(', ') : (visit?.reason ?? 'New problem')), sub: active.length > 0 && suggested.length > 0 ? `${suggested.length} more suggested` : null }}
        sessions={sessionsFact}
        next={next}
        finished={physio.finished}
        action={
          // A therapist with a login takes the payment on the checkout page, once, and only when something is owed.
          // Where the page collects itself (the desk), its Payment card does: no second button here.
          physio.finished && !payment && links.checkout && owed > 0 ? (
            <Link href={links.checkout} className={BTN_PRIMARY}>
              <Wallet className="h-4 w-4" aria-hidden="true" /> Take payment · {rupee(owed)}
            </Link>
          ) : null
        }
      />

      {desk && (
        <p className="flex flex-wrap items-center gap-2 rounded-xl bg-cx-violet-bg px-4 py-3 text-[13px] font-semibold text-cx-violet-ink">
          <UserRound className="h-4 w-4" aria-hidden="true" /> Front desk, working for {desk.therapist} (no login) — everything is recorded in their name.
        </p>
      )}
      {(errors?.physio || errors?.booking) && <p role="alert" className="rounded-xl bg-cx-rose-bg px-4 py-3 text-[13px] font-semibold text-cx-rose-ink">{errors.physio ?? errors.booking}</p>}

      {/* Three tabs across the top: the whole width stays for the work. */}
      <nav aria-label="Workspace" className="flex gap-1.5 overflow-x-auto rounded-full bg-white p-1.5 shadow-[0_10px_28px_-18px_rgba(30,50,110,0.28)] [scrollbar-width:none] sm:inline-flex">
        {TABS.map((item) => {
          const on = tab === item.key;
          const Icon = item.icon;

          return (
            <button
              key={item.key}
              type="button"
              aria-current={on ? 'page' : undefined}
              onClick={() => setTab(item.key)}
              className={cn('flex h-11 shrink-0 items-center gap-2 rounded-full px-4 text-[13.5px] font-bold transition-colors', FOCUS, on ? 'bg-[#1D2E4A] text-white' : 'text-[#475569] hover:bg-[#F1F5F9]')}
            >
              <Icon className="h-4 w-4" aria-hidden="true" />
              <span className="whitespace-nowrap">{item.label}</span>
              {item.hint && <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-bold', on ? 'bg-white/20 text-white' : 'bg-[#EEF2F7] text-[#475569]')}>{item.hint}</span>}
            </button>
          );
        })}
      </nav>

      {tab === 'today' && todayTab}
      {tab === 'history' && historyTab}
      {tab === 'billing' && billingTab}

      {/* On a phone or tablet the summary sits far below: Finish stays at hand. */}
      {!readOnly && (
        <div className="fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t border-[#E6EBF1] bg-white/95 px-4 py-3 backdrop-blur lg:hidden">
          <span className="min-w-0 flex-1 truncate text-[12.5px] text-[#475569]">
            {doneToday.length > 0 ? `${doneToday.length} ${doneToday.length === 1 ? 'session' : 'sessions'} · ${rupee(total)}` : canFinish ? 'Consultation only' : 'Nothing to save yet'}
          </span>
          <button type="button" disabled={!canFinish || busy} onClick={finish} className={cn('h-11 shrink-0 rounded-full bg-[#0E9F87] px-5 text-[14px] font-bold text-white disabled:opacity-60', FOCUS)}>
            {busy ? 'Saving…' : 'Finish'}
          </button>
        </div>
      )}

      {wizard && (
        <PlanWizard
          key={wizard.mode === 'new' ? 'new' : `${wizard.mode}-${wizard.course.id}`}
          wizard={wizard}
          problems={physio.problems.map((p) => problems.get(p.key) as Problem)}
          openKeys={physio.courses.map((c) => `${c.problem}:${c.side ?? ''}`)}
          sides={physio.sides}
          frequencies={physio.frequencies}
          choices={physio.choices}
          treatments={treatments}
          canSell={physio.can_sell_package}
          visitReason={visit?.reason ?? null}
          library={physio.exercises}
          url={links.plan}
          onClose={() => setWizard(null)}
        />
      )}
    </div>
  );
}

/** Patient 360's header (`PatientHeaderCard`), for the visit: who, why, and the facts the therapist asks first. */
function PhysioHeader({
  patient,
  encounter,
  backHref,
  backLabel,
  patientHref,
  problem,
  sessions,
  next,
  finished,
  action,
}: {
  patient: Props['patient'];
  encounter: Props['encounter'];
  backHref: string;
  backLabel: string;
  patientHref: string;
  problem: { title: string; sub: string | null };
  sessions: { title: string; sub: string | null };
  next: { date: string; time: string } | null;
  finished: boolean;
  action: ReactNode;
}) {
  const ageSex = [patient.age !== null ? `${patient.age} y` : null, patient.gender ? patient.gender[0].toUpperCase() + patient.gender.slice(1) : null].filter(Boolean).join(' · ');

  return (
    <header>
      <Link href={backHref} className={cn('mb-2 inline-flex h-9 items-center gap-1.5 rounded-full px-2.5 text-[13px] font-semibold text-cx-text-2 transition-colors hover:bg-white/70 hover:text-cx-nav-ink', FOCUS)}>
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {backLabel}
      </Link>

      <section aria-label="Patient" className="relative isolate overflow-clip rounded-[26px] border border-white/80 shadow-[0_2px_6px_rgba(15,23,42,0.05),0_24px_48px_-30px_rgba(40,40,120,0.4)]">
        <div className="absolute inset-0 -z-10 bg-[linear-gradient(118deg,#E2F6F5_0%,#E8F0FF_52%,#EFE9FE_100%)]" aria-hidden="true" />
        <div className="absolute -right-24 -top-24 -z-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,rgba(44,198,180,0.22),transparent_70%)]" aria-hidden="true" />
        <div className="absolute -bottom-32 right-40 -z-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,rgba(107,72,222,0.16),transparent_70%)]" aria-hidden="true" />

        <div className="flex flex-col gap-5 px-5 pb-5 pt-5 sm:px-7 lg:flex-row lg:items-start lg:justify-between">
          <div className="flex min-w-0 items-center gap-4">
            <span className="grid h-16 w-16 shrink-0 place-items-center rounded-[20px] bg-white text-[20px] font-semibold text-cx-blue-ink shadow-[0_8px_20px_-12px_rgba(30,50,110,0.5)] ring-4 ring-white/60 sm:h-[72px] sm:w-[72px] sm:text-[22px]" aria-hidden="true">
              {initials(patient.name)}
            </span>
            <div className="min-w-0">
              <h1 className="text-[22px] font-semibold leading-7 tracking-[-0.02em] text-[#1D2E4A] [overflow-wrap:anywhere] sm:text-[26px] sm:leading-8">{patient.name}</h1>
              <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                {patient.uhid && <span className="rounded-full bg-white px-2.5 py-0.5 font-mono text-[12px] font-semibold text-cx-blue-ink shadow-[inset_0_0_0_1px_rgba(43,111,216,0.18)]">{patient.uhid}</span>}
                {encounter.case_number && <span className="rounded-full bg-white px-2.5 py-0.5 font-mono text-[12px] font-semibold text-[#5B3BC4] shadow-[inset_0_0_0_1px_rgba(107,72,222,0.2)]" title="This year’s case number">Case {encounter.case_number}</span>}
                {ageSex && <Pill tone="slate" className="bg-white/80">{ageSex}</Pill>}
                <Pill tone="violet">Physiotherapy</Pill>
                {encounter.branch && <Pill tone="slate" className="bg-white/80">{encounter.branch}</Pill>}
                {finished && <Pill tone="mint">Visit finished</Pill>}
              </div>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-2 sm:justify-end">
            <Link href={patientHref} className={BTN}>
              <UserRound className="h-4 w-4" aria-hidden="true" /> Patient 360
            </Link>
            {action}
          </div>
        </div>

        <dl className="grid grid-cols-1 gap-2 px-4 pb-4 sm:grid-cols-3 sm:gap-2.5 sm:px-7 sm:pb-5">
          <Fact icon={ClipboardList} tone="amber" label="Problem">
            {problem.title}
            {problem.sub && <span className="block text-[11.5px] font-medium text-cx-text-3">{problem.sub}</span>}
          </Fact>
          <Fact icon={Activity} tone="violet" label="Sessions">
            {sessions.title}
            {sessions.sub && <span className="block text-[11.5px] font-medium text-cx-text-3">{sessions.sub}</span>}
          </Fact>
          <Fact icon={CalendarCheck2} tone="teal" label="Next visit">
            {next ? `${dayLabel(next.date, localToday())}, ${time12(next.time)}` : 'Not booked'}
          </Fact>
        </dl>
      </section>
    </header>
  );
}

function Fact({ icon, tone, label, children }: { icon: LucideIcon; tone: Tone; label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col items-start gap-2 rounded-[16px] bg-white/80 p-3 ring-1 ring-inset ring-white sm:flex-row sm:items-center sm:gap-3">
      <IconTile icon={icon} tone={tone} size="sm" />
      <div className="min-w-0">
        <dt className="text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">{label}</dt>
        <dd className="text-[13.5px] font-semibold leading-5 text-cx-nav-ink [overflow-wrap:anywhere]">{children}</dd>
      </div>
    </div>
  );
}

/** A white card with Patient 360's icon tile and title. */
function Panel({ icon, tone, title, detail, aside, children }: { icon: LucideIcon; tone: Tone; title: string; detail?: string; aside?: ReactNode; children: ReactNode }) {
  return (
    <section className={cn(CARD, 'p-4 sm:p-5')}>
      <header className="mb-4 flex flex-wrap items-center gap-3">
        <IconTile icon={icon} tone={tone} />
        <div className="min-w-0 flex-1">
          <h2 className="text-[15px] font-semibold leading-5 text-cx-nav-ink">{title}</h2>
          {detail && <p className="mt-0.5 text-[12px] leading-4 text-cx-text-3">{detail}</p>}
        </div>
        {aside && <div className="w-full sm:w-auto sm:min-w-[200px]">{aside}</div>}
      </header>
      {children}
    </section>
  );
}

function Payment({ row }: { row: HistoryRow }) {
  return (
    <>
      {row.package && <span className="block text-[12.5px] font-semibold text-cx-teal-ink">{row.package}{row.package_use ? ` · ${row.package_use}` : ''}</span>}
      {row.charged !== null && (
        <span className="inline-flex flex-wrap items-center justify-end gap-1 text-[12.5px] font-bold text-[#1D2E4A]">
          {rupee(row.charged)}
          <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-bold', row.payment === 'paid' ? 'bg-cx-teal-bg text-cx-teal-ink' : row.payment === 'due' ? 'bg-cx-rose-bg text-cx-rose-ink' : 'bg-cx-amber-bg text-cx-amber-ink')}>
            {row.payment === 'paid' ? 'Paid' : row.payment === 'due' ? 'Due' : 'Not billed yet'}
          </span>
        </span>
      )}
      {!row.package && row.charged === null && <span className="text-[12.5px] text-cx-text-2">No charge</span>}
    </>
  );
}

/**
 * How the course is paid, said where the therapist looks: a package running
 * low, one that ran out (now per visit) with its renewal one tap away, or
 * the packages of this problem a per-visit patient can switch to.
 */
function PackageStrip({ course: c, packages, canSell, busy, perVisit, onBuy }: { course: Course; packages: PackageOffer[]; canSell: boolean; busy: boolean; perVisit: number; onBuy: (id: string) => void }) {
  const offers = packages.length > 0 && (
    <div className="mt-2 flex flex-wrap gap-1.5">
      {packages.map((p) => (
        <button key={p.id} type="button" disabled={!canSell || busy} onClick={() => onBuy(p.id)} className={cn('inline-flex h-9 items-center gap-1.5 rounded-full border border-[#0E9F87] bg-white px-3 text-[12.5px] font-bold text-[#0E7C6B] disabled:cursor-not-allowed disabled:opacity-60', FOCUS)}>
          <PackageCheck className="h-4 w-4" aria-hidden="true" /> {p.name} · {rupee(p.price)}
          {perVisit * p.sessions > p.price && <span className="font-semibold text-[#12A877]">save {rupee(perVisit * p.sessions - p.price)}</span>}
        </button>
      ))}
    </div>
  );
  const noRight = !canSell && packages.length > 0 && <p className="mt-1.5 text-[12px] text-cx-text-2">Packages are sold by the owner or billing staff — the desk can add it.</p>;

  if (c.package) {
    if (c.package.left > 2) {
      return null;
    }

    return (
      <div className="border-t border-[#F3D9B5] bg-[#FFF9F1] px-4 py-2.5">
        <p className="text-[13px] font-bold text-cx-amber-ink">
          {c.package.left === 1 ? `Last session of ${c.package.name} today.` : `${c.package.name}: only ${c.package.left} sessions left.`} Renew before it runs out?
        </p>
        {offers}
        {noRight}
      </div>
    );
  }

  if (c.package_ended) {
    return (
      <div className="border-t border-[#F6C9CC] bg-[#FFF5F5] px-4 py-2.5">
        <p className="text-[13px] font-bold text-cx-rose-ink">
          {c.package_ended.name} {c.package_ended.expired && c.package_ended.used < c.package_ended.total ? 'has expired' : 'is used up'} ({c.package_ended.used} of {c.package_ended.total}). From now each visit is {priceLabel(perVisit)} — or renew:
        </p>
        {offers}
        {noRight}
      </div>
    );
  }

  return packages.length > 0 ? (
    <div className="border-t border-[#EEF2F7] px-4 py-2.5">
      <p className="text-[12.5px] font-semibold text-cx-text-2">Paying {priceLabel(perVisit)} a visit. Switch to a package:</p>
      {offers}
      {noRight}
    </div>
  ) : null;
}

/** One running course in today's visit: one tap for the session, the day's differences only when there are any. */
function SessionCard({
  course: c,
  tone: [bg, fg],
  row: r,
  readOnly,
  types,
  physio,
  treatments,
  covers,
  charge,
  typeOf,
  onPatch,
  onChangeType,
  packages,
  canSell,
  busy,
  onBuy,
  sheetUrl,
}: {
  course: Course;
  tone: [string, string];
  row: Row;
  /** The patient's printable home exercise sheet for this problem. */
  sheetUrl?: string;
  readOnly: boolean;
  types: SessionType[];
  physio: Props['physio'];
  treatments: Map<string, Treatment>;
  covers: (type: string | null) => boolean;
  charge: number;
  typeOf: (key: string | null) => SessionType | null;
  onPatch: (change: Partial<Row> | ((r: Row) => Partial<Row>)) => void;
  onChangeType: () => void;
  /** The owner's packages for this problem. */
  packages: PackageOffer[];
  canSell: boolean;
  busy: boolean;
  onBuy: (id: string) => void;
}) {
  const courseType = typeOf(c.type);
  const todayType = typeOf(r.type);
  const givenKeys = r.given ? Object.keys(r.given) : (todayType?.treatments ?? []);
  const recorded = c.today?.status === 'completed' ? c.today : null;
  const covered = covers(r.type);
  const usable = c.known_problem && !!todayType;

  return (
    <div className="overflow-hidden rounded-[18px] border-2 transition-shadow" style={{ borderColor: r.done || recorded ? fg : '#EEF2F7', boxShadow: r.done ? `0 14px 30px -24px ${fg}` : undefined }}>
      <div className="flex flex-wrap items-center gap-3 p-3.5 sm:px-4" style={{ background: bg }}>
        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-[14px] text-[14px] font-bold text-white" style={{ background: fg }}>{initials(c.title)}</span>
        <div className="min-w-0 flex-[1_1_200px]">
          <div className="flex flex-wrap items-center gap-x-2 text-[15px] font-semibold leading-5 text-cx-nav-ink [overflow-wrap:anywhere]">
            {c.title}
            {c.diagnosis && <span className="rounded-full bg-white/80 px-2 py-0.5 text-[11.5px] font-bold text-[#0E7C6B]">{c.diagnosis}</span>}
          </div>
          <div className="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[12.5px] font-semibold text-[#475569]">
            <span>{courseType?.label ?? 'No session type'}</span>
            <span aria-hidden="true">·</span>
            <span>{c.package ? `${c.package.name} · ${c.package.left} left` : courseType ? `${priceLabel(courseType.price)} a visit` : 'Per visit'}</span>
            {c.frequency && <><span aria-hidden="true">·</span><span>{physio.frequencies.find((f) => f.key === c.frequency)?.label}</span></>}
          </div>
          <div className="mt-1.5 flex items-center gap-2">
            <div className="h-1.5 w-full max-w-[160px] overflow-hidden rounded-full bg-white">
              <div className="h-full rounded-full" style={{ width: `${c.planned ? Math.min(100, Math.round((c.done / c.planned) * 100)) : 0}%`, background: fg }} />
            </div>
            <span className="whitespace-nowrap text-[12px] font-bold text-[#1D2E4A]">{c.done}{c.planned ? ` / ${c.planned}` : ''} sessions</span>
          </div>
        </div>
        {!readOnly && (
          <button type="button" onClick={onChangeType} className={cn('inline-flex h-10 items-center gap-1 rounded-full bg-white px-3.5 text-[12.5px] font-bold', FOCUS)} style={{ color: fg }}>
            <SlidersHorizontal className="h-3.5 w-3.5" aria-hidden="true" /> Plan
          </button>
        )}
        {!readOnly && !recorded && usable && (
          <button
            type="button"
            aria-pressed={r.done}
            onClick={() => onPatch((cur) => ({ done: !cur.done }))}
            className={cn('inline-flex h-12 w-full items-center justify-center gap-2 rounded-full border-2 px-5 text-[15px] font-bold sm:w-auto sm:min-w-[190px]', FOCUS)}
            style={{ borderColor: fg, background: r.done ? fg : '#FFFFFF', color: r.done ? '#FFFFFF' : fg }}
          >
            <Check className="h-5 w-5" aria-hidden="true" />
            {r.done ? `Done · ${covered ? (charge > 0 ? `pkg + ${rupee(charge)}` : 'package') : rupee(charge)}` : 'Session done'}
          </button>
        )}
      </div>

      {!readOnly && usable && (
        <PackageStrip course={c} packages={packages} canSell={canSell} busy={busy} perVisit={courseType?.price ?? 0} onBuy={onBuy} />
      )}

      {recorded ? (
        <p className="flex flex-wrap items-center gap-2 px-4 py-3 text-[13.5px] text-[#1D2E4A]">
          <Check className="h-5 w-5 rounded-full bg-[#0E9F87] p-0.5 text-white" aria-hidden="true" />
          <strong>Done today</strong> · {typeOf(recorded.type)?.label ?? ''} · {recorded.given.map((g) => g.label).join(', ')}
          {recorded.addons.length > 0 && ` + ${recorded.addons.map((a) => a.label).join(', ')}`}
          {recorded.charge ? ` · ${rupee(recorded.charge)}` : ' · package'}
        </p>
      ) : readOnly ? (
        <p className="px-4 py-3 text-[13px] text-cx-text-2">No session for this problem in this visit.</p>
      ) : !usable ? (
        <p className="px-4 py-3 text-[13px] font-semibold text-cx-amber-ink">This course has no problem from the clinic’s list. Add the patient’s problem above and start it to record sessions.</p>
      ) : r.done ? (
        <div className="space-y-3.5 p-4">
          <div className="grid grid-cols-12 gap-3">
            {types.length > 1 && (
              <div className="col-span-12 md:col-span-6">
                <div className="mb-1.5 text-[11.5px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Today’s type</div>
                <div className="flex flex-wrap gap-1.5">
                  {types.map((t) => {
                    const on = r.type === t.key;

                    return (
                      <button key={t.key} type="button" aria-pressed={on} onClick={() => onPatch({ type: t.key, given: null })} className={cn('h-9 rounded-full border px-3 text-[12.5px] font-bold', FOCUS)} style={{ borderColor: on ? fg : '#DCE6F3', background: on ? bg : '#FFFFFF', color: on ? fg : '#475569' }}>
                        {t.label} · {covers(t.key) ? 'pkg' : priceLabel(t.price)}
                      </button>
                    );
                  })}
                </div>
              </div>
            )}
            <div className={cn('col-span-12', types.length > 1 && 'md:col-span-6')}>
              <label htmlFor={`by-${c.id}`} className="mb-1.5 block text-[11.5px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Given by</label>
              <Select id={`by-${c.id}`} value={String(r.therapist ?? '')} onChange={(e) => onPatch({ therapist: Number(e.target.value) || null })} options={physio.therapists.map((t) => ({ value: String(t.id), label: t.name }))} placeholder={physio.therapists.length === 0 ? 'You' : undefined} />
            </div>
          </div>

          <div>
            <div className="mb-1.5 flex items-center justify-between gap-2">
              <span className="text-[11.5px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Given today</span>
              <button type="button" onClick={() => onPatch((cur) => ({ editing: !cur.editing }))} className={cn('inline-flex items-center gap-1 rounded-full px-2 text-[12px] font-bold text-[#1769AA]', FOCUS)}>
                <Pencil className="h-3 w-3" aria-hidden="true" /> {r.editing ? 'Done' : 'Different today'}
              </button>
            </div>
            {!r.editing && givenKeys.length > 0 && (
              <ul className="divide-y divide-[#EEF2F7] overflow-hidden rounded-[14px] border border-[#EEF2F7]">
                {givenKeys.map((key) => {
                  const t = treatments.get(key);
                  if (!t) return null;
                  const status = r.status[key] ?? 'performed';

                  return (
                    <li key={key} className="flex flex-wrap items-center gap-2 px-3 py-2 text-[13px]">
                      <span className={cn('min-w-0 flex-1 font-bold', status === 'not_performed' ? 'text-[#94A3B8] line-through' : 'text-[#1D2E4A]')}>
                        {t.label}
                        <span className="ml-1.5 text-[12px] font-semibold text-cx-text-2">{r.given?.[key] ?? t.minutes}′{!covered && !todayType?.bundle ? ` · ${priceLabel(t.price)}` : ''}</span>
                      </span>
                      <span className="flex gap-1" role="group" aria-label={`${t.label}: given?`}>
                        {STATUSES.map((s) => {
                          const on = status === s.key;

                          return (
                            <button
                              key={s.key}
                              type="button"
                              aria-pressed={on}
                              title={s.label}
                              aria-label={`${t.label}: ${s.label}`}
                              onClick={() => onPatch((cur) => ({ status: { ...cur.status, [key]: s.key } }))}
                              className={cn('h-8 min-w-9 rounded-full px-2 text-[13px] font-bold', FOCUS)}
                              style={{ background: on ? s.bg : '#F1F5F9', color: on ? s.fg : '#64748B' }}
                            >
                              {s.short}
                            </button>
                          );
                        })}
                      </span>
                    </li>
                  );
                })}
              </ul>
            )}
            <div className={cn('flex flex-wrap gap-1.5', !r.editing && 'hidden')}>
              {(r.editing ? physio.treatments.map((t) => t.key) : givenKeys).map((key) => {
                const t = treatments.get(key);
                if (!t) return null;
                const on = givenKeys.includes(key);

                return r.editing ? (
                  <button
                    key={key}
                    type="button"
                    aria-pressed={on}
                    onClick={() =>
                      onPatch((cur) => {
                        const base = cur.given ?? Object.fromEntries((typeOf(cur.type)?.treatments ?? []).map((k) => [k, treatments.get(k)?.minutes ?? 15]));
                        const nextGiven = { ...base };
                        if (nextGiven[key] !== undefined) delete nextGiven[key];
                        else nextGiven[key] = t.minutes;

                        return { given: nextGiven };
                      })
                    }
                    className={cn('h-8 rounded-full border px-3 text-[12.5px] font-bold', FOCUS)}
                    style={{ borderColor: on ? '#0E9F87' : '#DCE6F3', background: on ? '#E6F6F3' : '#FFFFFF', color: on ? '#0E7C6B' : '#64748B' }}
                  >
                    {on ? '✓ ' : ''}{t.label}
                  </button>
                ) : (
                  <span key={key} className="rounded-full bg-cx-teal-bg px-3 py-1 text-[12.5px] font-bold text-cx-teal-ink">{t.label} · {r.given?.[key] ?? t.minutes}′</span>
                );
              })}
            </div>
            {givenKeys.length === 0 && !r.editing && <p className="text-[12.5px] text-cx-text-2">The owner has not set what this type gives — tap “Different today”.</p>}
          </div>

          {/* The prescribed exercises: done as prescribed unless tapped — partly (with the sets done) or not. */}
          {c.exercises.length > 0 && (
            <div>
              <div className="mb-1.5 flex items-center justify-between gap-2">
                <span className="text-[11.5px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Exercises · as prescribed unless tapped</span>
                {sheetUrl && <a href={sheetUrl} target="_blank" rel="noopener" className={cn('rounded text-[12px] font-bold text-[#1769AA]', FOCUS)}>Print sheet</a>}
              </div>
              <ul className="divide-y divide-[#EEF2F7] overflow-hidden rounded-[14px] border border-[#EEF2F7]">
                {c.exercises.map((e) => {
                  const state = r.exercises[e.id] ?? { status: 'performed' as ExerciseStatus, sets: e.sets };
                  const set = (next: { status: ExerciseStatus; sets: number | null }) => onPatch((cur) => ({ exercises: { ...cur.exercises, [e.id]: next } }));

                  return (
                    <li key={e.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-[13px]">
                      <span className={cn('min-w-0 flex-1 font-bold', state.status === 'skipped' ? 'text-[#94A3B8] line-through' : 'text-[#1D2E4A]')}>
                        {e.name}
                        <span className="ml-1.5 text-[12px] font-semibold text-cx-text-2">{e.sets ?? '—'} × {e.reps ?? '—'}{e.hold ? ` · hold ${e.hold}s` : ''}</span>
                      </span>
                      {state.status === 'partial' && (
                        <span className="flex items-center gap-1 text-[12px] font-semibold text-cx-amber-ink">
                          did
                          <button type="button" aria-label={`${e.name}: fewer sets`} onClick={() => set({ status: 'partial', sets: Math.max(0, (state.sets ?? 0) - 1) })} className={cn('grid h-7 w-7 place-items-center rounded-full bg-[#FDF3E7] font-bold', FOCUS)}>−</button>
                          <span className="min-w-5 text-center font-bold tabular-nums">{state.sets ?? 0}</span>
                          <button type="button" aria-label={`${e.name}: more sets`} onClick={() => set({ status: 'partial', sets: Math.min(50, (state.sets ?? 0) + 1) })} className={cn('grid h-7 w-7 place-items-center rounded-full bg-[#FDF3E7] font-bold', FOCUS)}>+</button>
                          sets
                        </span>
                      )}
                      <span className="flex gap-1" role="group" aria-label={`${e.name}: done?`}>
                        {([
                          ['performed', '✓', 'Done', '#0E9F87'],
                          ['partial', '½', 'Partly', '#E9821A'],
                          ['skipped', '✕', 'Not done', '#94A3B8'],
                        ] as const).map(([key, short, name, color]) => {
                          const on = state.status === key;

                          return (
                            <button
                              key={key}
                              type="button"
                              aria-pressed={on}
                              title={name}
                              aria-label={`${e.name}: ${name}`}
                              onClick={() => set({ status: key, sets: key === 'partial' ? Math.max(0, (e.sets ?? 1) - 1) : e.sets })}
                              className={cn('h-8 min-w-9 rounded-full px-2 text-[13px] font-bold', FOCUS)}
                              style={{ background: on ? color : '#F1F5F9', color: on ? '#FFFFFF' : '#64748B' }}
                            >
                              {short}
                            </button>
                          );
                        })}
                      </span>
                    </li>
                  );
                })}
              </ul>
            </div>
          )}

          {physio.addons.length > 0 && (
            <div>
              <div className="mb-1.5 text-[11.5px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Add-ons · charged extra</div>
              <div className="flex flex-wrap gap-1.5">
                {physio.addons.map((a) => {
                  const on = r.addons.includes(a.key);

                  return (
                    <button key={a.key} type="button" aria-pressed={on} onClick={() => onPatch((cur) => ({ addons: on ? cur.addons.filter((k) => k !== a.key) : [...cur.addons, a.key] }))} className={cn('h-8 rounded-full border px-3 text-[12.5px] font-bold', FOCUS)} style={{ borderColor: on ? '#E9821A' : '#DCE6F3', background: on ? '#FDF3E7' : '#FFFFFF', color: on ? '#A35A06' : '#475569' }}>
                      {on ? '✓ ' : '+ '}{a.label} · {priceLabel(a.price)}
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          <div className="grid grid-cols-12 gap-3">
            <div className="col-span-12 md:col-span-6">
              <div className="mb-1.5 text-[11.5px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Patient feels</div>
              <div className="flex gap-1.5">
                {FEELS.map((f) => {
                  const on = r.feel === f.key;

                  return (
                    <button key={f.key} type="button" aria-pressed={on} onClick={() => onPatch({ feel: on ? null : f.key })} className={cn('h-10 flex-1 rounded-xl border text-[13px] font-bold', FOCUS)} style={{ borderColor: on ? f.color : '#DCE6F3', background: on ? f.color : '#FFFFFF', color: on ? '#FFFFFF' : '#1D2E4A' }}>
                      {f.label}
                    </button>
                  );
                })}
              </div>
            </div>
            <div className="col-span-12 md:col-span-6">
              <div className="mb-1.5 text-[11.5px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Note</div>
              {r.noteOpen ? (
                <input aria-label="Note for this session" autoFocus value={r.note} maxLength={1000} placeholder="Optional" onChange={(e) => onPatch({ note: e.target.value })} className={cn('h-10 w-full rounded-xl border border-[#DCE6F3] bg-white px-3 text-[13.5px]', FOCUS)} />
              ) : (
                <button type="button" onClick={() => onPatch({ noteOpen: true })} className={cn('h-10 w-full rounded-xl border border-dashed border-[#C9D3E1] text-[12.5px] font-bold text-[#1769AA]', FOCUS)}>+ Add a note</button>
              )}
            </div>
          </div>

          {types.length > 0 && (
            <button type="button" onClick={onChangeType} className={cn('inline-flex items-center gap-1.5 rounded text-[12.5px] font-bold', FOCUS)} style={{ color: fg }}>
              <Repeat className="h-3.5 w-3.5" aria-hidden="true" /> Change the plan for every coming visit
            </button>
          )}
        </div>
      ) : (
        <p className="px-4 py-2.5 text-[12.5px] text-cx-text-2">
          Session {c.done + 1}{c.planned ? ` of ${c.planned}` : ''} · gives {(todayType?.treatments ?? []).map((k) => treatments.get(k)?.label).filter(Boolean).join(', ') || 'the type’s treatments'}
        </p>
      )}
    </div>
  );
}

/** The coming visits: first day, a time from the list, how often, how many — booked on Finish. */
function BookingCard({
  booking,
  setBooking,
  physio,
  dayTimes,
  count,
  plannedDates,
  today,
  calendar,
  toBook,
}: {
  /** The calendar, where a booked visit is moved or cancelled. */
  calendar: string;
  /** Sessions still to come that no visit is booked for. */
  toBook: number;
  booking: { on: boolean; frequency: string; date: string; time: string; count: string };
  setBooking: (change: (b: { on: boolean; frequency: string; date: string; time: string; count: string }) => { on: boolean; frequency: string; date: string; time: string; count: string }) => void;
  physio: Props['physio'];
  dayTimes: string[];
  count: number;
  plannedDates: string[];
  today: string;
}) {
  return (
    <section className={cn(CARD, 'overflow-hidden')}>
      <div className="flex items-center gap-2.5 bg-[linear-gradient(118deg,#E8F0FF_0%,#EFE9FE_100%)] px-4 py-3.5">
        <IconTile icon={CalendarDays} tone="blue" />
        <div className="min-w-0 flex-1">
          <h2 className="text-[15px] font-semibold leading-5 text-cx-nav-ink">Coming visits</h2>
          <p className="text-[12px] text-[#475569]">
            {booking.on
              ? `${plannedDates.length} more booked on Finish · WhatsApp reminder`
              : physio.booking.upcoming.length > 0
                ? `${physio.booking.upcoming.length} booked${toBook === 0 ? ' · every session covered' : ` · ${toBook} more to book`}`
                : 'Nothing booked yet'}
          </p>
        </div>
        <button type="button" role="switch" aria-checked={booking.on} aria-label="Book the coming visits" onClick={() => setBooking((b) => ({ ...b, on: !b.on }))} className={cn('relative h-7 w-12 shrink-0 rounded-full transition-colors', FOCUS)} style={{ background: booking.on ? '#2B6FD8' : '#CBD5E1' }}>
          <span className="absolute top-1 h-5 w-5 rounded-full bg-white transition-all" style={{ left: booking.on ? 24 : 4 }} />
        </button>
      </div>

      <div className="space-y-3 p-4">
        {physio.booking.upcoming.length > 0 && (
          <div className="rounded-2xl bg-cx-teal-bg p-3">
            <div className="flex items-center gap-1.5 text-[12px] font-bold text-cx-teal-ink"><CalendarCheck2 className="h-4 w-4" aria-hidden="true" /> Already booked · {physio.booking.upcoming.length}</div>
            <div className="mt-1.5 flex flex-wrap gap-1">
              {physio.booking.upcoming.slice(0, 8).map((u) => (
                <span key={`${u.date}${u.time}`} className="rounded-lg bg-white px-2 py-1 text-[12px] font-semibold text-[#1D2E4A]">{new Date(`${u.date}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })} · {time12(u.time)}</span>
              ))}
              {physio.booking.upcoming.length > 8 && <span className="px-1 py-1 text-[12px] font-semibold text-cx-teal-ink">+{physio.booking.upcoming.length - 8} more</span>}
            </div>
            <Link href={calendar} className={cn('mt-2 inline-block rounded text-[12px] font-bold text-[#1769AA]', FOCUS)}>Move or cancel one in the calendar →</Link>
          </div>
        )}

        {booking.on ? (
          <>
            <div className="grid grid-cols-2 gap-2.5">
              <div className="col-span-2">
                <label htmlFor="b-first" className="mb-1 block text-[12px] font-bold text-cx-text-2">First visit</label>
                <Select
                  id="b-first"
                  value={booking.date}
                  onChange={(e) => {
                    const times = physio.booking.days.find((d) => d.date === e.target.value)?.times ?? [];
                    setBooking((b) => ({ ...b, date: e.target.value, time: times.includes(b.time) ? b.time : (times[0] ?? '') }));
                  }}
                  options={physio.booking.days.map((d) => ({ value: d.date, label: `${dayLabel(d.date, today)}${d.times.length === 0 ? ' · full' : ''}`, disabled: d.times.length === 0 }))}
                />
              </div>
              <div>
                <label htmlFor="b-time" className="mb-1 flex items-center gap-1 text-[12px] font-bold text-cx-text-2"><Clock className="h-3.5 w-3.5" aria-hidden="true" /> Time</label>
                <Select id="b-time" value={booking.time} onChange={(e) => setBooking((b) => ({ ...b, time: e.target.value }))} options={dayTimes.map((t) => ({ value: t, label: time12(t) }))} placeholder={dayTimes.length === 0 ? 'No free time' : undefined} />
              </div>
              <div>
                <label htmlFor="b-count" className="mb-1 block text-[12px] font-bold text-cx-text-2">Visits</label>
                <Select id="b-count" value={String(count)} onChange={(e) => setBooking((b) => ({ ...b, count: e.target.value }))} options={Array.from({ length: 30 }, (_, i) => ({ value: String(i + 1), label: `${i + 1} ${i === 0 ? 'visit' : 'visits'}` }))} />
              </div>
              <div className="col-span-2">
                <label htmlFor="b-freq" className="mb-1 block text-[12px] font-bold text-cx-text-2">How often</label>
                <Select id="b-freq" value={booking.frequency} onChange={(e) => setBooking((b) => ({ ...b, frequency: e.target.value }))} options={physio.frequencies.map((f) => ({ value: f.key, label: f.label }))} />
              </div>
            </div>

            {plannedDates.length > 0 && (
              <div>
                <div className="mb-1.5 text-[12px] font-bold text-[#475569]">{plannedDates.length} {plannedDates.length === 1 ? 'visit' : 'visits'} at {time12(booking.time)}</div>
                <div className="grid grid-cols-5 gap-1.5">
                  {plannedDates.map((iso) => {
                    const date = new Date(`${iso}T00:00:00`);

                    return (
                      <div key={iso} className="flex flex-col items-center rounded-xl bg-[#F5F8FF] py-1.5 ring-1 ring-inset ring-[#C9D7F0]">
                        <span className="text-[10.5px] font-semibold text-cx-text-2">{date.toLocaleDateString('en-IN', { weekday: 'short' })}</span>
                        <span className="text-[15px] font-bold text-[#1D2E4A]">{date.getDate()}</span>
                        <span className="text-[10.5px] font-semibold text-cx-text-2">{date.toLocaleDateString('en-IN', { month: 'short' })}</span>
                      </div>
                    );
                  })}
                </div>
                <p className="mt-1.5 text-[11.5px] text-cx-text-2">A day not free at that time moves to the next free day; days already booked are skipped.</p>
              </div>
            )}
          </>
        ) : (
          <p className="text-[12.5px] text-cx-text-2">
            {physio.booking.upcoming.length > 0
              ? toBook === 0
                ? 'Every session has a visit booked.'
                : `${toBook} ${toBook === 1 ? 'session has' : 'sessions have'} no visit yet. Turn on to book ${toBook === 1 ? 'it' : 'them'}.`
              : 'Not booking now. The desk can book from the calendar.'}
          </p>
        )}
      </div>
    </section>
  );
}

/** One row of tap-to-choose chips: one answer, or several. */
function ChipRow({ label, options, value, onChange, many = false, color, readOnly }: { label: string; options: Choice[]; value: string[]; onChange: (next: string[]) => void; many?: boolean; color: string; readOnly: boolean }) {
  const shown = readOnly ? options.filter((o) => value.includes(o.key)) : options;

  return (
    <div className="grid grid-cols-12 items-center gap-2">
      <span className="col-span-12 text-[12px] font-bold text-cx-text-2 sm:col-span-3">{label}</span>
      <div className="col-span-12 flex flex-wrap gap-1.5 sm:col-span-9">
        {shown.map((o) => {
          const on = value.includes(o.key);

          return readOnly ? (
            <span key={o.key} className="rounded-full px-3 py-1 text-[12.5px] font-bold text-white" style={{ background: color }}>{o.label}</span>
          ) : (
            <button
              key={o.key}
              type="button"
              aria-pressed={on}
              onClick={() => onChange(many ? (on ? value.filter((k) => k !== o.key) : [...value, o.key]) : on ? [] : [o.key])}
              className={cn('h-9 rounded-full px-3.5 text-[12.5px] font-bold transition-colors', FOCUS)}
              style={{ background: on ? color : '#F1F4F9', color: on ? '#FFFFFF' : '#334155' }}
            >
              {on && many ? '✓ ' : ''}{o.label}
            </button>
          );
        })}
        {readOnly && shown.length === 0 && <span className="text-[12.5px] text-cx-text-2">—</span>}
      </div>
    </div>
  );
}

/**
 * Where one problem stands (`PhysioConsultation::progress`): the complaint
 * then and now, the goals (tap one when it is reached), how the patient felt
 * visit by visit, the progress notes, and the next review. Only what was
 * recorded — no percentages or charts made up.
 */
function ProgressBlock({ course: c, tone: [, fg], choices, readOnly, busy, today, onPost }: { course: Course; tone: [string, string]; choices: Choices; readOnly: boolean; busy: boolean; today: string; onPost: (data: Record<string, unknown>) => void }) {
  const [note, setNote] = useState('');
  const [review, setReview] = useState(false);
  const p = c.progress;
  const label = (list: Choice[], key: string | null | undefined) => list.find((o) => o.key === key)?.label ?? null;
  const summary = (complaint: Complaint | null | undefined) =>
    complaint ? [label(choices.severities, complaint.severity), complaint.difficulties.map((k) => label(choices.difficulties, k)).join(', ')].filter(Boolean).join(' · ') || '—' : '—';
  const feelColor: Record<string, string> = { better: '#0E9F87', same: '#94A3B8', worse: '#E5484D' };
  const reviewOptions = [7, 14, 30].map((days) => ({ days, date: addDays(today, days) }));

  return (
    <div className="mt-3 space-y-3 rounded-[14px] bg-white/85 p-3">
      {p.initial && (
        <div className="grid grid-cols-12 gap-2 text-[12.5px]">
          <div className="col-span-12 rounded-xl bg-[#F7F8FC] p-2.5 sm:col-span-6">
            <div className="text-[11px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Then{p.initial.on ? ` · ${new Date(`${p.initial.on}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}` : ''}</div>
            <div className="font-semibold text-[#1D2E4A]">{summary(p.initial.complaint)}</div>
          </div>
          <div className="col-span-12 rounded-xl p-2.5 sm:col-span-6" style={{ background: `${fg}14` }}>
            <div className="text-[11px] font-bold uppercase tracking-[0.05em]" style={{ color: fg }}>Now</div>
            <div className="font-semibold text-[#1D2E4A]">{summary(p.now)}</div>
          </div>
        </div>
      )}

      {p.feels.length > 0 && (
        <div>
          <div className="mb-1 text-[11px] font-bold uppercase tracking-[0.05em] text-cx-text-2">How the patient felt · last {p.feels.length} sessions</div>
          <div className="flex gap-1" role="img" aria-label={p.feels.map((f) => f.feel ?? 'not asked').join(', ')}>
            {p.feels.map((f, i) => <span key={i} title={`${f.date ?? ''} · ${f.feel ?? 'not asked'}`} className="h-3 flex-1 rounded-full" style={{ background: f.feel ? feelColor[f.feel] : '#E2E8F0' }} />)}
          </div>
        </div>
      )}

      {p.goals.length > 0 && (
        <div>
          <div className="mb-1 text-[11px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Goals · {p.goals.filter((g) => g.done_on).length} of {p.goals.length} reached</div>
          <ul className="space-y-1">
            {p.goals.map((g) => (
              <li key={g.text}>
                <button
                  type="button"
                  disabled={readOnly || busy}
                  aria-pressed={!!g.done_on}
                  onClick={() => onPost({ goal: g.text, done: !g.done_on })}
                  className={cn('flex w-full items-center gap-2 rounded-xl px-2.5 py-1.5 text-left text-[13px] font-semibold disabled:cursor-default', FOCUS)}
                  style={{ background: g.done_on ? '#E6F6F3' : '#F7F8FC', color: g.done_on ? '#0E7C6B' : '#1D2E4A' }}
                >
                  <span className="grid h-5 w-5 shrink-0 place-items-center rounded-full text-[11px] font-bold text-white" style={{ background: g.done_on ? '#0E9F87' : '#CBD5E1' }}>{g.done_on ? '✓' : ''}</span>
                  <span className="flex-1">{g.text}</span>
                  {g.done_on && <span className="text-[11.5px] font-bold">{new Date(`${g.done_on}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}</span>}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div>
        <div className="mb-1 flex flex-wrap items-center gap-1.5">
          <span className="text-[11px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Next review</span>
          <span className="text-[12.5px] font-bold text-[#1D2E4A]">{p.review_on ? new Date(`${p.review_on}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' }) : 'Not set'}</span>
          {!readOnly && reviewOptions.map((o) => (
            <button key={o.days} type="button" disabled={busy} aria-pressed={p.review_on === o.date} onClick={() => onPost({ review_on: o.date })} className={cn('h-7 rounded-full px-2.5 text-[12px] font-bold', FOCUS)} style={{ background: p.review_on === o.date ? fg : '#F1F4F9', color: p.review_on === o.date ? '#FFFFFF' : '#475569' }}>
              {o.days === 30 ? 'in 1 month' : `in ${o.days / 7} week${o.days > 7 ? 's' : ''}`}
            </button>
          ))}
        </div>
      </div>

      <div>
        <div className="mb-1 text-[11px] font-bold uppercase tracking-[0.05em] text-cx-text-2">Progress notes</div>
        {!readOnly && (
          <div className="mb-2 flex flex-wrap gap-1.5">
            <input aria-label={`Progress note for ${c.title}`} value={note} maxLength={2000} placeholder="e.g. Stairs easier, add step-ups" onChange={(e) => setNote(e.target.value)} className={cn('h-9 min-w-[180px] flex-1 rounded-full border border-[#DCE6F3] bg-white px-3.5 text-[13px]', FOCUS)} />
            <button type="button" aria-pressed={review} onClick={() => setReview((r) => !r)} className={cn('h-9 rounded-full px-3 text-[12px] font-bold', FOCUS)} style={{ background: review ? '#6B48DE' : '#F1F4F9', color: review ? '#FFFFFF' : '#475569' }}>Review</button>
            <button type="button" disabled={busy || note.trim() === ''} onClick={() => { onPost({ note: note.trim(), review }); setNote(''); setReview(false); }} className={cn(BTN_PRIMARY, 'h-9')}>Add</button>
          </div>
        )}
        {p.notes.length === 0 ? (
          <p className="text-[12.5px] text-cx-text-2">No notes yet.</p>
        ) : (
          <ul className="space-y-1">
            {p.notes.slice(0, 6).map((n, i) => (
              <li key={i} className="flex flex-wrap gap-x-2 text-[12.5px]">
                <span className="font-bold text-[#1D2E4A]">{new Date(`${n.on}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}</span>
                {n.review && <span className="rounded-full bg-cx-violet-bg px-2 text-[11px] font-bold text-cx-violet-ink">Review</span>}
                <span className="flex-1 text-[#1D2E4A]">{n.text}</span>
                <span className="text-cx-text-2">{n.by}</span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

/** Follow-ups (spec §21): the patient's open ones, and one more in a tap — a reason and when. */
function FollowUpCard({ followUps, url, suggestion, today, readOnly }: { followUps: Props['physio']['follow_ups']; url: string; suggestion: string; today: string; readOnly: boolean }) {
  const [reason, setReason] = useState(suggestion);
  const [due, setDue] = useState(addDays(today, 14));
  const [busy, setBusy] = useState(false);
  const [open, setOpen] = useState(false);
  const options = [7, 14, 30, 90].map((days) => ({ days, date: addDays(today, days), label: days === 30 ? '1 month' : days === 90 ? '3 months' : `${days / 7} week${days > 7 ? 's' : ''}` }));

  return (
    <section className={cn(CARD, 'p-4 sm:p-5')}>
      <div className={cn('flex items-center gap-2.5', (open || followUps.length > 0) && 'mb-3')}>
        <IconTile icon={CalendarCheck2} tone="violet" />
        <div className="min-w-0 flex-1">
          <h2 className="text-[15px] font-semibold leading-5 text-cx-nav-ink">Follow-up</h2>
          <p className="text-[12px] leading-4 text-cx-text-3">A review reminder for later</p>
        </div>
        {!readOnly && !open && <button type="button" onClick={() => setOpen(true)} className={cn(BTN, 'h-9 px-4')}>Add</button>}
      </div>
      {followUps.length > 0 && (
        <ul className="mb-3 space-y-1 text-[13px]">
          {followUps.map((f) => (
            <li key={f.id} className="flex justify-between gap-2 rounded-xl bg-[#F7F8FC] px-3 py-1.5">
              <span className="min-w-0 truncate font-semibold text-[#1D2E4A]">{f.reason || 'Follow-up'}</span>
              <span className="shrink-0 font-bold text-cx-violet-ink">{f.due_on ? dayLabel(f.due_on, today) : '—'}</span>
            </li>
          ))}
        </ul>
      )}
      {!readOnly && open && (
        <div className="space-y-2">
          <input aria-label="Follow-up reason" value={reason} maxLength={500} placeholder="Reason, e.g. knee review" onChange={(e) => setReason(e.target.value)} className={cn('h-10 w-full rounded-xl border border-[#DCE6F3] px-3 text-[13px]', FOCUS)} />
          <div className="flex flex-wrap gap-1.5">
            {options.map((o) => (
              <button key={o.days} type="button" aria-pressed={due === o.date} onClick={() => setDue(o.date)} className={cn('h-8 rounded-full px-3 text-[12px] font-bold', FOCUS)} style={{ background: due === o.date ? '#6B48DE' : '#F1F4F9', color: due === o.date ? '#FFFFFF' : '#475569' }}>
                {o.label}
              </button>
            ))}
          </div>
          <button
            type="button"
            disabled={busy}
            onClick={() => router.post(url, { due_on: due, reason: reason.trim() || null } as never, { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
            className={cn(BTN, 'w-full')}
          >
            Add follow-up · {dayLabel(due, today)}
          </button>
        </div>
      )}
    </section>
  );
}

/**
 * What the desk collects once the visit is finished, on the page itself: this visit's charges (billed and paid in
 * one step, at the clinic's prices) and any bill with a balance, each with its own Collect. Nothing to collect says so.
 */
function PaymentCard({ payment, patientName }: { payment: DeskPayment; patientName: string }) {
  const [paying, setPaying] = useState<DeskPaymentItem | null>(null);
  const total = Math.round(payment.items.reduce((n, item) => n + item.amount, 0) * 100) / 100;

  return (
    <section id="visit-payment" className={cn(CARD, 'p-4 sm:p-5')}>
      <div className="mb-3 flex items-center gap-2.5">
        <IconTile icon={Wallet} tone="mint" />
        <div className="min-w-0 flex-1">
          <h2 className="text-[15px] font-semibold leading-5 text-cx-nav-ink">Payment</h2>
          <p className="text-[12px] leading-4 text-cx-text-3">{total > 0 ? `${rupee(total)} to collect` : 'Nothing to collect'}</p>
        </div>
      </div>
      {payment.items.length === 0 ? (
        <p className="rounded-xl bg-[#F7F8FC] px-3 py-3 text-[13px] text-cx-text-2">Today’s session is covered by the package, and no bill is open.</p>
      ) : (
        <ul className="divide-y divide-[#EEF2F7] rounded-2xl border border-[#EEF2F7]">
          {payment.items.map((item) => (
            <li key={item.key} className="flex items-center gap-3 px-3.5 py-2.5">
              <span className="min-w-0 flex-1">
                <span className="block text-[13.5px] font-semibold text-[#1D2E4A]">{item.label}</span>
                {item.detail && <span className="block text-[12px] text-cx-text-3">{item.detail}</span>}
              </span>
              <button type="button" onClick={() => setPaying(item)} className={cn(BTN_PRIMARY, 'h-9 shrink-0 px-4 text-[13px]')}>
                Collect {rupee(item.amount)}
              </button>
            </li>
          ))}
        </ul>
      )}
      {paying && (
        <CollectDialog
          item={{ key: paying.key, name: patientName, detail: [paying.label, paying.detail].filter(Boolean).join(' · '), amount: paying.amount, url: paying.url, bills: paying.bills }}
          billing={payment}
          onClose={() => setPaying(null)}
        />
      )}
    </section>
  );
}

/** What is booked after a finished visit: the coming visits and any review reminders, so the page never ends on an empty box. */
function ComingVisitsCard({ upcoming, followUps, toBook, calendar, today }: { upcoming: { date: string; time: string }[]; followUps: Props['physio']['follow_ups']; toBook: number; calendar: string; today: string }) {
  const next = upcoming[0] ?? null;

  return (
    <section className={cn(CARD, 'p-4 sm:p-5')}>
      <div className="mb-3 flex items-center gap-2.5">
        <IconTile icon={CalendarDays} tone="blue" />
        <div className="min-w-0 flex-1">
          <h2 className="text-[15px] font-semibold leading-5 text-cx-nav-ink">Coming visits</h2>
          <p className="text-[12px] leading-4 text-cx-text-3">{next ? `${upcoming.length} booked · next ${dayLabel(next.date, today)}, ${time12(next.time)}` : 'None booked yet'}</p>
        </div>
      </div>
      {upcoming.length > 0 && (
        <div className="flex flex-wrap gap-1">
          {upcoming.slice(0, 6).map((u) => (
            <span key={`${u.date}${u.time}`} className="rounded-lg bg-[#F5F8FF] px-2 py-1 text-[12px] font-semibold text-[#1D2E4A]">{new Date(`${u.date}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })} · {time12(u.time)}</span>
          ))}
          {upcoming.length > 6 && <span className="px-1 py-1 text-[12px] font-semibold text-cx-blue-ink">+{upcoming.length - 6} more</span>}
        </div>
      )}
      {toBook > 0 && <p className="mt-3 rounded-xl bg-cx-amber-bg px-3 py-2 text-[12.5px] font-semibold text-cx-amber-ink">{toBook} {toBook === 1 ? 'session has' : 'sessions have'} no visit booked yet.</p>}
      {followUps.length > 0 && (
        <ul className="mt-3 space-y-1 text-[13px]">
          {followUps.map((f) => (
            <li key={f.id} className="flex justify-between gap-2 rounded-xl bg-[#F7F8FC] px-3 py-1.5">
              <span className="min-w-0 truncate font-semibold text-[#1D2E4A]">{f.reason || 'Follow-up'}</span>
              <span className="shrink-0 font-bold text-cx-violet-ink">{f.due_on ? dayLabel(f.due_on, today) : '—'}</span>
            </li>
          ))}
        </ul>
      )}
      <Link href={calendar} className={cn('mt-3 inline-block rounded text-[12px] font-bold text-[#1769AA]', FOCUS)}>Open the calendar →</Link>
    </section>
  );
}

/** One exercise as the plan will prescribe it (`PhysioConsultation::prescribe`). */
type PlanExercise = { key: string; sets: number | null; reps: number | null; hold: number | null; per_day: number | null };

/** A problem's usual exercises (its setup), with the library's sets, reps and hold. */
function defaultExercises(problem: Problem | null, library: LibraryExercise[]): PlanExercise[] {
  return (problem?.exercises ?? []).flatMap((key) => {
    const item = library.find((x) => x.key === key);

    return item ? [{ key, sets: item.sets, reps: item.reps, hold: item.hold, per_day: 2 }] : [];
  });
}

/**
 * The doctor's plan, in three steps (`ConsultationController::physioPlan`):
 * 1. the problem, from the clinic's list, and the side; 2. what was found,
 * by tapping, with the diagnosis from the clinic's list; 3. the plan, filled
 * from the problem's setup — session type, how often, how many, aim, goals,
 * advice, and per visit or a package — then Start (or keep it for later). A
 * problem already found opens at step 2; a running one changes its plan.
 */
function PlanWizard({
  wizard,
  problems,
  openKeys,
  sides,
  frequencies,
  choices,
  treatments,
  canSell,
  visitReason,
  url,
  onClose,
  library,
}: {
  /** The clinic's exercise library. */
  library: LibraryExercise[];
  wizard: Wizard;
  problems: Problem[];
  /** "problem:side" of the problems already being treated, which are not offered again. */
  openKeys: string[];
  sides: Record<string, string>;
  frequencies: { key: string; label: string }[];
  choices: Choices;
  treatments: Map<string, Treatment>;
  canSell: boolean;
  visitReason: string | null;
  url: string;
  onClose: () => void;
}) {
  const course = wizard.mode === 'new' ? null : wizard.course;
  // The desk's reason ("Knee pain · Right") picks the problem when it names one of the list.
  const fromDesk = !course && visitReason ? problems.find((p) => visitReason.toLowerCase().startsWith(p.label.toLowerCase())) : undefined;
  const deskSide = fromDesk && visitReason ? (Object.entries(sides).find(([, name]) => visitReason.toLowerCase().endsWith(name.toLowerCase()))?.[0] ?? null) : null;
  const [problemKey, setProblemKey] = useState<string>(course?.problem ?? fromDesk?.key ?? '');
  const [side, setSide] = useState<string | null>(course?.side ?? deskSide);
  const problem = problems.find((p) => p.key === problemKey) ?? null;
  const saved = course?.details.complaint;
  const [form, setForm] = useState({
    duration: saved?.duration ?? null,
    severity: saved?.severity ?? null,
    difficulties: saved?.difficulties ?? ([] as string[]),
    previous: saved?.previous ?? ([] as string[]),
    description: saved?.description ?? '',
    diagnosis: course?.diagnosis ?? '',
    type: course?.type ?? problem?.types[0]?.key ?? '',
    frequency: course?.frequency ?? 'daily',
    sessions: course?.planned ?? 10,
    category: course?.details.plan?.category ?? null,
    goals: course?.goals ?? '',
    instructions: course?.details.plan?.instructions ?? '',
    package: null as string | null,
    // The exercises to prescribe: as prescribed now, else the problem's usual ones from the library.
    exercises: course && course.exercises.length > 0
      ? course.exercises.flatMap((e) => {
          const item = library.find((x) => x.label === e.name);

          return item ? [{ key: item.key, sets: e.sets, reps: e.reps, hold: e.hold, per_day: e.per_day }] : [];
        })
      : defaultExercises(problem, library),
  });
  const [step, setStep] = useState<1 | 2 | 3>(course ? (wizard.mode === 'edit' ? 3 : 2) : 1);
  const [adding, setAdding] = useState(false);
  const [search, setSearch] = useState('');
  const [area, setArea] = useState<string | null>(null);
  const [typing, setTyping] = useState(false);
  const [busy, setBusy] = useState(false);
  const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }));
  const types = problem?.types ?? [];
  const chosenType = types.find((t) => t.key === form.type) ?? types[0] ?? null;
  const chosenPackage = problem?.packages.find((p) => p.id === form.package) ?? null;
  const taken = (key: string, s: string | null) => openKeys.includes(`${key}:${s ?? ''}`) && !(course && course.problem === key && course.side === s);
  const problemReady = !!problem && (!problem.sided || !!side) && !taken(problemKey, problem.sided ? side : null);
  const diagnoses = Array.from(new Set([...(problem?.diagnoses ?? []), ...(course?.diagnosis ? [course.diagnosis] : [])]));
  const offerPackages = canSell && (problem?.packages.length ?? 0) > 0 && !course?.package;

  const pickProblem = (key: string) => {
    const next = problems.find((p) => p.key === key);
    setProblemKey(key);
    setSide(null);
    setForm((f) => ({ ...f, type: next?.types[0]?.key ?? '', diagnosis: '', package: null, exercises: defaultExercises(next ?? null, library) }));
  };
  const setExercise = (index: number, patch: Partial<PlanExercise>) => setForm((f) => ({ ...f, exercises: f.exercises.map((e, i) => (i === index ? { ...e, ...patch } : e)) }));
  const offered = library.filter(
    (x) => !form.exercises.some((e) => e.key === x.key) && (!area || x.category === area) && (search.trim() === '' || x.label.toLowerCase().includes(search.trim().toLowerCase())),
  );

  const submit = (start: boolean) =>
    router.post(
      url,
      {
        ...(course ? { course_id: course.id } : { problem: problemKey, side: problem?.sided ? side : null }),
        complaint: { duration: form.duration, severity: form.severity, difficulties: form.difficulties, previous: form.previous, description: form.description, remarks: saved?.remarks ?? null },
        diagnosis: form.diagnosis.trim() || null,
        category: form.category,
        goals: form.goals,
        instructions: form.instructions,
        type: chosenType?.key ?? null,
        frequency: form.frequency,
        sessions: chosenPackage ? null : form.sessions,
        package: form.package,
        exercises: form.exercises,
        start,
      } as never,
      { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose },
    );

  const STEPS_LIST: { n: 1 | 2 | 3; label: string }[] = [
    { n: 1, label: 'Problem' },
    { n: 2, label: 'Assessment' },
    { n: 3, label: 'Plan' },
  ];
  const title = course ? course.title : problem ? `${problem.label}${problem.sided && side ? ` · ${sides[side]}` : ''}` : 'New problem';

  return (
    <Modal
      open
      onOpenChange={(open) => !open && !busy && onClose()}
      title={wizard.mode === 'edit' ? `Plan · ${title}` : title}
      description={wizard.mode === 'edit' ? 'Change what you found or the plan; every coming visit follows it.' : `Tap through ${course ? 'two' : 'three'} steps. Everything is filled from your clinic’s setup.`}
      size="lg"
      footer={
        <>
          {step > (course ? 2 : 1) ? (
            <Button tone="ghost" disabled={busy} onClick={() => setStep((s) => (s - 1) as 1 | 2 | 3)}>Back</Button>
          ) : (
            <Button tone="ghost" disabled={busy} onClick={onClose}>Cancel</Button>
          )}
          {step < 3 ? (
            <Button tone="primary" disabled={step === 1 && !problemReady} onClick={() => setStep((s) => (s + 1) as 1 | 2 | 3)}>Next</Button>
          ) : wizard.mode === 'edit' ? (
            <Button tone="primary" loading={busy} disabled={!chosenType} onClick={() => submit(false)}>Save plan</Button>
          ) : (
            <>
              <Button tone="ghost" disabled={busy || !chosenType} onClick={() => submit(false)}>Start later</Button>
              <Button tone="primary" loading={busy} disabled={!chosenType} onClick={() => submit(true)}>Start treatment</Button>
            </>
          )}
        </>
      }
    >
      <ol className="mb-4 flex gap-1.5" aria-label="Steps">
        {STEPS_LIST.filter((s) => !course || s.n > 1).map((s, index) => {
          const on = step === s.n;
          const done = step > s.n;

          return (
            <li key={s.n} className="flex-1">
              <button
                type="button"
                disabled={s.n > step && !(s.n === 2 && problemReady) && !(s.n === 3 && problemReady)}
                onClick={() => setStep(s.n)}
                aria-current={on ? 'step' : undefined}
                className={cn('flex h-10 w-full items-center justify-center gap-2 rounded-full text-[12.5px] font-bold disabled:opacity-50', FOCUS)}
                style={{ background: on ? '#1D2E4A' : done ? '#E6F6F3' : '#F1F4F9', color: on ? '#FFFFFF' : done ? '#0E7C6B' : '#475569' }}
              >
                <span className="grid h-5 w-5 place-items-center rounded-full bg-white/25 text-[11px]">{done ? '✓' : index + 1}</span>
                {s.label}
              </button>
            </li>
          );
        })}
      </ol>

      {step === 1 && (
        <div className="space-y-4">
          <Field label="Problem" htmlFor="w-problem" required>
            <Select id="w-problem" value={problemKey} placeholder="Choose the problem" onChange={(e) => pickProblem(e.target.value)} options={problems.map((p) => ({ value: p.key, label: p.label }))} />
          </Field>
          {problem?.sided && (
            <div>
              <div className="mb-1.5 text-[12.5px] font-bold text-[#475569]">Side</div>
              <div className="flex gap-2">
                {Object.entries(sides).map(([key, name]) => {
                  const on = side === key;
                  const busyAlready = taken(problemKey, key);

                  return (
                    <button key={key} type="button" disabled={busyAlready} aria-pressed={on} onClick={() => setSide(key)} className={cn('h-11 flex-1 rounded-full text-[13.5px] font-bold disabled:cursor-not-allowed disabled:opacity-50', FOCUS)} style={{ background: on ? '#6B48DE' : '#F1F4F9', color: on ? '#FFFFFF' : '#334155' }}>
                      {name}{busyAlready ? ' · already treated' : ''}
                    </button>
                  );
                })}
              </div>
            </div>
          )}
          {problem && !problem.sided && taken(problemKey, null) && <p className="text-[12.5px] font-semibold text-cx-amber-ink">This problem is already being treated.</p>}
          {fromDesk && <p className="text-[12px] text-cx-text-2">Chosen from the desk’s reason: “{visitReason}”.</p>}
        </div>
      )}

      {step === 2 && (
        <div className="space-y-3">
          <ChipRow label="Since" options={choices.durations} value={form.duration ? [form.duration] : []} onChange={(v) => set({ duration: v[0] ?? null })} color="#2B6FD8" readOnly={false} />
          <ChipRow label="Pain" options={choices.severities} value={form.severity ? [form.severity] : []} onChange={(v) => set({ severity: v[0] ?? null })} color="#E9821A" readOnly={false} />
          <ChipRow label="Difficulty" options={choices.difficulties} value={form.difficulties} many onChange={(v) => set({ difficulties: v })} color="#6B48DE" readOnly={false} />
          <ChipRow label="Tried before" options={choices.previous} value={form.previous} many onChange={(v) => set({ previous: v })} color="#64748B" readOnly={false} />
          <div className="grid grid-cols-12 items-center gap-2">
            <span className="col-span-12 text-[12px] font-bold text-cx-text-2 sm:col-span-3">Diagnosis</span>
            <div className="col-span-12 flex flex-wrap items-center gap-1.5 sm:col-span-9">
              {diagnoses.map((d) => {
                const on = form.diagnosis === d;

                return (
                  <button key={d} type="button" aria-pressed={on} onClick={() => set({ diagnosis: on ? '' : d })} className={cn('h-9 rounded-full px-3.5 text-[12.5px] font-bold', FOCUS)} style={{ background: on ? '#0E9F87' : '#F1F4F9', color: on ? '#FFFFFF' : '#334155' }}>
                    {d}
                  </button>
                );
              })}
              {typing ? (
                <input aria-label="Another diagnosis" autoFocus value={diagnoses.includes(form.diagnosis) ? '' : form.diagnosis} maxLength={255} placeholder="Type the diagnosis" onChange={(e) => set({ diagnosis: e.target.value })} className={cn('h-9 min-w-[200px] flex-1 rounded-full border border-[#DCE6F3] px-3.5 text-[13px]', FOCUS)} />
              ) : (
                <button type="button" onClick={() => setTyping(true)} className={cn('h-9 rounded-full border border-dashed border-[#C9D3E1] px-3.5 text-[12.5px] font-bold text-[#1769AA]', FOCUS)}>+ Other</button>
              )}
            </div>
          </div>
          <textarea aria-label="Note" rows={2} value={form.description} maxLength={2000} placeholder="Anything else you found (optional)" onChange={(e) => set({ description: e.target.value })} className={cn('w-full resize-y rounded-xl border border-[#DCE6F3] px-3 py-2 text-[13px]', FOCUS)} />
        </div>
      )}

      {step === 3 && (
        <div className="space-y-4">
          <div>
            <div className="mb-1.5 text-[12.5px] font-bold text-[#475569]">Session type · what each visit gives</div>
            <div className="grid gap-2 sm:grid-cols-2">
              {types.map((t) => {
                const on = chosenType?.key === t.key;

                return (
                  <button key={t.key} type="button" aria-pressed={on} onClick={() => set({ type: t.key })} className={cn('rounded-2xl border-2 px-3 py-2.5 text-left', FOCUS)} style={{ borderColor: on ? '#6B48DE' : '#E6EBF1', background: on ? '#F1EDFD' : '#FFFFFF' }}>
                    <span className="flex justify-between gap-2 text-[14px] font-bold text-[#1D2E4A]">{t.label}<span>{priceLabel(t.price)}</span></span>
                    <span className="mt-0.5 block text-[12px] text-[#64748B]">{t.treatments.map((k) => treatments.get(k)?.label).filter(Boolean).join(' · ') || 'No treatments set'}</span>
                  </button>
                );
              })}
            </div>
          </div>
          <div className="grid grid-cols-12 items-center gap-3">
            <div className="col-span-12 sm:col-span-8">
              <div className="mb-1.5 text-[12.5px] font-bold text-[#475569]">How often</div>
              <div className="flex flex-wrap gap-1.5">
                {frequencies.map((f) => {
                  const on = form.frequency === f.key;

                  return (
                    <button key={f.key} type="button" aria-pressed={on} onClick={() => set({ frequency: f.key })} className={cn('h-9 rounded-full px-3.5 text-[12.5px] font-bold', FOCUS)} style={{ background: on ? '#2B6FD8' : '#F1F4F9', color: on ? '#FFFFFF' : '#334155' }}>
                      {f.label}
                    </button>
                  );
                })}
              </div>
            </div>
            <div className="col-span-12 sm:col-span-4">
              <div className="mb-1.5 text-[12.5px] font-bold text-[#475569]">Sessions</div>
              <div className="flex items-center gap-2">
                <button type="button" aria-label="Fewer sessions" disabled={!!chosenPackage} onClick={() => set({ sessions: Math.max(1, form.sessions - 1) })} className={cn('grid h-10 w-10 place-items-center rounded-full bg-[#F1F4F9] text-[18px] font-bold disabled:opacity-40', FOCUS)}>−</button>
                <span className="min-w-10 text-center text-[18px] font-bold tabular-nums text-[#1D2E4A]">{chosenPackage ? chosenPackage.sessions : form.sessions}</span>
                <button type="button" aria-label="More sessions" disabled={!!chosenPackage} onClick={() => set({ sessions: Math.min(500, form.sessions + 1) })} className={cn('grid h-10 w-10 place-items-center rounded-full bg-[#F1F4F9] text-[18px] font-bold disabled:opacity-40', FOCUS)}>+</button>
              </div>
            </div>
          </div>
          <ChipRow label="Aim" options={choices.categories} value={form.category ? [form.category] : []} onChange={(v) => set({ category: v[0] ?? null })} color="#0E9F87" readOnly={false} />
          <div className="grid gap-2 md:grid-cols-2">
            <input aria-label="Goals" value={form.goals} maxLength={1000} placeholder="Goals (optional), e.g. stairs without pain" onChange={(e) => set({ goals: e.target.value })} className={cn('h-10 w-full rounded-xl border border-[#DCE6F3] px-3 text-[13px]', FOCUS)} />
            <input aria-label="Advice to the patient" value={form.instructions} maxLength={2000} placeholder="Advice (optional), e.g. ice after walking" onChange={(e) => set({ instructions: e.target.value })} className={cn('h-10 w-full rounded-xl border border-[#DCE6F3] px-3 text-[13px]', FOCUS)} />
          </div>

          {/* The exercises: the problem's usual ones, from the clinic's library; change, remove or add. */}
          <div>
            <div className="mb-1.5 flex items-center justify-between gap-2">
              <span className="text-[12.5px] font-bold text-[#475569]">Exercises · home and in the clinic</span>
              <button type="button" onClick={() => setAdding((a) => !a)} className={cn('rounded-full px-2 text-[12.5px] font-bold text-[#1769AA]', FOCUS)}>{adding ? 'Done' : '+ Add exercise'}</button>
            </div>
            {form.exercises.length === 0 && !adding && <p className="rounded-xl bg-[#F7F8FC] px-3 py-2.5 text-[12.5px] text-cx-text-2">No exercises. Tap “+ Add exercise” to pick from your clinic’s library.</p>}
            <ul className="space-y-1.5">
              {form.exercises.map((e, index) => {
                const item = library.find((x) => x.key === e.key);

                return (
                  <li key={e.key} className="flex flex-wrap items-center gap-2 rounded-[14px] bg-[#EAF1FD] px-3 py-2 text-[13px]">
                    <span className="min-w-[140px] flex-1 font-bold text-[#1D2E4A]">{item?.label ?? e.key}</span>
                    <span className="flex items-center gap-1 font-semibold text-[#1D2E4A]">
                      <button type="button" aria-label={`${item?.label}: fewer sets`} onClick={() => setExercise(index, { sets: Math.max(1, (e.sets ?? 1) - 1) })} className={cn('grid h-7 w-7 place-items-center rounded-full bg-white font-bold', FOCUS)}>−</button>
                      <span className="min-w-4 text-center tabular-nums">{e.sets ?? '—'}</span>
                      <button type="button" aria-label={`${item?.label}: more sets`} onClick={() => setExercise(index, { sets: Math.min(50, (e.sets ?? 0) + 1) })} className={cn('grid h-7 w-7 place-items-center rounded-full bg-white font-bold', FOCUS)}>+</button>
                      <span className="text-cx-text-2">×</span>
                      <input aria-label={`${item?.label}: reps`} type="number" min={1} max={500} value={e.reps ?? ''} onChange={(ev) => setExercise(index, { reps: ev.target.value === '' ? null : Number(ev.target.value) })} className={cn('h-7 w-14 rounded-full border-0 bg-white px-2 text-center text-[13px] font-bold', FOCUS)} />
                    </span>
                    <span className="flex gap-1" role="group" aria-label={`${item?.label}: times a day`}>
                      {[1, 2, 3].map((n) => (
                        <button key={n} type="button" aria-pressed={e.per_day === n} onClick={() => setExercise(index, { per_day: n })} className={cn('h-7 rounded-full px-2.5 text-[12px] font-bold', FOCUS)} style={{ background: e.per_day === n ? '#2B6FD8' : '#FFFFFF', color: e.per_day === n ? '#FFFFFF' : '#475569' }}>
                          {n}×/day
                        </button>
                      ))}
                    </span>
                    <button type="button" aria-label={`Remove ${item?.label}`} onClick={() => setForm((f) => ({ ...f, exercises: f.exercises.filter((_, i) => i !== index) }))} className={cn('grid h-7 w-7 place-items-center rounded-full bg-white text-[13px] font-bold text-cx-rose-ink', FOCUS)}>✕</button>
                  </li>
                );
              })}
            </ul>
            {adding && (
              <div className="mt-2 rounded-[14px] border border-[#DCE6F3] p-2.5">
                <input aria-label="Search exercises" autoFocus value={search} placeholder="Search: squat, neck, stretch…" onChange={(e) => setSearch(e.target.value)} className={cn('h-9 w-full rounded-full border border-[#DCE6F3] px-3.5 text-[13px]', FOCUS)} />
                <div className="mt-2 flex flex-wrap gap-1">
                  {choices.exercise_categories.filter((c) => library.some((x) => x.category === c.key)).map((c) => (
                    <button key={c.key} type="button" aria-pressed={area === c.key} onClick={() => setArea(area === c.key ? null : c.key)} className={cn('h-7 rounded-full px-2.5 text-[12px] font-bold', FOCUS)} style={{ background: area === c.key ? '#1D2E4A' : '#F1F4F9', color: area === c.key ? '#FFFFFF' : '#475569' }}>
                      {c.label}
                    </button>
                  ))}
                </div>
                <div className="mt-2 flex max-h-44 flex-wrap gap-1.5 overflow-y-auto">
                  {offered.map((x) => (
                    <button key={x.key} type="button" onClick={() => setForm((f) => ({ ...f, exercises: [...f.exercises, { key: x.key, sets: x.sets, reps: x.reps, hold: x.hold, per_day: 2 }] }))} className={cn('h-8 rounded-full border border-[#C9D7F0] bg-white px-3 text-[12.5px] font-semibold text-[#1D2E4A] hover:bg-[#EAF1FD]', FOCUS)}>
                      + {x.label}
                    </button>
                  ))}
                  {offered.length === 0 && <span className="text-[12.5px] text-cx-text-2">Nothing more matches. The owner adds exercises in Settings.</span>}
                </div>
              </div>
            )}
          </div>

          {wizard.mode !== 'edit' || offerPackages ? (
            <div>
              <div className="mb-1.5 text-[12.5px] font-bold text-[#475569]">Payment</div>
              <div className="flex flex-col gap-2">
                <button type="button" aria-pressed={form.package === null} onClick={() => set({ package: null })} className={cn('flex items-center justify-between rounded-2xl border-2 px-3 py-2.5 text-left', FOCUS)} style={{ borderColor: form.package === null ? '#1769AA' : '#E6EBF1', background: form.package === null ? '#EEF4FF' : '#FFFFFF' }}>
                  <span className="text-[14px] font-bold text-[#1D2E4A]">{course?.package ? `Keep ${course.package.name}` : 'Pay per visit'}</span>
                  {!course?.package && <span className="text-[14px] font-bold text-[#1D2E4A]">{priceLabel(chosenType?.price ?? 0)} a visit</span>}
                </button>
                {offerPackages &&
                  problem!.packages.map((p) => {
                    const on = form.package === p.id;
                    const full = (chosenType?.price ?? 0) * p.sessions;

                    return (
                      <button key={p.id} type="button" aria-pressed={on} onClick={() => set({ package: p.id })} className={cn('flex items-center justify-between gap-2 rounded-2xl border-2 px-3 py-2.5 text-left', FOCUS)} style={{ borderColor: on ? '#0E9F87' : '#E6EBF1', background: on ? '#F0FBF8' : '#FFFFFF' }}>
                        <span>
                          <span className="block text-[14px] font-bold text-[#1D2E4A]">{p.name}</span>
                          <span className="block text-[12px] text-[#64748B]">{p.sessions} sessions · valid {p.valid_days} days</span>
                        </span>
                        <span className="text-right">
                          <span className="block text-[15px] font-bold text-[#1D2E4A]">{rupee(p.price)}</span>
                          {full > p.price && <span className="block text-[11.5px] font-bold text-[#0E7C6B]">Save {rupee(full - p.price)}</span>}
                        </span>
                      </button>
                    );
                  })}
                {!canSell && (problem?.packages.length ?? 0) > 0 && <p className="text-[12px] text-[#64748B]">Packages are sold by the owner or billing; the desk can add one.</p>}
              </div>
            </div>
          ) : null}
        </div>
      )}
    </Modal>
  );
}
