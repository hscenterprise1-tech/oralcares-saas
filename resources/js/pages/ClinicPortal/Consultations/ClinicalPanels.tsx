import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Activity, CheckCircle2, Dumbbell, Plus, Smile, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Checkbox, Field, Input, Select, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { humanize } from '@/clinic/ui/visuals';
import { ClinicHttpError, requestJson } from '@/clinic/http';

/*
 * The consultation's specialty work, written through the existing endpoints
 * (each authorises on the encounter and the clinic's modules):
 *
 * - Dental (`odontogram`): chart findings by tooth and surface, propose and
 *   accept plans, complete or cancel planned items, gum charting, lab cases.
 * - Physio (`physio_assessment`): the assessment with pain and outcomes.
 * - Treatments (both): start a treatment, work its stages, its plan of care
 *   and exercises, open / save / complete a session (with a package), sell
 *   a package — the treatment engine (`TreatmentCareController`).
 *
 * Every save goes to the server; the screen reloads its data from the
 * server's answer, never from what was typed.
 */

type Opt = { value: string; label: string };
const opts = (map: Record<string, string | { label: string }>): Opt[] => Object.entries(map).map(([value, entry]) => ({ value, label: typeof entry === 'string' ? entry : entry.label }));
const refresh = () => router.reload({ only: ['care', 'dental', 'physio', 'procedures', 'diagnoses'] });

function failure(error: unknown): string {
  const body = error instanceof ClinicHttpError ? (error.body as { message?: string; errors?: Record<string, string[]> } | null) : null;

  return Object.values(body?.errors ?? {})[0]?.[0] ?? body?.message ?? 'Not saved. Try again.';
}

function Block({ title, icon: Icon, aside, children }: { title: string; icon: typeof Activity; aside?: ReactNode; children: ReactNode }) {
  const id = `cp-${title.toLowerCase().replace(/\W+/g, '-')}`;

  return (
    <Card as="section" aria-labelledby={id} className="p-4 sm:p-5">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 id={id} className="flex items-center gap-2 text-[16px] font-semibold text-cx-text">
          <Icon className="h-[18px] w-[18px] text-cx-m-blue" aria-hidden="true" />
          {title}
        </h2>
        {aside}
      </div>
      {children}
    </Card>
  );
}

function Problem({ text }: { text: string | null }) {
  return text ? (
    <p role="alert" className="mb-2 rounded-[10px] bg-cx-rose-bg px-3 py-2 text-[13px] font-semibold text-cx-rose-ink">
      {text}
    </p>
  ) : null;
}

/** Inertia form posts (redirect endpoints) with the error shown, then the data refreshed from the server. */
function usePost() {
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const post = (url: string, data: Record<string, unknown>, onDone?: () => void) =>
    router.post(url, data as never, {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'Not saved.'),
      onSuccess: (page) => {
        const errors = (page.props.errors ?? {}) as Record<string, string>;
        if (Object.keys(errors).length) {
          setProblem(Object.values(errors)[0]);

          return;
        }
        setProblem(null);
        onDone?.();
      },
    });

  return { problem, setProblem, busy, post };
}

/* ================================================================ Dental */

type DentalProps = {
  teeth: { tooth: number; status: string; surfaces: string[]; notes: string | null }[];
  conditions: Record<string, { label: string; surface: boolean; category?: string | null }>;
  surfaces: Record<string, string>;
  plans: {
    id: number;
    title: string;
    status: string;
    notes: string | null;
    accept_url: string;
    items: { id: number; description: string; tooth: string | number | null; surfaces: string[]; status: string; complete_url: string; cancel_url: string }[];
  }[];
  links: { chart: string; plans: string; periodontal: string; lab: string; periodontal_vocabulary: { stages: string[]; grades: string[]; extents: Record<string, string> } };
  can_write: boolean;
};

const UPPER = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
const LOWER = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];


export function DentalPanel({ dental, services, open }: { dental: DentalProps; services: { id: number; name: string; code: string | null }[]; open: boolean }) {
  const [tooth, setTooth] = useState<number | null>(null);
  const [tab, setTab] = useState<'finding' | 'plan' | 'gums' | 'lab'>('finding');
  const record = dental.teeth.find((row) => row.tooth === tooth);
  const charted = useMemo(() => new Map(dental.teeth.map((row) => [row.tooth, row])), [dental.teeth]);
  const writable = open && dental.can_write;

  const cell = (number: number) => {
    const row = charted.get(number);
    const status = row?.status;
    const tone =
      !status || status === 'sound'
        ? 'border-cx-line bg-white'
        : status === 'missing' || status === 'extracted'
          ? 'border-cx-line bg-cx-slate-bg text-cx-text-3'
          : ['caries', 'recurrent_caries', 'fractured', 'abscess', 'periapical'].includes(status)
            ? 'border-[#F2C4C1] bg-cx-rose-bg'
            : 'border-[#BFD6FA] bg-cx-m-blue-bg';

    return (
      <button
        key={number}
        type="button"
        onClick={() => setTooth(number)}
        aria-pressed={tooth === number}
        aria-label={`Tooth ${number}${status ? `: ${dental.conditions[status]?.label ?? humanize(status)}` : ''}`}
        className={cn(
          'grid h-11 place-items-center rounded-[9px] border-2 text-[12.5px] font-bold tabular-nums focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan',
          tone,
          tooth === number && 'ring-2 ring-cx-cyan',
        )}
      >
        {number}
      </button>
    );
  };

  return (
    <Block title="Dental chart" icon={Smile}>
      <div className="space-y-1.5">
        <div className="grid grid-cols-8 gap-1 sm:grid-cols-[repeat(16,minmax(0,1fr))]">{UPPER.map(cell)}</div>
        <div className="grid grid-cols-8 gap-1 sm:grid-cols-[repeat(16,minmax(0,1fr))]">{LOWER.map(cell)}</div>
      </div>
      <p className="mt-2 text-[12.5px] text-cx-text-2">Choose a tooth to see or record it. Coloured teeth have findings on record.</p>

      {tooth !== null && (
        <div className="mt-3 rounded-[12px] border border-cx-line p-3" aria-live="polite">
          <p className="text-[14px] font-semibold text-cx-text">
            Tooth {tooth}
            <span className="font-normal text-cx-text-2">
              {' '}
              ·{' '}
              {record
                ? [dental.conditions[record.status]?.label ?? humanize(record.status), record.surfaces.length ? `surfaces ${record.surfaces.join('')}` : null, record.notes].filter(Boolean).join(' · ')
                : 'nothing recorded'}
            </span>
          </p>
          {writable && (
            <>
              <div role="tablist" aria-label="Record for this tooth" className="mt-2 flex flex-wrap gap-1 rounded-[10px] bg-cx-slate-bg p-1">
                {(
                  [
                    ['finding', 'Finding'],
                    ['plan', 'Add to a plan'],
                    ['gums', 'Gum charting'],
                    ['lab', 'Lab case'],
                  ] as const
                ).map(([key, label]) => (
                  <button
                    key={key}
                    type="button"
                    role="tab"
                    aria-selected={tab === key}
                    onClick={() => setTab(key)}
                    className={cn('h-8 rounded-[8px] px-3 text-[13px] font-semibold', tab === key ? 'bg-white text-cx-text shadow-sm' : 'text-cx-text-2')}
                  >
                    {label}
                  </button>
                ))}
              </div>
              <div className="mt-3">
                {tab === 'finding' && <FindingForm key={tooth} tooth={tooth} dental={dental} />}
                {tab === 'plan' && <PlanForm key={tooth} tooth={tooth} dental={dental} services={services} />}
                {tab === 'gums' && <GumForm key={tooth} tooth={tooth} dental={dental} />}
                {tab === 'lab' && <LabForm key={tooth} tooth={tooth} dental={dental} />}
              </div>
            </>
          )}
        </div>
      )}

      <PlansList dental={dental} writable={writable} />
    </Block>
  );
}

