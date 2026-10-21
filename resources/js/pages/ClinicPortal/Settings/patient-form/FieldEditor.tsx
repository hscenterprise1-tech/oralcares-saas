import { useId, useState } from 'react';
import { ArrowDown, ArrowUp, ChevronDown, Lock, Monitor, PanelsTopLeft } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ChoiceChips, Input } from '@/clinic';
import { MultiChoiceChips } from '@/clinic/ui/ChoiceChips';
import { Switch } from '@/clinic/ui/controls';
import { normalised, type FieldDefinition, type PatientFormProps } from './types';

/** The configurable fields, grouped as an owner thinks of them, with the forms' own wording. */
export const FIELD_GROUPS: { key: string; title: string; note?: string; fields: [string, string][] }[] = [
  { key: 'identity', title: 'Identity', fields: [['last_name', 'Last name'], ['gender', 'Gender'], ['date_of_birth', 'Date of birth'], ['blood_group', 'Blood group']] },
  { key: 'contact', title: 'Contact', fields: [['alternate_phone', 'Alternate mobile'], ['email', 'Email'], ['emergency_contact', 'Emergency contact']] },
  {
    key: 'address',
    title: 'Address',
    note: 'A part you have not set follows the address line.',
    fields: [['address', 'Address line'], ['address_locality', 'Locality / area'], ['address_city', 'City'], ['address_state', 'State'], ['address_pincode', 'PIN / postal code'], ['address_country', 'Country']],
  },
  {
    key: 'medical',
    title: 'Medical history & safety',
    note: 'Recorded by staff allowed to record clinical details. Hiding a list never removes what is already recorded.',
    fields: [['medical_conditions', 'Medical history'], ['allergies', 'Allergies'], ['medications', 'Current medications'], ['surgical_history', 'Past surgery'], ['family_history', 'Family history']],
  },
  { key: 'visit', title: 'Visit', fields: [['doctor_id', 'Assigned doctor']] },
];

const STATES = [
  { value: 'required', label: 'Required' },
  { value: 'optional', label: 'Optional' },
  { value: 'hidden', label: 'Hidden' },
];

/**
 * Every configurable field, by group: required / optional / hidden at a
 * tap, and — behind "More" — where it shows (Reception popup, full page),
 * which modules and roles it is for, who may change it, the clinic's own
 * label and help text, and its place in the order. The fields that cannot
 * be changed are listed first, with why.
 */
export function FieldEditor({
  props,
  draft,
  onChange,
  onMove,
}: {
  props: PatientFormProps;
  draft: Record<string, FieldDefinition>;
  onChange: (field: string, definition: FieldDefinition) => void;
  onMove: (field: string, direction: -1 | 1, group: string[]) => void;
}) {
  return (
    <div className="space-y-5">
      <section aria-labelledby="pf-system" className="rounded-2xl border border-clinic-border bg-clinic-inset/40 p-4">
        <h3 id="pf-system" className="flex items-center gap-1.5 text-[13px] font-bold text-clinic-ink">
          <Lock className="h-4 w-4 text-clinic-secondary" aria-hidden="true" />
          Always required
        </h3>
        <ul className="mt-2 space-y-1.5">
          {props.system_fields.map((field) => (
            <li key={field.key} className="text-[12.5px] text-clinic-ink">
              <span className="font-semibold">{field.label}</span> <span className="text-clinic-secondary">— {field.reason}</span>
            </li>
          ))}
        </ul>
      </section>

      {FIELD_GROUPS.map((group) => {
        const ordered = group.fields.filter(([field]) => draft[field]).sort((a, b) => draft[a[0]].order - draft[b[0]].order);

        return (
          <section key={group.key} aria-labelledby={`pf-group-${group.key}`} className="space-y-2">
            <div>
              <h3 id={`pf-group-${group.key}`} className="text-[12px] font-bold uppercase tracking-wide text-clinic-secondary">
                {group.title}
              </h3>
              {group.note && <p className="mt-0.5 text-[12px] text-clinic-secondary">{group.note}</p>}
            </div>
            <ul className="divide-y divide-clinic-border rounded-2xl border border-clinic-border">
              {ordered.map(([field, label], index) => (
                <FieldRow
                  key={field}
                  field={field}
                  defaultLabel={label}
                  props={props}
                  definition={draft[field]}
                  onChange={(definition) => onChange(field, normalised(definition))}
                  onMove={(direction) => onMove(field, direction, ordered.map(([key]) => key))}
                  first={index === 0}
                  last={index === ordered.length - 1}
                />
              ))}
            </ul>
          </section>
        );
      })}
    </div>
  );
}

