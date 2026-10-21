import { useState } from 'react';
import { Eye } from 'lucide-react';
import { Select } from '@/clinic';
import {
  EMPTY_PATIENT,
  MedicalSafetyFields,
  MoreDetailsFields,
  moreFields,
  PatientIdentityFields,
  showsSafety,
  type PatientForm,
  type RegistrationConfig,
} from '@/pages/Clinic/Reception/intake/PatientFields';
import type { CatalogueItem } from '@/pages/Clinic/Reception/intake/types';
import { effectiveFields, type FieldDefinition, type ListItem, type PatientFormProps, type PreviewScope, type Screen } from './types';

/** What an enabled list offers in a form: the shape the registration fields read. */
function offered(items: ListItem[]): CatalogueItem[] {
  return items.filter((item) => item.enabled && !item.archived).sort((a, b) => a.position - b.position);
}

/**
 * The registration form as one member will see it — the Reception popup or
 * the full page, for one module and role — built from the same field
 * components the real forms use, with the settings being edited (saved or
 * not). Try it: nothing typed here is saved.
 */
export function Preview({ props, draft, allergyStatus }: { props: PatientFormProps; draft: Record<string, FieldDefinition>; allergyStatus: PatientFormProps['quick_care']['allergy_status'] }) {
  const [scope, setScope] = useState<PreviewScope>({ module: props.modules.length === 1 ? props.modules[0].key : '', role: '', screen: 'new_case' });
  const [data, setData] = useState<PatientForm>(EMPTY_PATIENT);
  const config: RegistrationConfig = {
    fields: effectiveFields(draft, scope),
    catalogue: {
      conditions: offered(props.lists.condition),
      allergies: offered(props.lists.allergy),
      medications: offered(props.lists.medication),
      surgical_history: offered(props.lists.surgery),
      family_history: offered(props.lists.family_history),
      alerts: offered(props.lists.alert),
    },
    quick_care: { allergy_status: allergyStatus },
    can: { record_clinical: true },
  };
  const setField = <K extends keyof PatientForm>(field: K, value: PatientForm[K]) => setData((current) => ({ ...current, [field]: value }));

  return (
    <section aria-labelledby="pf-preview" className="space-y-4">
      <div>
        <h2 id="pf-preview" className="flex items-center gap-1.5 text-[13px] font-bold uppercase tracking-wide text-clinic-secondary">
          <Eye className="h-4 w-4" aria-hidden="true" />
          Preview
        </h2>
        <p className="mt-0.5 text-[12px] text-clinic-secondary">The form as it will be shown, with your changes. Nothing typed here is saved.</p>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label className="text-[12px] font-semibold text-clinic-ink">
          Screen
          <Select className="mt-1" value={scope.screen} onChange={(event) => setScope((current) => ({ ...current, screen: event.target.value as Screen }))} options={[{ value: 'new_case', label: 'Reception popup' }, { value: 'full_page', label: 'Full page' }]} />
        </label>
        <label className="text-[12px] font-semibold text-clinic-ink">
          Module
          <Select className="mt-1" value={scope.module} onChange={(event) => setScope((current) => ({ ...current, module: event.target.value }))} options={props.modules.map((module) => ({ value: module.key, label: module.label }))} placeholder="Not chosen yet" />
        </label>
        <label className="text-[12px] font-semibold text-clinic-ink">
          Seen by
          <Select className="mt-1" value={scope.role} onChange={(event) => setScope((current) => ({ ...current, role: event.target.value }))} options={props.roles.map((role) => ({ value: role, label: role }))} placeholder="Owner" />
        </label>
      </div>

      <div className="space-y-4 rounded-2xl border border-dashed border-clinic-border-strong p-4">
        <PatientIdentityFields config={config} data={data} setField={setField} errors={{}} idPrefix="pv" />
        {showsSafety(config) && (
          <div className="space-y-3 border-t border-clinic-border pt-4">
            <p className="text-[12px] font-bold uppercase tracking-wide text-clinic-secondary">{scope.screen === 'new_case' ? 'Medical safety · folded on the popup' : 'Medical safety'}</p>
            <MedicalSafetyFields config={config} data={data} setField={setField} errors={{}} specialty={scope.module} area={null} generalOnly={props.modules.length > 1 && scope.module === ''} />
          </div>
        )}
        {moreFields(config).length > 0 && (
          <div className="space-y-3 border-t border-clinic-border pt-4">
            <p className="text-[12px] font-bold uppercase tracking-wide text-clinic-secondary">{scope.screen === 'new_case' ? 'More details · folded on the popup' : 'Contact & details'}</p>
            <MoreDetailsFields config={config} data={data} setField={setField} errors={{}} idPrefix="pv" />
          </div>
        )}
      </div>
    </section>
  );
}
