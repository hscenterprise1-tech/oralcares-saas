import { useRef, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { Download, FileUp, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Input, Select, Textarea } from '@/clinic/ui/controls';
import { EmptyState } from '@/clinic/ui/states';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import type { DocumentItem, Profile } from './types';

const CARD = 'rounded-2xl border border-cx-line/80 bg-white p-5 shadow-cx-card';
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—');
const day = (iso: string | null) => (iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

function Card({ title, hint, children, actions }: { title: string; hint?: string; children: ReactNode; actions?: ReactNode }) {
  return (
    <section className={CARD} aria-label={title}>
      <header className="mb-4 flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="text-[14px] font-semibold text-cx-nav-ink">{title}</h2>
          {hint && <p className="mt-0.5 text-[12.5px] text-cx-text-3">{hint}</p>}
        </div>
        {actions}
      </header>
      {children}
    </section>
  );
}

function Fact({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-[11.5px] font-semibold uppercase tracking-[0.05em] text-cx-text-3">{label}</dt>
      <dd className="mt-0.5 break-words text-[13.5px] text-cx-nav-ink">{value || '—'}</dd>
    </div>
  );
}

/** Calls a redirecting endpoint through Inertia, so the page, the flash message and any errors come back as usual. */
function useSend() {
  const [busy, setBusy] = useState(false);
  const send = (method: 'post' | 'put' | 'patch' | 'delete', url: string, data: Record<string, unknown> = {}, extra: Record<string, unknown> = {}) =>
    router[method](url, data as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), ...extra });

  return { busy, send };
}

