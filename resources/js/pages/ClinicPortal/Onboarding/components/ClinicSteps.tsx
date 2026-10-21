import { useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Input, Select, Textarea, useFieldId } from '@/clinic/ui/controls';
import { asArray, asNumber, asString, errorUnder, LockedFacts, NumberField, SwitchField, TextField, WeekHours } from './fields';
import type { Option, StepFormProps } from './types';

/*
 * The clinic's setup steps, one form per section `component()`. Each form
 * edits exactly the keys its section's `rules()` accept; the section on the
 * server validates and applies them.
 */

const humanize = (value: string) => value.replace(/_/g, ' ').replace(/^\w/, (letter) => letter.toUpperCase());

export function ClinicIdentityStep(form: StepFormProps) {
  const locked = asArray<{ label: string; value: string | null }>(form.meta.locked);

  return (
    <div className="space-y-5">
      <LockedFacts facts={locked} note={asString(form.meta.locked_note) || undefined} />
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField form={form} name="display_name" label="Display name" required hint="Shown on screens and to patients." autoComplete="organization" />
        <TextField form={form} name="legal_name" label="Legal name" required hint="Printed on invoices." />
        <TextField form={form} name="contact_email" label="Contact email" type="email" autoComplete="email" />
        <TextField form={form} name="contact_phone" label="Contact phone" type="tel" inputMode="tel" autoComplete="tel" />
      </div>
    </div>
  );
}

export function PrimaryBranchStep(form: StepFormProps) {
  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField form={form} name="name" label="Branch name" required className="sm:col-span-2" />
        <TextField form={form} name="address_line1" label="Address" required className="sm:col-span-2" autoComplete="address-line1" />
        <TextField form={form} name="address_line2" label="Area / landmark" className="sm:col-span-2" autoComplete="address-line2" />
        <TextField form={form} name="city" label="City" required autoComplete="address-level2" />
        <TextField form={form} name="state" label="State" required autoComplete="address-level1" />
        <TextField form={form} name="pincode" label="PIN code" required inputMode="numeric" maxLength={6} autoComplete="postal-code" />
        <TextField form={form} name="phone" label="Phone" required type="tel" inputMode="tel" />
        <TextField form={form} name="whatsapp_number" label="WhatsApp number" type="tel" inputMode="tel" />
        <TextField form={form} name="gstin" label="GSTIN" hint="15 characters, e.g. 29ABCDE1234F1Z5." />
        <TextField form={form} name="clinical_establishment_registration_number" label="Clinical establishment registration no." className="sm:col-span-2" />
      </div>
      {asString(form.meta.note) && <p className="text-[12.5px] text-cx-text-2">{asString(form.meta.note)}</p>}
    </div>
  );
}