function FindingForm({ tooth, dental }: { tooth: number; dental: DentalProps }) {
  const { problem, busy, post } = usePost();
  const [condition, setCondition] = useState('');
  const [surfaces, setSurfaces] = useState<string[]>([]);
  const [note, setNote] = useState('');
  const allowsSurfaces = condition !== '' && dental.conditions[condition]?.surface;

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        post(dental.links.chart, { tooth, condition, surfaces: allowsSurfaces ? surfaces : [], note: note.trim() || null }, () => {
          setNote('');
          setSurfaces([]);
          refresh();
        });
      }}
      className="space-y-2"
    >
      <Problem text={problem} />
      <div className="grid gap-2 sm:grid-cols-[220px_minmax(0,1fr)]">
        <Field label="Finding" htmlFor={`finding-${tooth}`}>
          <Select id={`finding-${tooth}`} value={condition} onChange={(event) => setCondition(event.target.value)} placeholder="Choose" options={opts(dental.conditions)} />
        </Field>
        <Field label="Note" htmlFor={`finding-note-${tooth}`}>
          <Input id={`finding-note-${tooth}`} value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} />
        </Field>
      </div>
      {allowsSurfaces && (
        <fieldset className="flex flex-wrap items-center gap-3">
          <legend className="mb-1 text-[12.5px] font-semibold text-cx-text-2">Surfaces</legend>
          {Object.entries(dental.surfaces).map(([key, label]) => (
            <Checkbox key={key} checked={surfaces.includes(key)} onCheckedChange={(on) => setSurfaces((list) => (on ? [...list, key] : list.filter((item) => item !== key)))} label={`${key} · ${label}`} />
          ))}
        </fieldset>
      )}
      <div className="flex justify-end">
        <Button type="submit" tone="primary" disabled={condition === '' || busy || (allowsSurfaces && surfaces.length === 0)} loading={busy}>
          Record finding
        </Button>
      </div>
    </form>
  );
}

function PlanForm({ tooth, dental, services }: { tooth: number; dental: DentalProps; services: { id: number; name: string; code: string | null }[] }) {
  const { problem, busy, post } = usePost();
  const [title, setTitle] = useState('');
  const [items, setItems] = useState<{ clinical_procedure_id: string; tooth_number: number; surfaces: string[] }[]>([{ clinical_procedure_id: '', tooth_number: tooth, surfaces: [] }]);

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        post(
          dental.links.plans,
          { title: title.trim() || null, items: items.filter((item) => item.clinical_procedure_id).map((item) => ({ ...item, clinical_procedure_id: Number(item.clinical_procedure_id) })) },
          refresh,
        );
      }}
      className="space-y-2"
    >
      <Problem text={problem} />
      <Field label="Plan title (optional)" htmlFor="plan-title">
        <Input id="plan-title" value={title} maxLength={150} onChange={(event) => setTitle(event.target.value)} />
      </Field>
      {items.map((item, index) => (
        <div key={index} className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_110px_auto] sm:items-end">
          <Field label="Procedure" htmlFor={`plan-proc-${index}`}>
            <Select
              id={`plan-proc-${index}`}
              value={item.clinical_procedure_id}
              onChange={(event) => setItems((list) => list.map((row, at) => (at === index ? { ...row, clinical_procedure_id: event.target.value } : row)))}
              placeholder="Choose a service"
              options={services.map((service) => ({ value: String(service.id), label: [service.name, service.code].filter(Boolean).join(' · ') }))}
            />
          </Field>
          <Field label="Tooth" htmlFor={`plan-tooth-${index}`}>
            <Input
              id={`plan-tooth-${index}`}
              type="number"
              min={11}
              max={85}
              value={item.tooth_number}
              onChange={(event) => setItems((list) => list.map((row, at) => (at === index ? { ...row, tooth_number: Number(event.target.value) } : row)))}
            />
          </Field>
          <button
            type="button"
            aria-label="Remove this line"
            onClick={() => setItems((list) => list.filter((_, at) => at !== index))}
            disabled={items.length === 1}
            className="grid h-11 w-11 place-items-center rounded-[10px] text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink disabled:opacity-40"
          >
            <Trash2 className="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
      ))}
      <div className="flex flex-wrap justify-between gap-2">
        <Button icon={Plus} onClick={() => setItems((list) => [...list, { clinical_procedure_id: '', tooth_number: tooth, surfaces: [] }])}>
          Another procedure
        </Button>
        <Button type="submit" tone="primary" disabled={busy || items.every((item) => item.clinical_procedure_id === '')} loading={busy}>
          Propose plan
        </Button>
      </div>
    </form>
  );
}

const SITES = ['MB', 'B', 'DB', 'ML', 'L', 'DL'];

