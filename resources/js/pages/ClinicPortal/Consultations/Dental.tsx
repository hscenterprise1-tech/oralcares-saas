import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Activity, ArrowLeft, CalendarCheck2, Check, ClipboardList, FileText, History, MessageSquareText, Plus, Search, Smile, Stethoscope, UserRound, Wallet, Wrench, X, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { BTN, BTN_PRIMARY, FOCUS, IconTile, Pill, type Tone } from '../Patients/components/patient360/v2/ui';
import { CarePanel } from './ClinicalPanels';
import { DentalSpecialty, type SpecialtyData } from './DentalSpecialty';

type Condition = { label: string; tone: string; surface: boolean; category: string | null };
type Tooth = { tooth: number; status: string; surfaces: string[]; notes: string | null };
type Service = { id: number; code: string | null; name: string; category: string | null; price: number | null; visits: number; step: number };
type PlanItem = { id: number; description: string; tooth: number | null; surfaces: string[]; price: number | null; visits: number; step: number };
type Plan = { id: number; title: string; status: string; estimate_url: string; items: PlanItem[] };
type HistoryRow = { id: number; date: string | null; doctor: string | null; complaint: string | null; work: string[] };
type Booking = { duration: number; days: { date: string; times: string[] }[]; upcoming: { date: string; time: string }[] };

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
    today: { id: number; name: string; tooth: number | null }[];
    history: HistoryRow[];
    booking: Booking;
    specialty: SpecialtyData;
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
type Work = { key: string; id: number | null; procedure_id: number | null; label: string; tooth: number | null; surfaces: string[]; price: number | null; visits: number; step: number; done: boolean };

const CARD = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';
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

export default function DentalConsultation(props: Props) {
  return (
    <ClinicPortalShell title={`${props.patient.name} · Dental`}>
      <DentalPage {...props} />
    </ClinicPortalShell>
  );
}

type Tab = 'today' | 'specialty' | 'history' | 'treatments';

/**
 * The dental workspace: why the patient came, what is found on which tooth,
 * what to do (a plan item from before, or a service of the clinic), what was
 * done today, and the next visits — then Finish signs the visit and books them.
 */
