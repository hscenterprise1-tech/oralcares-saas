import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { LayoutTemplate, RotateCcw, Save, ShieldAlert } from 'lucide-react';
import { Button, Checkbox, ChoiceChips, Notice } from '@/clinic';
import { MultiChoiceChips } from '@/clinic/ui/ChoiceChips';
import { Switch } from '@/clinic/ui/controls';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Card } from '@/clinic/ui/Card';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { FieldEditor } from './patient-form/FieldEditor';
import { ListsEditor } from './patient-form/ListsEditor';
import { Preview } from './patient-form/Preview';
import type { FieldDefinition, PatientFormProps } from './patient-form/types';

/**
 * Settings → Patient form: what the clinic asks when a patient is
 * registered, on the Reception popup and the full page, per module and
 * role — with a live preview. Saves through the existing settings
 * endpoints (`SettingsController`), which validate and audit every change;
 * the forms and the server read the same settings, so nothing here is
 * advisory. Hiding a field never removes what patients already have.
 */
export default function PatientFormSettings(props: PatientFormProps) {
  return (
    <ClinicPortalShell title="Patient form">
      <Settings {...props} />
    </ClinicPortalShell>
  );
}

const ASK_STATES = [
  { value: 'required', label: 'Required' },
  { value: 'optional', label: 'Optional' },
  { value: 'hidden', label: 'Not asked' },
];