export function OverviewTab({ p }: { p: Profile }) {
  const e = p.employee;
  const { busy, send } = useSend();
  const [email, setEmail] = useState(e.email ?? '');

  return (
    <div className="grid gap-4 xl:grid-cols-3">
      <div className="xl:col-span-2">
        <Card title="Details">
          <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2">
            <Fact label="Email" value={e.email} />
            <Fact label="Phone" value={e.phone} />
            <Fact label="Role" value={e.staff_type_label} />
            <Fact label="Roles in the portal" value={e.roles.length > 0 ? e.roles.map(humanize).join(', ') : 'None'} />
            <Fact label="Joined" value={day(e.joining_date)} />
            <Fact label="Date of birth" value={day(e.date_of_birth)} />
            <Fact label="Gender" value={humanize(e.gender)} />
            <Fact label="Department" value={e.departments.join(', ')} />
          </dl>
          {e.notes && <p className="mt-4 whitespace-pre-line rounded-xl bg-cx-slate-bg/60 p-3 text-[13px] text-cx-text-2">{e.notes}</p>}
        </Card>
        <div className="mt-4">
          <Card title="Branches" hint="Where they work. Change this from Edit.">
            {e.branches.length === 0 ? (
              <p className="text-[13px] text-cx-text-2">Not placed at any branch yet.</p>
            ) : (
              <ul className="divide-y divide-cx-line/60">
                {e.branches.map((branch) => (
                  <li key={branch.id} className="flex items-center justify-between gap-3 py-2.5 text-[13.5px]">
                    <span className="font-medium text-cx-nav-ink">{branch.name}</span>
                    <span className="flex gap-2 text-[12px]">
                      {branch.is_primary && <span className="rounded-full bg-cx-blue-bg px-2.5 py-0.5 font-semibold text-cx-blue-ink">Main</span>}
                      {!branch.is_active && <span className="rounded-full bg-cx-amber-bg px-2.5 py-0.5 font-semibold text-cx-amber-ink">Paused</span>}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>

      <Card title="Sign-in" hint="Staff sign in with their own email and password.">
        {e.has_login ? (
          <div className="space-y-1 text-[13.5px]">
            <p className="font-semibold text-cx-green-ink">Can sign in</p>
            <p className="text-cx-text-2">{e.login_email}</p>
            <p className="text-[12.5px] text-cx-text-3">Last active: {when(e.last_activity)}</p>
          </div>
        ) : e.invitation ? (
          <div className="space-y-1 text-[13.5px]">
            <p className="font-semibold text-cx-amber-ink">Invitation sent</p>
            <p className="text-cx-text-2">{e.invitation.email}</p>
            <p className="text-[12.5px] text-cx-text-3">Expires {when(e.invitation.expires_at)}</p>
          </div>
        ) : (
          <p className="text-[13.5px] text-cx-text-2">No login yet.</p>
        )}
        {!e.has_login && p.can.edit && (
          <div className="mt-4 space-y-3">
            <Field label="Send invitation to" htmlFor="invite-email">
              <Input id="invite-email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} />
            </Field>
            <Button tone="primary" loading={busy} disabled={email.trim() === ''} onClick={() => send('post', p.links.invite, { clinic_employee_id: e.id, email })}>
              {e.invitation ? 'Send again' : 'Send invitation'}
            </Button>
          </div>
        )}
      </Card>
    </div>
  );
}

export function ProfessionalTab({ p }: { p: Profile }) {
  const prac = p.practitioner;
  const { busy, send } = useSend();
  const reg = prac?.registration;
  const [form, setForm] = useState({
    profession: prac?.profession ?? '',
    primary: prac?.primary_specialty?.id ? String(prac.primary_specialty.id) : '',
    experience_years: prac?.experience_years != null ? String(prac.experience_years) : '',
    consultation_fee: prac?.consultation_fee != null ? String(prac.consultation_fee) : '',
    bio: prac?.bio ?? '',
    registration_number: reg?.number ?? '',
    registration_authority: reg?.authority ?? '',
    registration_state: reg?.state ?? '',
    registration_type: reg?.type ?? '',
    registration_date: reg?.date ?? '',
    registration_expires_at: reg?.expires_at ?? '',
    is_active: prac?.is_active ?? true,
  });
  const [qual, setQual] = useState({ qualification: '', degree_type: '', specialization: '', institution: '', year: '' });
  if (!prac) {
    return <EmptyState title="Not a practitioner" description="Professional details are kept for doctors and therapists." />;
  }
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((f) => ({ ...f, [key]: value }));
  const editable = p.can.practitioner;
  const specialty = p.options.specialties.find((s) => String(s.id) === form.primary);

  const save = () =>
    send('put', `${p.links.self}/professional`, {
      profession: form.profession || null,
      primary_specialty: specialty ? { id: specialty.id, name: specialty.name, area: specialty.area } : null,
      additional_specialties: prac.additional_specialties,
      experience_years: form.experience_years === '' ? null : Number(form.experience_years),
      consultation_fee: form.consultation_fee === '' ? null : Number(form.consultation_fee),
      bio: form.bio || null,
      registration_number: form.registration_number || null,
      registration_authority: form.registration_authority || null,
      registration_state: form.registration_state || null,
      registration_type: form.registration_type || null,
      registration_date: form.registration_date || null,
      registration_expires_at: form.registration_expires_at || null,
      is_active: form.is_active,
    });

  const addQualification = () =>
    send('post', `${p.links.self}/qualifications`, { ...qual, degree_type: qual.degree_type || null, specialization: qual.specialization || null, institution: qual.institution || null, year: qual.year === '' ? null : Number(qual.year) }, { onSuccess: () => setQual({ qualification: '', degree_type: '', specialization: '', institution: '', year: '' }) });

  return (
    <div className="space-y-4">
      <Card
        title="Professional details"
        hint={prac.practice.length > 0 ? `Seen at the desk as: ${prac.practice.map((a) => `${a.module} · ${a.label}`).join(', ')}` : 'Choose a speciality so they can be booked for the right treatments.'}
        actions={editable && <Button tone="primary" loading={busy} onClick={save}>Save details</Button>}
      >
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <Field label="Profession" htmlFor="pr-prof"><Input id="pr-prof" value={form.profession} disabled={!editable} onChange={(e) => set('profession', e.target.value)} placeholder="e.g. Dentist" /></Field>
          <Field label="Main speciality" htmlFor="pr-spec">
            <Select id="pr-spec" disabled={!editable} value={form.primary} onChange={(e) => set('primary', e.target.value)} options={[{ value: '', label: prac.primary_specialty && !prac.primary_specialty.id ? prac.primary_specialty.name : 'Not set' }, ...p.options.specialties.filter((s) => s.id).map((s) => ({ value: String(s.id), label: `${s.group} · ${s.name}` }))]} />
          </Field>
          <Field label="Years of experience" htmlFor="pr-exp"><Input id="pr-exp" type="number" min={0} max={70} disabled={!editable} value={form.experience_years} onChange={(e) => set('experience_years', e.target.value)} /></Field>
          <Field label="Consultation fee (₹)" htmlFor="pr-fee" hint="Charged when this doctor sees a patient. Empty: no separate fee."><Input id="pr-fee" type="number" min={0} step="any" disabled={!editable} value={form.consultation_fee} onChange={(e) => set('consultation_fee', e.target.value)} /></Field>
          <Field label="Registration number" htmlFor="pr-reg"><Input id="pr-reg" disabled={!editable} value={form.registration_number} onChange={(e) => set('registration_number', e.target.value)} /></Field>
          <Field label="Registered with" htmlFor="pr-auth"><Input id="pr-auth" disabled={!editable} value={form.registration_authority} onChange={(e) => set('registration_authority', e.target.value)} placeholder="e.g. State Dental Council" /></Field>
          <Field label="State" htmlFor="pr-state"><Input id="pr-state" disabled={!editable} value={form.registration_state} onChange={(e) => set('registration_state', e.target.value)} /></Field>
          <Field label="Registration type" htmlFor="pr-type"><Select id="pr-type" disabled={!editable} value={form.registration_type} onChange={(e) => set('registration_type', e.target.value)} options={[{ value: '', label: 'Not set' }, ...Object.entries(p.options.registrationTypes).map(([value, label]) => ({ value, label }))]} /></Field>
          <Field label="Registered on" htmlFor="pr-date"><Input id="pr-date" type="date" disabled={!editable} value={form.registration_date} onChange={(e) => set('registration_date', e.target.value)} /></Field>
          <Field label="Valid until" htmlFor="pr-exp-date" hint={reg?.expiry_state === 'expired' ? 'Registration has expired.' : reg?.expiry_state === 'expiring_soon' ? 'Expires soon.' : undefined}>
            <Input id="pr-exp-date" type="date" disabled={!editable} value={form.registration_expires_at} onChange={(e) => set('registration_expires_at', e.target.value)} />
          </Field>
        </div>
        <Field label="About" htmlFor="pr-bio" className="mt-4"><Textarea id="pr-bio" disabled={!editable} value={form.bio} onChange={(e) => set('bio', e.target.value)} maxLength={2000} /></Field>
        <div className="mt-4"><Checkbox checked={form.is_active} onCheckedChange={(on) => set('is_active', on)} disabled={!editable} label="Can be booked for appointments" /></div>
      </Card>

      <Card title="Qualifications">
        {prac.qualifications.length === 0 ? (
          <p className="text-[13px] text-cx-text-2">No qualification recorded.</p>
        ) : (
          <ul className="divide-y divide-cx-line/60">
            {prac.qualifications.map((q) => (
              <li key={q.id} className="flex items-center justify-between gap-3 py-2.5">
                <div className="min-w-0">
                  <p className="truncate text-[13.5px] font-semibold text-cx-nav-ink">{q.label}</p>
                  <p className="text-[12px] text-cx-text-3">{[q.institution, q.year, q.verification_status ? humanize(q.verification_status) : null].filter(Boolean).join(' · ')}</p>
                </div>
                {editable && (
                  <button type="button" aria-label={`Remove ${q.label}`} onClick={() => send('delete', `${p.links.self}/qualifications/${q.id}`)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink">
                    <Trash2 className="h-4 w-4" aria-hidden="true" />
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
        {editable && (
          <div className="mt-4 grid gap-3 border-t border-cx-line/60 pt-4 sm:grid-cols-2 xl:grid-cols-5">
            <Input aria-label="Qualification" placeholder="Qualification, e.g. BDS" value={qual.qualification} onChange={(e) => setQual({ ...qual, qualification: e.target.value })} />
            <Select aria-label="Degree type" value={qual.degree_type} onChange={(e) => setQual({ ...qual, degree_type: e.target.value })} options={[{ value: '', label: 'Type' }, ...Object.entries(p.options.degreeTypes).map(([value, label]) => ({ value, label }))]} />
            <Input aria-label="Specialisation" placeholder="Specialisation" value={qual.specialization} onChange={(e) => setQual({ ...qual, specialization: e.target.value })} />
            <Input aria-label="Institution" placeholder="Institution" value={qual.institution} onChange={(e) => setQual({ ...qual, institution: e.target.value })} />
            <div className="flex gap-2">
              <Input aria-label="Year" type="number" placeholder="Year" value={qual.year} onChange={(e) => setQual({ ...qual, year: e.target.value })} />
              <Button onClick={addQualification} loading={busy} disabled={qual.qualification.trim() === ''}><Plus className="h-4 w-4" aria-hidden="true" /> Add</Button>
            </div>
          </div>
        )}
      </Card>
    </div>
  );
}

export function ServicesTab({ p }: { p: Profile }) {
  const { busy, send } = useSend();
  const [picked, setPicked] = useState<Set<number>>(new Set(p.services?.selected ?? []));
  if (!p.services) {
    return <EmptyState title="Not a practitioner" description="Only doctors and therapists are booked for services." />;
  }
  const groups = new Map<string, typeof p.services.catalogue>();
  p.services.catalogue.forEach((s) => groups.set(s.category ?? 'Other', [...(groups.get(s.category ?? 'Other') ?? []), s]));
  const flip = (id: number, on: boolean) => setPicked((current) => { const next = new Set(current); if (on) next.add(id); else next.delete(id); return next; });

  return (
    <Card
      title="Services they provide"
      hint={picked.size === 0 ? 'Nothing ticked: bookable for every service.' : `${picked.size} ticked: bookable only for these.`}
      actions={p.can.practitioner && <Button tone="primary" loading={busy} onClick={() => send('put', `${p.links.self}/services`, { services: Array.from(picked) })}>Save services</Button>}
    >
      {groups.size === 0 ? (
        <p className="text-[13px] text-cx-text-2">The clinic has no active services yet. Add them under Services.</p>
      ) : (
        <div className="space-y-5">
          {Array.from(groups.entries()).map(([name, rows]) => (
            <fieldset key={name}>
              <legend className="mb-2 text-[12.5px] font-bold uppercase tracking-[0.06em] text-cx-nav-teal-strong">{name}</legend>
              <div className="grid gap-2.5 sm:grid-cols-2 xl:grid-cols-3">
                {rows.map((s) => <Checkbox key={s.id} checked={picked.has(s.id)} disabled={!p.can.practitioner} onCheckedChange={(on) => flip(s.id, on)} label={s.name} />)}
              </div>
            </fieldset>
          ))}
        </div>
      )}
    </Card>
  );
}

export function ScheduleTab({ p }: { p: Profile }) {
  const { busy, send } = useSend();
  const placed = p.options.branches.filter((b) => p.employee.branches.some((x) => x.id === b.id));
  const choices = placed.length > 0 ? placed : p.options.branches;
  const [branchId, setBranchId] = useState<number | null>(choices[0]?.id ?? null);
  const weekly = p.schedule?.weekly ?? {};
  const initial = (id: number | null) => {
    const days: Record<number, { start: string; end: string }[]> = {};
    for (let d = 0; d < 7; d++) days[d] = (id !== null ? weekly[String(id)]?.[String(d)] : undefined) ?? [];

    return days;
  };
  const [days, setDays] = useState(() => initial(branchId));
  if (!p.schedule) {
    return <EmptyState title="Not a practitioner" description="Working hours are kept for doctors and therapists." />;
  }
  const switchBranch = (id: number) => { setBranchId(id); setDays(initial(id)); };
  const update = (d: number, i: number, key: 'start' | 'end', value: string) => setDays((c) => ({ ...c, [d]: c[d].map((s, j) => (j === i ? { ...s, [key]: value } : s)) }));

  return (
    <Card
      title="Weekly working hours"
      hint="Per branch. A day with no hours means not working that day there."
      actions={p.can.practitioner && branchId !== null && <Button tone="primary" loading={busy} onClick={() => send('put', `${p.links.self}/schedule`, { branch_id: branchId, days })}>Save hours</Button>}
    >
      {choices.length > 1 && (
        <div className="mb-4 w-full sm:w-64"><Select aria-label="Branch" value={String(branchId ?? '')} onChange={(e) => switchBranch(Number(e.target.value))} options={choices.map((b) => ({ value: String(b.id), label: b.name }))} /></div>
      )}
      {branchId === null ? (
        <p className="text-[13px] text-cx-text-2">Place them at a branch first.</p>
      ) : (
        <ul className="divide-y divide-cx-line/60">
          {[1, 2, 3, 4, 5, 6, 0].map((d) => (
            <li key={d} className="flex flex-wrap items-start gap-x-4 gap-y-2 py-3">
              <span className="w-28 pt-2 text-[13.5px] font-semibold text-cx-nav-ink">{WEEKDAYS[d]}</span>
              <div className="min-w-0 flex-1 space-y-2">
                {days[d].length === 0 && <p className="pt-2 text-[13px] text-cx-text-3">Off</p>}
                {days[d].map((s, i) => (
                  <div key={i} className="flex items-center gap-2">
                    <Input aria-label={`${WEEKDAYS[d]} start`} type="time" value={s.start} disabled={!p.can.practitioner} onChange={(e) => update(d, i, 'start', e.target.value)} className="w-32" />
                    <span className="text-cx-text-3">to</span>
                    <Input aria-label={`${WEEKDAYS[d]} end`} type="time" value={s.end} disabled={!p.can.practitioner} onChange={(e) => update(d, i, 'end', e.target.value)} className="w-32" />
                    {p.can.practitioner && <button type="button" aria-label="Remove shift" onClick={() => setDays((c) => ({ ...c, [d]: c[d].filter((_, j) => j !== i) }))} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink"><Trash2 className="h-4 w-4" aria-hidden="true" /></button>}
                  </div>
                ))}
              </div>
              {p.can.practitioner && days[d].length < 6 && (
                <Button size="sm" onClick={() => setDays((c) => ({ ...c, [d]: [...c[d], { start: '09:00', end: '13:00' }] }))}><Plus className="h-4 w-4" aria-hidden="true" /> Add hours</Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

const DOC_STATE: Record<string, [string, string]> = {
  missing: ['Missing', 'bg-cx-rose-bg text-cx-rose-ink'],
  pending_verification: ['Waiting to be checked', 'bg-cx-amber-bg text-cx-amber-ink'],
  verified: ['Verified', 'bg-cx-green-bg text-cx-green-ink'],
  rejected: ['Rejected', 'bg-cx-rose-bg text-cx-rose-ink'],
  expired: ['Expired', 'bg-cx-rose-bg text-cx-rose-ink'],
  expiring_soon: ['Expires soon', 'bg-cx-amber-bg text-cx-amber-ink'],
};

export function DocumentsTab({ p }: { p: Profile }) {
  const { busy, send } = useSend();
  const input = useRef<HTMLInputElement>(null);
  const [target, setTarget] = useState<DocumentItem | null>(null);
  const summary = p.documents.summary;

  const onFile = (file: File | undefined) => {
    if (file && target) {
      send('post', `${p.links.self}/documents`, { document_type_id: target.type.id, file }, { forceFormData: true });
    }
    if (input.current) input.current.value = '';
  };

  return (
    <Card
      title="Documents"
      hint={summary.required > 0 ? `${summary.complete} of ${summary.required} required documents complete${summary.attention > 0 ? ` · ${summary.attention} need attention` : ''}` : 'No document is required for this role.'}
      actions={<Link href={p.links.documentSettings} className="text-[13px] font-semibold text-[#1769AA] hover:underline">Change the checklist</Link>}
    >
      <input ref={input} type="file" hidden accept={p.options.upload.mimes.map((m) => `.${m}`).join(',')} onChange={(e) => onFile(e.target.files?.[0])} />
      {p.documents.items.length === 0 ? (
        <p className="text-[13px] text-cx-text-2">Nothing to show.</p>
      ) : (
        <ul className="divide-y divide-cx-line/60">
          {p.documents.items.map((item) => {
            const [label, tone] = DOC_STATE[item.state] ?? [humanize(item.state), 'bg-cx-slate-bg text-cx-slate-ink'];

            return (
              <li key={item.type.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                <div className="min-w-0 flex-1 basis-52">
                  <p className="text-[13.5px] font-semibold text-cx-nav-ink">{item.type.name}{item.requirement === 'required' && <span className="ml-1.5 text-[11.5px] font-semibold text-cx-text-3">Required</span>}</p>
                  {item.current && <p className="truncate text-[12px] text-cx-text-3">{item.current.file_name}{item.current.expires_on ? ` · valid until ${day(item.current.expires_on)}` : ''}</p>}
                </div>
                <span className={cn('rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', tone)}>{label}</span>
                <div className="flex items-center gap-2">
                  {item.current && <a href={`${p.links.self}/documents/${item.current.id}`} className="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-[12.5px] font-semibold text-cx-text-2 hover:bg-cx-slate-bg"><Download className="h-4 w-4" aria-hidden="true" /> Open</a>}
                  {p.can.verify && item.current?.status === 'pending_verification' && <Button size="sm" onClick={() => send('post', `${p.links.self}/documents/${item.current!.id}/verify`)} disabled={busy}>Verify</Button>}
                  {p.can.edit && <Button size="sm" onClick={() => { setTarget(item); input.current?.click(); }} disabled={busy}><FileUp className="h-4 w-4" aria-hidden="true" /> {item.current ? 'Replace' : 'Upload'}</Button>}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}

export function LeaveTab({ p }: { p: Profile }) {
  return (
    <Card title="Leave" actions={<Link href={p.links.leave} className="text-[13px] font-semibold text-[#1769AA] hover:underline">Open leave</Link>}>
      {p.leave.length === 0 ? (
        <p className="text-[13px] text-cx-text-2">No leave recorded.</p>
      ) : (
        <ul className="divide-y divide-cx-line/60">
          {p.leave.map((l) => (
            <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[13.5px]">
              <span><span className="font-semibold text-cx-nav-ink">{l.type_label}</span> · {day(l.start_date)} to {day(l.end_date)}{l.affected > 0 ? ` · ${l.affected} appointments affected` : ''}</span>
              <span className="rounded-full bg-cx-slate-bg px-2.5 py-0.5 text-[11.5px] font-semibold text-cx-slate-ink">{humanize(l.status)}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

export function AppointmentsTab({ p }: { p: Profile }) {
  return (
    <Card title="Upcoming appointments" actions={p.can.appointments && <Link href={p.links.appointments} className="text-[13px] font-semibold text-[#1769AA] hover:underline">Open calendar</Link>}>
      {p.appointments.length === 0 ? (
        <p className="text-[13px] text-cx-text-2">No upcoming appointments.</p>
      ) : (
        <ul className="divide-y divide-cx-line/60">
          {p.appointments.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[13.5px]">
              <span><span className="font-semibold text-cx-nav-ink">{a.patient}</span>{a.service ? ` · ${a.service}` : ''}{a.branch ? ` · ${a.branch}` : ''}</span>
              <span className="text-cx-text-2">{when(a.start)}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

export function ActivityTab({ p }: { p: Profile }) {
  return (
    <Card title="Recent activity" hint="Changes to this person's record and what they did, newest first.">
      {p.activity.length === 0 ? (
        <p className="text-[13px] text-cx-text-2">Nothing recorded yet.</p>
      ) : (
        <ul className="divide-y divide-cx-line/60">
          {p.activity.map((a, i) => (
            <li key={i} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[13.5px]">
              <span className="text-cx-nav-ink">{humanize(a.event.replace(/\./g, ' '))}</span>
              <span className="text-[12.5px] text-cx-text-3">{when(a.at)}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
