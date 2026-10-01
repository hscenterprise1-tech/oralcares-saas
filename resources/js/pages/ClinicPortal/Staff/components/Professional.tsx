import { useState } from 'react';
import { router } from '@inertiajs/react';
import { BadgeCheck, GraduationCap, Pencil, Plus, ShieldCheck, Trash2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Field, inputClass, Modal, selectClass, textareaClass } from '@/clinic/ui/Modal';
import type { ProfileProps, Qualification, SpecialtyRef } from '../lib/types';
import { Chip, Empty, Fact, formatDate, Section } from '../lib/ui';

const VERIFICATION: Record<string, { label: string; chip: string }> = {
  verified: { label: 'Verified', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  rejected: { label: 'Rejected', chip: 'bg-rose-50 text-rose-700 ring-rose-200' },
  unverified: { label: 'Not verified', chip: 'bg-slate-100 text-slate-700 ring-slate-200' },
};

/** Profession, specialties, professional registration and qualifications. */
export function Professional({ props }: { props: ProfileProps }) {
  const practitioner = props.practitioner!;
  const { can, options, links } = props;
  const [editing, setEditing] = useState(false);
  const [qualification, setQualification] = useState<Qualification | 'new' | null>(null);
  const registration = practitioner.registration;
  const degreeLabel = (key: string) => options.degreeTypes[key] ?? key;

  const remove = (item: Qualification) => {
    if (window.confirm(`Remove ${item.label}?`)) {
      router.delete(`${links.self}/qualifications/${item.id}`, { preserveScroll: true });
    }
  };

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Section
        title="Professional details"
        aside={can.practitioner && <Button size="sm" icon={Pencil} onClick={() => setEditing(true)}>Edit</Button>}
      >
        <dl className="grid gap-4 sm:grid-cols-2">
          <Fact label="Profession" value={practitioner.profession} />
          <Fact label="Primary specialty" value={practitioner.primary_specialty?.name} />
          <Fact
            label="Additional specialties"
            value={practitioner.additional_specialties.length > 0 && (
              <span className="flex flex-wrap gap-1">
                {practitioner.additional_specialties.map((specialty) => (
                  <Chip key={specialty.name} className="bg-violet-50 text-violet-800 ring-violet-200">{specialty.name}</Chip>
                ))}
              </span>
            )}
          />
          <Fact label="Experience" value={practitioner.experience_years !== null ? `${practitioner.experience_years} years` : null} />
          <div className="sm:col-span-2">
            <Fact label="About" value={practitioner.bio} />
          </div>
        </dl>
      </Section>

      <Section
        title="Professional registration"
        aside={registration.expiry_state && registration.expiry_state !== 'valid' && (
          <Chip className={registration.expiry_state === 'expired' ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-amber-50 text-amber-800 ring-amber-200'}>
            {registration.expiry_state === 'expired' ? 'Expired' : 'Expires soon'}
          </Chip>
        )}
      >
        {registration.number ? (
          <div className="flex items-start gap-3 rounded-2xl bg-gradient-to-br from-sky-50 to-teal-50 p-4">
            <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-teal-700" aria-hidden="true" />
            <dl className="grid min-w-0 flex-1 gap-3 sm:grid-cols-2">
              <Fact label="Registration number" value={<span className="font-bold tabular-nums">{registration.number}</span>} />
              <Fact label="Registering authority" value={registration.authority} />
              <Fact label="State" value={registration.state} />
              <Fact label="Type" value={registration.type ? (options.registrationTypes[registration.type] ?? registration.type) : null} />
              <Fact label="Registered on" value={formatDate(registration.date)} />
              <Fact label="Valid until" value={formatDate(registration.expires_at)} />
            </dl>
          </div>
        ) : (
          <Empty>No professional registration recorded.{can.practitioner ? ' Add it with Edit.' : ''}</Empty>
        )}
      </Section>

      <Section
        title="Qualifications"
        className="lg:col-span-2"
        aside={can.practitioner && <Button size="sm" icon={Plus} onClick={() => setQualification('new')}>Add qualification</Button>}
      >
        {practitioner.qualifications.length === 0 ? (
          <Empty>No qualifications recorded yet.</Empty>
        ) : (
          <ul className="grid gap-2 sm:grid-cols-2">
            {practitioner.qualifications.map((item) => {
              const verification = VERIFICATION[item.verification_status] ?? VERIFICATION.unverified;

              return (
                <li key={item.id} className="flex items-start gap-3 rounded-2xl border border-clinic-border bg-white p-3.5">
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-800">
                    <GraduationCap className="h-5 w-5" aria-hidden="true" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-1.5">
                      <span className="text-[14px] font-bold text-clinic-ink">{item.label}</span>
                      <Chip className={verification.chip}>
                        {item.verification_status === 'verified' && <BadgeCheck className="h-3 w-3" aria-hidden="true" />}
                        {verification.label}
                      </Chip>
                    </span>
                    <span className="mt-0.5 block text-[12px] text-clinic-secondary">
                      {[degreeLabel(item.degree_type), item.institution, item.year, [item.state, item.country].filter(Boolean).join(', ')].filter(Boolean).join(' · ')}
                    </span>
                    {item.staff_document_id && <span className="mt-1 block text-[11.5px] font-semibold text-teal-700">Certificate on file</span>}
                  </span>
                  {can.practitioner && (
                    <span className="flex shrink-0 gap-1">
                      <button type="button" aria-label={`Edit ${item.label}`} onClick={() => setQualification(item)} className="flex h-11 w-11 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink sm:h-8 sm:w-8">
                        <Pencil className="h-3.5 w-3.5" aria-hidden="true" />
                      </button>
                      <button type="button" aria-label={`Remove ${item.label}`} onClick={() => remove(item)} className="flex h-11 w-11 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-danger-soft hover:text-clinic-danger sm:h-8 sm:w-8">
                        <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                      </button>
                    </span>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </Section>

      {editing && <ProfessionalDialog props={props} onClose={() => setEditing(false)} />}
      {qualification && <QualificationDialog props={props} item={qualification === 'new' ? null : qualification} onClose={() => setQualification(null)} />}
    </div>
  );
}

function ProfessionalDialog({ props, onClose }: { props: ProfileProps; onClose: () => void }) {
  const practitioner = props.practitioner!;
  const { options, links } = props;
  const [form, setForm] = useState({
    profession: practitioner.profession ?? '',
    primary: practitioner.primary_specialty as SpecialtyRef | null,
    additional: practitioner.additional_specialties,
    experience_years: practitioner.experience_years !== null ? String(practitioner.experience_years) : '',
    bio: practitioner.bio ?? '',
    registration_number: practitioner.registration.number ?? '',
    registration_authority: practitioner.registration.authority ?? '',
    registration_state: practitioner.registration.state ?? '',
    registration_type: practitioner.registration.type ?? '',
    registration_date: practitioner.registration.date ?? '',
    registration_expires_at: practitioner.registration.expires_at ?? '',
  });
  const [newSpecialty, setNewSpecialty] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((current) => ({ ...current, [key]: value }));
  const specialtyKey = (specialty: SpecialtyRef) => (specialty.id ? `id:${specialty.id}` : `name:${specialty.name}`);
  const choices = options.specialties;

  const pickPrimary = (value: string) => set('primary', value ? (choices.find((choice) => specialtyKey(choice) === value) ?? null) : null);
  const addAdditional = (specialty: SpecialtyRef | null) => {
    if (specialty && !form.additional.some((item) => item.name.toLowerCase() === specialty.name.toLowerCase())) {
      set('additional', [...form.additional, specialty]);
    }
  };

  const save = () => {
    setSaving(true);
    router.put(
      `${links.self}/professional`,
      {
        profession: form.profession || null,
        primary_specialty: form.primary ? { id: form.primary.id, name: form.primary.name } : null,
        additional_specialties: form.additional.map((item) => ({ id: item.id, name: item.name })),
        experience_years: form.experience_years === '' ? null : Number(form.experience_years),
        bio: form.bio || null,
        registration_number: form.registration_number || null,
        registration_authority: form.registration_authority || null,
        registration_state: form.registration_state || null,
        registration_type: form.registration_type || null,
        registration_date: form.registration_date || null,
        registration_expires_at: form.registration_expires_at || null,
      },
      { preserveScroll: true, onSuccess: onClose, onError: (received) => setErrors(received as Record<string, string>), onFinish: () => setSaving(false) },
    );
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="lg"
      title="Professional details"
      description="Specialties come from your clinic's list. Type a new one to add it."
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>Cancel</Button>
          <Button tone="primary" loading={saving} loadingLabel="Saving…" onClick={save}>Save</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Profession" htmlFor="pr-profession" error={errors.profession} hint="For example Dentist, Orthodontist, Physiotherapist.">
          <input id="pr-profession" className={inputClass} value={form.profession} onChange={(e) => set('profession', e.target.value)} />
        </Field>
        <Field label="Primary specialty" htmlFor="pr-primary" error={errors.primary_specialty ?? errors['primary_specialty.id']}>
          <select id="pr-primary" className={selectClass} value={form.primary ? specialtyKey(form.primary) : ''} onChange={(e) => pickPrimary(e.target.value)}>
            <option value="">None</option>
            {form.primary && !choices.some((choice) => specialtyKey(choice) === specialtyKey(form.primary!)) && <option value={specialtyKey(form.primary)}>{form.primary.name}</option>}
            {choices.map((choice) => (
              <option key={specialtyKey(choice)} value={specialtyKey(choice)}>
                {choice.name}
                {choice.id ? '' : ' (suggested)'}
              </option>
            ))}
          </select>
        </Field>
        <div className="sm:col-span-2">
          <p className="mb-1.5 text-[12px] font-semibold text-clinic-ink">Additional specialties</p>
          <div className="mb-2 flex flex-wrap gap-1.5">
            {form.additional.map((item) => (
              <span key={item.name} className="inline-flex items-center gap-1 rounded-full bg-violet-50 py-1 pl-3 pr-1 text-[12px] font-semibold text-violet-800 ring-1 ring-violet-200">
                {item.name}
                <button type="button" aria-label={`Remove ${item.name}`} onClick={() => set('additional', form.additional.filter((other) => other !== item))} className="flex h-8 w-8 items-center justify-center rounded-full hover:bg-white">
                  <X className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
              </span>
            ))}
            {form.additional.length === 0 && <span className="text-[12px] text-clinic-secondary">None.</span>}
          </div>
          <div className="flex flex-wrap gap-2">
            <select aria-label="Add a specialty from the list" className={cn(selectClass, 'max-w-[260px] flex-1')} value="" onChange={(e) => addAdditional(choices.find((choice) => specialtyKey(choice) === e.target.value) ?? null)}>
              <option value="">Add from the list…</option>
              {choices.map((choice) => (
                <option key={specialtyKey(choice)} value={specialtyKey(choice)}>{choice.name}</option>
              ))}
            </select>
            <input aria-label="New specialty" placeholder="Or type a new specialty" className={cn(inputClass, 'max-w-[240px] flex-1')} value={newSpecialty} onChange={(e) => setNewSpecialty(e.target.value)} />
            <Button icon={Plus} disabled={newSpecialty.trim() === ''} onClick={() => { addAdditional({ id: null, name: newSpecialty.trim() }); setNewSpecialty(''); }}>Add</Button>
          </div>
        </div>
        <Field label="Experience (years)" htmlFor="pr-exp" error={errors.experience_years}>
          <input id="pr-exp" type="number" min={0} max={70} className={inputClass} value={form.experience_years} onChange={(e) => set('experience_years', e.target.value)} />
        </Field>
        <div />
        <Field label="About" htmlFor="pr-bio" error={errors.bio} className="sm:col-span-2">
          <textarea id="pr-bio" rows={3} maxLength={2000} className={textareaClass} value={form.bio} onChange={(e) => set('bio', e.target.value)} />
        </Field>

        <p className="text-[12px] font-bold uppercase tracking-wide text-clinic-secondary sm:col-span-2">Professional registration (if applicable)</p>
        <Field label="Registration number" htmlFor="pr-regno" error={errors.registration_number}>
          <input id="pr-regno" className={inputClass} value={form.registration_number} onChange={(e) => set('registration_number', e.target.value)} />
        </Field>
        <Field label="Registering authority" htmlFor="pr-auth" error={errors.registration_authority}>
          <input id="pr-auth" className={inputClass} value={form.registration_authority} onChange={(e) => set('registration_authority', e.target.value)} placeholder="For example State Dental Council" />
        </Field>
        <Field label="State" htmlFor="pr-state" error={errors.registration_state}>
          <input id="pr-state" className={inputClass} value={form.registration_state} onChange={(e) => set('registration_state', e.target.value)} />
        </Field>
        <Field label="Registration type" htmlFor="pr-type" error={errors.registration_type}>
          <select id="pr-type" className={selectClass} value={form.registration_type} onChange={(e) => set('registration_type', e.target.value)}>
            <option value="">Not specified</option>
            {Object.entries(options.registrationTypes).map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </Field>
        <Field label="Registration date" htmlFor="pr-date" error={errors.registration_date}>
          <input id="pr-date" type="date" className={inputClass} value={form.registration_date} onChange={(e) => set('registration_date', e.target.value)} />
        </Field>
        <Field label="Valid until (if it expires)" htmlFor="pr-expiry" error={errors.registration_expires_at}>
          <input id="pr-expiry" type="date" className={inputClass} value={form.registration_expires_at} onChange={(e) => set('registration_expires_at', e.target.value)} />
        </Field>
      </div>
    </Modal>
  );
}

function QualificationDialog({ props, item, onClose }: { props: ProfileProps; item: Qualification | null; onClose: () => void }) {
  const { options, links, documents } = props;
  const [form, setForm] = useState({
    qualification: item?.qualification ?? '',
    degree_type: item?.degree_type ?? 'bachelor',
    specialization: item?.specialization ?? '',
    institution: item?.institution ?? '',
    year: item?.year ? String(item.year) : '',
    country: item?.country ?? '',
    state: item?.state ?? '',
    staff_document_id: item?.staff_document_id ? String(item.staff_document_id) : '',
    verification_status: item?.verification_status ?? 'unverified',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((current) => ({ ...current, [key]: value }));
  const files = documents.items.flatMap((entry) => (entry.current ? [{ id: entry.current.id, label: `${entry.type.name}: ${entry.current.title}` }] : []));

  const save = () => {
    setSaving(true);
    const payload = {
      ...form,
      specialization: form.specialization || null,
      institution: form.institution || null,
      year: form.year ? Number(form.year) : null,
      country: form.country || null,
      state: form.state || null,
      staff_document_id: form.staff_document_id ? Number(form.staff_document_id) : null,
    };
    const options = { preserveScroll: true, onSuccess: onClose, onError: (received: Record<string, string>) => setErrors(received), onFinish: () => setSaving(false) };
    if (item) {
      router.put(`${links.self}/qualifications/${item.id}`, payload, options);
    } else {
      router.post(`${links.self}/qualifications`, payload, options);
    }
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      title={item ? `Edit ${item.label}` : 'Add qualification'}
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>Cancel</Button>
          <Button tone="primary" loading={saving} loadingLabel="Saving…" onClick={save}>Save</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Qualification" htmlFor="q-name" error={errors.qualification} hint="For example BDS, MDS, BPT, Fellowship in Implantology.">
          <input id="q-name" className={inputClass} value={form.qualification} onChange={(e) => set('qualification', e.target.value)} />
        </Field>
        <Field label="Degree / type" htmlFor="q-type" error={errors.degree_type}>
          <select id="q-type" className={selectClass} value={form.degree_type} onChange={(e) => set('degree_type', e.target.value)}>
            {Object.entries(options.degreeTypes).map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </Field>
        <Field label="Specialization (optional)" htmlFor="q-spec" error={errors.specialization} hint="Shown as MDS (Orthodontics).">
          <input id="q-spec" className={inputClass} value={form.specialization} onChange={(e) => set('specialization', e.target.value)} />
        </Field>
        <Field label="Institution" htmlFor="q-inst" error={errors.institution}>
          <input id="q-inst" className={inputClass} value={form.institution} onChange={(e) => set('institution', e.target.value)} />
        </Field>
        <Field label="Year" htmlFor="q-year" error={errors.year}>
          <input id="q-year" type="number" inputMode="numeric" className={inputClass} value={form.year} onChange={(e) => set('year', e.target.value)} />
        </Field>
        <Field label="State" htmlFor="q-state" error={errors.state}>
          <input id="q-state" className={inputClass} value={form.state} onChange={(e) => set('state', e.target.value)} />
        </Field>
        <Field label="Country" htmlFor="q-country" error={errors.country}>
          <input id="q-country" className={inputClass} value={form.country} onChange={(e) => set('country', e.target.value)} />
        </Field>
        <Field label="Supporting document" htmlFor="q-doc" error={errors.staff_document_id} hint={files.length === 0 ? 'Upload the certificate under Documents first.' : undefined}>
          <select id="q-doc" className={selectClass} value={form.staff_document_id} onChange={(e) => set('staff_document_id', e.target.value)}>
            <option value="">None</option>
            {files.map((file) => (
              <option key={file.id} value={file.id}>{file.label}</option>
            ))}
          </select>
        </Field>
        {item && (
          <Field label="Verification" htmlFor="q-verify" error={errors.verification_status}>
            <select id="q-verify" className={selectClass} value={form.verification_status} onChange={(e) => set('verification_status', e.target.value as typeof form.verification_status)}>
              <option value="unverified">Not verified</option>
              <option value="verified">Verified</option>
              <option value="rejected">Rejected</option>
            </select>
          </Field>
        )}
      </div>
    </Modal>
  );
}
