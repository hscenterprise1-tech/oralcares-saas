import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { CircleCheck, Upload } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Input, Select, useFieldId } from '@/clinic/ui/controls';
import { asArray, asString, errorUnder, LockedFacts, NumberField, TextField, WeekHours } from './fields';
import type { Option, StepFormProps } from './types';

/*
 * An invited staff member's own setup: their details, professional record,
 * services, hours and documents. Each writes only to their own profile; the
 * server decides whose session it is from the session, never the request.
 */

export function MyProfileStep(form: StepFormProps) {
  const genderId = useFieldId();
  const genders = asArray<Option>(form.meta.genders);

  return (
    <div className="space-y-5">
      <LockedFacts facts={asArray<{ label: string; value: string | null }>(form.meta.locked)} />
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField form={form} name="name" label="Full name" required autoComplete="name" />
        <TextField form={form} name="phone" label="Mobile number" required type="tel" inputMode="tel" autoComplete="tel" />
        <TextField form={form} name="date_of_birth" label="Date of birth" type="date" autoComplete="bday" />
        <Field label="Gender" htmlFor={genderId} error={form.errors.gender}>
          <Select
            id={genderId}
            value={asString(form.values.gender)}
            onChange={(event) => form.set('gender', event.target.value || null)}
            options={genders.map((gender) => ({ value: String(gender.value), label: gender.label }))}
            placeholder="Prefer not to say"
            invalid={Boolean(form.errors.gender)}
            disabled={form.disabled}
          />
        </Field>
      </div>
    </div>
  );
}

export function MyProfessionalStep(form: StepFormProps) {
  const degreeId = useFieldId();
  const listId = useFieldId();
  const degrees = asArray<Option>(form.meta.degree_types);
  const specialties = asArray<string>(form.meta.specialties);

  return (
    <div className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField form={form} name="profession" label="Profession" required placeholder="e.g. Dentist, Physiotherapist" />
        <TextField form={form} name="specialty" label="Specialty" list={listId} />
        <datalist id={listId}>
          {specialties.map((name) => (
            <option key={name} value={name} />
          ))}
        </datalist>
        <NumberField form={form} name="experience_years" label="Years of experience" min={0} max={70} step={1} />
        <NumberField form={form} name="consultation_fee" label="Consultation fee" min={0} step="any" suffix="₹" hint="Charged when a patient is seen by you. Leave empty if you do not charge a separate consultation fee." />
      </div>
      <div>
        <h3 className="text-[13.5px] font-semibold text-cx-nav-ink">Qualification</h3>
        <div className="mt-3 grid gap-4 sm:grid-cols-2">
          <TextField form={form} name="qualification" label="Qualification" required placeholder="e.g. BDS, BPT" />
          <Field label="Degree type" htmlFor={degreeId} required error={form.errors.degree_type}>
            <Select
              id={degreeId}
              value={asString(form.values.degree_type)}
              onChange={(event) => form.set('degree_type', event.target.value)}
              options={degrees.map((degree) => ({ value: String(degree.value), label: degree.label }))}
              invalid={Boolean(form.errors.degree_type)}
              disabled={form.disabled}
            />
          </Field>
          <TextField form={form} name="specialization" label="Specialization" />
          <TextField form={form} name="institution" label="Institution" />
          <NumberField form={form} name="year" label="Year completed" min={1950} max={new Date().getFullYear()} step={1} />
        </div>
      </div>
      <div>
        <h3 className="text-[13.5px] font-semibold text-cx-nav-ink">Registration</h3>
        <div className="mt-3 grid gap-4 sm:grid-cols-2">
          <TextField form={form} name="registration_number" label="Registration number" />
          <TextField form={form} name="registration_authority" label="Registering council" hint="Needed when a registration number is given." />
          <TextField form={form} name="registration_state" label="State" />
          <TextField form={form} name="registration_expires_at" label="Valid until" type="date" />
        </div>
      </div>
    </div>
  );
}

export function MyServicesStep(form: StepFormProps) {
  const services = asArray<Option & { category: string | null }>(form.meta.services);
  const chosen = asArray<number>(form.values.service_ids);
  const toggle = (id: number, on: boolean) => form.set('service_ids', on ? [...chosen, id] : chosen.filter((item) => item !== id));
  const error = errorUnder(form.errors, 'service_ids');

  if (services.length === 0) {
    return <p className="rounded-xl border border-dashed border-cx-line-strong px-4 py-6 text-center text-[13px] text-cx-text-2">Your clinic has not switched on any services yet. Ask the clinic owner to finish setting up services.</p>;
  }

  return (
    <fieldset disabled={form.disabled}>
      <legend className="sr-only">Services you provide</legend>
      {error && (
        <p role="alert" className="mb-2 text-[12px] font-medium text-clinic-danger">
          {error}
        </p>
      )}
      <div className="grid gap-2.5 sm:grid-cols-2">
        {services.map((service) => {
          const id = Number(service.value);

          return (
            <div key={id} className="rounded-xl border border-cx-line px-3.5 py-3">
              <Checkbox checked={chosen.includes(id)} onCheckedChange={(on) => toggle(id, on)} label={service.label} description={service.category ?? undefined} />
            </div>
          );
        })}
      </div>
    </fieldset>
  );
}