/** Gum charting for the chosen tooth: six probing depths, bleeding, mobility, furcation, and the overall classification. */
function GumForm({ tooth, dental }: { tooth: number; dental: DentalProps }) {
  const { problem, busy, post } = usePost();
  const [depths, setDepths] = useState<string[]>(['', '', '', '', '', '']);
  const [bleeding, setBleeding] = useState<boolean[]>([false, false, false, false, false, false]);
  const [mobility, setMobility] = useState('');
  const [furcation, setFurcation] = useState('');
  const [stage, setStage] = useState('');
  const [grade, setGrade] = useState('');
  const [extent, setExtent] = useState('');
  const vocabulary = dental.links.periodontal_vocabulary;

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        post(
          dental.links.periodontal,
          {
            teeth: [
              {
                tooth,
                probing_depths: depths.map((value) => (value === '' ? null : Number(value))),
                bleeding,
                mobility: mobility === '' ? null : Number(mobility),
                furcation: furcation === '' ? null : Number(furcation),
              },
            ],
            stage: stage || null,
            grade: grade || null,
            extent: extent || null,
          },
          refresh,
        );
      }}
      className="space-y-2"
    >
      <Problem text={problem} />
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
        {SITES.map((site, index) => (
          <div key={site}>
            <Field label={`${site} (mm)`} htmlFor={`pd-${site}`}>
              <Input id={`pd-${site}`} type="number" min={0} max={15} value={depths[index]} onChange={(event) => setDepths((list) => list.map((value, at) => (at === index ? event.target.value : value)))} />
            </Field>
            <Checkbox checked={bleeding[index]} onCheckedChange={(on) => setBleeding((list) => list.map((value, at) => (at === index ? on : value)))} label="Bleeds" />
          </div>
        ))}
      </div>
      <div className="grid gap-2 sm:grid-cols-5">
        <Field label="Mobility" htmlFor="perio-mobility">
          <Select id="perio-mobility" value={mobility} onChange={(event) => setMobility(event.target.value)} placeholder="—" options={['0', '1', '2', '3'].map((value) => ({ value, label: value }))} />
        </Field>
        <Field label="Furcation" htmlFor="perio-furcation">
          <Select id="perio-furcation" value={furcation} onChange={(event) => setFurcation(event.target.value)} placeholder="—" options={['0', '1', '2', '3'].map((value) => ({ value, label: value }))} />
        </Field>
        <Field label="Stage" htmlFor="perio-stage">
          <Select id="perio-stage" value={stage} onChange={(event) => setStage(event.target.value)} placeholder="—" options={vocabulary.stages.map((value) => ({ value, label: value }))} />
        </Field>
        <Field label="Grade" htmlFor="perio-grade">
          <Select id="perio-grade" value={grade} onChange={(event) => setGrade(event.target.value)} placeholder="—" options={vocabulary.grades.map((value) => ({ value, label: value }))} />
        </Field>
        <Field label="Extent" htmlFor="perio-extent">
          <Select id="perio-extent" value={extent} onChange={(event) => setExtent(event.target.value)} placeholder="—" options={opts(vocabulary.extents)} />
        </Field>
      </div>
      <div className="flex justify-end">
        <Button type="submit" tone="primary" disabled={busy} loading={busy}>
          Save gum charting
        </Button>
      </div>
    </form>
  );
}

function LabForm({ tooth, dental }: { tooth: number; dental: DentalProps }) {
  const { problem, busy, post } = usePost();
  const [form, setForm] = useState({ restoration: '', laboratory_name: '', shade: '', material: '', delivery_due_date: '', notes: '' });
  const set = (key: keyof typeof form) => (event: { target: { value: string } }) => setForm({ ...form, [key]: event.target.value });

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        post(dental.links.lab, { teeth: [tooth], ...Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()])) }, refresh);
      }}
      className="space-y-2"
    >
      <Problem text={problem} />
      <div className="grid gap-2 sm:grid-cols-3">
        <Field label="Restoration" htmlFor="lab-restoration" required>
          <Input id="lab-restoration" value={form.restoration} maxLength={150} onChange={set('restoration')} />
        </Field>
        <Field label="Laboratory" htmlFor="lab-name">
          <Input id="lab-name" value={form.laboratory_name} maxLength={150} onChange={set('laboratory_name')} />
        </Field>
        <Field label="Due back" htmlFor="lab-due">
          <Input id="lab-due" type="date" value={form.delivery_due_date} onChange={set('delivery_due_date')} />
        </Field>
        <Field label="Shade" htmlFor="lab-shade">
          <Input id="lab-shade" value={form.shade} maxLength={60} onChange={set('shade')} />
        </Field>
        <Field label="Material" htmlFor="lab-material">
          <Input id="lab-material" value={form.material} maxLength={100} onChange={set('material')} />
        </Field>
        <Field label="Notes" htmlFor="lab-notes">
          <Input id="lab-notes" value={form.notes} maxLength={2000} onChange={set('notes')} />
        </Field>
      </div>
      <div className="flex justify-end">
        <Button type="submit" tone="primary" disabled={busy || form.restoration.trim() === ''} loading={busy}>
          Send to lab
        </Button>
      </div>
    </form>
  );
}

function PlansList({ dental, writable }: { dental: DentalProps; writable: boolean }) {
  const { problem, post } = usePost();
  const [completing, setCompleting] = useState<DentalProps['plans'][number]['items'][number] | null>(null);
  const [condition, setCondition] = useState('');
  const [note, setNote] = useState('');

  if (dental.plans.length === 0) {
    return <p className="mt-3 text-[13.5px] text-cx-text-2">No treatment plan yet.</p>;
  }

  return (
    <div className="mt-4 space-y-3">
      <Problem text={problem} />
      {dental.plans.map((plan) => (
        <div key={plan.id} className="rounded-[12px] border border-cx-line p-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-[14px] font-semibold text-cx-text [overflow-wrap:anywhere]">
              {plan.title} <span className="font-normal text-cx-text-2">· {humanize(plan.status)}</span>
            </p>
            {writable && plan.status === 'proposed' && (
              <Button size="sm" onClick={() => post(plan.accept_url, {}, refresh)}>
                Patient accepted
              </Button>
            )}
          </div>
          <ul className="mt-1 divide-y divide-cx-line text-[13.5px]">
            {plan.items.map((item) => (
              <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                <span className="min-w-0 [overflow-wrap:anywhere]">
                  <span className="font-semibold text-cx-text">{item.description}</span>
                  <span className="text-cx-text-2"> · {[item.tooth ? `Tooth ${item.tooth}${item.surfaces.length ? ` (${item.surfaces.join('')})` : ''}` : null, humanize(item.status)].filter(Boolean).join(' · ')}</span>
                </span>
                {writable && ['planned', 'in_progress'].includes(item.status) && ['accepted', 'in_progress'].includes(plan.status) && (
                  <span className="flex gap-1.5">
                    <Button
                      size="sm"
                      onClick={() => {
                        setCompleting(item);
                        setCondition(item.surfaces.length ? 'filled' : '');
                      }}
                    >
                      Done today
                    </Button>
                    <Button size="sm" tone="ghost" onClick={() => post(item.cancel_url, {}, refresh)}>
                      Remove
                    </Button>
                  </span>
                )}
                {item.status === 'completed' && <CheckCircle2 className="h-4 w-4 text-cx-green-ink" aria-label="Done" />}
              </li>
            ))}
          </ul>
        </div>
      ))}
      {completing && (
        <Modal
          open
          onOpenChange={(next) => !next && setCompleting(null)}
          title={`Done today: ${completing.description}`}
          description={completing.tooth ? `Tooth ${completing.tooth}${completing.surfaces.length ? ` (${completing.surfaces.join('')})` : ''}.` : undefined}
          size="sm"
          footer={
            <>
              <Button tone="ghost" onClick={() => setCompleting(null)}>
                Back
              </Button>
              <Button
                tone="primary"
                disabled={completing.surfaces.length > 0 && condition === ''}
                onClick={() =>
                  post(completing.complete_url, { resulting_condition: condition || null, note: note.trim() || null }, () => {
                    setCompleting(null);
                    setNote('');
                    refresh();
                  })
                }
              >
                Record as done
              </Button>
            </>
          }
        >
          <div className="space-y-3">
            <Field label="Tooth afterwards" htmlFor="done-condition" hint={completing.surfaces.length ? 'Required for work on surfaces.' : 'Leave empty to keep the chart as it is.'}>
              <Select
                id="done-condition"
                value={condition}
                onChange={(event) => setCondition(event.target.value)}
                placeholder={completing.surfaces.length ? 'Choose' : 'No change'}
                options={Object.entries(dental.conditions)
                  .filter(([, entry]) => !completing.surfaces.length || entry.surface)
                  .map(([value, entry]) => ({ value, label: entry.label }))}
              />
            </Field>
            <Field label="Note" htmlFor="done-note">
              <Input id="done-note" value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} />
            </Field>
          </div>
        </Modal>
      )}
    </div>
  );
}

