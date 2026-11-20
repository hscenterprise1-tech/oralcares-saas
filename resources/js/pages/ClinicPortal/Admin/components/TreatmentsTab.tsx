import { useCallback, useEffect, useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { requestJson } from '@/clinic/http';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Input, Select, Switch, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { EmptyState, LoadingState } from '@/clinic/ui/states';
import { toast } from '@/clinic/ui/Toast';
import { formatMoney, humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { problem } from './api';

export interface ServiceLite {
  id: number;
  name: string;
  category: string | null;
  active: boolean;
  specialty: string | null;
}

export interface TreatmentsProps {
  specialties: { key: string; label: string; targets: { key: string; label: string }[] }[];
  treatment_types: string[];
  charging_models: string[];
  services: ServiceLite[];
  api: { treatments: string; prices: string };
}

interface TreatmentRow {
  id: number;
  code: string;
  name: string;
  specialty: string;
  category: string | null;
  is_active: boolean;
  versions: number;
  current_version: { id: number; version: number; treatment_type: string; charging_model: string; targets: string[] } | null;
}

interface StageDetail {
  id: number;
  position: number;
  name: string;
  description: string | null;
  duration_minutes: number | null;
  is_optional: boolean;
  planned_sessions: number | null;
  price: { amount: string } | null;
  procedures: { clinical_procedure_id: number; name: string | null; quantity: number; is_required: boolean }[];
}

interface VersionDetail {
  id: number;
  version: number;
  status: 'draft' | 'published' | 'retired';
  treatment_type: string;
  charging_model: string;
  targets: string[];
  planned_sessions: number | null;
  validity_days: number | null;
  default_duration_minutes: number | null;
  price: { amount: string } | null;
  stages: StageDetail[];
}

interface TreatmentDetail {
  id: number;
  name: string;
  code: string;
  specialty: string;
  is_active: boolean;
  current_version_id: number | null;
  versions: VersionDetail[];
}

interface FormStage {
  name: string;
  description: string;
  planned_sessions: string;
  duration_minutes: string;
  is_optional: boolean;
  price: string;
  procedures: { clinical_procedure_id: string; quantity: string; is_required: boolean }[];
}

interface Form {
  treatment_type: string;
  charging_model: string;
  targets: string[];
  planned_sessions: string;
  validity_days: string;
  default_duration_minutes: string;
  version_price: string;
  stages: FormStage[];
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';
const TYPE_LABEL: Record<string, string> = { single: 'One visit', multi_stage: 'Several stages', session_based: 'Fixed number of sessions' };
const CHARGE_LABEL: Record<string, string> = { upfront: 'One price for the whole treatment', per_stage: 'A price for each stage', per_procedure: 'Each procedure at its own price' };
const STATUS_TONE: Record<string, string> = { draft: 'bg-cx-amber-bg text-cx-amber-ink', published: 'bg-cx-green-bg text-cx-green-ink', retired: 'bg-cx-slate-bg text-cx-slate-ink' };

/** Typical stage plans, to start from. The owner changes every name, count and procedure. */
const PRESETS: Record<string, { label: string; type: string; charging: string; stages: [string, number][] }[]> = {
  dental: [
    { label: 'Root canal (RCT)', type: 'multi_stage', charging: 'upfront', stages: [['Access and cleaning', 1], ['Filling the canals', 1], ['Crown or cap', 1]] },
    { label: 'Dental implant', type: 'multi_stage', charging: 'per_stage', stages: [['Surgery: fixture placement', 1], ['Healing review', 1], ['Abutment', 1], ['Crown', 2]] },
    { label: 'Braces / orthodontics', type: 'multi_stage', charging: 'upfront', stages: [['Records and bonding', 2], ['Monthly adjustments', 18], ['Debonding and retainers', 2]] },
    { label: 'Crown or bridge', type: 'multi_stage', charging: 'upfront', stages: [['Preparation and impression', 1], ['Fitting', 1]] },
    { label: 'Gum treatment (per quadrant course)', type: 'session_based', charging: 'upfront', stages: [['Deep cleaning sessions', 4]] },
  ],
};

const blankStage = (): FormStage => ({ name: '', description: '', planned_sessions: '', duration_minutes: '', is_optional: false, price: '', procedures: [] });
const num = (value: string): number | null => (value.trim() === '' ? null : Number(value));

function formFrom(version: VersionDetail | null): Form {
  return {
    treatment_type: version?.treatment_type ?? 'multi_stage',
    charging_model: version?.charging_model ?? 'upfront',
    targets: version?.targets ?? [],
    planned_sessions: version?.planned_sessions != null ? String(version.planned_sessions) : '',
    validity_days: version?.validity_days != null ? String(version.validity_days) : '',
    default_duration_minutes: version?.default_duration_minutes != null ? String(version.default_duration_minutes) : '',
    version_price: version?.price ? String(Number(version.price.amount)) : '',
    stages: (version?.stages ?? []).map((s) => ({
      name: s.name,
      description: s.description ?? '',
      planned_sessions: s.planned_sessions != null ? String(s.planned_sessions) : '',
      duration_minutes: s.duration_minutes != null ? String(s.duration_minutes) : '',
      is_optional: s.is_optional,
      price: s.price ? String(Number(s.price.amount)) : '',
      procedures: s.procedures.map((p) => ({ clinical_procedure_id: String(p.clinical_procedure_id), quantity: String(p.quantity), is_required: p.is_required })),
    })),
  };
}

const configOf = (form: Form) => ({
  treatment_type: form.treatment_type,
  charging_model: form.charging_model,
  targets: form.targets,
  planned_sessions: num(form.planned_sessions),
  validity_days: num(form.validity_days),
  default_duration_minutes: num(form.default_duration_minutes),
  stages: form.stages.map((s) => ({
    name: s.name.trim(),
    description: s.description.trim() || null,
    planned_sessions: num(s.planned_sessions),
    duration_minutes: num(s.duration_minutes),
    is_optional: s.is_optional,
    procedures: s.procedures.filter((p) => p.clinical_procedure_id !== '').map((p) => ({ clinical_procedure_id: Number(p.clinical_procedure_id), quantity: Math.max(1, Number(p.quantity) || 1), is_required: p.is_required })),
  })),
});

/** The clinic's treatments: each with stages, the services done in each stage, and its price. A change is a new version; patients already on a treatment keep the version they started on. */
export function TreatmentsTab(props: TreatmentsProps) {
  const [rows, setRows] = useState<TreatmentRow[] | null>(null);
  const [failed, setFailed] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [openId, setOpenId] = useState<number | null>(null);
  const [specialty, setSpecialty] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await requestJson<{ data: TreatmentRow[] }>('GET', props.api.treatments);
      setRows(result.data);
      setFailed(null);
    } catch (error) {
      setFailed(problem(error));
    }
  }, [props.api.treatments]);

  useEffect(() => {
    void load();
  }, [load]);

  const toggle = async (row: TreatmentRow, active: boolean) => {
    setBusyId(row.id);
    try {
      await requestJson('POST', `${props.api.treatments}/${row.id}/active`, { body: { active } });
      await load();
    } catch (error) {
      toast.error('Could not change this treatment', problem(error));
    } finally {
      setBusyId(null);
    }
  };

  const shown = (rows ?? []).filter((r) => specialty === '' || r.specialty === specialty);
  const label = (key: string) => props.specialties.find((s) => s.key === key)?.label ?? humanize(key);

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-3">
        {props.specialties.length > 1 && (
          <div className="w-full sm:w-56">
            <Select aria-label="Specialty" value={specialty} onChange={(e) => setSpecialty(e.target.value)} options={[{ value: '', label: 'All specialties' }, ...props.specialties.map((s) => ({ value: s.key, label: s.label }))]} />
          </div>
        )}
        <Button tone="primary" className="ml-auto" onClick={() => setCreating(true)}><Plus className="h-4 w-4" aria-hidden="true" /> New treatment</Button>
      </div>

      {failed ? (
        <Notice tone="danger">{failed}</Notice>
      ) : rows === null ? (
        <LoadingState label="Loading treatments" rows={4} />
      ) : shown.length === 0 ? (
        <EmptyState title="No treatments yet" description="A treatment is a course with stages, such as a root canal, an implant or braces. Create one and set its stages and price." action={<Button tone="primary" onClick={() => setCreating(true)}>New treatment</Button>} />
      ) : (
        <ul className={cn(SURFACE, 'divide-y divide-cx-line/60')}>
          {shown.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
              <button type="button" onClick={() => setOpenId(row.id)} className="min-w-0 flex-1 basis-56 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35">
                <p className={cn('truncate text-[14px] font-semibold', row.is_active ? 'text-cx-nav-ink' : 'text-cx-text-3')}>{row.name}</p>
                <p className="text-[12px] text-cx-text-3">
                  {row.code}
                  {row.category ? ` · ${row.category}` : ''}
                  {props.specialties.length > 1 ? ` · ${label(row.specialty)}` : ''}
                </p>
              </button>
              <p className="w-64 text-[12.5px] text-cx-text-2">
                {row.current_version ? `Version ${row.current_version.version} · ${TYPE_LABEL[row.current_version.treatment_type] ?? humanize(row.current_version.treatment_type)}` : 'Not published yet'}
              </p>
              <div className="flex items-center gap-3">
                <Switch aria-label={`${row.name} switched ${row.is_active ? 'on' : 'off'}`} checked={row.is_active} disabled={busyId === row.id} onCheckedChange={(on) => toggle(row, on)} />
                <Button size="sm" onClick={() => setOpenId(row.id)}>Open</Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {creating && <CreateDialog {...props} onClose={() => setCreating(false)} onCreated={(id) => { setCreating(false); void load(); setOpenId(id); }} />}
      {openId !== null && <EditorDialog {...props} id={openId} onClose={() => { setOpenId(null); void load(); }} />}
    </div>
  );
}

function CreateDialog({ specialties, api, onClose, onCreated }: TreatmentsProps & { onClose: () => void; onCreated: (id: number) => void }) {
  const [f, setF] = useState({ specialty_key: specialties[0]?.key ?? '', name: '', code: '', category: '', description: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const save = async () => {
    setBusy(true);
    setError(null);
    const code = f.code.trim() || `TRT-${f.name.trim().replace(/[^A-Za-z0-9]+/g, '').slice(0, 6).toUpperCase()}${Math.floor(Math.random() * 90 + 10)}`;
    try {
      const result = await requestJson<{ data: { id: number } }>('POST', api.treatments, { body: { specialty_key: f.specialty_key, code, name: f.name.trim(), category: f.category.trim() || null, description: f.description.trim() || null } });
      toast.success('Treatment created', 'Now set its stages and price.');
      onCreated(result.data.id);
    } catch (failure) {
      setError(problem(failure));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="md"
      title="New treatment"
      description="Give it a name first. Stages, procedures and price come next."
      footer={<><Button onClick={onClose} disabled={busy}>Cancel</Button><Button tone="primary" onClick={save} loading={busy} disabled={f.name.trim() === '' || f.specialty_key === ''}>Create</Button></>}
    >
      <div className="space-y-4">
        {error && <Notice tone="danger">{error}</Notice>}
        <Field label="Treatment name" htmlFor="tr-name" required><Input id="tr-name" value={f.name} maxLength={160} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="e.g. Root canal with crown" /></Field>
        <div className="grid gap-4 sm:grid-cols-2">
          {specialties.length > 1 && <Field label="Specialty" htmlFor="tr-spec"><Select id="tr-spec" value={f.specialty_key} onChange={(e) => setF({ ...f, specialty_key: e.target.value })} options={specialties.map((s) => ({ value: s.key, label: s.label }))} /></Field>}
          <Field label="Category" htmlFor="tr-cat"><Input id="tr-cat" value={f.category} maxLength={80} onChange={(e) => setF({ ...f, category: e.target.value })} placeholder="e.g. Endodontics" /></Field>
          <Field label="Code" htmlFor="tr-code" hint="Leave empty to generate one."><Input id="tr-code" value={f.code} maxLength={40} onChange={(e) => setF({ ...f, code: e.target.value })} /></Field>
        </div>
        <Field label="Description" htmlFor="tr-desc"><Textarea id="tr-desc" value={f.description} maxLength={2000} onChange={(e) => setF({ ...f, description: e.target.value })} /></Field>
      </div>
    </Modal>
  );
}

function EditorDialog({ id, specialties, treatment_types, charging_models, services, api, onClose }: TreatmentsProps & { id: number; onClose: () => void }) {
  const [detail, setDetail] = useState<TreatmentDetail | null>(null);
  const [selected, setSelected] = useState<number | null>(null);
  const [form, setForm] = useState<Form>(formFrom(null));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(
    async (prefer?: number) => {
      const result = await requestJson<{ data: TreatmentDetail }>('GET', `${api.treatments}/${id}`);
      const versions = result.data.versions;
      const draft = versions.find((v) => v.status === 'draft');
      const pick = versions.find((v) => v.id === prefer) ?? draft ?? versions.find((v) => v.id === result.data.current_version_id) ?? versions[versions.length - 1] ?? null;
      setDetail(result.data);
      setSelected(pick?.id ?? null);
      setForm(formFrom(pick ?? null));

      return result.data;
    },
    [api.treatments, id],
  );

  useEffect(() => {
    load().catch((failure) => setError(problem(failure)));
  }, [load]);

  const specialty = specialties.find((s) => s.key === detail?.specialty);
  const version = detail?.versions.find((v) => v.id === selected) ?? null;
  const editable = version === null || version.status === 'draft';
  const usable = useMemo(() => services.filter((s) => s.active && (s.specialty === null || s.specialty === detail?.specialty)), [services, detail?.specialty]);
  const presets = PRESETS[detail?.specialty ?? ''] ?? [];

  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((f) => ({ ...f, [key]: value }));
  const setStage = (index: number, patch: Partial<FormStage>) => setForm((f) => ({ ...f, stages: f.stages.map((s, i) => (i === index ? { ...s, ...patch } : s)) }));
  const move = (index: number, by: -1 | 1) => setForm((f) => {
    const next = [...f.stages];
    const to = index + by;
    if (to < 0 || to >= next.length) return f;
    [next[index], next[to]] = [next[to], next[index]];

    return { ...f, stages: next };
  });

  /** Saves the draft, then prices what was entered: stages are rewritten on every save, so their prices are set after it. */
  const saveDraft = async (): Promise<number> => {
    const body = configOf(form);
    let versionId = version?.id ?? null;
    if (versionId === null) {
      versionId = (await requestJson<{ data: { id: number } }>('POST', `${api.treatments}/${id}/versions`, { body })).data.id;
    } else {
      await requestJson('PUT', `${api.treatments}/${id}/versions/${versionId}`, { body });
    }
    const fresh = await requestJson<{ data: TreatmentDetail }>('GET', `${api.treatments}/${id}`);
    const saved = fresh.data.versions.find((v) => v.id === versionId);
    if (form.charging_model === 'upfront' && form.version_price.trim() !== '') {
      await requestJson('POST', api.prices, { body: { target: 'catalog_treatment_version_id', target_id: versionId, amount: Number(form.version_price) } });
    }
    if (form.charging_model === 'per_stage') {
      for (const [index, stage] of form.stages.entries()) {
        const row = saved?.stages[index];
        if (row && stage.price.trim() !== '') {
          await requestJson('POST', api.prices, { body: { target: 'catalog_stage_id', target_id: row.id, amount: Number(stage.price) } });
        }
      }
    }

    return versionId;
  };

  const run = async (action: () => Promise<number | void>, done: string) => {
    setBusy(true);
    setError(null);
    try {
      const prefer = await action();
      await load(typeof prefer === 'number' ? prefer : undefined);
      toast.success(done);
    } catch (failure) {
      setError(problem(failure));
    } finally {
      setBusy(false);
    }
  };

  const startNew = () => run(async () => (await requestJson<{ data: { id: number } }>('POST', `${api.treatments}/${id}/versions`, { body: { from_version_id: version?.id } })).data.id, 'New version started');
  const publish = () => run(async () => { const vid = await saveDraft(); await requestJson('POST', `${api.treatments}/${id}/versions/${vid}/publish`); return vid; }, 'Published. New patients start on this version.');

  const applyPreset = (index: number) => {
    const p = presets[index];
    if (!p) return;
    setForm((f) => ({ ...f, treatment_type: p.type, charging_model: p.charging, stages: p.stages.map(([name, sessions]) => ({ ...blankStage(), name, planned_sessions: String(sessions) })) }));
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="xl"
      title={detail ? detail.name : 'Treatment'}
      description={detail ? `${detail.code}${detail.is_active ? '' : ' · switched off'}` : undefined}
      footer={
        detail && (
          editable ? (
            <>
              <Button onClick={onClose} disabled={busy}>Close</Button>
              <Button onClick={() => run(saveDraft, 'Draft saved')} loading={busy} disabled={form.stages.length === 0 || form.stages.some((s) => s.name.trim() === '')}>Save draft</Button>
              <Button tone="primary" onClick={publish} loading={busy} disabled={form.stages.length === 0 || form.stages.some((s) => s.name.trim() === '')}>Save and publish</Button>
            </>
          ) : (
            <>
              <Button onClick={onClose}>Close</Button>
              <Button tone="primary" onClick={startNew} loading={busy}>Change it: start a new version</Button>
            </>
          )
        )
      }
    >
      {!detail ? (
        error ? <Notice tone="danger">{error}</Notice> : <LoadingState label="Loading the treatment" rows={4} />
      ) : (
        <div className="space-y-5">
          {error && <Notice tone="danger">{error}</Notice>}

          {detail.versions.length > 0 && (
            <div className="flex flex-wrap gap-2" role="tablist" aria-label="Versions">
              {detail.versions.map((v) => (
                <button key={v.id} type="button" role="tab" aria-selected={v.id === selected} onClick={() => { setSelected(v.id); setForm(formFrom(v)); setError(null); }} className={cn('inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold', v.id === selected ? 'border-[#1769AA] bg-cx-blue-bg text-cx-blue-ink' : 'border-cx-line/80 text-cx-text-2 hover:bg-cx-slate-bg')}>
                  Version {v.version}
                  <span className={cn('rounded-full px-2 py-0.5 text-[10.5px]', STATUS_TONE[v.status])}>{humanize(v.status)}</span>
                </button>
              ))}
            </div>
          )}
          {!editable && <Notice tone="info">A published version never changes. Patients who started on it keep it. Start a new version to change the stages or the price.</Notice>}
          {version === null && presets.length > 0 && (
            <Field label="Start from a typical plan" htmlFor="tr-preset" hint="Fills in the stages. You can change everything.">
              <Select id="tr-preset" value="" onChange={(e) => e.target.value !== '' && applyPreset(Number(e.target.value))} options={[{ value: '', label: 'Choose…' }, ...presets.map((p, i) => ({ value: String(i), label: p.label }))]} />
            </Field>
          )}

          <fieldset disabled={!editable || busy} className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              <Field label="Kind of treatment" htmlFor="tv-type"><Select id="tv-type" value={form.treatment_type} onChange={(e) => set('treatment_type', e.target.value)} options={treatment_types.map((t) => ({ value: t, label: TYPE_LABEL[t] ?? humanize(t) }))} /></Field>
              <Field label="How it is charged" htmlFor="tv-charge"><Select id="tv-charge" value={form.charging_model} onChange={(e) => set('charging_model', e.target.value)} options={charging_models.map((t) => ({ value: t, label: CHARGE_LABEL[t] ?? humanize(t) }))} /></Field>
              {form.charging_model === 'upfront' && (
                <Field label="Price for the whole treatment (₹)" htmlFor="tv-price"><Input id="tv-price" type="number" min={0} step={100} value={form.version_price} onChange={(e) => set('version_price', e.target.value)} /></Field>
              )}
              <Field label="Total sessions" htmlFor="tv-sessions" hint={form.treatment_type === 'session_based' ? 'Needed for this kind.' : 'Optional.'}><Input id="tv-sessions" type="number" min={1} value={form.planned_sessions} onChange={(e) => set('planned_sessions', e.target.value)} /></Field>
              <Field label="Valid for (days)" htmlFor="tv-valid" hint="Optional. For example a package that expires."><Input id="tv-valid" type="number" min={1} value={form.validity_days} onChange={(e) => set('validity_days', e.target.value)} /></Field>
              <Field label="Usual visit time (minutes)" htmlFor="tv-dur"><Input id="tv-dur" type="number" min={1} value={form.default_duration_minutes} onChange={(e) => set('default_duration_minutes', e.target.value)} /></Field>
            </div>
            {specialty && specialty.targets.length > 0 && (
              <fieldset>
                <legend className="mb-2 text-[12px] font-semibold text-[#173B60]">Done on</legend>
                <div className="flex flex-wrap gap-x-6 gap-y-2">
                  {specialty.targets.map((t) => <Checkbox key={t.key} checked={form.targets.includes(t.key)} onCheckedChange={(on) => set('targets', on ? [...form.targets, t.key] : form.targets.filter((k) => k !== t.key))} label={t.label} />)}
                </div>
              </fieldset>
            )}

            <section aria-label="Stages">
              <div className="mb-3 flex items-center justify-between">
                <h3 className="text-[14px] font-semibold text-cx-nav-ink">Stages</h3>
                {editable && <Button size="sm" onClick={() => set('stages', [...form.stages, blankStage()])}><Plus className="h-4 w-4" aria-hidden="true" /> Add stage</Button>}
              </div>
              {form.stages.length === 0 ? (
                <p className="rounded-xl border border-dashed border-cx-line px-4 py-6 text-center text-[13px] text-cx-text-3">No stages yet. A treatment needs at least one.</p>
              ) : (
                <ol className="space-y-3">
                  {form.stages.map((stage, index) => (
                    <li key={index} className="rounded-2xl border border-cx-line/80 bg-cx-slate-bg/30 p-4">
                      <div className="flex flex-wrap items-start gap-3">
                        <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white text-[13px] font-semibold text-cx-nav-ink shadow-cx-card">{index + 1}</span>
                        <div className="min-w-0 flex-1 basis-64"><Input aria-label={`Stage ${index + 1} name`} value={stage.name} maxLength={160} placeholder="Stage name, e.g. Access and cleaning" onChange={(e) => setStage(index, { name: e.target.value })} /></div>
                        {editable && (
                          <div className="flex items-center gap-1">
                            <button type="button" aria-label="Move up" disabled={index === 0} onClick={() => move(index, -1)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-white disabled:opacity-40"><ArrowUp className="h-4 w-4" aria-hidden="true" /></button>
                            <button type="button" aria-label="Move down" disabled={index === form.stages.length - 1} onClick={() => move(index, 1)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-white disabled:opacity-40"><ArrowDown className="h-4 w-4" aria-hidden="true" /></button>
                            <button type="button" aria-label={`Remove stage ${index + 1}`} onClick={() => set('stages', form.stages.filter((_, i) => i !== index))} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink"><Trash2 className="h-4 w-4" aria-hidden="true" /></button>
                          </div>
                        )}
                      </div>
                      <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Field label="Sessions" htmlFor={`st-s-${index}`}><Input id={`st-s-${index}`} type="number" min={1} value={stage.planned_sessions} onChange={(e) => setStage(index, { planned_sessions: e.target.value })} /></Field>
                        <Field label="Minutes per visit" htmlFor={`st-d-${index}`}><Input id={`st-d-${index}`} type="number" min={1} value={stage.duration_minutes} onChange={(e) => setStage(index, { duration_minutes: e.target.value })} /></Field>
                        {form.charging_model === 'per_stage' && <Field label="Price (₹)" htmlFor={`st-p-${index}`}><Input id={`st-p-${index}`} type="number" min={0} step={100} value={stage.price} onChange={(e) => setStage(index, { price: e.target.value })} /></Field>}
                        <div className="flex items-end pb-2"><Checkbox checked={stage.is_optional} onCheckedChange={(on) => setStage(index, { is_optional: on })} label="Optional stage" /></div>
                      </div>
                      <div className="mt-3">
                        <p className="mb-2 text-[12px] font-semibold text-[#173B60]">Services done in this stage{form.charging_model === 'per_procedure' ? ' (charged at their own prices)' : ''}</p>
                        <ul className="space-y-2">
                          {stage.procedures.map((p, pi) => (
                            <li key={pi} className="flex flex-wrap items-center gap-2">
                              <div className="min-w-0 flex-1 basis-56">
                                <Select aria-label="Service" value={p.clinical_procedure_id} onChange={(e) => setStage(index, { procedures: stage.procedures.map((x, i) => (i === pi ? { ...x, clinical_procedure_id: e.target.value } : x)) })} options={[{ value: '', label: 'Choose a service' }, ...usable.map((s) => ({ value: String(s.id), label: `${s.name}${s.category ? ` · ${s.category}` : ''}` }))]} />
                              </div>
                              <Input aria-label="How many" type="number" min={1} max={100} value={p.quantity} onChange={(e) => setStage(index, { procedures: stage.procedures.map((x, i) => (i === pi ? { ...x, quantity: e.target.value } : x)) })} className="w-20" />
                              <Checkbox checked={p.is_required} onCheckedChange={(on) => setStage(index, { procedures: stage.procedures.map((x, i) => (i === pi ? { ...x, is_required: on } : x)) })} label="Required" />
                              {editable && <button type="button" aria-label="Remove service" onClick={() => setStage(index, { procedures: stage.procedures.filter((_, i) => i !== pi) })} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink"><Trash2 className="h-4 w-4" aria-hidden="true" /></button>}
                            </li>
                          ))}
                        </ul>
                        {editable && <Button size="sm" className="mt-2" onClick={() => setStage(index, { procedures: [...stage.procedures, { clinical_procedure_id: '', quantity: '1', is_required: true }] })}><Plus className="h-4 w-4" aria-hidden="true" /> Add service</Button>}
                      </div>
                    </li>
                  ))}
                </ol>
              )}
            </section>
          </fieldset>

          {form.charging_model === 'per_procedure' && <Notice tone="info">Each service is billed at the price set on the Services tab. Make sure every service used here has a price.</Notice>}
          {form.charging_model === 'upfront' && form.version_price !== '' && <p className="text-right text-[13px] text-cx-text-2">Total: <span className="font-semibold text-cx-nav-ink">{formatMoney(Number(form.version_price))}</span></p>}
        </div>
      )}
    </Modal>
  );
}
