import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Activity, ChevronDown, Dumbbell, PackageCheck, Plus, RotateCcw, Save, Sparkles, Stethoscope, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Switch } from '@/clinic/ui/controls';
import { BTN_PRIMARY, FOCUS, IconTile } from '../Patients/components/patient360/v2/ui';
import { SettingsTabs, type SettingsLinks } from './SettingsTabs';

type Treatment = { key: string; label: string; minutes: number; price: number; active: boolean };
type Addon = { key: string; label: string; minutes: number; price: number; active: boolean };
/** `price`: a fixed (bundle) price for the visit, or null — the treatments given are charged. */
type SessionType = { key: string; label: string; treatments: string[]; price: number | null };
type Package = { id: string; name: string; sessions: number; price: number; valid_days: number; types: string[] };
type Problem = { key: string; label: string; sided: boolean; active: boolean; types: SessionType[]; packages: Package[]; diagnoses: string[]; exercises: string[] };
/** One exercise of the clinic's library (text only: no pictures or videos). */
type Exercise = { key: string; label: string; category: string | null; description: string | null; instructions: string | null; precautions: string | null; level: string | null; equipment: string | null; sets: number | null; reps: number | null; hold: number | null; active: boolean };
type Catalogue = { treatments: Treatment[]; addons: Addon[]; problems: Problem[]; exercises: Exercise[] };
type Option = { key: string; label: string };

/** `SettingsController::physio`: the server is the contract. */
type Props = {
  catalogue: Catalogue;
  exercise_options: { categories: Option[]; levels: Option[] };
  links: { update: string; self: string; patient_form: string; automation: string };
  settings_links: SettingsLinks;
};

export default function PhysioSettings(props: Props) {
  return (
    <ClinicPortalShell title="Physio problems & prices">
      <PhysioSettingsPage {...props} />
    </ClinicPortalShell>
  );
}

const field = cn('h-10 w-full rounded-xl border border-[#DCE6F3] bg-white px-3 text-[13.5px] text-cx-nav-ink', FOCUS);
const CARD = 'rounded-2xl border border-cx-line/80 bg-white p-5 shadow-cx-card sm:p-6';
const TONES: [string, string][] = [
  ['#F1EDFD', '#6B48DE'],
  ['#FDF3E7', '#E9821A'],
  ['#EAF1FD', '#2B6FD8'],
  ['#E6F6F3', '#0E9F87'],
  ['#FDECEC', '#E5484D'],
];
/** The key the server gives a new row (`PhysioCatalogue::rows`), so a new treatment can be used in a type before saving. */
const slug = (label: string) => label.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

/**
 * The owner's physiotherapy (`PhysioCatalogue`): the problems patients come
 * with, each with its session types — what a visit gives and costs — and the
 * packages sold for it; the add-ons charged on top; and the treatments the
 * types are made of. The consultation and the desk read these; nothing is
 * priced by hand there.
 */
