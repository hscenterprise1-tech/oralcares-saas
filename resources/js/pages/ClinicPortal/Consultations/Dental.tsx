import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CalendarCheck2, Check, ClipboardList, FileText, History, MessageSquareText, Pill, Plus, Search, ShieldAlert, Smile, Stethoscope, UserRound, Wallet, Wrench, X, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { BTN, BTN_PRIMARY, FOCUS, IconTile, Pill as Badge, type Tone } from '../Patients/components/patient360/v2/ui';
import { CarePanel } from './ClinicalPanels';
import { DentalSpecialty, type SpecialtyData } from './DentalSpecialty';

type Condition = { label: string; tone: string; surface: boolean; category: string | null };
type Tooth = { tooth: number; status: string; surfaces: string[]; notes: string | null };
type Service = { id: number; code: string | null; name: string; category: string | null; price: number | null; visits: number; step: number; stages: string[] };
/** Where a treatment of several visits stands (`DentalConsultation::progress`). */
type Progress = { sittings_total: number | null; sittings_done: number; next_stage: string | null; last_note: string | null; last_date: string | null; stages: { name: string; state: 'done' | 'next' | 'later' }[] };
type PlanItem = { id: number; description: string; tooth: number | null; surfaces: string[]; price: number | null; visits: number; step: number } & Progress;
type Plan = { id: number; title: string; status: string; estimate_url: string; items: PlanItem[] };
type HistoryRow = { id: number; date: string | null; doctor: string | null; complaint: string | null; work: string[] };
type Booking = { duration: number; days: { date: string; times: string[] }[]; upcoming: { date: string; time: string }[] };
type RxLine = { drug_name: string; dosage: string; frequency: string; duration: string; instructions: string | null };
type RxTemplate = { key: string; label: string; lines: RxLine[] };

/** `ConsultationController::renderDental` (`DentalConsultation::payload`): the server is the contract. */
type Props = {
  encounter: { id: number; number: string; started_at: string | null; doctor: string | null; branch: string | null; case_number: string | null };
  patient: { id: number; name: string; uhid: string | null; age: number | null; gender: string | null };
  visit: { specialty: string | null; type: string | null; reason: string | null } | null;
  dental: {
    consultation: { complaint: string | null; findings: string | null; advice: string | null; diagnoses: string[] };
    complaints: { key: string; label: string }[];
    teeth: Tooth[];
    conditions: Record<string, Condition>;
    surfaces: Record<string, string>;
    services: Service[];
    plans: Plan[];
    /** Treatments begun on earlier visits and not finished. */
    continuing: ({ id: number; label: string; tooth: number | null } & Progress)[];
    today: { id: number; name: string; tooth: number | null }[];
    history: HistoryRow[];
    booking: Booking;
    specialty: SpecialtyData;
    alerts: { kind: 'allergy' | 'condition'; label: string }[];
    suggestions: Record<string, { diagnosis: string; services: string[] }>;
    rx: {
      can_prescribe: boolean;
      templates: RxTemplate[];
      allergy: { status: 'documented' | 'none_known' | 'not_recorded'; allergies: { label: string; severity: string | null; reaction: string | null }[]; fingerprint: string };
      issued: { number: string | null; print_url: string } | null;
    };
    finished: boolean;
    to_bill: number;
  };
  care: Parameters<typeof CarePanel>[0]['care'] | null;
  can_edit: boolean;
  /** Set when the desk runs this visit for a dentist who has no login. */
  desk: { dentist: string } | null;
  links: { finish: string; summary: string; record: string | null; patient: string; calendar: string; today: string; checkout: string | null };
};

/** One line of the visit's work: an item of an open plan (`id`), or a new one from the clinic's services. */
type Work = {
  key: string; id: number | null; procedure_id: number | null; label: string; tooth: number | null; surfaces: string[]; price: number | null; visits: number; step: number; done: boolean;
  /** Visits already done, the stages' names, what was written last time, and today's note. */
  sittingsDone: number; stages: string[]; lastNote: string | null; lastDate: string | null; note: string;
};

const stageOf = (w: Work) => w.stages[w.sittingsDone] ?? `Sitting ${w.sittingsDone + 1}`;
const isLastSitting = (w: Work) => w.sittingsDone + 1 >= w.visits;
/** What today's visit of a treatment adds to the bill: its share of the price, the last visit taking the remainder (as the server does). */
const amountToday = (w: Work) => {
  if (w.price === null) {
    return 0;
  }
  if (w.visits <= 1) {
    return w.price;
  }
  const share = Math.round((w.price / w.visits) * 100) / 100;

  return isLastSitting(w) ? Math.round((w.price - share * (w.visits - 1)) * 100) / 100 : share;
};
const QUICK_NOTES = ['Canals located', 'Cleaning done', 'Dressing placed', 'Obturation done', 'Impression taken', 'Crown fitted', 'Pain settled', 'Patient comfortable'];

const CARD = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';
const FIELD = 'w-full rounded-xl border border-[#DCE6F3] bg-white px-3 text-[13.5px] text-[#1D2E4A] placeholder:text-[#94A3B8] focus:border-[#1769AA] focus:outline-none disabled:bg-[#F6F8FC]';
const UPPER = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
const LOWER = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];
const NAMES: Record<number, string> = { 1: 'central incisor', 2: 'lateral incisor', 3: 'canine', 4: 'first premolar', 5: 'second premolar', 6: 'first molar', 7: 'second molar', 8: 'wisdom tooth' };
/** A condition's tone (`config/clinic_dental.php`) as the portal's soft colours. */
const TONES: Record<string, { bg: string; ink: string; solid: string }> = {
  neutral: { bg: '#FFFFFF', ink: '#475569', solid: '#94A3B8' },
  danger: { bg: '#FDECEC', ink: '#B42318', solid: '#E5484D' },
  warning: { bg: '#FFF3DC', ink: '#9A4A06', solid: '#E9821A' },
  brand: { bg: '#EAF1FF', ink: '#1D4ED8', solid: '#2B6FD8' },
  amber: { bg: '#FFF3DC', ink: '#9A4A06', solid: '#E9821A' },
  teal: { bg: '#E3F6F4', ink: '#0F6B64', solid: '#0E9F87' },
  violet: { bg: '#F0ECFE', ink: '#5B2BC4', solid: '#6B48DE' },
  muted: { bg: '#EEF1F5', ink: '#344054', solid: '#64748B' },
};
const toneOf = (c: Condition | undefined) => TONES[c?.tone ?? 'neutral'] ?? TONES.neutral;
const rupee = (n: number) => `₹${Math.round(n).toLocaleString('en-IN')}`;
const initials = (name: string) => name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('');
const time12 = (time: string) => {
  const [h, m] = time.split(':').map(Number);

  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
};
const dayLabel = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
const toothName = (n: number) => `${n < 30 || (n > 50 && n < 70) ? 'Upper' : 'Lower'} ${[1, 4, 5, 8].includes(Math.floor(n / 10)) ? 'right' : 'left'} ${NAMES[n % 10] ?? 'tooth'}`;
const BLANK_LINE: RxLine = { drug_name: '', dosage: '', frequency: '', duration: '', instructions: null };