export function MyScheduleStep(form: StepFormProps) {
  return <WeekHours form={form} startKey="start" endKey="end" />;
}

type DocumentItem = { type_id: number; name: string; has_expiry: boolean; requirement: string; state: string; file_name: string | null };

/** `StaffDocumentChecklist::state()`. */
const STATE_LABEL: Record<string, string> = {
  verified: 'Verified',
  expiring_soon: 'Verified · expiring soon',
  pending_verification: 'Waiting for review',
  rejected: 'Rejected',
  expired: 'Expired',
  missing: 'Not uploaded',
};
const UPLOADED = ['verified', 'expiring_soon', 'pending_verification'];

export function MyDocumentsStep(form: StepFormProps) {
  const items = asArray<DocumentItem>(form.meta.items);
  const uploadUrl = asString(form.meta.upload_url);

  if (items.length === 0) {
    return <p className="rounded-xl border border-dashed border-cx-line-strong px-4 py-6 text-center text-[13px] text-cx-text-2">Your clinic has not asked for any documents.</p>;
  }

  return (
    <ul className="space-y-3">
      {items.map((item) => (
        <DocumentRow key={item.type_id} item={item} uploadUrl={uploadUrl} accept={asString(form.meta.accept)} maxKb={Number(form.meta.max_kb ?? 0)} disabled={form.disabled} />
      ))}
    </ul>
  );
}

function DocumentRow({ item, uploadUrl, accept, maxKb, disabled }: { item: DocumentItem; uploadUrl: string; accept: string; maxKb: number; disabled: boolean }) {
  const [file, setFile] = useState<File | null>(null);
  const [expires, setExpires] = useState('');
  const [uploading, setUploading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const fileId = useFieldId();
  const expiryId = useFieldId();
  const done = UPLOADED.includes(item.state);

  const upload = (event: { preventDefault: () => void }) => {
    event.preventDefault();
    if (!file) {
      return;
    }
    setUploading(true);
    router.post(
      uploadUrl,
      { document_type_id: item.type_id, file, expires_on: expires || null },
      {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          setFile(null);
          setErrors({});
        },
        onError: (received) => setErrors(received as Record<string, string>),
        onFinish: () => setUploading(false),
      },
    );
  };

  return (
    <li className="rounded-xl border border-cx-line px-4 py-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-[13.5px] font-semibold text-cx-nav-ink">{item.name}</p>
          <p className="text-[12px] text-cx-text-3">
            {item.requirement === 'required' ? 'Required' : item.requirement === 'not_applicable' ? 'Not needed for your role' : 'Optional'}
            {item.file_name && ` · ${item.file_name}`}
          </p>
        </div>
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11.5px] font-semibold', done ? 'bg-cx-green-bg text-cx-green-ink' : 'bg-cx-slate-bg text-cx-slate-ink')}>
          {done && <CircleCheck className="h-3.5 w-3.5" aria-hidden="true" />}
          {STATE_LABEL[item.state] ?? item.state}
        </span>
      </div>
      {item.state !== 'verified' && item.state !== 'expiring_soon' && (
        // Not a <form>: this sits inside the wizard's own form, and a form inside a form is invalid HTML.
        <div className="mt-3 flex flex-wrap items-end gap-2">
          <Field label={done ? 'Replace file' : 'File'} htmlFor={fileId} error={errors.file ?? errors.document_type_id} hint={maxKb ? `Up to ${Math.round(maxKb / 1024)} MB.` : undefined} className="min-w-0 flex-1">
            <input
              id={fileId}
              type="file"
              accept={accept}
              disabled={disabled || uploading}
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
              className="block w-full text-[12.5px] text-cx-text-2 file:mr-3 file:rounded-full file:border-0 file:bg-cx-nav-bg file:px-3.5 file:py-2 file:text-[12.5px] file:font-semibold file:text-cx-nav-ink hover:file:bg-cx-nav-hover"
            />
          </Field>
          {item.has_expiry && (
            <Field label="Expires on" htmlFor={expiryId} error={errors.expires_on} className="w-[170px]">
              <Input id={expiryId} type="date" value={expires} onChange={(event) => setExpires(event.target.value)} disabled={disabled || uploading} />
            </Field>
          )}
          <Button type="button" onClick={upload} icon={Upload} loading={uploading} loadingLabel="Uploading…" disabled={disabled || !file}>
            Upload
          </Button>
        </div>
      )}
    </li>
  );
}
