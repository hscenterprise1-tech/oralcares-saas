import { useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PillTabs } from '@/clinic/ui/Tabs';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';

interface Canal { name: string; working_length?: number | string | null; reference?: string | null; master_file?: string | null }

interface Endo {
  id: number; tooth: number; status: string; stage: string; pulp_status: string | null; periapical_status: string | null; diagnosis: string | null; canals: Canal[];
  instrumentation_notes: string | null; obturation_technique: string | null; obturation_material: string | null; obturated_on: string | null;
  temporary_restoration: string | null; permanent_restoration: string | null; review_on: string | null; notes: string | null; started: string | null; url: string;
}

interface Implant {
  id: number; tooth: number; status: string; system: string | null; diameter_mm: number | null; length_mm: number | null; lot_number: string | null; placed_on: string | null;
  insertion_torque_ncm: number | null; integration_status: string | null; abutment: string | null; abutment_on: string | null; crown_status: string | null; crown_on: string | null;
  review_on: string | null; complications: string | null; notes: string | null; started: string | null; url: string;
}

interface OrthoCase {
  id: number; status: string; phase: string; arches: string; appliance: string; bracket_system: string | null; diagnosis: string | null; started_on: string | null;
  estimated_months: number | null; bracketed_teeth: number[]; notes: string | null; url: string; visit_url: string;
}

interface OrthoVisit { id: number; date: string | null; phase: string | null; upper_wire: string | null; lower_wire: string | null; adjustments: string | null; elastics: string | null; notes: string | null; next_visit_weeks: number | null; this_visit: boolean }

interface Lab {
  id: number; number: string | null; restoration: string | null; teeth: number[]; laboratory: string | null; shade: string | null; material: string | null; status: string;
  due: string | null; notes: string | null; cancellation_reason: string | null; steps: { step: string; url: string }[];
}

/** `DentalSpecialtyView::forVisit`: the server is the contract. */
export interface SpecialtyData {
  can_write: boolean;
  endodontic: Endo[];
  implants: Implant[];
  ortho: { case: OrthoCase | null; visits: OrthoVisit[] };
  lab: Lab[];
  vocab: {
    endodontic: { statuses: string[]; stages: string[]; pulp: string[]; periapical: string[] };
    implant: { statuses: string[]; integration: string[]; crown: string[] };
    ortho: { phases: string[]; appliances: Record<string, string> };
  };
  links: { endodontic: string; implant: string; ortho: string; lab: string };
}

type Section = 'endo' | 'implant' | 'ortho' | 'lab';

const CARD = 'rounded-2xl border border-cx-line/80 bg-white p-4 shadow-cx-card sm:p-5';
const PERMANENT = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28, 48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];
const MILK = [55, 54, 53, 52, 51, 61, 62, 63, 64, 65, 85, 84, 83, 82, 81, 71, 72, 73, 74, 75];
const TOOTH_OPTIONS = [{ value: '', label: 'Choose a tooth' }, ...[...PERMANENT, ...MILK].map((n) => ({ value: String(n), label: `${n}${n > 50 ? ' (milk tooth)' : ''}` }))];
const STATUS_TONE: Record<string, string> = {
  planned: 'bg-cx-slate-bg text-cx-slate-ink', in_progress: 'bg-cx-blue-bg text-cx-blue-ink', completed: 'bg-cx-green-bg text-cx-green-ink', abandoned: 'bg-cx-rose-bg text-cx-rose-ink',
  placed: 'bg-cx-blue-bg text-cx-blue-ink', integrating: 'bg-cx-amber-bg text-cx-amber-ink', restored: 'bg-cx-green-bg text-cx-green-ink', failed: 'bg-cx-rose-bg text-cx-rose-ink', removed: 'bg-cx-slate-bg text-cx-slate-ink',
  active: 'bg-cx-blue-bg text-cx-blue-ink', draft: 'bg-cx-slate-bg text-cx-slate-ink', requested: 'bg-cx-amber-bg text-cx-amber-ink', in_production: 'bg-cx-amber-bg text-cx-amber-ink',
  delivered: 'bg-cx-blue-bg text-cx-blue-ink', fitted: 'bg-cx-green-bg text-cx-green-ink', cancelled: 'bg-cx-rose-bg text-cx-rose-ink',
};
const STEP_LABEL: Record<string, string> = { send: 'Send to lab', start: 'Lab started', receive: 'Received from lab', fit: 'Fitted', complete: 'Complete', remake: 'Remake', cancel: 'Cancel' };
const day = (iso: string | null) => (iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const nul = (v: string) => (v.trim() === '' ? null : v.trim());
const toothList = (text: string): number[] => Array.from(new Set(text.split(/[\s,]+/).map(Number).filter((n) => Number.isInteger(n) && n >= 11 && n <= 85)));

function Chip({ value }: { value: string }) {
  return <span className={cn('rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', STATUS_TONE[value] ?? 'bg-cx-slate-bg text-cx-slate-ink')}>{humanize(value)}</span>;
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return children ? <p className="text-[12.5px] text-cx-text-2"><span className="text-cx-text-3">{label}: </span>{children}</p> : null;
}

/** Posts a form to a redirecting dental endpoint; the page and its flash come back as usual. */
function usePost(onDone: () => void) {
  const [busy, setBusy] = useState(false);
  const send = (url: string, data: Record<string, unknown>) => router.post(url, data as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onDone });

  return { busy, send };
}

/**
 * The dental specialty work on the visit page: root canal stages, implants, braces and lab cases.
 * Each is the patient's own record (it carries across visits); what is written here is saved in this visit.
 */