export default function DentalConsultation(props: Props) {
  return (
    <ClinicPortalShell title={`${props.patient.name} · Dental`}>
      <DentalPage {...props} />
    </ClinicPortalShell>
  );
}

type Tab = 'today' | 'specialty' | 'history' | 'treatments';

/**
 * The dental workspace as one screen: why the patient came, what is found on which tooth (and what is usually done about
 * it, one tap away), the work done today, the diagnosis it adds up to, the prescription and the next visit. One bar at
 * the bottom signs the visit; nothing needs scrolling to find the way out.
 */
function DentalPage({ encounter, patient, visit, dental, care, can_edit: canEdit, desk, links }: Props) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const readOnly = !canEdit || dental.finished;
  const [tab, setTab] = useState<Tab>('today');
  const [busy, setBusy] = useState(false);

  const [complaintKeys, setComplaintKeys] = useState<string[]>([]);
  const [complaintText, setComplaintText] = useState(dental.consultation.complaint ?? visit?.reason ?? '');
  const [findingsText, setFindingsText] = useState(dental.consultation.findings ?? '');
  const [diagnosisText, setDiagnosisText] = useState(dental.consultation.diagnoses[0] ?? '');
  const [diagnosisTouched, setDiagnosisTouched] = useState(false);
  const [advice, setAdvice] = useState(dental.consultation.advice ?? '');
  const [showExam, setShowExam] = useState(Boolean(dental.consultation.findings));

  const charted = useMemo(() => new Map(dental.teeth.map((t) => [t.tooth, t])), [dental.teeth]);
  /** Findings made on this visit: tooth → condition (the chart as saved is `charted`). */
  const [findings, setFindings] = useState<Record<number, { condition: string; surfaces: string[] }>>({});
  const [selected, setSelected] = useState<number | null>(null);
  const stateOf = (tooth: number) => findings[tooth]?.condition ?? charted.get(tooth)?.status ?? 'sound';

  /** A treatment already begun is what the patient came back for, so today's visit of it starts ticked. */
  const continuingIds = useMemo(() => new Set(dental.continuing.map((c) => c.id)), [dental.continuing]);
  const [work, setWork] = useState<Work[]>(() =>
    dental.plans.flatMap((plan) => plan.items.map((item) => ({
      key: `i${item.id}`, id: item.id, procedure_id: null, label: item.description, tooth: item.tooth, surfaces: item.surfaces, price: item.price,
      visits: item.sittings_total ?? item.visits, step: item.step, done: continuingIds.has(item.id) && !dental.finished,
      sittingsDone: item.sittings_done, stages: item.stages.map((s) => s.name), lastNote: item.last_note, lastDate: item.last_date, note: '',
    }))),
  );
  const patchWork = (key: string, patch: Partial<Work>) => setWork((all) => all.map((x) => (x.key === key ? { ...x, ...patch } : x)));
  const [serviceQuery, setServiceQuery] = useState('');
  const total = work.filter((w) => w.done).reduce((n, w) => n + amountToday(w), 0);

  const [rxLines, setRxLines] = useState<RxLine[]>([]);
  const [allergyChecked, setAllergyChecked] = useState(false);
  const allergyDocumented = dental.rx.allergy.status === 'documented';

  const firstDay = dental.booking.days.find((d) => d.times.length > 0) ?? null;
  const [book, setBook] = useState(false);
  const [booking, setBooking] = useState<{ date: string; time: string; step: number; count: number }>({ date: firstDay?.date ?? '', time: firstDay?.times[0] ?? '', step: 7, count: 1 });
  /** A treatment that is planned and needs several sittings: its visits are offered (and switched on) as booked, as the doctor plans it. */
  const sittings = useMemo(() => {
    // Visits still to book: those to come after today (today's own, if it is being done, is not booked again), less the ones already in the diary.
    const left = (w: Work) => w.visits - w.sittingsDone - (w.done ? 1 : 0) - dental.booking.upcoming.length;
    const multi = work.filter((w) => w.visits > 1 && left(w) > 0);

    return multi.length === 0 ? null : { count: Math.min(12, Math.max(...multi.map(left))), step: Math.min(...multi.map((w) => w.step)), names: multi.map((w) => w.label) };
  }, [work]);
  /** One tap for "the next sitting in a few days": the first free day from then on, at the time already chosen where that day has it. */
  const pickIn = (days: number) => {
    const from = new Date();
    from.setDate(from.getDate() + days);
    const wanted = from.toISOString().slice(0, 10);
    const day = dental.booking.days.find((d) => d.date >= wanted && d.times.length > 0) ?? firstDay;
    if (!day) {
      return;
    }
    setTouched(true);
    setBook(true);
    setBooking((b) => ({ ...b, date: day.date, time: day.times.includes(b.time) ? b.time : day.times[0], step: days >= 14 ? 14 : b.step }));
  };
  const [touched, setTouched] = useState(false);
  useEffect(() => {
    if (touched || readOnly) {
      return;
    }
    if (sittings && firstDay) {
      setBook(true);
      setBooking((b) => ({ ...b, count: sittings.count, step: sittings.step }));
    } else {
      setBook(false);
    }
  }, [sittings, touched, readOnly]); // eslint-disable-line react-hooks/exhaustive-deps
  const times = dental.booking.days.find((d) => d.date === booking.date)?.times ?? [];

  const complaintLabel = (keys: string[]) => keys.map((key) => dental.complaints.find((c) => c.key === key)?.label).filter(Boolean).join(', ');
  const toggleComplaint = (key: string) => {
    const keys = complaintKeys.includes(key) ? complaintKeys.filter((k) => k !== key) : [...complaintKeys, key];
    setComplaintKeys(keys);
    setComplaintText(complaintLabel(keys));
  };

  const setCondition = (tooth: number, condition: string) => {
    const surfaces = dental.conditions[condition]?.surface ? findings[tooth]?.surfaces ?? charted.get(tooth)?.surfaces ?? [] : [];
    setFindings((f) => ({ ...f, [tooth]: { condition, surfaces } }));
  };
  const toggleSurface = (tooth: number, surface: string) => {
    const current = findings[tooth] ?? { condition: stateOf(tooth), surfaces: charted.get(tooth)?.surfaces ?? [] };
    const surfaces = current.surfaces.includes(surface) ? current.surfaces.filter((s) => s !== surface) : [...current.surfaces, surface];
    setFindings((f) => ({ ...f, [tooth]: { ...current, surfaces } }));
  };
  /** Added at the chair, so it counts as done today; untick it to leave it on the plan only. */
  const addService = (service: Service, tooth: number | null) => {
    const surfaces = tooth ? findings[tooth]?.surfaces ?? [] : [];
    setWork((w) => [...w, { key: `n${Date.now()}${w.length}`, id: null, procedure_id: service.id, label: service.name, tooth, surfaces, price: service.price, visits: service.visits, step: service.step, done: true, sittingsDone: 0, stages: service.stages, lastNote: null, lastDate: null, note: '' }]);
    setServiceQuery('');
  };
  const matches = serviceQuery.trim().length > 0 ? dental.services.filter((s) => `${s.name} ${s.code ?? ''}`.toLowerCase().includes(serviceQuery.trim().toLowerCase())).slice(0, 6) : [];

  /** The clinic's own services that usually follow a finding (`config/clinic_dental.php` suggestions), in the order they are usually chosen. */
  const suggestionsFor = (condition: string): Service[] => {
    const needles = dental.suggestions[condition]?.services ?? [];
    const seen = new Set<number>();

    return needles.flatMap((needle) => dental.services.filter((s) => s.name.toLowerCase().includes(needle))).filter((s) => (seen.has(s.id) ? false : (seen.add(s.id), true))).slice(0, 4);
  };

  const changed = Object.entries(findings).filter(([tooth, f]) => f.condition !== (charted.get(Number(tooth))?.status ?? 'sound') || JSON.stringify(f.surfaces) !== JSON.stringify(charted.get(Number(tooth))?.surfaces ?? []));
  /** What this visit's findings add up to, so the doctor confirms a diagnosis instead of typing one. */
  const suggestedDiagnosis = [...new Set(changed.map(([, f]) => dental.suggestions[f.condition]?.diagnosis).filter(Boolean) as string[])].join(', ');
  const diagnosis = (diagnosisTouched ? diagnosisText : suggestedDiagnosis || diagnosisText).slice(0, 255);
  const doneCount = work.filter((w) => w.done).length;
  const finishLabel = busy ? 'Saving…' : doneCount === 0 ? 'Finish consultation' : `Finish visit · ${rupee(total)}`;
  const rxFilled = rxLines.filter((l) => l.drug_name.trim() !== '');
  const blockedByAllergy = rxFilled.length > 0 && allergyDocumented && !allergyChecked;

  const addTemplate = (template: RxTemplate) =>
    setRxLines((lines) => [...lines, ...template.lines.filter((line) => !lines.some((l) => l.drug_name === line.drug_name))]);

  const finish = () => {
    if (readOnly || busy || blockedByAllergy) {
      return;
    }
    router.post(
      links.finish,
      {
        consultation: { complaint: complaintText || null, findings: findingsText || null, diagnosis: diagnosis || null, advice: advice || null },
        findings: changed.map(([tooth, f]) => ({ tooth: Number(tooth), condition: f.condition, surfaces: f.surfaces })),
        work: work.map((w) => ({ id: w.id ?? undefined, procedure_id: w.procedure_id ?? undefined, tooth: w.tooth ?? undefined, surfaces: w.surfaces, done: w.done, visits: w.visits > 1 ? w.visits : undefined, sitting_note: w.visits > 1 && w.note.trim() ? w.note.trim() : undefined })),
        booking: book && booking.date && booking.time ? booking : null,
        prescription: rxFilled.length > 0 ? { items: rxFilled.map((l) => ({ ...l, instructions: l.instructions || null })), allergy_fingerprint: allergyDocumented ? dental.rx.allergy.fingerprint : undefined } : undefined,
      } as never,
      { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
    );
  };
  // Ctrl+Enter signs the visit from anywhere on the screen.
  const finishRef = useRef(finish);
  finishRef.current = finish;
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        finishRef.current();
      }
    };
    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  }, []);

  /**
   * What the doctor has typed or ticked is kept in this browser while the visit is open, so a refresh, a closed tab or a
   * dropped connection does not lose it. It is removed once the visit is signed, and the doctor can discard it.
   */
  const draftKey = `dental-draft:${encounter.id}`;
  const snapshot = { complaintKeys, complaintText, findingsText, diagnosisText, diagnosisTouched, advice, findings, rxLines, allergyChecked, work: work.map((w) => ({ key: w.key, id: w.id, procedure_id: w.procedure_id, label: w.label, tooth: w.tooth, surfaces: w.surfaces, price: w.price, visits: w.visits, step: w.step, done: w.done, note: w.note, stages: w.stages })) };
  const pristine = useRef<string | null>(null);
  pristine.current ??= JSON.stringify(snapshot);
  const hydrated = useRef(false);
  const [draftRestored, setDraftRestored] = useState(false);
  useEffect(() => {
    try {
      if (readOnly) {
        window.localStorage.removeItem(draftKey);
      } else {
        const raw = window.localStorage.getItem(draftKey);
        if (raw) {
          const d = JSON.parse(raw) as typeof snapshot;
          setComplaintKeys(d.complaintKeys ?? []);
          setComplaintText(d.complaintText ?? '');
          setFindingsText(d.findingsText ?? '');
          setDiagnosisText(d.diagnosisText ?? '');
          setDiagnosisTouched(Boolean(d.diagnosisTouched));
          setAdvice(d.advice ?? '');
          setShowExam(Boolean(d.findingsText) || showExam);
          setFindings(d.findings ?? {});
          setRxLines(d.rxLines ?? []);
          setAllergyChecked(Boolean(d.allergyChecked));
          setWork((current) => {
            const saved = new Map((d.work ?? []).map((w) => [w.key, w]));
            const kept = current.map((w) => (saved.has(w.key) ? { ...w, done: saved.get(w.key)!.done, note: saved.get(w.key)!.note ?? '', visits: saved.get(w.key)!.visits } : w));
            const added = (d.work ?? []).filter((w) => w.key.startsWith('n') && !current.some((c) => c.key === w.key)).map((w) => ({ ...w, sittingsDone: 0, lastNote: null, lastDate: null }));

            return [...kept, ...added] as Work[];
          });
          setDraftRestored(true);
        }
      }
    } catch {
      // Storage can be blocked or hold an old shape; the visit simply starts clean.
    }
    hydrated.current = true;
  }, []); // eslint-disable-line react-hooks/exhaustive-deps
  const serialised = JSON.stringify(snapshot);
  useEffect(() => {
    if (!hydrated.current || readOnly) {
      return;
    }
    const timer = window.setTimeout(() => {
      try {
        if (serialised === pristine.current) {
          window.localStorage.removeItem(draftKey);
        } else {
          window.localStorage.setItem(draftKey, serialised);
        }
      } catch {
        // Not saving the draft must never get in the way of the visit.
      }
    }, 600);

    return () => window.clearTimeout(timer);
  }, [serialised, readOnly, draftKey]);
  const discardDraft = () => {
    try {
      window.localStorage.removeItem(draftKey);
    } catch {
      // Nothing to remove.
    }
    window.location.reload();
  };

  const ageSex = [patient.age !== null ? `${patient.age} y` : null, patient.gender ? patient.gender[0].toUpperCase() + patient.gender.slice(1) : null].filter(Boolean).join(' · ');
  const next = dental.booking.upcoming[0] ?? null;
  const allergies = dental.alerts.filter((a) => a.kind === 'allergy');
  const conditions = dental.alerts.filter((a) => a.kind === 'condition');

  const TABS: { key: Tab; label: string; icon: LucideIcon }[] = [
    { key: 'today', label: 'Today', icon: Stethoscope },
    { key: 'specialty', label: 'Root canal · Implant · Braces · Lab', icon: Wrench },
    { key: 'history', label: 'History', icon: History },
    { key: 'treatments', label: 'Treatments & Packages', icon: Wallet },
  ];

  return (
    <div className="flex flex-col gap-3 pb-3">
      <header className="flex flex-col gap-2">
        <Link href={links.today} className={cn('inline-flex h-9 w-fit items-center gap-1.5 rounded-full px-2.5 text-[13px] font-semibold text-cx-text-2 transition-colors hover:bg-white/70 hover:text-cx-nav-ink', FOCUS)}>
          <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Today’s patients
        </Link>
        <section aria-label="Patient" className={cn(CARD, 'flex flex-col gap-3 p-3.5 sm:flex-row sm:items-center sm:justify-between sm:p-4')}>
          <div className="flex min-w-0 items-center gap-3.5">
            <span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-cx-blue-bg text-[16px] font-semibold text-cx-blue-ink" aria-hidden="true">{initials(patient.name)}</span>
            <div className="min-w-0">
              <h1 className="text-[18px] font-semibold leading-6 tracking-[-0.01em] text-[#1D2E4A] [overflow-wrap:anywhere]">{patient.name}{ageSex && <span className="ml-2 text-[13px] font-medium text-cx-text-2">{ageSex}</span>}</h1>
              <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[12px]">
                {patient.uhid && <span className="rounded-full bg-[#F1F5F9] px-2 py-0.5 font-mono font-semibold text-cx-blue-ink">{patient.uhid}</span>}
                {encounter.case_number && <span className="rounded-full bg-[#F1F5F9] px-2 py-0.5 font-mono font-semibold text-[#5B3BC4]">Case {encounter.case_number}</span>}
                {encounter.branch && <Badge tone="slate">{encounter.branch}</Badge>}
                {dental.finished && <Badge tone="mint">Visit finished</Badge>}
                {next && <span className="inline-flex items-center gap-1 text-cx-text-2"><CalendarCheck2 className="h-3.5 w-3.5" aria-hidden="true" /> Next {dayLabel(next.date)}, {time12(next.time)}</span>}
              </div>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-2 sm:justify-end">
            <Link href={links.patient} className={cn(BTN, 'h-9 text-[12.5px]')}><UserRound className="h-4 w-4" aria-hidden="true" /> Patient 360</Link>
            {links.record && <Link href={links.record} className={cn(BTN, 'h-9 text-[12.5px]')}><FileText className="h-4 w-4" aria-hidden="true" /> Full record</Link>}
          </div>
        </section>
        {dental.alerts.length > 0 && (
          <div role="note" aria-label="Medical alerts" className="flex flex-wrap items-center gap-1.5 rounded-2xl border border-[#F4C7C3] bg-[#FFF5F4] px-3.5 py-2.5">
            <ShieldAlert className="h-4 w-4 shrink-0 text-[#B42318]" aria-hidden="true" />
            {allergies.map((a) => <span key={`a${a.label}`} className="rounded-full bg-[#FDECEC] px-2.5 py-0.5 text-[12.5px] font-bold text-[#B42318]">Allergy: {a.label}</span>)}
            {conditions.map((a) => <span key={`c${a.label}`} className="rounded-full bg-[#FFF3DC] px-2.5 py-0.5 text-[12.5px] font-bold text-[#9A4A06]">{a.label}</span>)}
          </div>
        )}
      </header>

      {draftRestored && !readOnly && (
        <p role="status" className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-cx-amber-bg px-4 py-2.5 text-[13px] font-semibold text-cx-amber-ink">
          <span>We brought back what you had entered on this visit.</span>
          <button type="button" onClick={discardDraft} className={cn('rounded-full px-3 py-1 text-[12.5px] font-bold underline', FOCUS)}>Start again</button>
        </p>
      )}
      {desk && <p className="rounded-xl bg-cx-blue-bg px-4 py-3 text-[13px] font-semibold text-cx-blue-ink">You are recording this visit for {desk.dentist}, who does not sign in. Everything you save is in {desk.dentist}’s name, with you noted as who recorded it. Root canal, implant, braces and lab records stay with the dentist.</p>}
      {!desk && !canEdit && !dental.finished && <p className="rounded-xl bg-cx-amber-bg px-4 py-3 text-[13px] font-semibold text-cx-amber-ink">You can read this visit but only its doctor can record in it.</p>}
      {(errors?.dental || errors?.booking) && <p role="alert" className="rounded-xl bg-cx-rose-bg px-4 py-3 text-[13px] font-semibold text-cx-rose-ink">{errors.dental ?? errors.booking}</p>}

      <nav aria-label="Workspace" className="flex gap-1.5 overflow-x-auto rounded-full bg-white p-1 shadow-[0_10px_28px_-18px_rgba(30,50,110,0.28)] [scrollbar-width:none] sm:inline-flex sm:self-start">
        {TABS.map((item) => {
          const on = tab === item.key;
          const Icon = item.icon;

          return (
            <button key={item.key} type="button" aria-current={on ? 'page' : undefined} onClick={() => setTab(item.key)} className={cn('flex h-10 shrink-0 items-center gap-2 rounded-full px-3.5 text-[13px] font-bold transition-colors', FOCUS, on ? 'bg-[#1D2E4A] text-white' : 'text-[#475569] hover:bg-[#F1F5F9]')}>
              <Icon className="h-4 w-4" aria-hidden="true" /> <span className="whitespace-nowrap">{item.label}</span>
            </button>
          );
        })}
      </nav>

      {tab === 'specialty' && <DentalSpecialty data={dental.specialty} />}

      {tab === 'history' && (
        <Panel icon={History} tone="slate" title="Earlier visits" detail="Signed visits, newest first.">
          {dental.history.length === 0 ? <p className="text-[13px] text-cx-text-2">No earlier visit.</p> : (
            <ul className="space-y-2">
              {dental.history.map((h) => (
                <li key={h.id} className="rounded-2xl border border-[#E6EDF7] px-3.5 py-3">
                  <div className="flex justify-between gap-2 text-[13.5px] font-bold text-[#1D2E4A]"><span>{h.date ? dayLabel(h.date) : '—'}</span><span className="text-[12px] font-medium text-cx-text-3">{h.doctor}</span></div>
                  {h.complaint && <p className="text-[12.5px] text-cx-text-2">{h.complaint}</p>}
                  {h.work.length > 0 && <div className="mt-1.5 flex flex-wrap gap-1.5">{h.work.map((w, i) => <Badge key={i} tone="blue">{w}</Badge>)}</div>}
                </li>
              ))}
            </ul>
          )}
        </Panel>
      )}

      {tab === 'treatments' && (care ? <CarePanel care={care} encounterId={encounter.id} open={!readOnly} /> : <p className="text-[13px] text-cx-text-2">No treatment is running for this patient.</p>)}

      {tab === 'today' && (
        <div className="flex flex-col gap-3">
          {dental.continuing.length > 0 && (
            <Panel icon={Wrench} tone="amber" title="Continue treatment" detail="Where you left off. Today’s visit is ticked; untick it if you are not doing it today.">
              <ul className="flex flex-col gap-3">
                {work.filter((w) => w.id !== null && continuingIds.has(w.id)).map((w) => (
                  <li key={w.key} className="rounded-2xl border border-[#F2D9A8] bg-[#FFFBF2] p-3.5"><SittingBlock w={w} readOnly={readOnly} onPatch={(patch) => patchWork(w.key, patch)} lead /></li>
                ))}
              </ul>
            </Panel>
          )}

          <Panel icon={MessageSquareText} tone="amber" title="Complaint" detail="From the desk. Tap to add or change.">
            <div className="flex flex-wrap gap-1.5">
              {dental.complaints.map((c) => {
                const on = complaintKeys.includes(c.key);

                return (
                  <button key={c.key} type="button" disabled={readOnly} aria-pressed={on} onClick={() => toggleComplaint(c.key)} className={cn('inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-[12.5px] font-bold', FOCUS, on ? 'border-[#E9821A] bg-[#FFF3DC] text-[#9A4A06]' : 'border-[#DCE6F3] bg-white text-[#475569] hover:border-[#B9C6D8]')}>
                    {on && <Check className="h-3.5 w-3.5" aria-hidden="true" />} {c.label}
                  </button>
                );
              })}
            </div>
            <input value={complaintText} onChange={(e) => setComplaintText(e.target.value)} disabled={readOnly} placeholder="Complaint in the patient’s words" aria-label="Complaint" className={cn(FIELD, 'mt-2.5 h-10')} />
          </Panel>

          <Panel icon={Smile} tone="blue" title="Teeth" detail="Tap a tooth, then what you find. The usual treatments appear to add in one tap.">
            <ToothChart stateOf={stateOf} conditions={dental.conditions} selected={selected} onSelect={setSelected} />
            <div className="mt-3 border-t border-[#EEF2F7] pt-3">
              {selected === null ? (
                <p className="text-[13px] text-cx-text-2">Tap a tooth on the chart to record what you find.</p>
              ) : (
                <ToothPanel
                  tooth={selected}
                  state={stateOf(selected)}
                  surfaces={findings[selected]?.surfaces ?? charted.get(selected)?.surfaces ?? []}
                  dental={dental}
                  readOnly={readOnly}
                  suggestions={suggestionsFor(stateOf(selected))}
                  alreadyAdded={(service) => work.some((w) => w.tooth === selected && w.procedure_id === service.id)}
                  onCondition={(c) => setCondition(selected, c)}
                  onSurface={(s) => toggleSurface(selected, s)}
                  onAdd={(service) => addService(service, selected)}
                />
              )}
            </div>
          </Panel>

          <Panel icon={FileText} tone="violet" title="Work today" detail="Tick what you did. Anything unticked stays on the plan and is not billed.">
            {!readOnly && (
              <div className="relative mb-3">
                <Search className="pointer-events-none absolute left-3.5 top-3 h-4 w-4 text-[#94A3B8]" aria-hidden="true" />
                <input value={serviceQuery} onChange={(e) => setServiceQuery(e.target.value)} placeholder={selected ? `Add any service for tooth ${selected}…` : 'Add any service…'} aria-label="Add a service" className={cn(FIELD, 'h-10 pl-10')} />
                {matches.length > 0 && (
                  <ul className="absolute z-10 mt-1 w-full overflow-hidden rounded-2xl border border-[#DCE6F3] bg-white shadow-cx-card">
                    {matches.map((s) => (
                      <li key={s.id}>
                        <button type="button" onClick={() => addService(s, selected)} className="flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left text-[13px] hover:bg-[#F6F8FC]">
                          <span className="font-semibold text-[#1D2E4A]">{s.name}{s.category && <span className="ml-2 text-[11.5px] font-medium text-cx-text-3">{s.category}</span>}</span>
                          <span className="text-cx-text-2">{s.price ? rupee(s.price) : 'price not set'}</span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}
            {dental.plans.length > 0 && (
              <div className="mb-3 flex flex-wrap gap-2">
                {dental.plans.map((plan) => (
                  <a key={plan.id} href={plan.estimate_url} target="_blank" rel="noreferrer" className={cn(BTN, 'h-9 text-[12.5px]')}>
                    <FileText className="h-4 w-4" aria-hidden="true" /> Print estimate · {plan.title}
                  </a>
                ))}
              </div>
            )}
            {work.filter((w) => !(w.id !== null && continuingIds.has(w.id))).length === 0 ? <p className="rounded-2xl bg-[#F6F8FC] px-4 py-5 text-center text-[13px] text-cx-text-2">Nothing yet. Tap a tooth and a finding, or search a service above.</p> : (
              <ul className="flex flex-col gap-2">
                {work.filter((w) => !(w.id !== null && continuingIds.has(w.id))).map((w) => (
                  <li key={w.key} className={cn('flex flex-wrap items-center gap-3 rounded-2xl border px-3.5 py-2.5', w.done ? 'border-[#BDE7DF] bg-[#F4FBF9]' : 'border-[#E6EDF7] bg-white')}>
                    <button type="button" disabled={readOnly} aria-pressed={w.done} aria-label={`Done today: ${w.label}`} onClick={() => setWork((all) => all.map((x) => (x.key === w.key ? { ...x, done: !x.done } : x)))} className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-full border-2', FOCUS, w.done ? 'border-[#0E9F87] bg-[#0E9F87] text-white' : 'border-[#C9D3E1] text-transparent hover:border-[#0E9F87]')}><Check className="h-4 w-4" aria-hidden="true" /></button>
                    {w.tooth && <span className="grid h-9 min-w-9 place-items-center rounded-[11px] bg-cx-blue-bg px-1 text-[12.5px] font-extrabold text-cx-blue-ink">{w.tooth}</span>}
                    <div className="min-w-0 flex-1"><p className="text-[13.5px] font-bold text-[#1D2E4A]">{w.label}{w.surfaces.length > 0 && <span className="ml-1.5 text-[12px] font-medium text-cx-text-3">{w.surfaces.join('')}</span>}</p><p className="text-[12px] text-cx-text-3">{w.id ? 'From the plan' : 'New'} · {w.done ? (w.visits > 1 ? `visit ${w.sittingsDone + 1} of ${w.visits} today, ${rupee(amountToday(w))} billed` : 'done today, billed') : 'planned, not billed'}{w.visits > 1 && !w.done && ` · about ${w.visits} visits`}</p></div>
                    <strong className="text-[13.5px] text-[#1D2E4A]">{w.price ? rupee(w.price) : '—'}</strong>
                    {!readOnly && !w.id && <button type="button" aria-label={`Remove ${w.label}`} onClick={() => setWork((all) => all.filter((x) => x.key !== w.key))} className="grid h-8 w-8 place-items-center rounded-full text-[#94A3B8] hover:bg-cx-rose-bg hover:text-cx-rose-ink"><X className="h-4 w-4" aria-hidden="true" /></button>}
                    {w.visits > 1 && <div className="basis-full border-t border-[#E6EDF7] pt-2.5"><SittingBlock w={w} readOnly={readOnly} onPatch={(patch) => patchWork(w.key, patch)} /></div>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel icon={ClipboardList} tone="violet" title="Diagnosis and advice" detail="The diagnosis fills in from your findings. Change it if you like.">
            <div className="grid gap-3 sm:grid-cols-2">
              <Labelled label="Diagnosis"><input value={diagnosisTouched ? diagnosisText : diagnosis} onChange={(e) => { setDiagnosisTouched(true); setDiagnosisText(e.target.value); }} disabled={readOnly} placeholder="e.g. Dental caries" className={cn(FIELD, 'h-10')} /></Labelled>
              <Labelled label="Advice / next step"><input value={advice} onChange={(e) => setAdvice(e.target.value)} disabled={readOnly} placeholder="e.g. Avoid hot food for a day" className={cn(FIELD, 'h-10')} /></Labelled>
            </div>
            {showExam ? (
              <Labelled label="Examination"><textarea value={findingsText} onChange={(e) => setFindingsText(e.target.value)} disabled={readOnly} rows={2} className={cn(FIELD, 'mt-1 py-2.5')} /></Labelled>
            ) : !readOnly && <button type="button" onClick={() => setShowExam(true)} className={cn('mt-2.5 inline-flex h-8 items-center gap-1 rounded-full px-2.5 text-[12.5px] font-bold text-[#1769AA] hover:bg-[#F1F5F9]', FOCUS)}><Plus className="h-3.5 w-3.5" aria-hidden="true" /> Add examination notes</button>}
          </Panel>

          {dental.rx.can_prescribe && (
            <Panel icon={Pill} tone="teal" title="Prescription" detail="One tap adds the usual medicines. Read each line; change anything you need.">
              {dental.rx.issued ? (
                <p className="text-[13px] text-cx-text-2">Prescription {dental.rx.issued.number} was issued with this visit. <a href={dental.rx.issued.print_url} target="_blank" rel="noreferrer" className="font-bold text-[#1769AA] underline">Print it</a></p>
              ) : (
                <>
                  {!readOnly && (
                    <div className="mb-3 flex flex-wrap gap-1.5">
                      {dental.rx.templates.map((t) => <button key={t.key} type="button" onClick={() => addTemplate(t)} className={cn('inline-flex h-9 items-center gap-1.5 rounded-full border border-[#DCE6F3] bg-white px-3 text-[12.5px] font-bold text-[#475569] hover:border-[#B9C6D8]', FOCUS)}><Plus className="h-3.5 w-3.5" aria-hidden="true" />{t.label}</button>)}
                      <button type="button" onClick={() => setRxLines((l) => [...l, { ...BLANK_LINE }])} className={cn('inline-flex h-9 items-center gap-1.5 rounded-full px-3 text-[12.5px] font-bold text-[#1769AA] hover:bg-[#F1F5F9]', FOCUS)}><Plus className="h-3.5 w-3.5" aria-hidden="true" />Another medicine</button>
                    </div>
                  )}
                  {rxLines.length === 0 ? <p className="text-[13px] text-cx-text-2">No medicine. Nothing is prescribed unless you add one.</p> : (
                    <ul className="flex flex-col gap-2">
                      {rxLines.map((line, i) => {
                        const set = (patch: Partial<RxLine>) => setRxLines((all) => all.map((l, j) => (j === i ? { ...l, ...patch } : l)));

                        return (
                          <li key={i} className="grid gap-2 rounded-2xl border border-[#E6EDF7] p-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1.5fr)_minmax(0,1fr)_auto]">
                            <input aria-label="Medicine" maxLength={150} value={line.drug_name} onChange={(e) => set({ drug_name: e.target.value })} disabled={readOnly} placeholder="Medicine" className={cn(FIELD, 'h-9')} />
                            <input aria-label="Dose" maxLength={60} value={line.dosage} onChange={(e) => set({ dosage: e.target.value })} disabled={readOnly} placeholder="Dose" className={cn(FIELD, 'h-9')} />
                            <input aria-label="How often" maxLength={30} value={line.frequency} onChange={(e) => set({ frequency: e.target.value })} disabled={readOnly} placeholder="How often" className={cn(FIELD, 'h-9')} />
                            <input aria-label="For how long" maxLength={40} value={line.duration} onChange={(e) => set({ duration: e.target.value })} disabled={readOnly} placeholder="For how long" className={cn(FIELD, 'h-9')} />
                            {!readOnly && <button type="button" aria-label={`Remove ${line.drug_name || 'medicine'}`} onClick={() => setRxLines((all) => all.filter((_, j) => j !== i))} className="grid h-9 w-9 place-items-center rounded-full text-[#94A3B8] hover:bg-cx-rose-bg hover:text-cx-rose-ink"><X className="h-4 w-4" aria-hidden="true" /></button>}
                            {line.instructions && <p className="text-[12px] text-cx-text-2 sm:col-span-5">{line.instructions}</p>}
                          </li>
                        );
                      })}
                    </ul>
                  )}
                  {rxFilled.length > 0 && allergyDocumented && (
                    <label className="mt-3 flex cursor-pointer items-start gap-3 rounded-2xl border border-[#F4C7C3] bg-[#FFF5F4] px-3.5 py-3 text-[13px] text-[#7A271A]">
                      <input type="checkbox" checked={allergyChecked} onChange={(e) => setAllergyChecked(e.target.checked)} className="mt-0.5 h-5 w-5 accent-[#B42318]" />
                      <span><strong className="flex items-center gap-1"><AlertTriangle className="h-4 w-4" aria-hidden="true" /> Allergies on record: {dental.rx.allergy.allergies.map((a) => a.label).join(', ')}</strong>I have checked these against this prescription.</span>
                    </label>
                  )}
                  {rxFilled.length > 0 && dental.rx.allergy.status === 'not_recorded' && <p className="mt-2 text-[12px] font-semibold text-[#9A4A06]">No allergy is recorded for this patient. That is not the same as none: the prescription says so.</p>}
                </>
              )}
            </Panel>
          )}

          {!dental.finished && !readOnly && (
            <Panel icon={CalendarCheck2} tone="teal" title="Next visit" detail="Booked when you finish.">
              <label className="flex cursor-pointer items-center justify-between gap-3 rounded-2xl bg-[#F6F8FC] px-3.5 py-3 text-[13px] font-semibold text-cx-nav-ink">Book the next visit<input type="checkbox" checked={book} onChange={(e) => { setTouched(true); setBook(e.target.checked); }} className="h-5 w-5 accent-[#0E9F87]" /></label>
              {sittings && <p className="mt-2 rounded-2xl bg-cx-teal-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-cx-teal-ink">{sittings.names.join(', ')}: {sittings.count} more {sittings.count === 1 ? 'visit' : 'visits'} to come, booked for you every {sittings.step} days.</p>}
              {!readOnly && firstDay && (
                <div className="mt-2.5 flex flex-wrap items-center gap-1.5" role="group" aria-label="Next visit in">
                  <span className="text-[12px] font-semibold text-cx-text-2">Next visit in</span>
                  {[[3, '3 days'], [7, '1 week'], [14, '2 weeks']].map(([days, label]) => (
                    <button key={days} type="button" onClick={() => pickIn(Number(days))} className={cn('inline-flex h-9 items-center rounded-full border border-[#DCE6F3] bg-white px-3 text-[12.5px] font-bold text-[#475569] hover:border-[#B9C6D8]', FOCUS)}>{label}</button>
                  ))}
                </div>
              )}
              {book && (
                <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                  <Labelled label="Day"><select value={booking.date} onChange={(e) => setBooking({ ...booking, date: e.target.value, time: dental.booking.days.find((d) => d.date === e.target.value)?.times[0] ?? '' })} className="h-10 w-full rounded-full border border-[#DCE6F3] bg-white px-3 text-[13px]">{dental.booking.days.filter((d) => d.times.length > 0).map((d) => <option key={d.date} value={d.date}>{dayLabel(d.date)}</option>)}</select></Labelled>
                  <Labelled label="Time"><select value={booking.time} onChange={(e) => setBooking({ ...booking, time: e.target.value })} className="h-10 w-full rounded-full border border-[#DCE6F3] bg-white px-3 text-[13px]">{times.map((t) => <option key={t} value={t}>{time12(t)}</option>)}</select></Labelled>
                  <Labelled label="Visits"><select value={booking.count} onChange={(e) => setBooking({ ...booking, count: Number(e.target.value) })} className="h-10 w-full rounded-full border border-[#DCE6F3] bg-white px-3 text-[13px]">{[1, 2, 3, 4, 5, 6].map((n) => <option key={n} value={n}>{n}</option>)}</select></Labelled>
                  <Labelled label="Every"><select value={booking.step} onChange={(e) => setBooking({ ...booking, step: Number(e.target.value) })} className="h-10 w-full rounded-full border border-[#DCE6F3] bg-white px-3 text-[13px]">{[[3, '3 days'], [5, '5 days'], [7, 'week'], [14, '2 weeks'], [30, 'month']].map(([n, l]) => <option key={n} value={n}>{l}</option>)}</select></Labelled>
                </div>
              )}
              {!firstDay && <p className="mt-2 text-[12px] text-cx-text-3">No free time in the next three weeks: book from the calendar.</p>}
            </Panel>
          )}
        </div>
      )}

      {tab === 'today' && (
        <div className="sticky bottom-3 z-30 rounded-2xl border border-[#D5DEEC] bg-white/95 px-4 py-3 shadow-[0_14px_34px_-16px_rgba(30,50,110,0.45)] backdrop-blur">
          <div className="flex flex-wrap items-center gap-3">
            <div className="min-w-0 flex-1">
              <p className="text-[16px] font-bold leading-5 text-[#1D2E4A]">{dental.finished ? 'To pay' : 'To pay today'} {rupee(dental.finished ? dental.to_bill : total)}</p>
              <p className="truncate text-[12px] text-cx-text-2">
                {blockedByAllergy ? 'Confirm the allergy check above to finish.' : [changed.length > 0 ? `${changed.length} ${changed.length === 1 ? 'finding' : 'findings'}` : null, doneCount > 0 ? `${doneCount} done today` : null, rxFilled.length > 0 ? `${rxFilled.length} ${rxFilled.length === 1 ? 'medicine' : 'medicines'}` : null].filter(Boolean).join(' · ') || 'Nothing recorded yet'}
              </p>
            </div>
            {dental.finished ? (
              <>
                <a href={links.summary} target="_blank" rel="noopener" className={cn(BTN, 'h-11')}><FileText className="h-4 w-4" aria-hidden="true" /> Visit summary</a>
                {links.checkout && dental.to_bill > 0 && <Link href={links.checkout} className={cn(BTN_PRIMARY, 'h-11 px-6')}>Take payment</Link>}
                <Link href={links.today} className={cn(BTN, 'h-11')}>Next patient</Link>
              </>
            ) : (
              <button type="button" disabled={readOnly || busy || blockedByAllergy} onClick={finish} title="Ctrl + Enter" className={cn('h-12 rounded-full bg-[#0E9F87] px-7 text-[15px] font-bold text-white disabled:opacity-60', FOCUS)}>{finishLabel}</button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * A treatment of several visits, as the doctor needs it at the chair: which visit this is, its stages (done, today, to come),
 * what was written last time, and a note for next time (one tap per usual phrase). `lead` adds the treatment's own heading
 * and the tick for doing today's visit, for the "continue treatment" card.
 */
function SittingBlock({ w, readOnly, onPatch, lead = false }: { w: Work; readOnly: boolean; onPatch: (patch: Partial<Work>) => void; lead?: boolean }) {
  const last = isLastSitting(w);

  return (
    <div className="flex flex-col gap-2.5">
      {lead && (
        <div className="flex flex-wrap items-center gap-3">
          <button type="button" disabled={readOnly} aria-pressed={w.done} aria-label={`Doing visit ${w.sittingsDone + 1} of ${w.label} today`} onClick={() => onPatch({ done: !w.done })} className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-full border-2', FOCUS, w.done ? 'border-[#0E9F87] bg-[#0E9F87] text-white' : 'border-[#C9D3E1] bg-white text-transparent hover:border-[#0E9F87]')}><Check className="h-4 w-4" aria-hidden="true" /></button>
          {w.tooth && <span className="grid h-9 min-w-9 place-items-center rounded-[11px] bg-cx-blue-bg px-1 text-[12.5px] font-extrabold text-cx-blue-ink">{w.tooth}</span>}
          <div className="min-w-0 flex-1">
            <p className="text-[14px] font-bold text-[#1D2E4A]">{w.label}</p>
            <p className="text-[12.5px] text-cx-text-2">Visit {w.sittingsDone + 1} of {w.visits}: <strong className="text-[#1D2E4A]">{stageOf(w)}</strong></p>
          </div>
          {w.done && w.price !== null && <span className="text-[12.5px] font-semibold text-cx-text-2">Billed today {rupee(amountToday(w))}</span>}
        </div>
      )}
      <ol className="flex flex-wrap gap-1.5" aria-label="Visits of this treatment">
        {Array.from({ length: w.visits }, (_, i) => {
          const state = i < w.sittingsDone ? 'done' : i === w.sittingsDone ? 'today' : 'later';

          return (
            <li key={i} className={cn('inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[12px] font-bold', state === 'done' ? 'border-[#BDE7DF] bg-[#F4FBF9] text-[#0F6B64]' : state === 'today' ? 'border-[#1769AA] bg-[#EAF1FF] text-[#1D4ED8]' : 'border-[#E6EDF7] bg-white text-cx-text-3')}>
              {state === 'done' && <Check className="h-3 w-3" aria-hidden="true" />}{i + 1} · {w.stages[i] ?? `Sitting ${i + 1}`}{state === 'today' && ' · today'}
            </li>
          );
        })}
      </ol>
      {w.lastNote && <p className="rounded-xl bg-[#F6F8FC] px-3 py-2 text-[12.5px] text-cx-text-2"><strong className="text-[#1D2E4A]">Last time{w.lastDate ? ` (${dayLabel(w.lastDate)})` : ''}:</strong> {w.lastNote}</p>}
      {w.done && !readOnly && (
        <div className="flex flex-col gap-1.5">
          <input value={w.note} maxLength={500} onChange={(e) => onPatch({ note: e.target.value })} placeholder={last ? 'Note on the finished treatment (optional)' : 'Note for next time (optional)'} aria-label={`Note for ${w.label}`} className={cn(FIELD, 'h-9')} />
          <div className="flex flex-wrap gap-1.5">
            {QUICK_NOTES.map((phrase) => <button key={phrase} type="button" onClick={() => onPatch({ note: w.note ? `${w.note.replace(/[.\s]+$/, '')}. ${phrase}` : phrase })} className={cn('inline-flex h-7 items-center rounded-full border border-[#E6EDF7] bg-white px-2.5 text-[11.5px] font-semibold text-cx-text-2 hover:border-[#B9C6D8]', FOCUS)}>{phrase}</button>)}
          </div>
        </div>
      )}
      {last && w.done && <p className="text-[12.5px] font-semibold text-[#0F6B64]">This is the last visit: signing closes the treatment and charts the tooth.</p>}
    </div>
  );
}

function Panel({ icon, tone, title, detail, children }: { icon: LucideIcon; tone: Tone; title: string; detail?: string; children: ReactNode }) {
  return (
    <section className={cn(CARD, 'p-3.5 sm:p-4')}>
      <header className="mb-3 flex items-center gap-3"><IconTile icon={icon} tone={tone} /><div className="min-w-0"><h2 className="text-[15px] font-semibold leading-5 text-cx-nav-ink">{title}</h2>{detail && <p className="mt-0.5 text-[12px] leading-4 text-cx-text-3">{detail}</p>}</div></header>
      {children}
    </section>
  );
}

function Labelled({ label, children }: { label: string; children: ReactNode }) {
  return <label className="block text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">{label}<span className="mt-1 block normal-case tracking-normal">{children}</span></label>;
}

/**
 * The tooth chart. One component with one job (show a state per tooth, report a tap), so the 3D artwork can replace
 * this grid without touching the page.
 */
function ToothChart({ stateOf, conditions, selected, onSelect }: { stateOf: (tooth: number) => string; conditions: Record<string, Condition>; selected: number | null; onSelect: (tooth: number) => void }) {
  const row = (teeth: number[], label: string) => (
    <div>
      <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">{label}</p>
      <div className="scroll-x grid grid-cols-8 gap-1 sm:grid-cols-16 [grid-template-columns:repeat(16,minmax(34px,1fr))] overflow-x-auto pb-1">
        {teeth.map((tooth) => {
          const state = stateOf(tooth);
          const t = toneOf(conditions[state]);
          const on = selected === tooth;

          return (
            <button key={tooth} type="button" onClick={() => onSelect(tooth)} aria-pressed={on} aria-label={`Tooth ${tooth}, ${conditions[state]?.label ?? state}`} title={`${tooth} · ${conditions[state]?.label ?? state}`}
              className={cn('flex h-11 flex-col items-center justify-center gap-0.5 rounded-xl border text-[12.5px] font-extrabold transition-colors', FOCUS, on && 'ring-2 ring-[#1769AA] ring-offset-1')}
              style={{ background: t.bg, color: t.ink, borderColor: on ? '#1769AA' : `${t.solid}66` }}>
              {tooth}
              <span className="h-1.5 w-1.5 rounded-full" style={{ background: t.solid }} />
            </button>
          );
        })}
      </div>
    </div>
  );

  return <div className="flex flex-col gap-2 rounded-[18px] bg-[#F7FAFF] p-2.5 ring-1 ring-inset ring-[#E6EDF7]">{row(UPPER, 'Upper jaw')}{row(LOWER, 'Lower jaw')}</div>;
}

function ToothPanel({ tooth, state, surfaces, dental, readOnly, suggestions, alreadyAdded, onCondition, onSurface, onAdd }: { tooth: number; state: string; surfaces: string[]; dental: Props['dental']; readOnly: boolean; suggestions: Service[]; alreadyAdded: (service: Service) => boolean; onCondition: (condition: string) => void; onSurface: (surface: string) => void; onAdd: (service: Service) => void }) {
  const current = dental.conditions[state];
  const t = toneOf(current);
  const [showAll, setShowAll] = useState(false);
  const common = ['sound', 'caries', 'filled', 'root_canal', 'crown', 'extracted', 'missing', 'implant', 'fractured', 'abscess', 'mobile'];
  const keys = showAll ? Object.keys(dental.conditions) : common.filter((k) => dental.conditions[k]);

  return (
    <>
      <div className="mb-3 flex items-center gap-3">
        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-[14px] text-[17px] font-extrabold" style={{ background: t.bg, color: t.ink, boxShadow: `inset 0 0 0 1.5px ${t.solid}66` }}>{tooth}</span>
        <div className="min-w-0"><h3 className="text-[14.5px] font-semibold leading-5 text-cx-nav-ink">{toothName(tooth)}</h3><span className="mt-0.5 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11.5px] font-bold" style={{ background: t.bg, color: t.ink }}><span className="h-2 w-2 rounded-full" style={{ background: t.solid }} />{current?.label ?? state}</span></div>
      </div>
      <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">Finding</p>
      <div className="flex flex-wrap gap-1.5">
        {keys.map((key) => {
          const ct = toneOf(dental.conditions[key]);
          const on = state === key;

          return (
            <button key={key} type="button" disabled={readOnly} aria-pressed={on} onClick={() => onCondition(key)} className={cn('inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-[12.5px] font-bold', FOCUS)} style={on ? { background: ct.bg, color: ct.ink, borderColor: ct.solid } : { background: '#fff', color: '#475569', borderColor: '#DCE6F3' }}>
              <span className="h-2.5 w-2.5 rounded-full" style={{ background: ct.solid }} />{dental.conditions[key].label}
            </button>
          );
        })}
        <button type="button" onClick={() => setShowAll((v) => !v)} className={cn('inline-flex h-9 items-center gap-1 rounded-full px-3 text-[12.5px] font-bold text-[#1769AA]', FOCUS)}><Plus className="h-3.5 w-3.5" aria-hidden="true" />{showAll ? 'Fewer' : 'More'}</button>
      </div>
      {current?.surface && (
        <>
          <p className="mb-2 mt-3 text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">Surfaces</p>
          <div className="flex gap-1.5">
            {Object.entries(dental.surfaces).map(([key, label]) => (
              <button key={key} type="button" disabled={readOnly} aria-pressed={surfaces.includes(key)} title={label} onClick={() => onSurface(key)} className={cn('grid h-10 w-10 place-items-center rounded-full border text-[13px] font-extrabold', FOCUS, surfaces.includes(key) ? 'border-[#2B6FD8] bg-[#EAF1FF] text-[#1D4ED8]' : 'border-[#DCE6F3] bg-white text-[#475569]')}>{key}</button>
            ))}
          </div>
        </>
      )}
      {!readOnly && suggestions.length > 0 && (
        <>
          <p className="mb-2 mt-3 text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">Usually done for {current?.label?.toLowerCase() ?? 'this'} · tap to add</p>
          <div className="flex flex-wrap gap-1.5">
            {suggestions.map((service) => {
              const added = alreadyAdded(service);

              return (
                <button key={service.id} type="button" disabled={added} onClick={() => onAdd(service)} className={cn('inline-flex h-10 items-center gap-1.5 rounded-full border px-3.5 text-[13px] font-bold', FOCUS, added ? 'border-[#BDE7DF] bg-[#F4FBF9] text-[#0F6B64]' : 'border-[#0E9F87] bg-white text-[#0F6B64] hover:bg-[#F4FBF9]')}>
                  {added ? <Check className="h-4 w-4" aria-hidden="true" /> : <Plus className="h-4 w-4" aria-hidden="true" />}{service.name}{service.price ? <span className="font-medium text-[#475569]"> · {rupee(service.price)}</span> : null}
                </button>
              );
            })}
          </div>
        </>
      )}
    </>
  );
}

// Re-exported for the Patient 360 dental section, which reads a tooth the same way.
export { toothName };
