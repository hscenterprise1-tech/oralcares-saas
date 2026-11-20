import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { FileSignature, Pencil, Plus } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select, Switch, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState } from '@/clinic/ui/states';
import { cn } from '@/lib/utils';
import { SettingsTabs, type SettingsLinks } from '../Settings/SettingsTabs';

interface ConsentType {
  id: number;
  key: string;
  label: string;
  specialty: string | null;
  version: number;
  text: string | null;
  active: boolean;
  given: number;
}

/** `ConsentTypeController::index`: the server is the contract. */
interface Props {
  types: ConsentType[];
  specialties: { key: string; label: string }[];
  settings_links: SettingsLinks;
  links: { base: string };
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';

export default function Consents(props: Props) {
  return (
    <ClinicPortalShell title="Consents">
      <ConsentsPage {...props} />
    </ClinicPortalShell>
  );
}

/** The consents patients are asked to give, and their wording. A changed wording is a new version; consents already given keep the wording they were given under. */
function ConsentsPage({ types, specialties, settings_links, links }: Props) {
  const [editing, setEditing] = useState<ConsentType | 'new' | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const toggle = (type: ConsentType, active: boolean) =>
    router.post(`${links.base}/${type.id}/active`, { active } as never, { preserveScroll: true, onStart: () => setBusyId(type.id), onFinish: () => setBusyId(null) });

  return (
    <div className="mx-auto w-full max-w-[1100px] pb-12">
      <SettingsTabs active="consents" links={settings_links} />
      <PageHeader
        icon={FileSignature}
        title="Consents"
        description="What patients agree to, in your own words."
        actions={<Button tone="primary" onClick={() => setEditing('new')}><Plus className="h-4 w-4" aria-hidden="true" /> New consent</Button>}
      />

      {types.length === 0 ? (
        <EmptyState title="No consents yet" description="Add the consents your clinic asks for, such as treatment, photographs or sharing records." action={<Button tone="primary" onClick={() => setEditing('new')}>New consent</Button>} />
      ) : (
        <ul className={cn(SURFACE, 'divide-y divide-cx-line/60')}>
          {types.map((type) => (
            <li key={type.id} className="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 py-4">
              <div className="min-w-0 flex-1 basis-64">
                <p className={cn('text-[14px] font-semibold', type.active ? 'text-cx-nav-ink' : 'text-cx-text-3')}>{type.label}</p>
                <p className="text-[12px] text-cx-text-3">
                  Version {type.version}
                  {type.specialty ? ` · ${type.specialty} only` : ''}
                  {` · ${type.given} given`}
                </p>
                {type.text && <p className="mt-1.5 line-clamp-2 text-[12.5px] text-cx-text-2">{type.text}</p>}
              </div>
              <div className="flex items-center gap-2">
                <Switch aria-label={`${type.label} switched ${type.active ? 'on' : 'off'}`} checked={type.active} disabled={busyId === type.id} onCheckedChange={(on) => toggle(type, on)} />
                <button type="button" aria-label={`Edit ${type.label}`} onClick={() => setEditing(type)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-slate-bg hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35">
                  <Pencil className="h-4 w-4" aria-hidden="true" />
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {editing && <ConsentDialog type={editing === 'new' ? null : editing} specialties={specialties} base={links.base} onClose={() => setEditing(null)} />}
    </div>
  );
}

function ConsentDialog({ type, specialties, base, onClose }: { type: ConsentType | null; specialties: Props['specialties']; base: string; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [label, setLabel] = useState(type?.label ?? '');
  const [text, setText] = useState(type?.text ?? '');
  const [specialty, setSpecialty] = useState('');
  const [busy, setBusy] = useState(false);
  const changed = type !== null && text.trim() !== (type.text ?? '').trim();

  const save = () => {
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose };
    if (type) router.put(`${base}/${type.id}`, { label: label.trim(), text: text.trim() }, options);
    else router.post(base, { label: label.trim(), text: text.trim(), specialty: specialty || null }, options);
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="lg"
      title={type ? `Edit ${type.label}` : 'New consent'}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button tone="primary" loading={busy} disabled={label.trim() === '' || text.trim() === ''} onClick={save}>{type ? 'Save changes' : 'Add consent'}</Button>
        </>
      }
    >
      <div className="space-y-4">
        {(errors.consent || errors.label || errors.text) && <Notice tone="danger">{errors.consent ?? errors.label ?? errors.text}</Notice>}
        {changed && <Notice tone="info">Changing the wording saves it as version {type.version + 1}. Consents already given keep version {type.version}.</Notice>}
        <Field label="Name" htmlFor="cs-label" required><Input id="cs-label" value={label} maxLength={120} onChange={(e) => setLabel(e.target.value)} placeholder="e.g. Consent for treatment" /></Field>
        {!type && specialties.length > 1 && (
          <Field label="Asked for" htmlFor="cs-spec">
            <Select id="cs-spec" value={specialty} onChange={(e) => setSpecialty(e.target.value)} options={[{ value: '', label: 'Every patient' }, ...specialties.map((s) => ({ value: s.key, label: `${s.label} patients only` }))]} />
          </Field>
        )}
        <Field label="What the patient agrees to" htmlFor="cs-text" required hint="Written as the patient will read it.">
          <Textarea id="cs-text" value={text} rows={10} maxLength={20000} onChange={(e) => setText(e.target.value)} className="min-h-[220px]" />
        </Field>
      </div>
    </Modal>
  );
}