export function DentalSpecialty({ data }: { data: SpecialtyData }) {
  const [section, setSection] = useState<Section>('endo');
  const { errors } = usePage<{ errors: Record<string, string> }>().props;

  return (
    <div className="flex flex-col gap-4">
      {!data.can_write && <Notice tone="info">This visit is signed or you are not its doctor, so these records can be read but not changed.</Notice>}
      {errors?.dental && <Notice tone="danger">{errors.dental}</Notice>}
      <PillTabs
        label="Specialty work"
        value={section}
        onChange={setSection}
        options={[
          { key: 'endo', label: 'Root canal', count: data.endodontic.length },
          { key: 'implant', label: 'Implants', count: data.implants.length },
          { key: 'ortho', label: 'Braces', count: data.ortho.case ? 1 : 0 },
          { key: 'lab', label: 'Lab work', count: data.lab.length },
        ]}
      />
      {section === 'endo' && <EndoSection data={data} />}
      {section === 'implant' && <ImplantSection data={data} />}
      {section === 'ortho' && <OrthoSection data={data} />}
      {section === 'lab' && <LabSection data={data} />}
    </div>
  );
}

function Head({ title, hint, action }: { title: string; hint: string; action?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div><h2 className="text-[15px] font-semibold text-cx-nav-ink">{title}</h2><p className="text-[12.5px] text-cx-text-3">{hint}</p></div>
      {action}
    </div>
  );
}

/* ---------------------------------------------------------------- Root canal */

const ENDO_STAGES: Record<string, string> = { diagnosis: 'Diagnosis', access: 'Access opened', cleaning_shaping: 'Cleaning and shaping', obturation: 'Filling the canals', restoration: 'Restoration', review: 'Review' };