/* ================================================================ Physio assessment */

type PhysioProps = {
  extensions: string[];
  can_write: Record<string, boolean>;
  assessment: Record<string, unknown> | null;
  vocabulary: { functional_measures: Record<string, { label: string; max?: number }> };
  links: { assessment: string };
};

export function PhysioAssessmentPanel({ physio, open }: { physio: PhysioProps; open: boolean }) {
  const { problem, busy, post } = usePost();
  const saved = physio.assessment ?? {};
  const [form, setForm] = useState({
    presenting_problem: String(saved.presenting_problem ?? ''),
    pain_location: String(saved.pain_location ?? ''),
    onset: String(saved.onset ?? ''),
    pain_at_rest: saved.pain_at_rest != null ? String(saved.pain_at_rest) : '',
    pain_on_activity: saved.pain_on_activity != null ? String(saved.pain_on_activity) : '',
    objective_findings: String(saved.objective_findings ?? ''),
    clinical_impression: String(saved.clinical_impression ?? ''),
    diagnosis: String(saved.diagnosis ?? ''),
    goals: String(saved.goals ?? ''),
  });
  const [measures, setMeasures] = useState<{ measure: string; score: string }[]>(
    ((saved.functional_measures as { measure: string; score: number }[] | undefined) ?? []).map((row) => ({ measure: row.measure, score: String(row.score) })),
  );
  const writable = open && physio.can_write.physio_assessment;
  const set = (key: keyof typeof form) => (event: { target: { value: string } }) => setForm({ ...form, [key]: event.target.value });
  const measureOptions = opts(physio.vocabulary.functional_measures);

  if (!physio.extensions.includes('physio_assessment')) {
    return null;
  }

  return (
    <Block title="Physiotherapy assessment" icon={Activity} aside={saved && physio.assessment ? <span className="text-[12.5px] text-cx-text-2">Saved for this visit</span> : undefined}>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          post(
            physio.links.assessment,
            {
              ...Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()])),
              pain_at_rest: form.pain_at_rest === '' ? null : Number(form.pain_at_rest),
              pain_on_activity: form.pain_on_activity === '' ? null : Number(form.pain_on_activity),
              functional_measures: measures.filter((row) => row.measure && row.score !== '').map((row) => ({ measure: row.measure, score: Number(row.score) })),
            },
            refresh,
          );
        }}
        className="space-y-2"
      >
        <Problem text={problem} />
        <fieldset disabled={!writable} className="space-y-2">
          <Field label="Presenting problem" htmlFor="pa-problem">
            <Textarea id="pa-problem" value={form.presenting_problem} maxLength={5000} onChange={set('presenting_problem')} />
          </Field>
          <div className="grid gap-2 sm:grid-cols-4">
            <Field label="Where" htmlFor="pa-location">
              <Input id="pa-location" value={form.pain_location} maxLength={255} onChange={set('pain_location')} />
            </Field>
            <Field label="Onset" htmlFor="pa-onset">
              <Input id="pa-onset" value={form.onset} maxLength={255} onChange={set('onset')} />
            </Field>
            <Field label="Pain at rest (0–10)" htmlFor="pa-rest">
              <Input id="pa-rest" type="number" min={0} max={10} value={form.pain_at_rest} onChange={set('pain_at_rest')} />
            </Field>
            <Field label="Pain on activity (0–10)" htmlFor="pa-activity">
              <Input id="pa-activity" type="number" min={0} max={10} value={form.pain_on_activity} onChange={set('pain_on_activity')} />
            </Field>
          </div>
          <Field label="Objective findings" htmlFor="pa-objective">
            <Textarea id="pa-objective" value={form.objective_findings} maxLength={10000} onChange={set('objective_findings')} />
          </Field>
          <div className="grid gap-2 sm:grid-cols-2">
            <Field label="Clinical impression" htmlFor="pa-impression">
              <Textarea id="pa-impression" value={form.clinical_impression} maxLength={10000} onChange={set('clinical_impression')} />
            </Field>
            <Field label="Goals" htmlFor="pa-goals">
              <Textarea id="pa-goals" value={form.goals} maxLength={5000} onChange={set('goals')} />
            </Field>
          </div>
          <Field label="Diagnosis" htmlFor="pa-diagnosis">
            <Input id="pa-diagnosis" value={form.diagnosis} maxLength={500} onChange={set('diagnosis')} />
          </Field>
          <div>
            <p className="text-[12.5px] font-semibold text-cx-text-2">Outcome measures</p>
            {measures.map((row, index) => (
              <div key={index} className="mt-1 grid gap-2 sm:grid-cols-[minmax(0,1fr)_120px_auto] sm:items-end">
                <Select
                  aria-label="Measure"
                  value={row.measure}
                  onChange={(event) => setMeasures((list) => list.map((item, at) => (at === index ? { ...item, measure: event.target.value } : item)))}
                  placeholder="Choose"
                  options={measureOptions}
                />
                <Input aria-label="Score" type="number" min={0} value={row.score} onChange={(event) => setMeasures((list) => list.map((item, at) => (at === index ? { ...item, score: event.target.value } : item)))} />
                <button
                  type="button"
                  aria-label="Remove measure"
                  onClick={() => setMeasures((list) => list.filter((_, at) => at !== index))}
                  className="grid h-11 w-11 place-items-center rounded-[10px] text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink"
                >
                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                </button>
              </div>
            ))}
            {measureOptions.length > 0 && (
              <Button size="sm" icon={Plus} className="mt-2" onClick={() => setMeasures((list) => [...list, { measure: '', score: '' }])}>
                Add a measure
              </Button>
            )}
          </div>
        </fieldset>
        {writable && (
          <div className="flex justify-end">
            <Button type="submit" tone="primary" disabled={busy} loading={busy}>
              Save assessment
            </Button>
          </div>
        )}
      </form>
    </Block>
  );
}