function PhysioSettingsPage({ catalogue, exercise_options: options, links, settings_links }: Props) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const initial = useMemo(() => catalogue, [catalogue]);
  const [data, setData] = useState<Catalogue>(catalogue);
  const [open, setOpen] = useState<number | null>(catalogue.problems.length > 0 ? 0 : null);
  const [saving, setSaving] = useState(false);
  const dirty = JSON.stringify(data) !== JSON.stringify(initial);
  const savedTreatments = new Set(initial.treatments.map((t) => t.key));

  const setProblem = (index: number, change: (p: Problem) => Problem) => setData((d) => ({ ...d, problems: d.problems.map((p, i) => (i === index ? change(p) : p)) }));
  const setTreatment = (index: number, patch: Partial<Treatment>) =>
    setData((d) => {
      const old = d.treatments[index];
      // A new treatment's key follows its name until saved; the types using it follow too.
      const key = patch.label !== undefined && !savedTreatments.has(old.key) ? slug(patch.label) : old.key;
      const remap = (keys: string[]) => keys.map((k) => (k === old.key && old.key !== '' ? key : k));

      return {
        ...d,
        treatments: d.treatments.map((t, i) => (i === index ? { ...t, ...patch, key } : t)),
        problems: key === old.key ? d.problems : d.problems.map((p) => ({ ...p, types: p.types.map((t) => ({ ...t, treatments: remap(t.treatments) })) })),
      };
    });
  const savedExercises = new Set(initial.exercises.map((e) => e.key));
  const setExercise = (key: string, patch: Partial<Exercise>) =>
    setData((d) => {
      const old = d.exercises.find((e) => e.key === key);
      if (!old) return d;
      // A new exercise's key follows its name until saved; the problems using it follow too.
      const next = patch.label !== undefined && !savedExercises.has(old.key) ? slug(patch.label) || old.key : old.key;

      return {
        ...d,
        exercises: d.exercises.map((e) => (e.key === key ? { ...e, ...patch, key: next } : e)),
        problems: next === old.key ? d.problems : d.problems.map((p) => ({ ...p, exercises: p.exercises.map((k) => (k === old.key ? next : k)) })),
      };
    });
  const [exerciseSearch, setExerciseSearch] = useState('');
  const [exerciseArea, setExerciseArea] = useState<string | null>(null);
  const [openExercise, setOpenExercise] = useState<string | null>(null);
  const shownExercises = data.exercises.filter(
    (e) => (!exerciseArea || e.category === exerciseArea) && (exerciseSearch.trim() === '' || e.label.toLowerCase().includes(exerciseSearch.trim().toLowerCase())),
  );
  const setAddon = (index: number, patch: Partial<Addon>) => setData((d) => ({ ...d, addons: d.addons.map((a, i) => (i === index ? { ...a, ...patch } : a)) }));
  const save = () => router.put(links.update, data as never, { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) });
  const treatments = data.treatments.filter((t) => t.key && t.active);
  /** What a visit of this type costs when paid on the day: its fixed price, else its treatments' prices added up. */
  const visitPrice = (type: SessionType) => type.price ?? type.treatments.reduce((n, k) => n + (data.treatments.find((t) => t.key === k)?.price ?? 0), 0);

  return (
    <div className="mx-auto w-full max-w-[1100px] pb-24 lg:pb-8">
      <SettingsTabs active="physio" links={settings_links} />

      <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-[12px] font-semibold uppercase tracking-[0.06em] text-[#5B3BC4]">Settings</p>
          <h1 className="text-[22px] font-semibold tracking-[-0.02em] text-[#1D2E4A]">Physio problems &amp; prices</h1>
          <p className="text-[13px] text-cx-text-2">Each problem’s session types and prices, its packages, and the add-ons charged extra.</p>
        </div>
        <div className="flex items-center gap-2">
          {dirty && (
            <button type="button" onClick={() => setData(initial)} className={cn('inline-flex h-10 items-center gap-1.5 rounded-full px-3 text-[13px] font-semibold text-cx-text-2 hover:text-cx-nav-ink', FOCUS)}>
              <RotateCcw className="h-4 w-4" aria-hidden="true" /> Undo
            </button>
          )}
          <button type="button" className={BTN_PRIMARY} disabled={!dirty || saving} onClick={save}>
            <Save className="h-4 w-4" aria-hidden="true" /> {saving ? 'Saving…' : 'Save changes'}
          </button>
        </div>
      </div>
      {(errors?.physio || Object.keys(errors ?? {}).length > 0) && (
        <p role="alert" className="mb-4 rounded-xl bg-cx-rose-bg px-4 py-3 text-[13px] font-semibold text-cx-rose-ink">{errors.physio ?? 'Some rows are incomplete: every name, price and session count is needed.'}</p>
      )}

      {/* Problems */}
      <section className={CARD}>
        <header className="mb-4 flex items-start gap-3">
          <IconTile icon={Stethoscope} tone="violet" />
          <div>
            <h2 className="pt-0.5 text-[15px] font-semibold text-cx-nav-ink">Problems</h2>
            <p className="mt-0.5 text-[12.5px] text-cx-text-2">What patients come with. A visit paid on the day costs the treatments given (their prices below), or the type’s fixed price when you set one; a package pays it instead.</p>
          </div>
        </header>
        <ul className="space-y-2.5">
          {data.problems.map((p, index) => {
            const [bg, fg] = TONES[index % TONES.length];
            const expanded = open === index;

            return (
              <li key={p.key || `new-${index}`} className="overflow-hidden rounded-[18px] border" style={{ borderColor: expanded ? fg : '#E6EBF1' }}>
                <button type="button" aria-expanded={expanded} onClick={() => setOpen(expanded ? null : index)} className={cn('flex w-full items-center gap-3 px-4 py-3 text-left', FOCUS)} style={{ background: expanded ? bg : '#FFFFFF' }}>
                  <span className="h-3 w-3 shrink-0 rounded-full" style={{ background: fg }} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[15px] font-bold text-[#1D2E4A]">{p.label || 'New problem'}</span>
                    <span className="block text-[12px] text-cx-text-2">
                      {p.types.map((t) => `${t.label || 'Type'} ₹${visitPrice(t)}${t.price === null ? '' : ' fixed'}`).join(' · ') || 'No session type'}
                      {p.packages.length > 0 ? ` · ${p.packages.length} ${p.packages.length === 1 ? 'package' : 'packages'}` : ''}
                      {!p.active ? ' · off' : ''}
                    </span>
                  </span>
                  <ChevronDown className={cn('h-4 w-4 shrink-0 text-cx-text-2 transition-transform', expanded && 'rotate-180')} aria-hidden="true" />
                </button>

                {expanded && (
                  <div className="space-y-4 border-t border-[#EEF2F7] p-4">
                    <div className="grid grid-cols-12 items-center gap-3">
                      <input aria-label="Problem name" value={p.label} maxLength={60} placeholder="e.g. Knee pain" onChange={(e) => setProblem(index, (x) => ({ ...x, label: e.target.value }))} className={cn(field, 'col-span-12 font-semibold sm:col-span-6')} />
                      <div className="col-span-6 sm:col-span-3"><Switch checked={p.sided} onCheckedChange={(on) => setProblem(index, (x) => ({ ...x, sided: on }))} label="Ask the side" /></div>
                      <div className="col-span-6 flex items-center justify-end gap-1 sm:col-span-3">
                        <Switch checked={p.active} onCheckedChange={(on) => setProblem(index, (x) => ({ ...x, active: on }))} label="In use" />
                        <button type="button" aria-label={`Remove ${p.label || 'problem'}`} onClick={() => { setData((d) => ({ ...d, problems: d.problems.filter((_, i) => i !== index) })); setOpen(null); }} className={cn('grid h-10 w-10 place-items-center rounded-xl text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink', FOCUS)}>
                          <Trash2 className="h-4 w-4" aria-hidden="true" />
                        </button>
                      </div>
                    </div>

                    {/* Session types */}
                    <div>
                      <div className="mb-2 text-[12px] font-bold uppercase tracking-[0.05em]" style={{ color: fg }}>Session types · price per visit</div>
                      <ul className="space-y-2">
                        {p.types.map((t, ti) => {
                          const setType = (patch: Partial<SessionType>) =>
                            setProblem(index, (x) => {
                              const key = patch.label !== undefined && !initial.problems[index]?.types.some((o) => o.key === t.key) ? slug(patch.label) : t.key;

                              return {
                                ...x,
                                types: x.types.map((o, i) => (i === ti ? { ...o, ...patch, key } : o)),
                                packages: key === t.key ? x.packages : x.packages.map((pk) => ({ ...pk, types: pk.types.map((k) => (k === t.key && t.key !== '' ? key : k)) })),
                              };
                            });

                          return (
                            <li key={t.key || `t-${ti}`} className="rounded-2xl bg-[#F7F8FC] p-3">
                              <div className="grid grid-cols-12 items-center gap-2.5">
                                <input aria-label="Session type" value={t.label} maxLength={60} placeholder="e.g. Full treatment" onChange={(e) => setType({ label: e.target.value })} className={cn(field, 'col-span-12 font-semibold sm:col-span-7')} />
                                <label className="col-span-9 flex items-center gap-2 sm:col-span-4">
                                  <span className="whitespace-nowrap text-[12.5px] font-semibold text-cx-text-2">Fixed ₹</span>
                                  <input
                                    aria-label={`${t.label} fixed price, optional`}
                                    type="number"
                                    min={0}
                                    step={10}
                                    value={t.price ?? ''}
                                    placeholder={`₹${t.treatments.reduce((n, k) => n + (data.treatments.find((tr) => tr.key === k)?.price ?? 0), 0)} by treatment`}
                                    onChange={(e) => setType({ price: e.target.value === '' ? null : Number(e.target.value) })}
                                    className={field}
                                  />
                                </label>
                                <button type="button" aria-label={`Remove ${t.label || 'type'}`} disabled={p.types.length === 1} onClick={() => setProblem(index, (x) => ({ ...x, types: x.types.filter((_, i) => i !== ti), packages: x.packages.map((pk) => ({ ...pk, types: pk.types.filter((k) => k !== t.key) })) }))} className={cn('col-span-3 grid h-10 w-10 place-items-center justify-self-end rounded-xl text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink disabled:opacity-40 sm:col-span-1', FOCUS)}>
                                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                                </button>
                              </div>
                              <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                <span className="text-[12px] font-semibold text-cx-text-2">Gives:</span>
                                {treatments.map((tr) => {
                                  const on = t.treatments.includes(tr.key);

                                  return (
                                    <button key={tr.key} type="button" aria-pressed={on} onClick={() => setType({ treatments: on ? t.treatments.filter((k) => k !== tr.key) : [...t.treatments, tr.key] })} className={cn('h-7 rounded-full border px-2.5 text-[12px] font-semibold', FOCUS)} style={{ borderColor: on ? fg : '#DCE6F3', background: on ? fg : '#FFFFFF', color: on ? '#FFFFFF' : '#475569' }}>
                                      {tr.label}
                                    </button>
                                  );
                                })}
                                {treatments.length === 0 && <span className="text-[12px] text-cx-text-2">Add treatments below first.</span>}
                              </div>
                            </li>
                          );
                        })}
                      </ul>
                      <button type="button" onClick={() => setProblem(index, (x) => ({ ...x, types: [...x.types, { key: '', label: '', treatments: [], price: null }] }))} className={cn('mt-2 inline-flex h-9 items-center gap-1.5 rounded-full border border-dashed border-[#C9D3E1] px-3.5 text-[12.5px] font-semibold text-[#1769AA]', FOCUS)}>
                        <Plus className="h-4 w-4" aria-hidden="true" /> Add a session type
                      </button>
                    </div>

                    {/* Packages */}
                    <div>
                      <div className="mb-2 text-[12px] font-bold uppercase tracking-[0.05em] text-[#0E7C6B]">Packages for {p.label || 'this problem'}</div>
                      <ul className="space-y-2">
                        {p.packages.map((pk, pi) => {
                          const setPackage = (patch: Partial<Package>) => setProblem(index, (x) => ({ ...x, packages: x.packages.map((o, i) => (i === pi ? { ...o, ...patch } : o)) }));
                          const covered = p.types.find((t) => pk.types.includes(t.key)) ?? p.types[0];
                          const full = (covered ? visitPrice(covered) : 0) * pk.sessions;

                          return (
                            <li key={pk.id || `p-${pi}`} className="rounded-2xl border border-[#CBEFE6] bg-[#F0FBF8] p-3">
                              <div className="grid grid-cols-12 items-center gap-2.5">
                                <input aria-label="Package name" value={pk.name} maxLength={60} placeholder="e.g. Knee 10 sessions" onChange={(e) => setPackage({ name: e.target.value })} className={cn(field, 'col-span-12 font-semibold sm:col-span-4')} />
                                <label className="col-span-4 sm:col-span-2"><span className="sr-only">Sessions</span><input aria-label="Sessions" type="number" min={1} max={200} value={pk.sessions} onChange={(e) => setPackage({ sessions: Number(e.target.value) })} className={field} /></label>
                                <label className="col-span-4 sm:col-span-3"><span className="sr-only">Price</span><input aria-label="Price" type="number" min={0} step={50} value={pk.price} onChange={(e) => setPackage({ price: Number(e.target.value) })} className={field} /></label>
                                <label className="col-span-3 sm:col-span-2"><span className="sr-only">Valid days</span><input aria-label="Valid for days" type="number" min={1} max={730} value={pk.valid_days} onChange={(e) => setPackage({ valid_days: Number(e.target.value) })} className={field} /></label>
                                <button type="button" aria-label={`Remove ${pk.name || 'package'}`} onClick={() => setProblem(index, (x) => ({ ...x, packages: x.packages.filter((_, i) => i !== pi) }))} className={cn('col-span-1 grid h-10 w-10 place-items-center justify-self-end rounded-xl text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink', FOCUS)}>
                                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                                </button>
                              </div>
                              <p className="mt-1.5 text-[12px] text-cx-text-2">
                                {pk.sessions} sessions · ₹{pk.price} · valid {pk.valid_days} days{full > pk.price ? ` · saves ₹${full - pk.price}` : ''}
                              </p>
                              <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <span className="text-[12px] font-semibold text-cx-text-2">Covers:</span>
                                {p.types.filter((t) => t.key).map((t) => {
                                  const on = pk.types.includes(t.key);

                                  return (
                                    <button key={t.key} type="button" aria-pressed={on} onClick={() => setPackage({ types: on ? pk.types.filter((k) => k !== t.key) : [...pk.types, t.key] })} className={cn('h-7 rounded-full border px-2.5 text-[12px] font-semibold', FOCUS, on ? 'border-transparent bg-[#0E9F87] text-white' : 'border-[#DCE6F3] bg-white text-cx-text-2')}>
                                      {t.label}
                                    </button>
                                  );
                                })}
                                {pk.types.length === 0 && <span className="text-[12px] text-cx-text-2">(none ticked = every type)</span>}
                              </div>
                            </li>
                          );
                        })}
                      </ul>
                      <button type="button" onClick={() => setProblem(index, (x) => ({ ...x, packages: [...x.packages, { id: '', name: '', sessions: 10, price: 0, valid_days: 30, types: [] }] }))} className={cn('mt-2 inline-flex h-9 items-center gap-1.5 rounded-full border border-dashed border-[#9FDCCD] px-3.5 text-[12.5px] font-semibold text-[#0E7C6B]', FOCUS)}>
                        <PackageCheck className="h-4 w-4" aria-hidden="true" /> Add a package
                      </button>
                    </div>

                    {/* The exercises usually given: a new plan for this problem starts with them. */}
                    <div>
                      <div className="mb-2 text-[12px] font-bold uppercase tracking-[0.05em] text-[#1D4ED8]">Usual exercises for {p.label || 'this problem'}</div>
                      <div className="flex flex-wrap gap-1.5">
                        {data.exercises.filter((e) => e.key && e.active).map((e) => {
                          const on = (p.exercises ?? []).includes(e.key);

                          return (
                            <button key={e.key} type="button" aria-pressed={on} onClick={() => setProblem(index, (x) => ({ ...x, exercises: on ? x.exercises.filter((k) => k !== e.key) : [...(x.exercises ?? []), e.key] }))} className={cn('h-7 rounded-full border px-2.5 text-[12px] font-semibold', FOCUS)} style={{ borderColor: on ? '#2B6FD8' : '#DCE6F3', background: on ? '#2B6FD8' : '#FFFFFF', color: on ? '#FFFFFF' : '#475569' }}>
                              {e.label}
                            </button>
                          );
                        })}
                        {data.exercises.length === 0 && <span className="text-[12px] text-cx-text-2">Add exercises to the library below first.</span>}
                      </div>
                    </div>

                    {/* The clinic's own diagnoses for this problem: the doctor taps one. */}
                    <div>
                      <div className="mb-2 text-[12px] font-bold uppercase tracking-[0.05em] text-[#A35A06]">Diagnoses for {p.label || 'this problem'}</div>
                      <div className="flex flex-wrap items-center gap-1.5">
                        {(p.diagnoses ?? []).map((d) => (
                          <span key={d} className="inline-flex h-8 items-center gap-1 rounded-full bg-cx-amber-bg pl-3 pr-1 text-[12.5px] font-semibold text-cx-amber-ink">
                            {d}
                            <button type="button" aria-label={`Remove ${d}`} onClick={() => setProblem(index, (x) => ({ ...x, diagnoses: (x.diagnoses ?? []).filter((n) => n !== d) }))} className={cn('grid h-6 w-6 place-items-center rounded-full hover:bg-white', FOCUS)}>
                              <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                          </span>
                        ))}
                        <input
                          aria-label={`Add a diagnosis to ${p.label || 'this problem'}`}
                          placeholder="Add a diagnosis, press Enter"
                          maxLength={120}
                          onKeyDown={(e) => {
                            const value = e.currentTarget.value.trim();
                            if (e.key !== 'Enter' || value === '') return;
                            e.preventDefault();
                            setProblem(index, (x) => ({ ...x, diagnoses: Array.from(new Set([...(x.diagnoses ?? []), value])) }));
                            e.currentTarget.value = '';
                          }}
                          className={cn(field, 'h-8 w-56 rounded-full')}
                        />
                      </div>
                    </div>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
        {data.problems.length === 0 && <p className="rounded-2xl bg-[#F7F8FC] px-4 py-5 text-center text-[13px] text-cx-text-2">No problems yet. Add the ones your patients come with.</p>}
        <button
          type="button"
          onClick={() => {
            setData((d) => ({ ...d, problems: [...d.problems, { key: '', label: '', sided: true, active: true, types: [{ key: 'full_treatment', label: 'Full treatment', treatments: [], price: null }], packages: [], diagnoses: [], exercises: [] }] }));
            setOpen(data.problems.length);
          }}
          className={cn('mt-3 inline-flex h-10 items-center gap-1.5 rounded-full border border-dashed border-[#C9D3E1] px-4 text-[13px] font-semibold text-[#1769AA]', FOCUS)}
        >
          <Plus className="h-4 w-4" aria-hidden="true" /> Add a problem
        </button>
      </section>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        {/* Add-ons */}
        <section className={CARD}>
          <header className="mb-4 flex items-start gap-3">
            <IconTile icon={Sparkles} tone="amber" />
            <div>
              <h2 className="pt-0.5 text-[15px] font-semibold text-cx-nav-ink">Add-ons</h2>
              <p className="mt-0.5 text-[12.5px] text-cx-text-2">Charged on top of any session, even one from a package — cupping, taping.</p>
            </div>
          </header>
          <ul className="space-y-2">
            {data.addons.map((a, index) => (
              <li key={a.key || `a-${index}`} className="grid grid-cols-12 items-center gap-2.5">
                <input aria-label="Add-on name" value={a.label} maxLength={60} placeholder="e.g. Cupping" onChange={(e) => setAddon(index, { label: e.target.value })} className={cn(field, 'col-span-12 font-semibold sm:col-span-5')} />
                <label className="col-span-5 flex items-center gap-1.5 sm:col-span-3"><span className="text-[12.5px] text-cx-text-2">₹</span><input aria-label={`${a.label} price`} type="number" min={0} step={10} value={a.price} onChange={(e) => setAddon(index, { price: Number(e.target.value) })} className={field} /></label>
                <div className="col-span-4 sm:col-span-3"><Switch checked={a.active} onCheckedChange={(on) => setAddon(index, { active: on })} label="" /></div>
                <button type="button" aria-label={`Remove ${a.label || 'add-on'}`} onClick={() => setData((d) => ({ ...d, addons: d.addons.filter((_, i) => i !== index) }))} className={cn('col-span-3 grid h-10 w-10 place-items-center justify-self-end rounded-xl text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink sm:col-span-1', FOCUS)}>
                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                </button>
              </li>
            ))}
          </ul>
          <button type="button" onClick={() => setData((d) => ({ ...d, addons: [...d.addons, { key: '', label: '', minutes: 15, price: 0, active: true }] }))} className={cn('mt-3 inline-flex h-10 items-center gap-1.5 rounded-full border border-dashed border-[#C9D3E1] px-4 text-[13px] font-semibold text-[#1769AA]', FOCUS)}>
            <Plus className="h-4 w-4" aria-hidden="true" /> Add an add-on
          </button>
        </section>

        {/* Treatments */}
        <section className={CARD}>
          <header className="mb-4 flex items-start gap-3">
            <IconTile icon={Activity} tone="teal" />
            <div>
              <h2 className="pt-0.5 text-[15px] font-semibold text-cx-nav-ink">Treatments</h2>
              <p className="mt-0.5 text-[12.5px] text-cx-text-2">What a session type is made of, with its usual minutes. Turned off = hidden, kept in old sessions.</p>
            </div>
          </header>
          <ul className="space-y-2">
            {data.treatments.map((t, index) => (
              <li key={savedTreatments.has(t.key) ? t.key : `t-${index}`} className="grid grid-cols-12 items-center gap-2.5">
                <input aria-label="Treatment name" value={t.label} maxLength={60} placeholder="e.g. IFT" onChange={(e) => setTreatment(index, { label: e.target.value })} className={cn(field, 'col-span-12 font-semibold sm:col-span-5')} />
                <label className="col-span-4 flex items-center gap-1.5 sm:col-span-2"><input aria-label={`${t.label} minutes`} type="number" min={1} max={180} value={t.minutes} onChange={(e) => setTreatment(index, { minutes: Number(e.target.value) })} className={field} /><span className="text-[12px] text-cx-text-2">min</span></label>
                <label className="col-span-5 flex items-center gap-1.5 sm:col-span-2"><span className="text-[12.5px] text-cx-text-2">₹</span><input aria-label={`${t.label} price`} type="number" min={0} step={10} value={t.price} onChange={(e) => setTreatment(index, { price: Number(e.target.value) })} className={field} /></label>
                <div className="col-span-3 sm:col-span-2"><Switch checked={t.active} onCheckedChange={(on) => setTreatment(index, { active: on })} label="" /></div>
                <button type="button" aria-label={`Remove ${t.label || 'treatment'}`} onClick={() => setData((d) => ({ ...d, treatments: d.treatments.filter((_, i) => i !== index), problems: d.problems.map((p) => ({ ...p, types: p.types.map((ty) => ({ ...ty, treatments: ty.treatments.filter((k) => k !== t.key) })) })) }))} className={cn('col-span-3 grid h-10 w-10 place-items-center justify-self-end rounded-xl text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink sm:col-span-1', FOCUS)}>
                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                </button>
              </li>
            ))}
          </ul>
          <button type="button" onClick={() => setData((d) => ({ ...d, treatments: [...d.treatments, { key: '', label: '', minutes: 15, price: 0, active: true }] }))} className={cn('mt-3 inline-flex h-10 items-center gap-1.5 rounded-full border border-dashed border-[#C9D3E1] px-4 text-[13px] font-semibold text-[#1769AA]', FOCUS)}>
            <Plus className="h-4 w-4" aria-hidden="true" /> Add a treatment
          </button>
        </section>
      </div>

      {/* The exercise library: what the doctor prescribes from. Text only — no pictures or videos. */}
      <section className={cn(CARD, 'mt-5')}>
        <header className="mb-4 flex flex-wrap items-start gap-3">
          <IconTile icon={Dumbbell} tone="blue" />
          <div className="min-w-0 flex-1">
            <h2 className="pt-0.5 text-[15px] font-semibold text-cx-nav-ink">Exercise library <span className="font-normal text-cx-text-2">· {data.exercises.length}</span></h2>
            <p className="mt-0.5 text-[12.5px] text-cx-text-2">The doctor picks from these. A patient’s prescription keeps its own copy, so a change here never changes an old one.</p>
          </div>
          <input aria-label="Search exercises" value={exerciseSearch} placeholder="Search exercises" onChange={(e) => setExerciseSearch(e.target.value)} className={cn(field, 'w-full rounded-full sm:w-56')} />
        </header>
        <div className="mb-3 flex flex-wrap gap-1.5">
          <button type="button" aria-pressed={exerciseArea === null} onClick={() => setExerciseArea(null)} className={cn('h-8 rounded-full px-3 text-[12.5px] font-semibold', FOCUS, exerciseArea === null ? 'bg-[#1D2E4A] text-white' : 'bg-[#F1F4F9] text-cx-text-2')}>All</button>
          {options.categories.map((c) => (
            <button key={c.key} type="button" aria-pressed={exerciseArea === c.key} onClick={() => setExerciseArea(exerciseArea === c.key ? null : c.key)} className={cn('h-8 rounded-full px-3 text-[12.5px] font-semibold', FOCUS, exerciseArea === c.key ? 'bg-[#1D2E4A] text-white' : 'bg-[#F1F4F9] text-cx-text-2')}>
              {c.label}
            </button>
          ))}
        </div>
        <ul className="space-y-2">
          {shownExercises.map((e) => {
            const expanded = openExercise === e.key;

            return (
              <li key={savedExercises.has(e.key) ? e.key : `new-${data.exercises.indexOf(e)}`} className={cn('rounded-2xl p-3', e.active ? 'bg-[#F7F8FC]' : 'bg-[#F7F8FC] opacity-60')}>
                <div className="grid grid-cols-12 items-center gap-2">
                  <input aria-label="Exercise name" value={e.label} maxLength={150} placeholder="e.g. Straight leg raise" onChange={(ev) => setExercise(e.key, { label: ev.target.value })} className={cn(field, 'col-span-12 font-semibold sm:col-span-4')} />
                  <select aria-label={`${e.label} category`} value={e.category ?? ''} onChange={(ev) => setExercise(e.key, { category: ev.target.value || null })} className={cn(field, 'col-span-6 sm:col-span-2')}>
                    <option value="">Category</option>
                    {options.categories.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
                  </select>
                  <label className="col-span-6 flex items-center gap-1 sm:col-span-3">
                    <input aria-label={`${e.label} sets`} type="number" min={0} max={50} value={e.sets ?? ''} onChange={(ev) => setExercise(e.key, { sets: ev.target.value === '' ? null : Number(ev.target.value) })} className={cn(field, 'px-2 text-center')} />
                    <span className="text-[12px] text-cx-text-2">×</span>
                    <input aria-label={`${e.label} reps`} type="number" min={0} max={500} value={e.reps ?? ''} onChange={(ev) => setExercise(e.key, { reps: ev.target.value === '' ? null : Number(ev.target.value) })} className={cn(field, 'px-2 text-center')} />
                  </label>
                  <div className="col-span-12 flex items-center justify-end gap-1 sm:col-span-3">
                    <Switch checked={e.active} onCheckedChange={(on) => setExercise(e.key, { active: on })} label="" />
                    <button type="button" aria-expanded={expanded} onClick={() => setOpenExercise(expanded ? null : e.key)} className={cn('h-9 rounded-full px-3 text-[12.5px] font-semibold text-[#1769AA] hover:bg-white', FOCUS)}>{expanded ? 'Less' : 'More'}</button>
                    <button type="button" aria-label={`Remove ${e.label || 'exercise'}`} onClick={() => setData((d) => ({ ...d, exercises: d.exercises.filter((x) => x.key !== e.key), problems: d.problems.map((p) => ({ ...p, exercises: p.exercises.filter((k) => k !== e.key) })) }))} className={cn('grid h-9 w-9 place-items-center rounded-xl text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink', FOCUS)}>
                      <Trash2 className="h-4 w-4" aria-hidden="true" />
                    </button>
                  </div>
                </div>
                {expanded && (
                  <div className="mt-2 grid grid-cols-12 gap-2">
                    <input aria-label={`${e.label} instructions`} value={e.instructions ?? ''} maxLength={500} placeholder="How to do it" onChange={(ev) => setExercise(e.key, { instructions: ev.target.value || null })} className={cn(field, 'col-span-12 md:col-span-6')} />
                    <input aria-label={`${e.label} precautions`} value={e.precautions ?? ''} maxLength={500} placeholder="Precautions" onChange={(ev) => setExercise(e.key, { precautions: ev.target.value || null })} className={cn(field, 'col-span-12 md:col-span-6')} />
                    <input aria-label={`${e.label} description`} value={e.description ?? ''} maxLength={500} placeholder="Description" onChange={(ev) => setExercise(e.key, { description: ev.target.value || null })} className={cn(field, 'col-span-12 md:col-span-6')} />
                    <input aria-label={`${e.label} equipment`} value={e.equipment ?? ''} maxLength={120} placeholder="Equipment, e.g. towel, band" onChange={(ev) => setExercise(e.key, { equipment: ev.target.value || null })} className={cn(field, 'col-span-6 md:col-span-3')} />
                    <select aria-label={`${e.label} level`} value={e.level ?? ''} onChange={(ev) => setExercise(e.key, { level: ev.target.value || null })} className={cn(field, 'col-span-3 md:col-span-2')}>
                      <option value="">Level</option>
                      {options.levels.map((l) => <option key={l.key} value={l.key}>{l.label}</option>)}
                    </select>
                    <label className="col-span-3 flex items-center gap-1 md:col-span-1">
                      <input aria-label={`${e.label} hold seconds`} type="number" min={0} max={600} value={e.hold ?? ''} placeholder="hold" onChange={(ev) => setExercise(e.key, { hold: ev.target.value === '' ? null : Number(ev.target.value) })} className={cn(field, 'px-2 text-center')} />
                      <span className="text-[11px] text-cx-text-2">s</span>
                    </label>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
        {shownExercises.length === 0 && <p className="rounded-2xl bg-[#F7F8FC] px-4 py-5 text-center text-[13px] text-cx-text-2">No exercise matches.</p>}
        <button
          type="button"
          onClick={() => {
            const key = `new_${Date.now()}`;
            setData((d) => ({ ...d, exercises: [{ key, label: '', category: exerciseArea, description: null, instructions: null, precautions: null, level: null, equipment: null, sets: 3, reps: 10, hold: null, active: true }, ...d.exercises] }));
            setOpenExercise(key);
          }}
          className={cn('mt-3 inline-flex h-10 items-center gap-1.5 rounded-full border border-dashed border-[#C9D3E1] px-4 text-[13px] font-semibold text-[#1769AA]', FOCUS)}
        >
          <Plus className="h-4 w-4" aria-hidden="true" /> Add an exercise
        </button>
      </section>
    </div>
  );
}