function EndoSection({ data }: { data: SpecialtyData }) {
  const [editing, setEditing] = useState<Endo | 'new' | null>(null);

  return (
    <section className={CARD} aria-label="Root canal">
      <Head title="Root canal treatment" hint="Follow each tooth from diagnosis to the final restoration." action={data.can_write && <Button tone="primary" onClick={() => setEditing('new')}><Plus className="h-4 w-4" aria-hidden="true" /> Start a root canal</Button>} />
      {data.endodontic.length === 0 ? (
        <p className="mt-4 rounded-xl bg-cx-slate-bg/60 px-4 py-6 text-center text-[13px] text-cx-text-2">No root canal recorded for this patient.</p>
      ) : (
        <ul className="mt-4 space-y-3">
          {data.endodontic.map((r) => {
            const index = Object.keys(ENDO_STAGES).indexOf(r.stage);

            return (
              <li key={r.id} className="rounded-2xl border border-cx-line/80 p-4">
                <div className="flex flex-wrap items-center gap-3">
                  <span className="grid h-10 min-w-10 place-items-center rounded-xl bg-cx-blue-bg px-1 text-[13px] font-extrabold text-cx-blue-ink">{r.tooth}</span>
                  <div className="min-w-0 flex-1"><p className="text-[14px] font-semibold text-cx-nav-ink">{r.diagnosis || 'Root canal'}</p><p className="text-[12px] text-cx-text-3">Started {day(r.started)}{r.review_on ? ` · review ${day(r.review_on)}` : ''}</p></div>
                  <Chip value={r.status} />
                  {data.can_write && <button type="button" aria-label={`Edit root canal of tooth ${r.tooth}`} onClick={() => setEditing(r)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-slate-bg"><Pencil className="h-4 w-4" aria-hidden="true" /></button>}
                </div>
                <ol className="mt-3 flex gap-1" aria-label="Stages">
                  {Object.entries(ENDO_STAGES).map(([key, label], i) => (
                    <li key={key} className="flex-1" title={label}>
                      <div className={cn('h-1.5 rounded-full', i <= index ? (r.status === 'completed' ? 'bg-[#0E9F87]' : 'bg-[#1769AA]') : 'bg-cx-line')} />
                      <p className={cn('mt-1 hidden text-[10.5px] sm:block', i === index ? 'font-semibold text-cx-nav-ink' : 'text-cx-text-3')}>{label}</p>
                    </li>
                  ))}
                </ol>
                <div className="mt-3 space-y-0.5">
                  <Row label="Pulp">{r.pulp_status ? humanize(r.pulp_status) : null}</Row>
                  <Row label="Around the root tip">{r.periapical_status ? humanize(r.periapical_status) : null}</Row>
                  <Row label="Canals">{r.canals.length > 0 ? r.canals.map((c) => `${c.name}${c.working_length ? ` ${c.working_length} mm` : ''}`).join(', ') : null}</Row>
                  <Row label="Filled">{[r.obturation_technique, r.obturation_material, r.obturated_on ? day(r.obturated_on) : null].filter(Boolean).join(' · ') || null}</Row>
                  <Row label="Restoration">{[r.temporary_restoration && `temporary: ${r.temporary_restoration}`, r.permanent_restoration && `permanent: ${r.permanent_restoration}`].filter(Boolean).join(' · ') || null}</Row>
                  <Row label="Notes">{r.notes}</Row>
                </div>
              </li>
            );
          })}
        </ul>
      )}
      {editing && <EndoDialog record={editing === 'new' ? null : editing} data={data} onClose={() => setEditing(null)} />}
    </section>
  );
}

function EndoDialog({ record, data, onClose }: { record: Endo | null; data: SpecialtyData; onClose: () => void }) {
  const v = data.vocab.endodontic;
  const completed = record?.status === 'completed';
  const [f, setF] = useState({
    tooth_number: record ? String(record.tooth) : '', status: record?.status ?? 'in_progress', stage: record?.stage ?? 'diagnosis', pulp_status: record?.pulp_status ?? '', periapical_status: record?.periapical_status ?? '',
    diagnosis: record?.diagnosis ?? '', instrumentation_notes: record?.instrumentation_notes ?? '', obturation_technique: record?.obturation_technique ?? '', obturation_material: record?.obturation_material ?? '',
    obturated_on: record?.obturated_on ?? '', temporary_restoration: record?.temporary_restoration ?? '', permanent_restoration: record?.permanent_restoration ?? '', review_on: record?.review_on ?? '', notes: record?.notes ?? '',
  });
  const [canals, setCanals] = useState<Canal[]>(record?.canals.length ? record.canals : []);
  const { busy, send } = usePost(onClose);
  const set = (key: keyof typeof f, value: string) => setF((c) => ({ ...c, [key]: value }));
  const opts = (list: string[], empty?: string) => [...(empty !== undefined ? [{ value: '', label: empty }] : []), ...list.map((x) => ({ value: x, label: humanize(x) }))];

  const save = () =>
    send(record ? record.url : data.links.endodontic, {
      ...(record ? {} : { tooth_number: Number(f.tooth_number) }),
      status: f.status, stage: f.stage, pulp_status: nul(f.pulp_status), periapical_status: nul(f.periapical_status), diagnosis: nul(f.diagnosis),
      canals: canals.filter((c) => c.name.trim() !== '').map((c) => ({ name: c.name.trim(), working_length: c.working_length === '' ? null : c.working_length, reference: nul(String(c.reference ?? '')), master_file: nul(String(c.master_file ?? '')) })),
      instrumentation_notes: nul(f.instrumentation_notes), obturation_technique: nul(f.obturation_technique), obturation_material: nul(f.obturation_material), obturated_on: nul(f.obturated_on),
      temporary_restoration: nul(f.temporary_restoration), permanent_restoration: nul(f.permanent_restoration), review_on: nul(f.review_on), notes: nul(f.notes),
    });

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="xl"
      title={record ? `Root canal · tooth ${record.tooth}` : 'Start a root canal'}
      description={completed ? 'This root canal is completed. Only the review date and notes can still change.' : 'Saved with this visit.'}
      footer={<><Button onClick={onClose} disabled={busy}>Cancel</Button><Button tone="primary" onClick={save} loading={busy} disabled={!record && f.tooth_number === ''}>Save</Button></>}
    >
      <div className="space-y-5">
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {!record && <Field label="Tooth" htmlFor="en-tooth" required><Select id="en-tooth" value={f.tooth_number} onChange={(e) => set('tooth_number', e.target.value)} options={TOOTH_OPTIONS} /></Field>}
          <Field label="Status" htmlFor="en-status"><Select id="en-status" value={f.status} onChange={(e) => set('status', e.target.value)} options={opts(v.statuses)} /></Field>
          <Field label="Stage reached" htmlFor="en-stage"><Select id="en-stage" value={f.stage} onChange={(e) => set('stage', e.target.value)} options={v.stages.map((s) => ({ value: s, label: ENDO_STAGES[s] ?? humanize(s) }))} /></Field>
          <Field label="Pulp" htmlFor="en-pulp"><Select id="en-pulp" value={f.pulp_status} onChange={(e) => set('pulp_status', e.target.value)} options={opts(v.pulp, 'Not recorded')} /></Field>
          <Field label="Around the root tip" htmlFor="en-peri"><Select id="en-peri" value={f.periapical_status} onChange={(e) => set('periapical_status', e.target.value)} options={opts(v.periapical, 'Not recorded')} /></Field>
        </div>
        <Field label="Diagnosis" htmlFor="en-diag"><Input id="en-diag" value={f.diagnosis} maxLength={500} onChange={(e) => set('diagnosis', e.target.value)} /></Field>

        <fieldset disabled={completed} className="space-y-5">
          <div>
            <div className="mb-2 flex items-center justify-between"><p className="text-[12px] font-semibold text-[#173B60]">Canals</p>{canals.length < 6 && <Button size="sm" onClick={() => setCanals([...canals, { name: '', working_length: '', reference: '', master_file: '' }])}><Plus className="h-4 w-4" aria-hidden="true" /> Add canal</Button>}</div>
            {canals.length === 0 ? <p className="text-[12.5px] text-cx-text-3">No canal recorded yet.</p> : (
              <ul className="space-y-2">
                {canals.map((c, i) => (
                  <li key={i} className="grid grid-cols-2 items-center gap-2 sm:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                    <Input aria-label="Canal name" placeholder="Name, e.g. MB" value={c.name} maxLength={20} onChange={(e) => setCanals(canals.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} />
                    <Input aria-label="Working length (mm)" type="number" step="0.5" placeholder="Length mm" value={c.working_length ?? ''} onChange={(e) => setCanals(canals.map((x, j) => (j === i ? { ...x, working_length: e.target.value } : x)))} />
                    <Input aria-label="Reference point" placeholder="Reference point" value={c.reference ?? ''} maxLength={40} onChange={(e) => setCanals(canals.map((x, j) => (j === i ? { ...x, reference: e.target.value } : x)))} />
                    <Input aria-label="Master file" placeholder="Master file" value={c.master_file ?? ''} maxLength={40} onChange={(e) => setCanals(canals.map((x, j) => (j === i ? { ...x, master_file: e.target.value } : x)))} />
                    <button type="button" aria-label="Remove canal" onClick={() => setCanals(canals.filter((_, j) => j !== i))} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink"><Trash2 className="h-4 w-4" aria-hidden="true" /></button>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <Field label="Cleaning and shaping notes" htmlFor="en-instr"><Textarea id="en-instr" value={f.instrumentation_notes} maxLength={5000} onChange={(e) => set('instrumentation_notes', e.target.value)} /></Field>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Filling technique" htmlFor="en-tech"><Input id="en-tech" value={f.obturation_technique} maxLength={100} onChange={(e) => set('obturation_technique', e.target.value)} /></Field>
            <Field label="Filling material" htmlFor="en-mat"><Input id="en-mat" value={f.obturation_material} maxLength={100} onChange={(e) => set('obturation_material', e.target.value)} /></Field>
            <Field label="Filled on" htmlFor="en-on"><Input id="en-on" type="date" value={f.obturated_on} onChange={(e) => set('obturated_on', e.target.value)} /></Field>
            <Field label="Temporary restoration" htmlFor="en-temp"><Input id="en-temp" value={f.temporary_restoration} maxLength={150} onChange={(e) => set('temporary_restoration', e.target.value)} /></Field>
            <Field label="Permanent restoration" htmlFor="en-perm"><Input id="en-perm" value={f.permanent_restoration} maxLength={150} onChange={(e) => set('permanent_restoration', e.target.value)} placeholder="e.g. Zirconia crown" /></Field>
          </div>
        </fieldset>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Review on" htmlFor="en-review"><Input id="en-review" type="date" value={f.review_on} onChange={(e) => set('review_on', e.target.value)} /></Field>
          <Field label="Notes" htmlFor="en-notes" className="sm:col-span-2"><Textarea id="en-notes" value={f.notes} maxLength={5000} onChange={(e) => set('notes', e.target.value)} /></Field>
        </div>
      </div>
    </Modal>
  );
}

/* ------------------------------------------------------------------ Implants */

function ImplantSection({ data }: { data: SpecialtyData }) {
  const [editing, setEditing] = useState<Implant | 'new' | null>(null);

  return (
    <section className={CARD} aria-label="Implants">
      <Head title="Dental implants" hint="From planning to placement, healing, abutment and crown." action={data.can_write && <Button tone="primary" onClick={() => setEditing('new')}><Plus className="h-4 w-4" aria-hidden="true" /> Plan or record an implant</Button>} />
      {data.implants.length === 0 ? (
        <p className="mt-4 rounded-xl bg-cx-slate-bg/60 px-4 py-6 text-center text-[13px] text-cx-text-2">No implant recorded for this patient.</p>
      ) : (
        <ul className="mt-4 space-y-3">
          {data.implants.map((r) => (
            <li key={r.id} className="rounded-2xl border border-cx-line/80 p-4">
              <div className="flex flex-wrap items-center gap-3">
                <span className="grid h-10 min-w-10 place-items-center rounded-xl bg-cx-blue-bg px-1 text-[13px] font-extrabold text-cx-blue-ink">{r.tooth}</span>
                <div className="min-w-0 flex-1"><p className="text-[14px] font-semibold text-cx-nav-ink">{r.system || 'Implant'}{r.diameter_mm && r.length_mm ? ` · ${r.diameter_mm} × ${r.length_mm} mm` : ''}</p><p className="text-[12px] text-cx-text-3">{r.placed_on ? `Placed ${day(r.placed_on)}` : 'Not placed yet'}{r.review_on ? ` · review ${day(r.review_on)}` : ''}</p></div>
                <Chip value={r.status} />
                {data.can_write && <button type="button" aria-label={`Edit implant at tooth ${r.tooth}`} onClick={() => setEditing(r)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-slate-bg"><Pencil className="h-4 w-4" aria-hidden="true" /></button>}
              </div>
              <div className="mt-3 space-y-0.5">
                <Row label="Healing">{r.integration_status ? humanize(r.integration_status) : null}</Row>
                <Row label="Abutment">{[r.abutment, r.abutment_on && day(r.abutment_on)].filter(Boolean).join(' · ') || null}</Row>
                <Row label="Crown">{r.crown_status && r.crown_status !== 'none' ? `${humanize(r.crown_status)}${r.crown_on ? ` · ${day(r.crown_on)}` : ''}` : null}</Row>
                <Row label="Lot / torque">{[r.lot_number, r.insertion_torque_ncm && `${r.insertion_torque_ncm} Ncm`].filter(Boolean).join(' · ') || null}</Row>
                <Row label="Complications">{r.complications}</Row>
                <Row label="Notes">{r.notes}</Row>
              </div>
            </li>
          ))}
        </ul>
      )}
      {editing && <ImplantDialog record={editing === 'new' ? null : editing} data={data} onClose={() => setEditing(null)} />}
    </section>
  );
}

function ImplantDialog({ record, data, onClose }: { record: Implant | null; data: SpecialtyData; onClose: () => void }) {
  const v = data.vocab.implant;
  const [f, setF] = useState({
    tooth_number: record ? String(record.tooth) : '', status: record?.status ?? 'planned', system: record?.system ?? '', diameter_mm: record?.diameter_mm != null ? String(record.diameter_mm) : '', length_mm: record?.length_mm != null ? String(record.length_mm) : '',
    lot_number: record?.lot_number ?? '', placed_on: record?.placed_on ?? '', insertion_torque_ncm: record?.insertion_torque_ncm != null ? String(record.insertion_torque_ncm) : '', integration_status: record?.integration_status ?? '',
    abutment: record?.abutment ?? '', abutment_on: record?.abutment_on ?? '', crown_status: record?.crown_status ?? '', crown_on: record?.crown_on ?? '', review_on: record?.review_on ?? '', complications: record?.complications ?? '', notes: record?.notes ?? '',
  });
  const { busy, send } = usePost(onClose);
  const set = (key: keyof typeof f, value: string) => setF((c) => ({ ...c, [key]: value }));
  const opts = (list: string[], empty?: string) => [...(empty !== undefined ? [{ value: '', label: empty }] : []), ...list.map((x) => ({ value: x, label: humanize(x) }))];
  const n = (value: string) => (value.trim() === '' ? null : Number(value));

  const save = () =>
    send(record ? record.url : data.links.implant, {
      ...(record ? {} : { tooth_number: Number(f.tooth_number) }),
      status: f.status, system: nul(f.system), diameter_mm: n(f.diameter_mm), length_mm: n(f.length_mm), lot_number: nul(f.lot_number), placed_on: nul(f.placed_on), insertion_torque_ncm: n(f.insertion_torque_ncm),
      integration_status: nul(f.integration_status), abutment: nul(f.abutment), abutment_on: nul(f.abutment_on), crown_status: nul(f.crown_status), crown_on: nul(f.crown_on), review_on: nul(f.review_on), complications: nul(f.complications), notes: nul(f.notes),
    });

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="xl"
      title={record ? `Implant · tooth ${record.tooth}` : 'Plan or record an implant'}
      description="Saved with this visit. A placed implant needs its placement date."
      footer={<><Button onClick={onClose} disabled={busy}>Cancel</Button><Button tone="primary" onClick={save} loading={busy} disabled={!record && f.tooth_number === ''}>Save</Button></>}
    >
      <div className="space-y-5">
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {!record && <Field label="Tooth" htmlFor="im-tooth" required><Select id="im-tooth" value={f.tooth_number} onChange={(e) => set('tooth_number', e.target.value)} options={TOOTH_OPTIONS} /></Field>}
          <Field label="Status" htmlFor="im-status"><Select id="im-status" value={f.status} onChange={(e) => set('status', e.target.value)} options={opts(v.statuses)} /></Field>
          <Field label="System / brand" htmlFor="im-sys"><Input id="im-sys" value={f.system} maxLength={120} onChange={(e) => set('system', e.target.value)} /></Field>
          <Field label="Diameter (mm)" htmlFor="im-dia"><Input id="im-dia" type="number" step="0.1" min={2} max={8} value={f.diameter_mm} onChange={(e) => set('diameter_mm', e.target.value)} /></Field>
          <Field label="Length (mm)" htmlFor="im-len"><Input id="im-len" type="number" step="0.5" min={4} max={25} value={f.length_mm} onChange={(e) => set('length_mm', e.target.value)} /></Field>
          <Field label="Lot number" htmlFor="im-lot"><Input id="im-lot" value={f.lot_number} maxLength={60} onChange={(e) => set('lot_number', e.target.value)} /></Field>
          <Field label="Placed on" htmlFor="im-placed"><Input id="im-placed" type="date" value={f.placed_on} onChange={(e) => set('placed_on', e.target.value)} /></Field>
          <Field label="Insertion torque (Ncm)" htmlFor="im-torque"><Input id="im-torque" type="number" min={5} max={100} value={f.insertion_torque_ncm} onChange={(e) => set('insertion_torque_ncm', e.target.value)} /></Field>
          <Field label="Healing" htmlFor="im-int"><Select id="im-int" value={f.integration_status} onChange={(e) => set('integration_status', e.target.value)} options={opts(v.integration, 'Not recorded')} /></Field>
          <Field label="Abutment" htmlFor="im-abut"><Input id="im-abut" value={f.abutment} maxLength={120} onChange={(e) => set('abutment', e.target.value)} /></Field>
          <Field label="Abutment on" htmlFor="im-abuton"><Input id="im-abuton" type="date" value={f.abutment_on} onChange={(e) => set('abutment_on', e.target.value)} /></Field>
          <Field label="Crown" htmlFor="im-crown"><Select id="im-crown" value={f.crown_status} onChange={(e) => set('crown_status', e.target.value)} options={opts(v.crown, 'Not recorded')} /></Field>
          <Field label="Crown on" htmlFor="im-crownon"><Input id="im-crownon" type="date" value={f.crown_on} onChange={(e) => set('crown_on', e.target.value)} /></Field>
          <Field label="Review on" htmlFor="im-review"><Input id="im-review" type="date" value={f.review_on} onChange={(e) => set('review_on', e.target.value)} /></Field>
        </div>
        <Field label="Complications" htmlFor="im-comp"><Textarea id="im-comp" value={f.complications} maxLength={5000} onChange={(e) => set('complications', e.target.value)} /></Field>
        <Field label="Notes" htmlFor="im-notes"><Textarea id="im-notes" value={f.notes} maxLength={5000} onChange={(e) => set('notes', e.target.value)} /></Field>
      </div>
    </Modal>
  );
}

/* -------------------------------------------------------------------- Braces */

function OrthoSection({ data }: { data: SpecialtyData }) {
  const c = data.ortho.case;
  const [caseDialog, setCaseDialog] = useState(false);
  const [visitDialog, setVisitDialog] = useState(false);
  const appliance = (key: string) => data.vocab.ortho.appliances[key] ?? humanize(key);
  const phases = data.vocab.ortho.phases;

  return (
    <section className={CARD} aria-label="Braces">
      <Head
        title="Braces and aligners"
        hint="One case per patient: the plan, then an adjustment at each visit."
        action={data.can_write && (c ? (
          <div className="flex flex-wrap gap-2"><Button onClick={() => setCaseDialog(true)}>Edit case</Button>{c.status === 'active' && <Button tone="primary" onClick={() => setVisitDialog(true)}><Plus className="h-4 w-4" aria-hidden="true" /> Record today's adjustment</Button>}</div>
        ) : <Button tone="primary" onClick={() => setCaseDialog(true)}><Plus className="h-4 w-4" aria-hidden="true" /> Open a case</Button>)}
      />
      {!c ? (
        <p className="mt-4 rounded-xl bg-cx-slate-bg/60 px-4 py-6 text-center text-[13px] text-cx-text-2">No orthodontic case for this patient.</p>
      ) : (
        <>
          <div className="mt-4 rounded-2xl border border-cx-line/80 p-4">
            <div className="flex flex-wrap items-center gap-3"><p className="min-w-0 flex-1 text-[14px] font-semibold text-cx-nav-ink">{appliance(c.appliance)} · {humanize(c.arches)} arch{c.arches === 'both' ? 'es' : ''}</p><Chip value={c.status} /></div>
            <ol className="mt-3 flex gap-1" aria-label="Phases">
              {phases.map((p, i) => (
                <li key={p} className="flex-1" title={humanize(p)}><div className={cn('h-1.5 rounded-full', i <= phases.indexOf(c.phase) ? 'bg-[#1769AA]' : 'bg-cx-line')} /><p className={cn('mt-1 hidden text-[10.5px] sm:block', p === c.phase ? 'font-semibold text-cx-nav-ink' : 'text-cx-text-3')}>{humanize(p)}</p></li>
              ))}
            </ol>
            <div className="mt-3 space-y-0.5">
              <Row label="Started">{c.started_on ? day(c.started_on) : null}</Row>
              <Row label="Expected">{c.estimated_months ? `${c.estimated_months} months` : null}</Row>
              <Row label="Brackets / system">{c.bracket_system}</Row>
              <Row label="Teeth with brackets">{c.bracketed_teeth.length > 0 ? c.bracketed_teeth.join(', ') : null}</Row>
              <Row label="Diagnosis">{c.diagnosis}</Row>
              <Row label="Notes">{c.notes}</Row>
            </div>
          </div>
          <h3 className="mb-2 mt-5 text-[13px] font-semibold text-cx-nav-ink">Adjustment visits</h3>
          {data.ortho.visits.length === 0 ? <p className="text-[12.5px] text-cx-text-3">No adjustment recorded yet.</p> : (
            <ul className="divide-y divide-cx-line/60 rounded-xl border border-cx-line/80">
              {data.ortho.visits.map((v) => (
                <li key={v.id} className="px-4 py-3">
                  <p className="flex flex-wrap items-center gap-2 text-[13px] font-semibold text-cx-nav-ink">{day(v.date)}{v.this_visit && <span className="rounded-full bg-cx-blue-bg px-2 py-0.5 text-[10.5px] font-semibold text-cx-blue-ink">Today</span>}{v.phase && <span className="text-[12px] font-normal text-cx-text-3">{humanize(v.phase)}</span>}</p>
                  <Row label="Wires">{[v.upper_wire && `upper ${v.upper_wire}`, v.lower_wire && `lower ${v.lower_wire}`].filter(Boolean).join(' · ') || null}</Row>
                  <Row label="Done">{v.adjustments}</Row>
                  <Row label="Elastics">{v.elastics}</Row>
                  <Row label="Next visit">{v.next_visit_weeks ? `in ${v.next_visit_weeks} weeks` : null}</Row>
                  <Row label="Notes">{v.notes}</Row>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
      {caseDialog && <OrthoCaseDialog current={c} data={data} onClose={() => setCaseDialog(false)} />}
      {visitDialog && c && <OrthoVisitDialog current={c} today={data.ortho.visits.find((v) => v.this_visit) ?? null} data={data} onClose={() => setVisitDialog(false)} />}
    </section>
  );
}

function OrthoCaseDialog({ current, data, onClose }: { current: OrthoCase | null; data: SpecialtyData; onClose: () => void }) {
  const [f, setF] = useState({
    arches: current?.arches ?? 'both', appliance: current?.appliance ?? 'fixed_metal', bracket_system: current?.bracket_system ?? '', phase: current?.phase ?? 'planning', diagnosis: current?.diagnosis ?? '',
    started_on: current?.started_on ?? '', estimated_months: current?.estimated_months != null ? String(current.estimated_months) : '', teeth: (current?.bracketed_teeth ?? []).join(', '), notes: current?.notes ?? '',
  });
  const { busy, send } = usePost(onClose);
  const set = (key: keyof typeof f, value: string) => setF((c) => ({ ...c, [key]: value }));

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="lg"
      title={current ? 'Edit the orthodontic case' : 'Open an orthodontic case'}
      footer={<><Button onClick={onClose} disabled={busy}>Cancel</Button><Button tone="primary" loading={busy} onClick={() => send(current ? current.url : data.links.ortho, { arches: f.arches, appliance: f.appliance, bracket_system: nul(f.bracket_system), phase: f.phase, diagnosis: nul(f.diagnosis), started_on: nul(f.started_on), estimated_months: f.estimated_months === '' ? null : Number(f.estimated_months), bracketed_teeth: toothList(f.teeth), notes: nul(f.notes) })}>{current ? 'Save case' : 'Open case'}</Button></>}
    >
      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Appliance" htmlFor="oc-app"><Select id="oc-app" value={f.appliance} onChange={(e) => set('appliance', e.target.value)} options={Object.entries(data.vocab.ortho.appliances).map(([value, label]) => ({ value, label }))} /></Field>
          <Field label="Arches" htmlFor="oc-arch"><Select id="oc-arch" value={f.arches} onChange={(e) => set('arches', e.target.value)} options={[{ value: 'both', label: 'Upper and lower' }, { value: 'upper', label: 'Upper only' }, { value: 'lower', label: 'Lower only' }]} /></Field>
          <Field label="Phase" htmlFor="oc-phase"><Select id="oc-phase" value={f.phase} onChange={(e) => set('phase', e.target.value)} options={data.vocab.ortho.phases.map((p) => ({ value: p, label: humanize(p) }))} /></Field>
          <Field label="Bracket system" htmlFor="oc-brk"><Input id="oc-brk" value={f.bracket_system} maxLength={120} onChange={(e) => set('bracket_system', e.target.value)} placeholder="e.g. MBT 0.022" /></Field>
          <Field label="Started on" htmlFor="oc-start"><Input id="oc-start" type="date" value={f.started_on} onChange={(e) => set('started_on', e.target.value)} /></Field>
          <Field label="Expected months" htmlFor="oc-months"><Input id="oc-months" type="number" min={1} max={72} value={f.estimated_months} onChange={(e) => set('estimated_months', e.target.value)} /></Field>
        </div>
        <Field label="Teeth with brackets" htmlFor="oc-teeth" hint="Tooth numbers, separated by commas."><Input id="oc-teeth" value={f.teeth} onChange={(e) => set('teeth', e.target.value)} placeholder="e.g. 13, 12, 11, 21, 22, 23" /></Field>
        <Field label="Diagnosis" htmlFor="oc-diag"><Input id="oc-diag" value={f.diagnosis} maxLength={500} onChange={(e) => set('diagnosis', e.target.value)} /></Field>
        <Field label="Notes" htmlFor="oc-notes"><Textarea id="oc-notes" value={f.notes} maxLength={5000} onChange={(e) => set('notes', e.target.value)} /></Field>
      </div>
    </Modal>
  );
}

function OrthoVisitDialog({ current, today, data, onClose }: { current: OrthoCase; today: OrthoVisit | null; data: SpecialtyData; onClose: () => void }) {
  const [f, setF] = useState({ phase: today?.phase ?? current.phase, upper_wire: today?.upper_wire ?? '', lower_wire: today?.lower_wire ?? '', adjustments: today?.adjustments ?? '', elastics: today?.elastics ?? '', notes: today?.notes ?? '', next_visit_weeks: today?.next_visit_weeks != null ? String(today.next_visit_weeks) : '4' });
  const { busy, send } = usePost(onClose);
  const set = (key: keyof typeof f, value: string) => setF((c) => ({ ...c, [key]: value }));

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="lg"
      title="Today's adjustment"
      description="Saved with this visit. Recording again today replaces it."
      footer={<><Button onClick={onClose} disabled={busy}>Cancel</Button><Button tone="primary" loading={busy} onClick={() => send(current.visit_url, { phase: f.phase, upper_wire: nul(f.upper_wire), lower_wire: nul(f.lower_wire), adjustments: nul(f.adjustments), elastics: nul(f.elastics), notes: nul(f.notes), next_visit_weeks: f.next_visit_weeks === '' ? null : Number(f.next_visit_weeks) })}>Save adjustment</Button></>}
    >
      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Phase" htmlFor="ov-phase"><Select id="ov-phase" value={f.phase} onChange={(e) => set('phase', e.target.value)} options={data.vocab.ortho.phases.map((p) => ({ value: p, label: humanize(p) }))} /></Field>
          <Field label="Next visit in (weeks)" htmlFor="ov-next"><Input id="ov-next" type="number" min={1} max={52} value={f.next_visit_weeks} onChange={(e) => set('next_visit_weeks', e.target.value)} /></Field>
          <Field label="Upper wire" htmlFor="ov-up"><Input id="ov-up" value={f.upper_wire} maxLength={80} onChange={(e) => set('upper_wire', e.target.value)} placeholder="e.g. 0.016 NiTi" /></Field>
          <Field label="Lower wire" htmlFor="ov-low"><Input id="ov-low" value={f.lower_wire} maxLength={80} onChange={(e) => set('lower_wire', e.target.value)} /></Field>
        </div>
        <Field label="What was done" htmlFor="ov-adj"><Textarea id="ov-adj" value={f.adjustments} maxLength={5000} onChange={(e) => set('adjustments', e.target.value)} /></Field>
        <Field label="Elastics" htmlFor="ov-el"><Input id="ov-el" value={f.elastics} maxLength={150} onChange={(e) => set('elastics', e.target.value)} /></Field>
        <Field label="Notes" htmlFor="ov-notes"><Textarea id="ov-notes" value={f.notes} maxLength={5000} onChange={(e) => set('notes', e.target.value)} /></Field>
      </div>
    </Modal>
  );
}

/* ------------------------------------------------------------------- Lab work */

function LabSection({ data }: { data: SpecialtyData }) {
  const [ordering, setOrdering] = useState(false);
  const [cancelling, setCancelling] = useState<Lab | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const advance = (lab: Lab, step: { step: string; url: string }) => {
    if (step.step === 'cancel') {
      setCancelling(lab);

      return;
    }
    router.post(step.url, {}, { preserveScroll: true, onStart: () => setBusyId(lab.id), onFinish: () => setBusyId(null) });
  };

  return (
    <section className={CARD} aria-label="Lab work">
      <Head title="Lab work" hint="Crowns, bridges, dentures and aligners made by a lab: order, track and fit." action={data.can_write && <Button tone="primary" onClick={() => setOrdering(true)}><Plus className="h-4 w-4" aria-hidden="true" /> Order lab work</Button>} />
      {data.lab.length === 0 ? (
        <p className="mt-4 rounded-xl bg-cx-slate-bg/60 px-4 py-6 text-center text-[13px] text-cx-text-2">No lab work ordered for this patient.</p>
      ) : (
        <ul className="mt-4 space-y-3">
          {data.lab.map((lab) => (
            <li key={lab.id} className="rounded-2xl border border-cx-line/80 p-4">
              <div className="flex flex-wrap items-center gap-3">
                <div className="min-w-0 flex-1"><p className="text-[14px] font-semibold text-cx-nav-ink">{lab.restoration}{lab.teeth.length > 0 ? ` · tooth ${lab.teeth.join(', ')}` : ''}</p><p className="text-[12px] text-cx-text-3">{[lab.number, lab.laboratory, lab.due && `due ${day(lab.due)}`].filter(Boolean).join(' · ')}</p></div>
                <Chip value={lab.status} />
              </div>
              <div className="mt-2 space-y-0.5"><Row label="Shade">{lab.shade}</Row><Row label="Material">{lab.material}</Row><Row label="Notes">{lab.notes}</Row><Row label="Cancelled because">{lab.cancellation_reason}</Row></div>
              {lab.steps.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2">
                  {lab.steps.map((s) => <Button key={s.step} size="sm" tone={s.step === 'cancel' ? 'danger' : 'secondary'} disabled={busyId === lab.id} onClick={() => advance(lab, s)}>{STEP_LABEL[s.step] ?? humanize(s.step)}</Button>)}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      {ordering && <LabDialog data={data} onClose={() => setOrdering(false)} />}
      {cancelling && <CancelLabDialog lab={cancelling} onClose={() => setCancelling(null)} />}
    </section>
  );
}

function LabDialog({ data, onClose }: { data: SpecialtyData; onClose: () => void }) {
  const [f, setF] = useState({ restoration: '', teeth: '', laboratory_name: '', shade: '', material: '', delivery_due_date: '', notes: '' });
  const { busy, send } = usePost(onClose);
  const set = (key: keyof typeof f, value: string) => setF((c) => ({ ...c, [key]: value }));
  const teeth = toothList(f.teeth);

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="md"
      title="Order lab work"
      footer={<><Button onClick={onClose} disabled={busy}>Cancel</Button><Button tone="primary" loading={busy} disabled={f.restoration.trim() === '' || teeth.length === 0} onClick={() => send(data.links.lab, { restoration: f.restoration.trim(), teeth, laboratory_name: nul(f.laboratory_name), shade: nul(f.shade), material: nul(f.material), delivery_due_date: nul(f.delivery_due_date), notes: nul(f.notes) })}>Order</Button></>}
    >
      <div className="space-y-4">
        <Field label="What is needed" htmlFor="lb-rest" required><Input id="lb-rest" value={f.restoration} maxLength={150} onChange={(e) => set('restoration', e.target.value)} placeholder="e.g. Zirconia crown" /></Field>
        <Field label="Teeth" htmlFor="lb-teeth" required hint="Tooth numbers, separated by commas."><Input id="lb-teeth" value={f.teeth} onChange={(e) => set('teeth', e.target.value)} placeholder="e.g. 36" /></Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Laboratory" htmlFor="lb-lab"><Input id="lb-lab" value={f.laboratory_name} maxLength={150} onChange={(e) => set('laboratory_name', e.target.value)} /></Field>
          <Field label="Needed by" htmlFor="lb-due"><Input id="lb-due" type="date" value={f.delivery_due_date} onChange={(e) => set('delivery_due_date', e.target.value)} /></Field>
          <Field label="Shade" htmlFor="lb-shade"><Input id="lb-shade" value={f.shade} maxLength={60} onChange={(e) => set('shade', e.target.value)} placeholder="e.g. A2" /></Field>
          <Field label="Material" htmlFor="lb-mat"><Input id="lb-mat" value={f.material} maxLength={100} onChange={(e) => set('material', e.target.value)} /></Field>
        </div>
        <Field label="Notes for the lab" htmlFor="lb-notes"><Textarea id="lb-notes" value={f.notes} maxLength={2000} onChange={(e) => set('notes', e.target.value)} /></Field>
      </div>
    </Modal>
  );
}

function CancelLabDialog({ lab, onClose }: { lab: Lab; onClose: () => void }) {
  const [reason, setReason] = useState('');
  const url = lab.steps.find((s) => s.step === 'cancel')?.url ?? '';
  const { busy, send } = usePost(onClose);

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="sm"
      title="Cancel this lab case?"
      footer={<><Button onClick={onClose} disabled={busy}>Keep it</Button><Button tone="danger" loading={busy} disabled={reason.trim().length < 3} onClick={() => send(url, { reason: reason.trim() })}>Cancel the case</Button></>}
    >
      <Field label="Why" htmlFor="lc-why" required><Textarea id="lc-why" value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} /></Field>
    </Modal>
  );
}