/* ================================================================ Treatments (both modules) */

type Exercise = { id: number; name: string; sets: number | null; reps: number | null; hold_seconds: number | null; frequency: string | null; instructions: string | null; is_home: boolean };
type SessionCard = {
  id: number;
  status: string;
  session_number: number | null;
  subjective: string | null;
  objective: string | null;
  assessment: string | null;
  plan: string | null;
  pain_before: number | null;
  pain_after: number | null;
  treatment_performed: string | null;
  home_program: string | null;
  next_step: string | null;
  completed_at: string | null;
  exercises: { id: number; physio_exercise_id: number | null; name: string; status: string | null; sets: number | null; reps: number | null; duration_seconds: number | null }[];
};
type CareCard = {
  id: number;
  title: string;
  specialty: string | null;
  specialty_label: string | null;
  status: string;
  progress: { done: number; total: number | null; unit: string };
  stages: { id: number; position: number; name: string; status: string; links: { start: string; complete: string } }[];
  runs_sessions: boolean;
  plan: { id: number; revision: number; title: string; goals: string | null; planned_sessions: number | null; frequency: string | null; exercises: Exercise[] } | null;
  packages: { id: number; name: string; remaining: number; sessions_total: number; sessions_used: number; expired: boolean }[];
  session: SessionCard | null;
  next_session_number: number;
  links: {
    open_session: string;
    session: string | null;
    complete_session: string | null;
    start_plan: string;
    revise_plan: string | null;
    close_plan: string | null;
    complete: string | null;
    sell_package: string;
  };
  encounter_id: number;
  can: { execute: boolean };
};
type CareProps = {
  treatments: CareCard[];
  can: { execute: boolean; start: boolean; sell_package: boolean };
  start: { url: string; options: string; specialties: { key: string; label: string; runs_sessions: boolean; tooth: boolean }[] };
  modalities: Record<string, string>;
};

/** JSON calls to the treatment engine, then a reload of the consultation's data from the server. */
function useJson() {
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const call = (method: 'POST' | 'PUT', url: string, body: Record<string, unknown>, onDone?: () => void) => {
    setBusy(true);
    setProblem(null);
    requestJson(method, url, { body })
      .then(() => {
        onDone?.();
        refresh();
      })
      .catch((error) => setProblem(failure(error)))
      .finally(() => setBusy(false));
  };

  return { problem, setProblem, busy, call };
}

export function CarePanel({ care, encounterId, open }: { care: CareProps; encounterId: number; open: boolean }) {
  const [starting, setStarting] = useState(false);

  return (
    <Block
      title="Treatments"
      icon={Dumbbell}
      aside={
        open && care.can.start ? (
          <Button icon={Plus} onClick={() => setStarting(true)}>
            Start a treatment
          </Button>
        ) : undefined
      }
    >
      {care.treatments.length === 0 ? (
        <p className="text-[13.5px] text-cx-text-2">No treatment under way for this patient.</p>
      ) : (
        <div className="space-y-3">
          {care.treatments.map((card) => (
            <TreatmentCard key={card.id} card={card} care={care} encounterId={encounterId} open={open} />
          ))}
        </div>
      )}
      {starting && <StartTreatment care={care} encounterId={encounterId} onClose={() => setStarting(false)} />}
    </Block>
  );
}