function DentalPage({ encounter, patient, visit, dental, care, can_edit: canEdit, desk, links }: Props) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const readOnly = !canEdit || dental.finished;
  const [tab, setTab] = useState<Tab>('today');
  const [busy, setBusy] = useState(false);

  const [complaintKeys, setComplaintKeys] = useState<string[]>([]);
  const [complaintText, setComplaintText] = useState(dental.consultation.complaint ?? visit?.reason ?? '');
  const [findingsText, setFindingsText] = useState(dental.consultation.findings ?? '');
  const [diagnosis, setDiagnosis] = useState(dental.consultation.diagnoses[0] ?? '');
  const [advice, setAdvice] = useState(dental.consultation.advice ?? '');

  const charted = useMemo(() => new Map(dental.teeth.map((t) => [t.tooth, t])), [dental.teeth]);
  /** Findings made on this visit: tooth → condition (the chart as saved is `charted`). */
  const [findings, setFindings] = useState<Record<number, { condition: string; surfaces: string[] }>>({});
  const [selected, setSelected] = useState<number | null>(null);
  const stateOf = (tooth: number) => findings[tooth]?.condition ?? charted.get(tooth)?.status ?? 'sound';

  const [work, setWork] = useState<Work[]>(() =>
    dental.plans.flatMap((plan) => plan.items.map((item) => ({ key: `i${item.id}`, id: item.id, procedure_id: null, label: item.description, tooth: item.tooth, surfaces: item.surfaces, price: item.price, visits: item.visits, step: item.step, done: false }))),
  );
  const [serviceQuery, setServiceQuery] = useState('');
  const total = work.filter((w) => w.done).reduce((n, w) => n + (w.price ?? 0), 0);

  const firstDay = dental.booking.days.find((d) => d.times.length > 0) ?? null;
  const [book, setBook] = useState(false);
  const [booking, setBooking] = useState<{ date: string; time: string; step: number; count: number }>({ date: firstDay?.date ?? '', time: firstDay?.times[0] ?? '', step: 7, count: 1 });
  /** A treatment that is planned and needs several sittings: its visits are offered (and switched on) as booked, as the doctor plans it. */
  const sittings = useMemo(() => {
    const multi = work.filter((w) => !w.done && w.visits > 1);

    return multi.length === 0 ? null : { count: Math.max(...multi.map((w) => w.visits)), step: Math.min(...multi.map((w) => w.step)), names: multi.map((w) => w.label) };
  }, [work]);
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
  const addService = (service: Service, tooth: number | null) => {
    const surfaces = tooth ? findings[tooth]?.surfaces ?? [] : [];
    setWork((w) => [...w, { key: `n${Date.now()}${w.length}`, id: null, procedure_id: service.id, label: service.name, tooth, surfaces, price: service.price, visits: service.visits, step: service.step, done: false }]);
    setServiceQuery('');
  };
  const matches = serviceQuery.trim().length > 0 ? dental.services.filter((s) => `${s.name} ${s.code ?? ''}`.toLowerCase().includes(serviceQuery.trim().toLowerCase())).slice(0, 6) : [];

  const changed = Object.entries(findings).filter(([tooth, f]) => f.condition !== (charted.get(Number(tooth))?.status ?? 'sound') || JSON.stringify(f.surfaces) !== JSON.stringify(charted.get(Number(tooth))?.surfaces ?? []));
  const doneCount = work.filter((w) => w.done).length;
  const finishLabel = busy ? 'Saving…' : doneCount === 0 ? 'Finish consultation' : `Finish visit · ${rupee(total)}`;

  const finish = () =>
    router.post(
      links.finish,
      {
        consultation: { complaint: complaintText || null, findings: findingsText || null, diagnosis: diagnosis || null, advice: advice || null },
        findings: changed.map(([tooth, f]) => ({ tooth: Number(tooth), condition: f.condition, surfaces: f.surfaces })),
        work: work.map((w) => ({ id: w.id ?? undefined, procedure_id: w.procedure_id ?? undefined, tooth: w.tooth ?? undefined, surfaces: w.surfaces, done: w.done })),
        booking: book && booking.date && booking.time ? booking : null,
      } as never,
      { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
    );

  const ageSex = [patient.age !== null ? `${patient.age} y` : null, patient.gender ? patient.gender[0].toUpperCase() + patient.gender.slice(1) : null].filter(Boolean).join(' · ');
  const withFindings = new Set([...dental.teeth.filter((t) => t.status !== 'sound').map((t) => t.tooth), ...Object.keys(findings).filter((k) => findings[Number(k)].condition !== 'sound').map(Number)]).size;
  const next = dental.booking.upcoming[0] ?? null;

  const TABS: { key: Tab; label: string; icon: LucideIcon }[] = [
    { key: 'today', label: 'Today', icon: Stethoscope },
    { key: 'specialty', label: 'Root canal · Implant · Braces · Lab', icon: Wrench },
    { key: 'history', label: 'History', icon: History },
    { key: 'treatments', label: 'Treatments & Packages', icon: Wallet },
  ];

  return (
    <div className="flex flex-col gap-4">
      <header>
        <Link href={links.today} className={cn('mb-2 inline-flex h-9 items-center gap-1.5 rounded-full px-2.5 text-[13px] font-semibold text-cx-text-2 transition-colors hover:bg-white/70 hover:text-cx-nav-ink', FOCUS)}>
          <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Today’s patients
        </Link>
        <section aria-label="Patient" className="relative isolate overflow-clip rounded-[26px] border border-white/80 shadow-[0_2px_6px_rgba(15,23,42,0.05),0_24px_48px_-30px_rgba(40,40,120,0.4)]">
          <div className="absolute inset-0 -z-10 bg-[linear-gradient(118deg,#E2F6F5_0%,#E8F0FF_52%,#EFE9FE_100%)]" aria-hidden="true" />
          <div className="absolute -right-24 -top-24 -z-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,rgba(44,198,180,0.22),transparent_70%)]" aria-hidden="true" />
          <div className="flex flex-col gap-5 px-5 pb-5 pt-5 sm:px-7 lg:flex-row lg:items-start lg:justify-between">
            <div className="flex min-w-0 items-center gap-4">
              <span className="grid h-16 w-16 shrink-0 place-items-center rounded-[20px] bg-white text-[20px] font-semibold text-cx-blue-ink shadow-[0_8px_20px_-12px_rgba(30,50,110,0.5)] ring-4 ring-white/60 sm:h-[72px] sm:w-[72px] sm:text-[22px]" aria-hidden="true">{initials(patient.name)}</span>
              <div className="min-w-0">
                <h1 className="text-[22px] font-semibold leading-7 tracking-[-0.02em] text-[#1D2E4A] [overflow-wrap:anywhere] sm:text-[26px] sm:leading-8">{patient.name}</h1>
                <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                  {patient.uhid && <span className="rounded-full bg-white px-2.5 py-0.5 font-mono text-[12px] font-semibold text-cx-blue-ink shadow-[inset_0_0_0_1px_rgba(43,111,216,0.18)]">{patient.uhid}</span>}
                  {encounter.case_number && <span className="rounded-full bg-white px-2.5 py-0.5 font-mono text-[12px] font-semibold text-[#5B3BC4] shadow-[inset_0_0_0_1px_rgba(107,72,222,0.2)]">Case {encounter.case_number}</span>}
                  {ageSex && <Pill tone="slate" className="bg-white/80">{ageSex}</Pill>}
                  <Pill tone="blue">Dental</Pill>
                  {encounter.branch && <Pill tone="slate" className="bg-white/80">{encounter.branch}</Pill>}
                  {dental.finished && <Pill tone="mint">Visit finished</Pill>}
                </div>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-2 sm:justify-end">
              <Link href={links.patient} className={BTN}><UserRound className="h-4 w-4" aria-hidden="true" /> Patient 360</Link>
              {links.record && <Link href={links.record} className={BTN}><FileText className="h-4 w-4" aria-hidden="true" /> Full record</Link>}
            </div>
          </div>
          <dl className="grid grid-cols-1 gap-2 px-4 pb-4 sm:grid-cols-3 sm:gap-2.5 sm:px-7 sm:pb-5">
            <Fact icon={ClipboardList} tone="amber" label="Complaint">{complaintText || visit?.reason || 'Not recorded'}</Fact>
            <Fact icon={Activity} tone="blue" label="Teeth with findings">{withFindings} {withFindings === 1 ? 'tooth' : 'teeth'}</Fact>
            <Fact icon={CalendarCheck2} tone="teal" label="Next visit">{next ? `${dayLabel(next.date)}, ${time12(next.time)}` : 'Not booked'}</Fact>
          </dl>
        </section>
      </header>

      {desk && <p className="rounded-xl bg-cx-blue-bg px-4 py-3 text-[13px] font-semibold text-cx-blue-ink">You are recording this visit for {desk.dentist}, who does not sign in. Everything you save is in {desk.dentist}’s name, with you noted as who recorded it. Root canal, implant, braces and lab records stay with the dentist.</p>}
      {!desk && !canEdit && !dental.finished && <p className="rounded-xl bg-cx-amber-bg px-4 py-3 text-[13px] font-semibold text-cx-amber-ink">You can read this visit but only its doctor can record in it.</p>}
      {(errors?.dental || errors?.booking) && <p role="alert" className="rounded-xl bg-cx-rose-bg px-4 py-3 text-[13px] font-semibold text-cx-rose-ink">{errors.dental ?? errors.booking}</p>}

      <nav aria-label="Workspace" className="flex gap-1.5 overflow-x-auto rounded-full bg-white p-1.5 shadow-[0_10px_28px_-18px_rgba(30,50,110,0.28)] [scrollbar-width:none] sm:inline-flex sm:self-start">
        {TABS.map((item) => {
          const on = tab === item.key;
          const Icon = item.icon;

          return (
            <button key={item.key} type="button" aria-current={on ? 'page' : undefined} onClick={() => setTab(item.key)} className={cn('flex h-11 shrink-0 items-center gap-2 rounded-full px-4 text-[13.5px] font-bold transition-colors', FOCUS, on ? 'bg-[#1D2E4A] text-white' : 'text-[#475569] hover:bg-[#F1F5F9]')}>
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
                  {h.work.length > 0 && <div className="mt-1.5 flex flex-wrap gap-1.5">{h.work.map((w, i) => <Pill key={i} tone="blue">{w}</Pill>)}</div>}
                </li>
              ))}
            </ul>
          )}
        </Panel>
      )}

      {tab === 'treatments' && (care ? <CarePanel care={care} encounterId={encounter.id} open={!readOnly} /> : <p className="text-[13px] text-cx-text-2">No treatment is running for this patient.</p>)}

      {tab === 'today' && (
        <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
          <div className="flex min-w-0 flex-col gap-4">
            <Panel icon={MessageSquareText} tone="amber" title="What brings the patient in?" detail="Tap one or more, or write it.">
              <div className="flex flex-wrap gap-2">
                {dental.complaints.map((c) => {
                  const on = complaintKeys.includes(c.key);

                  return (
                    <button key={c.key} type="button" disabled={readOnly} aria-pressed={on} onClick={() => toggleComplaint(c.key)} className={cn('inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-[12.5px] font-bold', FOCUS, on ? 'border-[#E9821A] bg-[#FFF3DC] text-[#9A4A06]' : 'border-[#DCE6F3] bg-white text-[#475569] hover:border-[#B9C6D8]')}>
                      {on && <Check className="h-3.5 w-3.5" aria-hidden="true" />} {c.label}
                    </button>
                  );
                })}
              </div>
              <textarea value={complaintText} onChange={(e) => setComplaintText(e.target.value)} disabled={readOnly} rows={2} placeholder="Complaint in the patient’s words" className="mt-3 w-full rounded-2xl border border-[#DCE6F3] px-3.5 py-2.5 text-[13.5px] text-[#1D2E4A] placeholder:text-[#94A3B8] focus:border-[#1769AA] focus:outline-none" />
            </Panel>

            <Panel icon={Smile} tone="blue" title="Teeth" detail="Tap a tooth, then what you find. Saved with the visit.">
              <ToothChart stateOf={stateOf} conditions={dental.conditions} selected={selected} onSelect={setSelected} />
              <div className="mt-3 flex flex-wrap gap-1.5">
                {Object.entries(dental.conditions).filter(([, c]) => c.category && ['pathology', 'restoration', 'endodontic', 'prosthetic', 'implant', 'missing', 'urgent'].includes(c.category)).slice(0, 12).map(([key, c]) => {
                  const t = toneOf(c);

                  return <span key={key} className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11.5px] font-bold" style={{ background: t.bg, color: t.ink, boxShadow: `inset 0 0 0 1px ${t.solid}55` }}><span className="h-2 w-2 rounded-full" style={{ background: t.solid }} />{c.label}</span>;
                })}
              </div>
            </Panel>

            <Panel icon={ClipboardList} tone="violet" title="Diagnosis & notes" detail="Optional: a diagnosis is signed with the visit.">
              <div className="grid gap-3 sm:grid-cols-2">
                <Labelled label="Diagnosis"><input value={diagnosis} onChange={(e) => setDiagnosis(e.target.value)} disabled={readOnly} className="h-10 w-full rounded-full border border-[#DCE6F3] px-4 text-[13.5px] focus:border-[#1769AA] focus:outline-none" /></Labelled>
                <Labelled label="Advice / next step"><input value={advice} onChange={(e) => setAdvice(e.target.value)} disabled={readOnly} className="h-10 w-full rounded-full border border-[#DCE6F3] px-4 text-[13.5px] focus:border-[#1769AA] focus:outline-none" /></Labelled>
              </div>
              <Labelled label="Examination"><textarea value={findingsText} onChange={(e) => setFindingsText(e.target.value)} disabled={readOnly} rows={2} className="mt-1 w-full rounded-2xl border border-[#DCE6F3] px-3.5 py-2.5 text-[13.5px] focus:border-[#1769AA] focus:outline-none" /></Labelled>
            </Panel>

            <Panel icon={FileText} tone="violet" title="Plan & work today" detail="Add a service from the clinic’s list. Tick what was done today; the rest stays on the plan.">
              {!readOnly && (
                <div className="relative mb-3">
                  <Search className="pointer-events-none absolute left-3.5 top-3 h-4 w-4 text-[#94A3B8]" aria-hidden="true" />
                  <input value={serviceQuery} onChange={(e) => setServiceQuery(e.target.value)} placeholder={selected ? `Add a service for tooth ${selected}…` : 'Add a service…'} className="h-10 w-full rounded-full border border-[#DCE6F3] pl-10 pr-4 text-[13.5px] focus:border-[#1769AA] focus:outline-none" />
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
              {work.length === 0 ? <p className="rounded-2xl bg-[#F6F8FC] px-4 py-6 text-center text-[13px] text-cx-text-2">Nothing yet. Search a service above.</p> : (
                <ul className="flex flex-col gap-2">
                  {work.map((w) => (
                    <li key={w.key} className="flex flex-wrap items-center gap-3 rounded-2xl border border-[#E6EDF7] bg-white px-3.5 py-2.5">
                      <button type="button" disabled={readOnly} aria-pressed={w.done} aria-label="Done today" onClick={() => setWork((all) => all.map((x) => (x.key === w.key ? { ...x, done: !x.done } : x)))} className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-full border-2', FOCUS, w.done ? 'border-[#0E9F87] bg-[#0E9F87] text-white' : 'border-[#C9D3E1] text-transparent hover:border-[#0E9F87]')}><Check className="h-4 w-4" aria-hidden="true" /></button>
                      {w.tooth && <span className="grid h-9 min-w-9 place-items-center rounded-[11px] bg-cx-blue-bg px-1 text-[12.5px] font-extrabold text-cx-blue-ink">{w.tooth}</span>}
                      <div className="min-w-0 flex-1"><p className="text-[13.5px] font-bold text-[#1D2E4A]">{w.label}{w.surfaces.length > 0 && <span className="ml-1.5 text-[12px] font-medium text-cx-text-3">{w.surfaces.join('')}</span>}</p><p className="text-[12px] text-cx-text-3">{w.id ? 'From the plan' : 'New'} · {w.done ? 'done today' : 'planned'}{w.visits > 1 && ` · about ${w.visits} visits`}</p></div>
                      <strong className="text-[13.5px] text-[#1D2E4A]">{w.price ? rupee(w.price) : '—'}</strong>
                      {!readOnly && !w.id && <button type="button" aria-label="Remove" onClick={() => setWork((all) => all.filter((x) => x.key !== w.key))} className="grid h-8 w-8 place-items-center rounded-full text-[#94A3B8] hover:bg-cx-rose-bg hover:text-cx-rose-ink"><X className="h-4 w-4" aria-hidden="true" /></button>}
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          </div>

          <aside className="flex min-w-0 flex-col gap-4 xl:sticky xl:top-4">
            <section className={cn(CARD, 'p-4 sm:p-5')}>
              {selected === null ? (
                <p className="text-[13px] text-cx-text-2">Tap a tooth on the chart to record what you find.</p>
              ) : (
                <ToothPanel tooth={selected} state={stateOf(selected)} surfaces={findings[selected]?.surfaces ?? charted.get(selected)?.surfaces ?? []} dental={dental} readOnly={readOnly} onCondition={(c) => setCondition(selected, c)} onSurface={(s) => toggleSurface(selected, s)} />
              )}
            </section>

            <section className={cn(CARD, 'p-4 sm:p-5')}>
              <div className="mb-3 flex items-center gap-2.5"><IconTile icon={Wallet} tone="mint" /><h2 className="text-[15px] font-semibold leading-5 text-cx-nav-ink">This visit</h2></div>
              <ul className="space-y-2 text-[13px]">
                {work.filter((w) => w.done).map((w) => <li key={w.key} className="flex justify-between gap-2"><span className="min-w-0 font-semibold text-[#1D2E4A]">{w.label}{w.tooth && <span className="block text-[12px] font-medium text-cx-text-2">Tooth {w.tooth}</span>}</span><strong>{w.price ? rupee(w.price) : '—'}</strong></li>)}
                {doneCount === 0 && <li className="text-cx-text-2">Tick a treatment as done and it is billed. Nothing ticked: consultation only.</li>}
                {changed.length > 0 && <li className="font-semibold text-cx-blue-ink">{changed.length} {changed.length === 1 ? 'finding' : 'findings'} charted</li>}
              </ul>
              {dental.finished ? (
                <>
                  <div className="mt-3 flex justify-between border-t border-[#EEF2F7] pt-3 text-[15px] font-bold text-[#1D2E4A]">To pay <span>{rupee(dental.to_bill)}</span></div>
                  {links.checkout && dental.to_bill > 0 && <Link href={links.checkout} className={cn(BTN_PRIMARY, 'mt-3.5 h-12 w-full text-[15px]')}>Take payment</Link>}
                  <a href={links.summary} target="_blank" rel="noopener" className={cn(BTN, 'mt-2.5 h-11 w-full text-[14px]')}><FileText className="h-4 w-4" aria-hidden="true" /> Print visit summary</a>
                </>
              ) : (
                <>
                  <div className="mt-3 flex justify-between border-t border-[#EEF2F7] pt-3 text-[15px] font-bold text-[#1D2E4A]">To pay <span>{rupee(total)}</span></div>
                  <button type="button" disabled={readOnly || busy} onClick={finish} className={cn('mt-3.5 h-12 w-full rounded-full bg-[#0E9F87] text-[15px] font-bold text-white disabled:opacity-60', FOCUS)}>{finishLabel}</button>
                  <p className="mt-2 text-center text-[12px] text-cx-text-2">Then take the payment, or leave it for the desk.</p>
                </>
              )}
            </section>

            {!dental.finished && !readOnly && (
              <section className={cn(CARD, 'p-4 sm:p-5')}>
                <div className="mb-3 flex items-center gap-2.5"><IconTile icon={CalendarCheck2} tone="teal" /><div><h2 className="text-[15px] font-semibold leading-5 text-cx-nav-ink">Next visits</h2><p className="text-[12px] text-cx-text-3">Booked when you finish.</p></div></div>
                <label className="flex cursor-pointer items-center justify-between gap-3 rounded-2xl bg-[#F6F8FC] px-3.5 py-3 text-[13px] font-semibold text-cx-nav-ink">Book the next visit<input type="checkbox" checked={book} onChange={(e) => { setTouched(true); setBook(e.target.checked); }} className="h-5 w-5 accent-[#0E9F87]" /></label>
                {sittings && <p className="mt-2 rounded-2xl bg-cx-teal-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-cx-teal-ink">{sittings.names.join(', ')} takes about {sittings.count} visits: they are booked for you, every {sittings.step} days.</p>}
                {book && (
                  <div className="mt-3 grid grid-cols-2 gap-2">
                    <Labelled label="Day"><select value={booking.date} onChange={(e) => setBooking({ ...booking, date: e.target.value, time: dental.booking.days.find((d) => d.date === e.target.value)?.times[0] ?? '' })} className="h-10 w-full rounded-full border border-[#DCE6F3] bg-white px-3 text-[13px]">{dental.booking.days.filter((d) => d.times.length > 0).map((d) => <option key={d.date} value={d.date}>{dayLabel(d.date)}</option>)}</select></Labelled>
                    <Labelled label="Time"><select value={booking.time} onChange={(e) => setBooking({ ...booking, time: e.target.value })} className="h-10 w-full rounded-full border border-[#DCE6F3] bg-white px-3 text-[13px]">{times.map((t) => <option key={t} value={t}>{time12(t)}</option>)}</select></Labelled>
                    <Labelled label="Visits"><select value={booking.count} onChange={(e) => setBooking({ ...booking, count: Number(e.target.value) })} className="h-10 w-full rounded-full border border-[#DCE6F3] bg-white px-3 text-[13px]">{[1, 2, 3, 4, 5, 6].map((n) => <option key={n} value={n}>{n}</option>)}</select></Labelled>
                    <Labelled label="Every"><select value={booking.step} onChange={(e) => setBooking({ ...booking, step: Number(e.target.value) })} className="h-10 w-full rounded-full border border-[#DCE6F3] bg-white px-3 text-[13px]">{[[3, '3 days'], [5, '5 days'], [7, 'week'], [14, '2 weeks'], [30, 'month']].map(([n, l]) => <option key={n} value={n}>{l}</option>)}</select></Labelled>
                  </div>
                )}
                {!firstDay && <p className="mt-2 text-[12px] text-cx-text-3">No free time in the next three weeks: book from the calendar.</p>}
              </section>
            )}
          </aside>
        </div>
      )}
    </div>
  );
}

function Fact({ icon, tone, label, children }: { icon: LucideIcon; tone: Tone; label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 items-center gap-3 rounded-[16px] bg-white/80 p-3 ring-1 ring-inset ring-white">
      <IconTile icon={icon} tone={tone} size="sm" />
      <div className="min-w-0"><dt className="text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">{label}</dt><dd className="text-[13.5px] font-semibold leading-5 text-cx-nav-ink [overflow-wrap:anywhere]">{children}</dd></div>
    </div>
  );
}

function Panel({ icon, tone, title, detail, children }: { icon: LucideIcon; tone: Tone; title: string; detail?: string; children: ReactNode }) {
  return (
    <section className={cn(CARD, 'p-4 sm:p-5')}>
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
      <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">{label}</p>
      <div className="scroll-x grid grid-cols-8 gap-1.5 sm:grid-cols-16 [grid-template-columns:repeat(16,minmax(34px,1fr))] overflow-x-auto pb-1">
        {teeth.map((tooth) => {
          const state = stateOf(tooth);
          const t = toneOf(conditions[state]);
          const on = selected === tooth;

          return (
            <button key={tooth} type="button" onClick={() => onSelect(tooth)} aria-pressed={on} aria-label={`Tooth ${tooth}, ${conditions[state]?.label ?? state}`} title={`${tooth} · ${conditions[state]?.label ?? state}`}
              className={cn('flex h-14 flex-col items-center justify-center gap-0.5 rounded-xl border text-[12.5px] font-extrabold transition-colors', FOCUS, on && 'ring-2 ring-[#1769AA] ring-offset-1')}
              style={{ background: t.bg, color: t.ink, borderColor: on ? '#1769AA' : `${t.solid}66` }}>
              {tooth}
              <span className="h-1.5 w-1.5 rounded-full" style={{ background: t.solid }} />
            </button>
          );
        })}
      </div>
    </div>
  );

  return <div className="flex flex-col gap-3 rounded-[18px] bg-[#F7FAFF] p-3 ring-1 ring-inset ring-[#E6EDF7]">{row(UPPER, 'Upper jaw')}{row(LOWER, 'Lower jaw')}</div>;
}

function ToothPanel({ tooth, state, surfaces, dental, readOnly, onCondition, onSurface }: { tooth: number; state: string; surfaces: string[]; dental: Props['dental']; readOnly: boolean; onCondition: (condition: string) => void; onSurface: (surface: string) => void }) {
  const current = dental.conditions[state];
  const t = toneOf(current);
  const [showAll, setShowAll] = useState(false);
  const common = ['sound', 'caries', 'filled', 'root_canal', 'crown', 'extracted', 'missing', 'implant', 'fractured', 'abscess'];
  const keys = showAll ? Object.keys(dental.conditions) : common.filter((k) => dental.conditions[k]);

  return (
    <>
      <div className="mb-3 flex items-center gap-3">
        <span className="grid h-12 w-12 shrink-0 place-items-center rounded-[14px] text-[18px] font-extrabold" style={{ background: t.bg, color: t.ink, boxShadow: `inset 0 0 0 1.5px ${t.solid}66` }}>{tooth}</span>
        <div className="min-w-0"><h2 className="text-[15px] font-semibold leading-5 text-cx-nav-ink">{toothName(tooth)}</h2><span className="mt-0.5 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11.5px] font-bold" style={{ background: t.bg, color: t.ink }}><span className="h-2 w-2 rounded-full" style={{ background: t.solid }} />{current?.label ?? state}</span></div>
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
          <p className="mb-2 mt-4 text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">Surfaces</p>
          <div className="flex gap-1.5">
            {Object.entries(dental.surfaces).map(([key, label]) => (
              <button key={key} type="button" disabled={readOnly} aria-pressed={surfaces.includes(key)} title={label} onClick={() => onSurface(key)} className={cn('grid h-10 w-10 place-items-center rounded-full border text-[13px] font-extrabold', FOCUS, surfaces.includes(key) ? 'border-[#2B6FD8] bg-[#EAF1FF] text-[#1D4ED8]' : 'border-[#DCE6F3] bg-white text-[#475569]')}>{key}</button>
            ))}
          </div>
        </>
      )}
    </>
  );
}

// Re-exported for the Patient 360 dental section, which reads a tooth the same way.
export { toothName };