function Settings(props: PatientFormProps) {
  const [draft, setDraft] = useState<Record<string, FieldDefinition>>(props.fields);
  const [saving, setSaving] = useState(false);
  const [template, setTemplate] = useState<string | null>(null);
  const [replaceCustomised, setReplaceCustomised] = useState(false);

  // A save or a template brings the server's settings back: start from them.
  useEffect(() => setDraft(props.fields), [props.fields]);

  const changed = useMemo(() => Object.keys(draft).filter((field) => JSON.stringify(draft[field]) !== JSON.stringify(props.fields[field])), [draft, props.fields]);
  const dirty = changed.length > 0;

  // Leaving with unsaved changes asks first.
  useEffect(() => {
    if (!dirty) {
      return;
    }
    const removeBefore = router.on('before', (event) => {
      if (event.detail.visit.method === 'get' && !window.confirm('Leave without saving your patient form changes?')) {
        event.preventDefault();
      }
    });
    const onUnload = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener('beforeunload', onUnload);

    return () => {
      removeBefore();
      window.removeEventListener('beforeunload', onUnload);
    };
  }, [dirty]);

  const save = () => {
    router.put(props.links.fields, Object.fromEntries(changed.map((field) => [field, draft[field]])) as never, {
      preserveScroll: true,
      onStart: () => setSaving(true),
      onFinish: () => setSaving(false),
    });
  };

  const move = (field: string, direction: -1 | 1, group: string[]) => {
    const index = group.indexOf(field);
    const other = group[index + direction];
    if (!other) {
      return;
    }
    setDraft((current) => ({ ...current, [field]: { ...current[field], order: current[other].order }, [other]: { ...current[other], order: current[field].order } }));
  };

  const applyTemplate = (key: string) => {
    router.post(props.links.template, { template: key, replace_customised: replaceCustomised }, { preserveScroll: true, onStart: () => setTemplate(key), onFinish: () => setTemplate(null) });
  };

  const quickCare = (patch: Partial<PatientFormProps['quick_care']>) => router.put(props.links.quick_care, patch as never, { preserveScroll: true });

  return (
    <>
      <PageHeader
        eyebrow={<span className="text-[11px] font-bold uppercase tracking-[0.08em] text-cx-nav-teal-strong">Settings</span>}
        title="Patient form"
        description="What your clinic asks when a patient is registered — on the Reception popup and the full page — and for whom."
      />

      <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        <div className="min-w-0 space-y-5">
          <Card as="div" className="p-5 sm:p-6">
            <h2 className="flex items-center gap-1.5 text-[13px] font-bold uppercase tracking-wide text-clinic-secondary">
              <LayoutTemplate className="h-4 w-4" aria-hidden="true" />
              Start from a template
            </h2>
            <p className="mt-1 text-[12.5px] text-clinic-secondary">
              Sensible settings for what you practise. Nothing medical is made required.
              {props.customised.length > 0 ? ` ${props.customised.length} field${props.customised.length === 1 ? '' : 's'} you have set yourself ${props.customised.length === 1 ? 'is' : 'are'} kept unless you choose to replace them.` : ''}
            </p>
            <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
              {props.templates.map((option) => (
                <div key={option.key} className="flex flex-col justify-between gap-3 rounded-2xl border border-clinic-border p-3.5">
                  <div>
                    <p className="text-[13.5px] font-bold text-clinic-ink">{option.label}</p>
                    <p className="mt-0.5 text-[12px] text-clinic-secondary">{option.description}</p>
                  </div>
                  <Button size="sm" onClick={() => applyTemplate(option.key)} loading={template === option.key} loadingLabel="Applying…" disabled={template !== null || dirty}>
                    Apply
                  </Button>
                </div>
              ))}
            </div>
            <div className="mt-3 space-y-2">
              {props.customised.length > 0 && <Checkbox checked={replaceCustomised} onCheckedChange={setReplaceCustomised} label="Also replace the fields I have set myself" />}
              {dirty && <p className="text-[12px] text-clinic-secondary">Save or undo your changes below before applying a template.</p>}
            </div>
          </Card>

          <Card as="div" className="p-5 sm:p-6">
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 className="text-[13px] font-bold uppercase tracking-wide text-clinic-secondary">Fields</h2>
                <p className="mt-0.5 text-[12.5px] text-clinic-secondary">Required, optional or hidden; then where each shows and for whom.</p>
              </div>
              <div className="flex gap-2">
                <Button tone="ghost" icon={RotateCcw} onClick={() => setDraft(props.fields)} disabled={!dirty || saving}>
                  Undo
                </Button>
                <Button tone="primary" icon={Save} onClick={save} loading={saving} loadingLabel="Saving…" disabled={!dirty || saving}>
                  {dirty ? `Save ${changed.length} change${changed.length === 1 ? '' : 's'}` : 'Saved'}
                </Button>
              </div>
            </div>
            <FieldEditor props={props} draft={draft} onChange={(field, definition) => setDraft((current) => ({ ...current, [field]: definition }))} onMove={move} />
          </Card>

          <Card as="div" className="space-y-4 p-5 sm:p-6">
            <h2 className="flex items-center gap-1.5 text-[13px] font-bold uppercase tracking-wide text-clinic-secondary">
              <ShieldAlert className="h-4 w-4" aria-hidden="true" />
              Allergies and alerts
            </h2>
            <div>
              <p className="mb-1 text-[12px] font-semibold text-clinic-ink">Ask whether the patient has allergies</p>
              <p className="mb-1.5 text-[12px] text-clinic-secondary">Required means reception records allergies or ticks “No known allergies” — “not asked yet” stays distinct from “none”.</p>
              <ChoiceChips label="Ask about allergies" options={ASK_STATES} value={props.quick_care.allergy_status} onChange={(state) => quickCare({ allergy_status: state as PatientFormProps['quick_care']['allergy_status'] })} />
            </div>
            <Switch checked={props.quick_care.alerts} onCheckedChange={(alerts) => quickCare({ alerts })} label="Offer team alerts at registration" description="Fall risk, needs assistance and the like — for the team, never a diagnosis." />
            {props.quick_care.alerts && (
              <div>
                <p className="mb-1.5 text-[12px] font-semibold text-clinic-ink">Who may set team alerts</p>
                <MultiChoiceChips label="Who may set team alerts" options={props.roles.map((role) => ({ value: role, label: role }))} value={props.quick_care.alert_roles} onChange={(roles) => quickCare({ alert_roles: roles })} noneLabel="Everyone who registers" size="sm" />
              </div>
            )}
            <Switch
              checked={props.quick_care.conditions_from_catalogue}
              onCheckedChange={(conditionsFromCatalogue) => quickCare({ conditions_from_catalogue: conditionsFromCatalogue })}
              label="Record medical conditions only from the clinic's list"
              description="A condition typed by name is refused unless it is on the list; conditions already recorded stay. Add the clinic's own under Lists → Medical history."
            />
          </Card>

          <Card as="div" className="p-5 sm:p-6">
            <h2 className="mb-3 text-[13px] font-bold uppercase tracking-wide text-clinic-secondary">Lists</h2>
            <ListsEditor props={props} />
          </Card>
        </div>

        <div className="min-w-0">
          <Card as="div" className="p-5 sm:p-6 xl:sticky xl:top-4">
            {dirty && (
              <Notice tone="info" className="mb-4">
                The preview shows your unsaved changes.
              </Notice>
            )}
            <Preview props={props} draft={draft} allergyStatus={props.quick_care.allergy_status} />
          </Card>
        </div>
      </div>
    </>
  );
}