function TreatmentCard({ card, care, encounterId, open }: { card: CareCard; care: CareProps; encounterId: number; open: boolean }) {
  const { problem, busy, call } = useJson();
  // A completed or cancelled treatment stays on today's card for reference only.
  const underway = ['planned', 'in_progress', 'paused'].includes(card.status);
  const can = open && card.can.execute && underway;

  return (
    <div className="rounded-[12px] border border-cx-line p-3">
      <Problem text={problem} />
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-[14px] font-semibold text-cx-text [overflow-wrap:anywhere]">
          {card.title}
          <span className="font-normal text-cx-text-2">
            {' '}
            · {[card.specialty_label, humanize(card.status), card.progress.total && card.progress.total > 1 ? `${card.progress.done} of ${card.progress.total} ${card.progress.unit}` : null].filter(Boolean).join(' · ')}
          </span>
        </p>
      </div>

      {!card.runs_sessions && card.stages.length > 0 && (
        <ul className="mt-2 divide-y divide-cx-line text-[13.5px]">
          {card.stages.map((stage) => (
            <li key={stage.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
              <span className="min-w-0 [overflow-wrap:anywhere]">
                {stage.position}. <span className="font-semibold text-cx-text">{stage.name}</span> <span className="text-cx-text-2">· {humanize(stage.status)}</span>
              </span>
              {can && (
                <span className="flex gap-1.5">
                  {stage.status === 'pending' && (
                    <Button size="sm" disabled={busy} onClick={() => call('POST', stage.links.start, { encounter_id: encounterId })}>
                      Start
                    </Button>
                  )}
                  {['pending', 'in_progress'].includes(stage.status) && (
                    <Button size="sm" tone="primary" disabled={busy} onClick={() => call('POST', stage.links.complete, { encounter_id: encounterId })}>
                      Done today
                    </Button>
                  )}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}

      {card.runs_sessions && (
        <div className="mt-2 space-y-3">
          <PlanOfCare card={card} encounterId={encounterId} can={can} />
          <Session card={card} care={care} encounterId={encounterId} can={can} />
          <Packages card={card} can={open && underway && care.can.sell_package} />
          {can && card.links.complete && card.session?.status !== 'in_progress' && <Discharge card={card} />}
        </div>
      )}
    </div>
  );
}

/**
 * Discharge: the plan of care closed as completed (with the discharge note,
 * shown under Patient 360's discharges), then the treatment completed.
 */
function Discharge({ card }: { card: CareCard }) {
  const { post, problem: closeProblem } = usePost();
  const { problem, setProblem, busy, call } = useJson();
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState('');
  const complete = () => call('POST', card.links.complete as string, { note: note.trim() || null }, () => setOpen(false));
  const discharge = () => {
    setProblem(null);
    if (card.links.close_plan) {
      post(card.links.close_plan, { status: 'completed', note: note.trim() || null }, complete);
    } else {
      complete();
    }
  };

  return (
    <div className="flex justify-end">
      <Button size="sm" icon={CheckCircle2} onClick={() => setOpen(true)}>
        Discharge
      </Button>
      <Modal
        open={open}
        onOpenChange={setOpen}
        title={`Discharge: ${card.title}`}
        description="Closes the plan of care as completed and completes the treatment. Further care needs a new treatment."
        size="sm"
        footer={
          <>
            <Button tone="ghost" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button tone="primary" loading={busy} disabled={busy} onClick={discharge}>
              Discharge
            </Button>
          </>
        }
      >
        <div className="space-y-2">
          <Problem text={problem ?? closeProblem} />
          <Field label="Discharge note" htmlFor={`discharge-${card.id}`} hint="Outcome and advice, kept with the plan of care.">
            <Textarea id={`discharge-${card.id}`} value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />
          </Field>
        </div>
      </Modal>
    </div>
  );
}

function PlanOfCare({ card, encounterId, can }: { card: CareCard; encounterId: number; can: boolean }) {
  const { problem, busy, call } = useJson();
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({
    title: card.plan?.title ?? card.title,
    goals: card.plan?.goals ?? '',
    planned_sessions: card.plan?.planned_sessions ? String(card.plan.planned_sessions) : '',
    frequency: card.plan?.frequency ?? '',
  });
  const [exercises, setExercises] = useState<{ name: string; sets: string; reps: string; hold_seconds: string; is_home: boolean }[]>(
    (card.plan?.exercises ?? []).map((exercise) => ({
      name: exercise.name,
      sets: exercise.sets != null ? String(exercise.sets) : '',
      reps: exercise.reps != null ? String(exercise.reps) : '',
      hold_seconds: exercise.hold_seconds != null ? String(exercise.hold_seconds) : '',
      is_home: exercise.is_home,
    })),
  );
  const num = (value: string) => (value === '' ? null : Number(value));
  const save = () =>
    call(
      'POST',
      card.plan && card.links.revise_plan ? card.links.revise_plan : card.links.start_plan,
      {
        encounter_id: encounterId,
        title: form.title.trim() || null,
        goals: form.goals.trim() || null,
        planned_sessions: num(form.planned_sessions),
        frequency: form.frequency.trim() || null,
        exercises: exercises
          .filter((exercise) => exercise.name.trim())
          .map((exercise) => ({ name: exercise.name.trim(), sets: num(exercise.sets), reps: num(exercise.reps), hold_seconds: num(exercise.hold_seconds), is_home: exercise.is_home })),
      },
      () => setEditing(false),
    );

  return (
    <div className="rounded-[10px] bg-cx-slate-bg/60 p-3">
      <Problem text={problem} />
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-[13.5px] font-semibold text-cx-text">Plan of care{card.plan ? ` · revision ${card.plan.revision}` : ''}</p>
        {can && !editing && (
          <Button size="sm" onClick={() => setEditing(true)}>
            {card.plan ? 'Revise plan' : 'Write plan'}
          </Button>
        )}
      </div>
      {!editing ? (
        card.plan ? (
          <div className="text-[13px] text-cx-text-2">
            <p>{[card.plan.planned_sessions ? `${card.plan.planned_sessions} sessions` : null, card.plan.frequency].filter(Boolean).join(' · ')}</p>
            {card.plan.goals && <p className="text-cx-text [overflow-wrap:anywhere]">Goals: {card.plan.goals}</p>}
            {card.plan.exercises.length > 0 && (
              <p>
                Exercises:{' '}
                {card.plan.exercises.map((exercise) => [exercise.name, exercise.sets && exercise.reps ? `${exercise.sets}×${exercise.reps}` : null, exercise.is_home ? 'home' : null].filter(Boolean).join(' ')).join('; ')}
              </p>
            )}
          </div>
        ) : (
          <p className="text-[13px] text-cx-text-2">No plan written yet.</p>
        )
      ) : (
        <div className="mt-2 space-y-2">
          <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_140px_160px]">
            <Field label="Title" htmlFor={`plan-${card.id}-title`}>
              <Input id={`plan-${card.id}-title`} value={form.title} maxLength={150} onChange={(event) => setForm({ ...form, title: event.target.value })} />
            </Field>
            <Field label="Sessions" htmlFor={`plan-${card.id}-sessions`}>
              <Input id={`plan-${card.id}-sessions`} type="number" min={1} max={200} value={form.planned_sessions} onChange={(event) => setForm({ ...form, planned_sessions: event.target.value })} />
            </Field>
            <Field label="How often" htmlFor={`plan-${card.id}-freq`}>
              <Input id={`plan-${card.id}-freq`} value={form.frequency} maxLength={60} onChange={(event) => setForm({ ...form, frequency: event.target.value })} />
            </Field>
          </div>
          <Field label="Goals" htmlFor={`plan-${card.id}-goals`}>
            <Textarea id={`plan-${card.id}-goals`} value={form.goals} maxLength={5000} onChange={(event) => setForm({ ...form, goals: event.target.value })} />
          </Field>
          <p className="text-[12.5px] font-semibold text-cx-text-2">Exercises</p>
          {exercises.map((exercise, index) => (
            <div key={index} className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_80px_80px_100px_auto_auto] sm:items-center">
              <Input aria-label="Exercise" value={exercise.name} maxLength={150} onChange={(event) => setExercises((list) => list.map((row, at) => (at === index ? { ...row, name: event.target.value } : row)))} />
              <Input
                aria-label="Sets"
                type="number"
                min={0}
                max={50}
                value={exercise.sets}
                onChange={(event) => setExercises((list) => list.map((row, at) => (at === index ? { ...row, sets: event.target.value } : row)))}
                placeholder="Sets"
              />
              <Input
                aria-label="Reps"
                type="number"
                min={0}
                max={500}
                value={exercise.reps}
                onChange={(event) => setExercises((list) => list.map((row, at) => (at === index ? { ...row, reps: event.target.value } : row)))}
                placeholder="Reps"
              />
              <Input
                aria-label="Hold (s)"
                type="number"
                min={0}
                max={600}
                value={exercise.hold_seconds}
                onChange={(event) => setExercises((list) => list.map((row, at) => (at === index ? { ...row, hold_seconds: event.target.value } : row)))}
                placeholder="Hold s"
              />
              <Checkbox checked={exercise.is_home} onCheckedChange={(on) => setExercises((list) => list.map((row, at) => (at === index ? { ...row, is_home: on } : row)))} label="Home" />
              <button
                type="button"
                aria-label="Remove exercise"
                onClick={() => setExercises((list) => list.filter((_, at) => at !== index))}
                className="grid h-11 w-11 place-items-center rounded-[10px] text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink"
              >
                <Trash2 className="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
          ))}
          <div className="flex flex-wrap justify-between gap-2">
            <Button size="sm" icon={Plus} onClick={() => setExercises((list) => [...list, { name: '', sets: '', reps: '', hold_seconds: '', is_home: false }])}>
              Add exercise
            </Button>
            <span className="flex gap-1.5">
              <Button size="sm" tone="ghost" onClick={() => setEditing(false)}>
                Cancel
              </Button>
              <Button size="sm" tone="primary" disabled={busy} loading={busy} onClick={save}>
                Save plan
              </Button>
            </span>
          </div>
        </div>
      )}
    </div>
  );
}

/** Today's session: open it, write SOAP with pain before/after and the exercises done, then complete it (using a package session where there is one). */
function Session({ card, care, encounterId, can }: { card: CareCard; care: CareProps; encounterId: number; can: boolean }) {
  const { problem, setProblem, busy, call } = useJson();
  const session = card.session;
  const [form, setForm] = useState(() => ({
    subjective: session?.subjective ?? '',
    objective: session?.objective ?? '',
    assessment: session?.assessment ?? '',
    plan: session?.plan ?? '',
    pain_before: session?.pain_before != null ? String(session.pain_before) : '',
    pain_after: session?.pain_after != null ? String(session.pain_after) : '',
    treatment_performed: session?.treatment_performed ?? '',
    home_program: session?.home_program ?? '',
    next_step: session?.next_step ?? '',
  }));
  const [done, setDone] = useState<Record<number, { status: 'performed' | 'skipped'; sets: string; reps: string }>>(() =>
    Object.fromEntries(
      (card.plan?.exercises ?? []).map((exercise) => {
        const recorded = session?.exercises.find((row) => row.physio_exercise_id === exercise.id);

        return [exercise.id, { status: (recorded?.status as 'performed' | 'skipped') ?? 'performed', sets: String(recorded?.sets ?? exercise.sets ?? ''), reps: String(recorded?.reps ?? exercise.reps ?? '') }];
      }),
    ),
  );
  const [packageId, setPackageId] = useState('');
  useEffect(() => setPackageId(card.packages.find((item) => !item.expired && item.remaining > 0)?.id.toString() ?? ''), [card.packages]);
  const set = (key: keyof typeof form) => (event: { target: { value: string } }) => setForm({ ...form, [key]: event.target.value });
  const payload = () => ({
    ...Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()])),
    pain_before: form.pain_before === '' ? null : Number(form.pain_before),
    pain_after: form.pain_after === '' ? null : Number(form.pain_after),
    exercises: (card.plan?.exercises ?? []).map((exercise) => ({
      physio_exercise_id: exercise.id,
      name: exercise.name,
      status: done[exercise.id]?.status ?? 'performed',
      sets: done[exercise.id]?.sets ? Number(done[exercise.id].sets) : null,
      reps: done[exercise.id]?.reps ? Number(done[exercise.id].reps) : null,
    })),
  });

  if (!session) {
    return can ? (
      <div>
        <Problem text={problem} />
        <Button tone="primary" disabled={busy} loading={busy} onClick={() => call('POST', card.links.open_session, { encounter_id: encounterId })}>
          Open session {card.next_session_number}
        </Button>
      </div>
    ) : (
      <p className="text-[13px] text-cx-text-2">No session in this visit.</p>
    );
  }

  const completed = session.status === 'completed';

  return (
    <div className="rounded-[10px] border border-cx-line p-3">
      <Problem text={problem} />
      <p className="text-[13.5px] font-semibold text-cx-text">
        Session {session.session_number} · {completed ? 'completed' : 'in progress'}
      </p>
      <fieldset disabled={!can || completed} className="mt-2 space-y-2">
        <div className="grid gap-2 sm:grid-cols-2">
          {(['subjective', 'objective', 'assessment', 'plan'] as const).map((key) => (
            <Field key={key} label={humanize(key)} htmlFor={`s-${session.id}-${key}`}>
              <Textarea id={`s-${session.id}-${key}`} value={form[key]} maxLength={10000} onChange={set(key)} />
            </Field>
          ))}
        </div>
        <div className="grid gap-2 sm:grid-cols-4">
          <Field label="Pain before (0–10)" htmlFor={`s-${session.id}-pb`}>
            <Input id={`s-${session.id}-pb`} type="number" min={0} max={10} value={form.pain_before} onChange={set('pain_before')} />
          </Field>
          <Field label="Pain after (0–10)" htmlFor={`s-${session.id}-pa`}>
            <Input id={`s-${session.id}-pa`} type="number" min={0} max={10} value={form.pain_after} onChange={set('pain_after')} />
          </Field>
          <div className="sm:col-span-2">
            <Field label="Next step" htmlFor={`s-${session.id}-next`}>
              <Input id={`s-${session.id}-next`} value={form.next_step} maxLength={500} onChange={set('next_step')} />
            </Field>
          </div>
        </div>
        {(card.plan?.exercises.length ?? 0) > 0 && (
          <div>
            <p className="text-[12.5px] font-semibold text-cx-text-2">Exercises today</p>
            <ul className="mt-1 divide-y divide-cx-line">
              {card.plan!.exercises.map((exercise) => (
                <li key={exercise.id} className="grid gap-2 py-1.5 sm:grid-cols-[minmax(0,1fr)_140px_80px_80px] sm:items-center">
                  <span className="text-[13.5px] font-semibold text-cx-text [overflow-wrap:anywhere]">{exercise.name}</span>
                  <Select
                    aria-label={`${exercise.name}: done or skipped`}
                    value={done[exercise.id]?.status ?? 'performed'}
                    onChange={(event) => setDone({ ...done, [exercise.id]: { ...done[exercise.id], status: event.target.value as 'performed' | 'skipped' } })}
                    options={[
                      { value: 'performed', label: 'Done' },
                      { value: 'skipped', label: 'Skipped' },
                    ]}
                  />
                  <Input
                    aria-label={`${exercise.name}: sets`}
                    type="number"
                    min={0}
                    max={50}
                    value={done[exercise.id]?.sets ?? ''}
                    onChange={(event) => setDone({ ...done, [exercise.id]: { ...done[exercise.id], sets: event.target.value } })}
                  />
                  <Input
                    aria-label={`${exercise.name}: reps`}
                    type="number"
                    min={0}
                    max={500}
                    value={done[exercise.id]?.reps ?? ''}
                    onChange={(event) => setDone({ ...done, [exercise.id]: { ...done[exercise.id], reps: event.target.value } })}
                  />
                </li>
              ))}
            </ul>
          </div>
        )}
        <Field label="Home programme" htmlFor={`s-${session.id}-home`}>
          <Textarea id={`s-${session.id}-home`} value={form.home_program} maxLength={5000} onChange={set('home_program')} />
        </Field>
      </fieldset>
      {can && !completed && session && card.links.session && (
        <div className="mt-2 flex flex-wrap items-end justify-end gap-2">
          <Button disabled={busy} onClick={() => call('PUT', card.links.session as string, payload())}>
            Save session
          </Button>
          {card.packages.some((item) => !item.expired && item.remaining > 0) && (
            <div className="w-[220px]">
              <Select
                aria-label="Use a package session"
                value={packageId}
                onChange={(event) => setPackageId(event.target.value)}
                placeholder="Pay per session"
                options={card.packages.filter((item) => !item.expired && item.remaining > 0).map((item) => ({ value: String(item.id), label: `${item.name} (${item.remaining} left)` }))}
              />
            </div>
          )}
          <Button
            tone="primary"
            disabled={busy || !card.links.complete_session}
            onClick={() => {
              // Save what is on screen first, then complete.
              requestJson('PUT', card.links.session as string, { body: payload() })
                .then(() => call('POST', card.links.complete_session as string, { package_id: packageId ? Number(packageId) : null }))
                .catch((error) => setProblem(failure(error)));
            }}
          >
            Complete session
          </Button>
        </div>
      )}
    </div>
  );
}

function Packages({ card, can }: { card: CareCard; can: boolean }) {
  const { problem, busy, call } = useJson();
  const [selling, setSelling] = useState(false);
  const [form, setForm] = useState({ name: `${card.title} package`, sessions_total: '10', price: '' });
  const [key] = useState(() => `pkg-${card.id}-${Date.now()}`);

  return (
    <div>
      <Problem text={problem} />
      {card.packages.length > 0 && (
        <ul className="text-[13px] text-cx-text-2">
          {card.packages.map((item) => (
            <li key={item.id}>
              <span className="font-semibold text-cx-text">{item.name}</span> · {item.sessions_used} of {item.sessions_total} used · {item.expired ? 'expired' : `${item.remaining} left`}
            </li>
          ))}
        </ul>
      )}
      {can && !selling && (
        <Button size="sm" className="mt-1" onClick={() => setSelling(true)}>
          Sell a package
        </Button>
      )}
      {selling && (
        <div className="mt-2 grid gap-2 sm:grid-cols-[minmax(0,1fr)_110px_140px_auto] sm:items-end">
          <Field label="Package" htmlFor={`pkg-${card.id}-name`}>
            <Input id={`pkg-${card.id}-name`} value={form.name} maxLength={150} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </Field>
          <Field label="Sessions" htmlFor={`pkg-${card.id}-n`}>
            <Input id={`pkg-${card.id}-n`} type="number" min={1} max={200} value={form.sessions_total} onChange={(event) => setForm({ ...form, sessions_total: event.target.value })} />
          </Field>
          <Field label="Price (₹)" htmlFor={`pkg-${card.id}-price`}>
            <Input id={`pkg-${card.id}-price`} type="number" min={0} value={form.price} onChange={(event) => setForm({ ...form, price: event.target.value })} />
          </Field>
          <span className="flex gap-1.5">
            <Button tone="ghost" onClick={() => setSelling(false)}>
              Cancel
            </Button>
            <Button
              tone="primary"
              disabled={busy || form.name.trim() === '' || form.sessions_total === ''}
              loading={busy}
              onClick={() =>
                call('POST', card.links.sell_package, { name: form.name.trim(), sessions_total: Number(form.sessions_total), price: form.price === '' ? null : Number(form.price), idempotency_key: key }, () =>
                  setSelling(false),
                )
              }
            >
              Sell
            </Button>
          </span>
        </div>
      )}
    </div>
  );
}

type StartOption = { version_id: number; name: string; code: string | null; specialty: string; targets: { key: string; label: string }[]; price?: number | null };

/** Start a treatment in this consultation: one of the clinic's catalogued treatments (with teeth where it needs them), or a custom one. */
function StartTreatment({ care, encounterId, onClose }: { care: CareProps; encounterId: number; onClose: () => void }) {
  const { problem, busy, call } = useJson();
  const [options, setOptions] = useState<StartOption[] | null>(null);
  const [choice, setChoice] = useState('');
  const [teeth, setTeeth] = useState('');
  const [custom, setCustom] = useState({ title: '', specialty_key: care.start.specialties[0]?.key ?? '' });
  useEffect(() => {
    requestJson<{ data: StartOption[] }>('GET', care.start.options)
      .then((result) => setOptions(result.data))
      .catch(() => setOptions([]));
  }, [care.start.options]);
  const option = options?.find((item) => String(item.version_id) === choice);
  const needsTeeth = option?.targets.some((target) => target.key === 'tooth');

  const start = () => {
    if (option) {
      const toothList = teeth
        .split(/[\s,]+/)
        .map(Number)
        .filter((value) => value >= 11 && value <= 85);
      call('POST', care.start.url, { mode: 'catalog', version_id: option.version_id, targets: needsTeeth ? { tooth: toothList.map((tooth_number) => ({ tooth_number })) } : null }, onClose);
    } else {
      call('POST', care.start.url, { mode: 'adhoc', title: custom.title.trim(), specialty_key: custom.specialty_key }, onClose);
    }
  };

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title="Start a treatment"
      description="Started in this consultation, for this patient."
      size="md"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button tone="primary" loading={busy} disabled={busy || (option ? needsTeeth && teeth.trim() === '' : custom.title.trim() === '' || custom.specialty_key === '')} onClick={start}>
            Start
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Problem text={problem} />
        <Field label="Treatment" htmlFor="start-choice" hint={options === null ? 'Loading the clinic list…' : undefined}>
          <Select
            id="start-choice"
            value={choice}
            onChange={(event) => setChoice(event.target.value)}
            placeholder="Custom treatment"
            options={(options ?? []).map((item) => ({ value: String(item.version_id), label: [item.name, item.code].filter(Boolean).join(' · ') }))}
          />
        </Field>
        {option && needsTeeth && (
          <Field label="Teeth" htmlFor="start-teeth" hint="FDI numbers, separated by commas.">
            <Input id="start-teeth" value={teeth} onChange={(event) => setTeeth(event.target.value)} />
          </Field>
        )}
        {!option && (
          <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_180px]">
            <Field label="Title" htmlFor="start-title">
              <Input id="start-title" value={custom.title} maxLength={150} onChange={(event) => setCustom({ ...custom, title: event.target.value })} />
            </Field>
            <Field label="Specialty" htmlFor="start-specialty">
              <Select
                id="start-specialty"
                value={custom.specialty_key}
                onChange={(event) => setCustom({ ...custom, specialty_key: event.target.value })}
                options={care.start.specialties.map((item) => ({ value: item.key, label: item.label }))}
              />
            </Field>
          </div>
        )}
      </div>
    </Modal>
  );
}