function FieldRow({
  field,
  defaultLabel,
  props,
  definition,
  onChange,
  onMove,
  first,
  last,
}: {
  field: string;
  defaultLabel: string;
  props: PatientFormProps;
  definition: FieldDefinition;
  onChange: (definition: FieldDefinition) => void;
  onMove: (direction: -1 | 1) => void;
  first: boolean;
  last: boolean;
}) {
  const [open, setOpen] = useState(false);
  const id = useId();
  const patch = (changes: Partial<FieldDefinition>) => onChange({ ...definition, ...changes });
  const required = definition.state === 'required';
  const hidden = definition.state === 'hidden';
  const roleOptions = props.roles.map((role) => ({ value: role, label: role }));

  return (
    <li className="px-3.5 py-3">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <div className="min-w-0 flex-1">
          <p className="text-[13.5px] font-semibold text-clinic-ink">
            {definition.label || defaultLabel}
            {definition.label && <span className="ml-1.5 text-[11.5px] font-medium text-clinic-secondary">({defaultLabel})</span>}
          </p>
          {!hidden && (
            <p className="mt-0.5 flex flex-wrap items-center gap-x-3 text-[11.5px] text-clinic-secondary">
              {definition.new_case && (
                <span className="inline-flex items-center gap-1">
                  <PanelsTopLeft className="h-3.5 w-3.5" aria-hidden="true" />
                  Reception popup
                </span>
              )}
              {definition.full_page && (
                <span className="inline-flex items-center gap-1">
                  <Monitor className="h-3.5 w-3.5" aria-hidden="true" />
                  Full page
                </span>
              )}
              {definition.specialties && <span>Only {definition.specialties.map((key) => props.modules.find((module) => module.key === key)?.label ?? key).join(', ')}</span>}
              {definition.visible_roles && <span>Seen by {definition.visible_roles.join(', ')}</span>}
            </p>
          )}
        </div>
        <ChoiceChips label={`${definition.label || defaultLabel}: required, optional or hidden`} id={`${id}-state`} options={STATES} value={definition.state} onChange={(state) => patch({ state: state as FieldDefinition['state'] })} />
        <button
          type="button"
          aria-expanded={open}
          aria-controls={`${id}-more`}
          onClick={() => setOpen((current) => !current)}
          className="inline-flex min-h-10 items-center gap-1 rounded-full px-3 text-[12.5px] font-semibold text-clinic-ink hover:bg-clinic-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
        >
          More
          <ChevronDown className={cn('h-4 w-4 motion-safe:transition-transform', open && 'rotate-180')} aria-hidden="true" />
        </button>
      </div>

      {open && (
        <div id={`${id}-more`} className="mt-3 grid grid-cols-1 gap-4 rounded-xl bg-clinic-inset/40 p-3.5 md:grid-cols-2">
          <div className="space-y-2.5">
            <Switch
              checked={definition.new_case}
              disabled={required || hidden}
              onCheckedChange={(checked) => patch({ new_case: checked })}
              label="Show on the Reception popup"
              description={required ? 'A required field shows everywhere a patient is registered.' : 'Keep the popup short: only what the desk needs.'}
            />
            <Switch checked={definition.full_page} disabled={required || hidden} onCheckedChange={(checked) => patch({ full_page: checked })} label="Show on the full registration page" />
          </div>
          <div className="space-y-2.5">
            <label className="block text-[12px] font-semibold text-clinic-ink" htmlFor={`${id}-label`}>
              Your label <span className="font-medium text-clinic-secondary">· blank uses “{defaultLabel}”</span>
            </label>
            <Input id={`${id}-label`} value={definition.label ?? ''} maxLength={props.limits.label} onChange={(event) => patch({ label: event.target.value || null })} placeholder={defaultLabel} />
            <label className="block text-[12px] font-semibold text-clinic-ink" htmlFor={`${id}-help`}>
              Help text <span className="font-medium text-clinic-secondary">· optional</span>
            </label>
            <Input id={`${id}-help`} value={definition.help ?? ''} maxLength={props.limits.help} onChange={(event) => patch({ help: event.target.value || null })} placeholder="Shown under the field" />
          </div>
          {props.modules.length > 1 && (
            <div className="md:col-span-2">
              <p className="mb-1.5 text-[12px] font-semibold text-clinic-ink">For which modules</p>
              <MultiChoiceChips
                label="For which modules"
                options={props.modules.map((module) => ({ value: module.key, label: module.label }))}
                value={definition.specialties}
                onChange={(specialties) => patch({ specialties })}
                noneLabel="All modules"
                size="sm"
              />
            </div>
          )}
          <div>
            <p className="mb-1.5 text-[12px] font-semibold text-clinic-ink">Who sees it</p>
            <MultiChoiceChips label="Who sees it" options={roleOptions} value={definition.visible_roles} onChange={(roles) => patch({ visible_roles: roles })} noneLabel="Everyone" size="sm" />
          </div>
          <div>
            <p className="mb-1.5 text-[12px] font-semibold text-clinic-ink">Who can change it</p>
            <MultiChoiceChips label="Who can change it" options={roleOptions} value={definition.editable_roles} onChange={(roles) => patch({ editable_roles: roles })} noneLabel="Everyone who sees it" size="sm" />
          </div>
          <div className="flex items-center gap-2 md:col-span-2">
            <span className="text-[12px] font-semibold text-clinic-ink">Order</span>
            <button type="button" onClick={() => onMove(-1)} disabled={first} aria-label={`Move ${definition.label || defaultLabel} up`} className="flex h-10 w-10 items-center justify-center rounded-full border border-clinic-border text-clinic-ink hover:bg-clinic-inset disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong">
              <ArrowUp className="h-4 w-4" aria-hidden="true" />
            </button>
            <button type="button" onClick={() => onMove(1)} disabled={last} aria-label={`Move ${definition.label || defaultLabel} down`} className="flex h-10 w-10 items-center justify-center rounded-full border border-clinic-border text-clinic-ink hover:bg-clinic-inset disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong">
              <ArrowDown className="h-4 w-4" aria-hidden="true" />
            </button>
          </div>
        </div>
      )}
    </li>
  );
}