export function WorkingHoursStep(form: StepFormProps) {
  const holidays = asArray<string>(form.values.holidays);
  const [date, setDate] = useState('');
  const dateId = useFieldId();
  const add = () => {
    if (date && !holidays.includes(date)) {
      form.set('holidays', [...holidays, date].sort());
    }
    setDate('');
  };

  return (
    <div className="space-y-6">
      <WeekHours form={form} startKey="opens" endKey="closes" />
      <div>
        <h3 className="text-[13.5px] font-semibold text-cx-nav-ink">Holidays</h3>
        <p className="mt-0.5 text-[12.5px] text-cx-text-2">Days the clinic is closed. No appointments are offered on them.</p>
        <div className="mt-3 flex flex-wrap items-end gap-2">
          <Field label="Add a holiday" htmlFor={dateId} error={errorUnder(form.errors, 'holidays')} className="w-full max-w-[220px]">
            <Input id={dateId} type="date" value={date} onChange={(event) => setDate(event.target.value)} disabled={form.disabled} />
          </Field>
          <Button icon={Plus} onClick={add} disabled={form.disabled || !date}>
            Add
          </Button>
        </div>
        {holidays.length > 0 ? (
          <ul className="mt-3 flex flex-wrap gap-2">
            {holidays.map((holiday) => (
              <li key={holiday} className="inline-flex items-center gap-1 rounded-full bg-cx-nav-bg py-1 pl-3 pr-1 text-[12.5px] font-medium text-cx-nav-ink">
                {formatDate(holiday)}
                <button
                  type="button"
                  onClick={() => form.set('holidays', holidays.filter((item) => item !== holiday))}
                  disabled={form.disabled}
                  aria-label={`Remove ${formatDate(holiday)}`}
                  className="flex h-7 w-7 items-center justify-center rounded-full text-cx-nav-muted hover:bg-cx-nav-hover hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
                >
                  <X className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-3 text-[12.5px] text-cx-text-3">No holidays added.</p>
        )}
      </div>
    </div>
  );
}

function formatDate(iso: string): string {
  const date = new Date(`${iso}T00:00:00`);

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

export function ClinicalResourcesStep(form: StepFormProps) {
  const label = asString(form.meta.label) || 'Treatment rooms';
  const max = Number(form.meta.max ?? 50);

  return (
    <div className="max-w-xs">
      <NumberField form={form} name="count" label={`Number of ${label.toLowerCase()}`} required min={1} max={max} step={1} hint={`Between 1 and ${max}.`} />
    </div>
  );
}

type ServiceRow = { id: number | null; name: string; category: string | null; duration: number | null; price: number | null; active: boolean };

export function ServicesStep(form: StepFormProps) {
  const services = asArray<ServiceRow>(form.values.services);
  const update = (index: number, patch: Partial<ServiceRow>) =>
    form.set(
      'services',
      services.map((row, at) => (at === index ? { ...row, ...patch } : row)),
    );
  const remove = (index: number) => form.set('services', services.filter((_, at) => at !== index));
  const add = () => form.set('services', [...services, { id: null, name: '', category: '', duration: 30, price: null, active: true }]);
  const sectionError = form.errors.services;
  const offered = services.filter((row) => row.active).length;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-[12.5px] text-cx-text-2">
          <span className="font-semibold text-cx-nav-ink">{offered}</span> of {services.length} switched on
        </p>
        <Button icon={Plus} size="sm" onClick={add} disabled={form.disabled || services.length >= 200}>
          Add a service
        </Button>
      </div>
      {sectionError && (
        <p role="alert" className="text-[12px] font-medium text-clinic-danger">
          {sectionError}
        </p>
      )}
      {services.length === 0 ? (
        <p className="rounded-xl border border-dashed border-cx-line-strong px-4 py-6 text-center text-[13px] text-cx-text-2">No services yet. Add the first one.</p>
      ) : (
        <ul className="divide-y divide-cx-line rounded-xl border border-cx-line">
          {services.map((row, index) => {
            const rowError = errorUnder(form.errors, `services.${index}`);
            const named = row.name.trim() || 'New service';

            return (
              <li key={row.id ?? `new-${index}`} className={cn('px-4 py-3', !row.active && 'bg-cx-canvas/60')}>
                <div className="grid items-center gap-3 sm:grid-cols-[auto_minmax(0,1fr)_110px_120px_auto]">
                  <Checkbox checked={row.active} onCheckedChange={(active) => update(index, { active })} label={<span className="sr-only">Offer {named}</span>} disabled={form.disabled} />
                  <div className="min-w-0">
                    {row.id === null ? (
                      <div className="grid gap-2 sm:grid-cols-2">
                        <Input aria-label="Service name" placeholder="Service name" value={row.name} onChange={(event) => update(index, { name: event.target.value })} disabled={form.disabled} invalid={Boolean(form.errors[`services.${index}.name`])} />
                        <Input aria-label={`Category of ${named}`} placeholder="Category (optional)" value={row.category ?? ''} onChange={(event) => update(index, { category: event.target.value })} disabled={form.disabled} />
                      </div>
                    ) : (
                      <>
                        <p className={cn('truncate text-[13.5px] font-semibold', row.active ? 'text-cx-nav-ink' : 'text-cx-text-2')}>{row.name}</p>
                        {row.category && <p className="truncate text-[12px] text-cx-text-3">{row.category}</p>}
                      </>
                    )}
                  </div>
                  <div className="relative">
                    <Input
                      aria-label={`Usual duration of ${named}, minutes`}
                      type="number"
                      inputMode="numeric"
                      min={5}
                      max={480}
                      value={asString(row.duration)}
                      onChange={(event) => update(index, { duration: asNumber(event.target.value) })}
                      disabled={form.disabled}
                      invalid={Boolean(form.errors[`services.${index}.duration`])}
                      className="h-10 pr-11"
                    />
                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-cx-text-3" aria-hidden="true">
                      min
                    </span>
                  </div>
                  <div className="relative">
                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[12.5px] text-cx-text-3" aria-hidden="true">
                      ₹
                    </span>
                    <Input
                      aria-label={`Price of ${named}`}
                      type="number"
                      inputMode="decimal"
                      min={0}
                      step="any"
                      placeholder="Price"
                      value={asString(row.price)}
                      onChange={(event) => update(index, { price: asNumber(event.target.value) })}
                      disabled={form.disabled}
                      invalid={Boolean(form.errors[`services.${index}.price`])}
                      className="h-10 pl-7"
                    />
                  </div>
                  <div className="flex justify-end">
                    {row.id === null && (
                      <button
                        type="button"
                        onClick={() => remove(index)}
                        disabled={form.disabled}
                        aria-label={`Remove ${named}`}
                        className="flex h-9 w-9 items-center justify-center rounded-full text-cx-text-3 hover:bg-cx-rose-bg hover:text-cx-rose-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
                      >
                        <Trash2 className="h-4 w-4" aria-hidden="true" />
                      </button>
                    )}
                  </div>
                </div>
                {rowError && (
                  <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
                    {rowError}
                  </p>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

export function AppointmentsStep(form: StepFormProps) {
  const slotId = useFieldId();
  const slots = asArray<number>(form.meta.slot_choices);

  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Calendar slot length" htmlFor={slotId} required error={form.errors.slot_minutes}>
          <Select
            id={slotId}
            value={asString(form.values.slot_minutes)}
            onChange={(event) => form.set('slot_minutes', Number(event.target.value))}
            options={slots.map((minutes) => ({ value: String(minutes), label: `${minutes} minutes` }))}
            invalid={Boolean(form.errors.slot_minutes)}
            disabled={form.disabled}
          />
        </Field>
        <NumberField form={form} name="default_duration_minutes" label="Usual visit length" required min={5} max={480} step={5} suffix="min" />
        <NumberField form={form} name="buffer_minutes" label="Gap between visits" required min={0} max={120} step={5} suffix="min" />
        <NumberField form={form} name="cancellation_notice_hours" label="Cancellation notice" required min={0} max={168} step={1} suffix="hours" />
        <NumberField form={form} name="no_show_after_minutes" label="Mark as no-show after" required min={5} max={240} step={5} suffix="min" hint="How late a patient can be before the visit may be marked missed." />
      </div>
      <SwitchField form={form} name="online_booking_enabled" label="Online booking" description="Let patients book themselves when online booking is available for your clinic." />
    </div>
  );
}

export function BillingReceptionStep(form: StepFormProps) {
  const methods = asArray<string>(form.meta.payment_methods);
  const taxModes = asArray<string>(form.meta.tax_modes);
  const chosen = asArray<string>(form.values.payment_methods);
  const taxId = useFieldId();
  const footerId = useFieldId();
  const noTax = form.values.tax_mode === 'none';
  const toggle = (method: string, on: boolean) => form.set('payment_methods', on ? [...chosen, method] : chosen.filter((item) => item !== method));
  const TAX_LABELS: Record<string, string> = { none: 'No tax', inclusive: 'Included in prices', exclusive: 'Added on top of prices' };

  return (
    <div className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField form={form} name="invoice_prefix" label="Invoice number prefix" required maxLength={12} hint="Letters, numbers, - and /. For example INV-." />
        <NumberField form={form} name="registration_fee" label="Registration fee" min={0} step="any" suffix="₹" hint="Optional. Kept with your billing settings; patients are not charged it automatically. Consultation fees are set for each doctor." />
        <Field label="Tax on invoices" htmlFor={taxId} required error={form.errors.tax_mode}>
          <Select
            id={taxId}
            value={asString(form.values.tax_mode)}
            onChange={(event) => {
              form.set('tax_mode', event.target.value);
              if (event.target.value === 'none') {
                form.set('tax_percent', 0);
              }
            }}
            options={taxModes.map((mode) => ({ value: mode, label: TAX_LABELS[mode] ?? humanize(mode) }))}
            invalid={Boolean(form.errors.tax_mode)}
            disabled={form.disabled}
          />
        </Field>
        {!noTax && <NumberField form={form} name="tax_percent" label="Tax rate" required min={0} max={28} step="any" suffix="%" />}
      </div>
      <fieldset disabled={form.disabled}>
        <legend className="text-[12px] font-semibold text-cx-nav-ink">
          Payment methods accepted at the desk<span className="ml-0.5 text-clinic-danger" aria-hidden="true">*</span>
        </legend>
        <div className="mt-2 grid gap-2.5 sm:grid-cols-3">
          {methods.map((method) => (
            <Checkbox key={method} checked={chosen.includes(method)} onCheckedChange={(on) => toggle(method, on)} label={humanize(method)} />
          ))}
        </div>
        {errorUnder(form.errors, 'payment_methods') && (
          <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
            {errorUnder(form.errors, 'payment_methods')}
          </p>
        )}
      </fieldset>
      <Field label="Receipt footer" htmlFor={footerId} error={form.errors.receipt_footer} hint="Printed at the bottom of every receipt. Up to 300 characters.">
        <Textarea
          id={footerId}
          maxLength={300}
          value={asString(form.values.receipt_footer)}
          onChange={(event) => form.set('receipt_footer', event.target.value)}
          invalid={Boolean(form.errors.receipt_footer)}
          disabled={form.disabled}
        />
      </Field>
    </div>
  );
}

export function CommunicationStep(form: StepFormProps) {
  const hoursId = useFieldId();
  const languageId = useFieldId();
  const hours = asArray<number>(form.meta.reminder_hours);
  const languages = asArray<{ value: string; label: string }>(form.meta.languages);

  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField form={form} name="sender_name" label="Messages signed as" required maxLength={60} />
        <Field label="Language" htmlFor={languageId} required error={form.errors.language}>
          <Select
            id={languageId}
            value={asString(form.values.language)}
            onChange={(event) => form.set('language', event.target.value)}
            options={languages}
            invalid={Boolean(form.errors.language)}
            disabled={form.disabled}
          />
        </Field>
      </div>
      <SwitchField form={form} name="send_confirmations" label="Booking confirmations" description="A message when an appointment is booked." />
      <SwitchField form={form} name="send_reminders" label="Appointment reminders" description="A reminder before each appointment." />
      {Boolean(form.values.send_reminders) && (
        <Field label="Send reminders" htmlFor={hoursId} required error={form.errors.reminder_hours_before} className="max-w-xs">
          <Select
            id={hoursId}
            value={asString(form.values.reminder_hours_before)}
            onChange={(event) => form.set('reminder_hours_before', Number(event.target.value))}
            options={hours.map((hour) => ({ value: String(hour), label: `${hour} hours before` }))}
            invalid={Boolean(form.errors.reminder_hours_before)}
            disabled={form.disabled}
          />
        </Field>
      )}
    </div>
  );
}

type InviteRow = { name: string; email: string; staff_type: string; role_id: number | null; branch_ids: number[] };

export function StaffInvitationsStep(form: StepFormProps) {
  const invites = asArray<InviteRow>(form.values.invites);
  const staffTypes = asArray<Option & { clinical: boolean }>(form.meta.staff_types);
  const roles = asArray<Option>(form.meta.roles);
  const branches = asArray<Option & { primary: boolean }>(form.meta.branches);
  const invited = asArray<{ name: string; email: string | null; role: string | null; status: string | null }>(form.meta.invited);
  const primary = branches.find((branch) => branch.primary) ?? branches[0];
  const update = (index: number, patch: Partial<InviteRow>) =>
    form.set(
      'invites',
      invites.map((row, at) => (at === index ? { ...row, ...patch } : row)),
    );
  const add = () =>
    form.set('invites', [
      ...invites,
      { name: '', email: '', staff_type: asString(staffTypes[0]?.value), role_id: roles[0] ? Number(roles[0].value) : null, branch_ids: primary ? [Number(primary.value)] : [] },
    ]);

  return (
    <div className="space-y-4">
      {invited.length > 0 && (
        <div className="rounded-xl bg-cx-slate-bg px-4 py-3">
          <p className="text-[12px] font-semibold text-cx-nav-ink">Already invited</p>
          <ul className="mt-1.5 space-y-1 text-[12.5px] text-cx-text-2">
            {invited.map((person) => (
              <li key={`${person.email}-${person.name}`} className="break-words">
                <span className="font-medium text-cx-nav-ink">{person.name}</span>
                {person.role && ` · ${person.role}`}
                {person.email && ` · ${person.email}`}
              </li>
            ))}
          </ul>
        </div>
      )}
      {form.errors.invites && (
        <p role="alert" className="text-[12px] font-medium text-clinic-danger">
          {form.errors.invites}
        </p>
      )}
      {invites.length === 0 ? (
        <p className="rounded-xl border border-dashed border-cx-line-strong px-4 py-6 text-center text-[13px] text-cx-text-2">
          Nobody to invite yet. Add a team member, or continue and invite people later.
        </p>
      ) : (
        <ul className="space-y-3">
          {invites.map((row, index) => {
            const field = (name: string) => form.errors[`invites.${index}.${name}`];

            return (
              <li key={index} className="rounded-xl border border-cx-line p-4">
                <div className="mb-3 flex items-center justify-between gap-2">
                  <p className="text-[12.5px] font-semibold text-cx-nav-ink">Team member {index + 1}</p>
                  <button
                    type="button"
                    onClick={() => form.set('invites', invites.filter((_, at) => at !== index))}
                    disabled={form.disabled}
                    aria-label={`Remove team member ${index + 1}`}
                    className="flex h-9 w-9 items-center justify-center rounded-full text-cx-text-3 hover:bg-cx-rose-bg hover:text-cx-rose-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
                  >
                    <Trash2 className="h-4 w-4" aria-hidden="true" />
                  </button>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Name" htmlFor={`invite-${index}-name`} required error={field('name')}>
                    <Input id={`invite-${index}-name`} value={row.name} onChange={(event) => update(index, { name: event.target.value })} invalid={Boolean(field('name'))} disabled={form.disabled} />
                  </Field>
                  <Field label="Email" htmlFor={`invite-${index}-email`} required error={field('email')}>
                    <Input id={`invite-${index}-email`} type="email" value={row.email} onChange={(event) => update(index, { email: event.target.value })} invalid={Boolean(field('email'))} disabled={form.disabled} />
                  </Field>
                  <Field label="Works as" htmlFor={`invite-${index}-type`} required error={field('staff_type')}>
                    <Select
                      id={`invite-${index}-type`}
                      value={row.staff_type}
                      onChange={(event) => update(index, { staff_type: event.target.value })}
                      options={staffTypes.map((type) => ({ value: String(type.value), label: type.label }))}
                      invalid={Boolean(field('staff_type'))}
                      disabled={form.disabled}
                    />
                  </Field>
                  <Field label="Role" htmlFor={`invite-${index}-role`} required error={field('role_id')}>
                    <Select
                      id={`invite-${index}-role`}
                      value={asString(row.role_id)}
                      onChange={(event) => update(index, { role_id: event.target.value ? Number(event.target.value) : null })}
                      options={roles.map((role) => ({ value: String(role.value), label: role.label }))}
                      invalid={Boolean(field('role_id'))}
                      disabled={form.disabled}
                    />
                  </Field>
                </div>
                {branches.length > 1 && (
                  <fieldset disabled={form.disabled} className="mt-3">
                    <legend className="text-[12px] font-semibold text-cx-nav-ink">Branches</legend>
                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                      {branches.map((branch) => {
                        const id = Number(branch.value);
                        const checked = row.branch_ids.includes(id);

                        return (
                          <Checkbox
                            key={id}
                            checked={checked}
                            onCheckedChange={(on) => update(index, { branch_ids: on ? [...row.branch_ids, id] : row.branch_ids.filter((item) => item !== id) })}
                            label={branch.label}
                          />
                        );
                      })}
                    </div>
                  </fieldset>
                )}
                {errorUnder(form.errors, `invites.${index}.branch_ids`) && (
                  <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
                    {errorUnder(form.errors, `invites.${index}.branch_ids`)}
                  </p>
                )}
              </li>
            );
          })}
        </ul>
      )}
      <Button icon={Plus} onClick={add} disabled={form.disabled || invites.length >= 25 || roles.length === 0 || branches.length === 0}>
        Add a team member
      </Button>
    </div>
  );
}
