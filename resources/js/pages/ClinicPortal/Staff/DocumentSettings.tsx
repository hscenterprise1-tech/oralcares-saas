import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ClipboardCheck, Plus } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Input, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';

interface DocType {
  id: number;
  key: string;
  name: string;
  audience: string;
  has_expiry: boolean;
  is_active: boolean;
  requirements: Record<string, string>;
}

/** `StaffDocumentController::settings`: the server is the contract. */
interface Props {
  types: DocType[];
  staffTypes: { value: string; label: string; clinical: boolean }[];
  levels: string[];
  audiences: string[];
  links: { back: string; update: string; types: string };
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';

export default function DocumentSettings(props: Props) {
  return (
    <ClinicPortalShell title="Document checklist">
      <ChecklistPage {...props} />
    </ClinicPortalShell>
  );
}

/** Which documents each kind of staff must, may or need not give (licence, ID, agreement…). Shown on every profile's Documents tab. */
function ChecklistPage({ types, staffTypes, levels, audiences, links }: Props) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [rows, setRows] = useState<DocType[]>(types);
  const [saving, setSaving] = useState(false);
  const [adding, setAdding] = useState(false);
  const dirty = JSON.stringify(rows) !== JSON.stringify(types);

  const patch = (id: number, change: Partial<DocType>) => setRows((current) => current.map((row) => (row.id === id ? { ...row, ...change } : row)));
  const level = (row: DocType, staffType: string) => row.requirements[staffType] ?? 'optional';
  const save = () =>
    router.put(
      links.update,
      { types: rows.map((row) => ({ id: row.id, is_active: row.is_active, has_expiry: row.has_expiry, requirements: Object.fromEntries(staffTypes.map((t) => [t.value, level(row, t.value)])) })) } as never,
      { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
    );

  return (
    <div className="w-full pb-12">
      <PageHeader
        icon={ClipboardCheck}
        title="Document checklist"
        description="What each kind of staff must provide. Required documents show as missing on a profile until they are uploaded and verified."
        actions={
          <>
            <Link href={links.back} className="inline-flex h-10 items-center gap-1.5 rounded-xl px-3 text-[13.5px] font-semibold text-cx-text-2 hover:text-cx-nav-ink">
              <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Staff
            </Link>
            <Button onClick={() => setAdding(true)}><Plus className="h-4 w-4" aria-hidden="true" /> New document type</Button>
            <Button tone="primary" onClick={save} loading={saving} disabled={!dirty}>Save checklist</Button>
          </>
        }
      />
      {Object.keys(errors ?? {}).length > 0 && <Notice tone="danger" className="mb-4">Some rows could not be saved. Check them and try again.</Notice>}

      <div className={cn(SURFACE, 'overflow-x-auto')}>
        <table className="w-full min-w-[760px] text-left text-[13px]">
          <caption className="sr-only">Document requirements by kind of staff</caption>
          <thead>
            <tr className="border-b border-cx-line/70 text-[11.5px] font-semibold uppercase tracking-[0.05em] text-cx-text-3">
              <th scope="col" className="sticky left-0 bg-white px-5 py-3">Document</th>
              {staffTypes.map((t) => <th key={t.value} scope="col" className="px-3 py-3">{t.label}</th>)}
            </tr>
          </thead>
          <tbody className="divide-y divide-cx-line/60">
            {rows.map((row) => (
              <tr key={row.id} className={cn(!row.is_active && 'opacity-60')}>
                <th scope="row" className="sticky left-0 min-w-[220px] bg-white px-5 py-3 align-top font-normal">
                  <p className="font-semibold text-cx-nav-ink">{row.name}</p>
                  <p className="mb-2 text-[12px] text-cx-text-3">For {humanize(row.audience)}</p>
                  <div className="flex flex-col gap-1.5">
                    <Checkbox checked={row.is_active} onCheckedChange={(on) => patch(row.id, { is_active: on })} label="In use" />
                    <Checkbox checked={row.has_expiry} onCheckedChange={(on) => patch(row.id, { has_expiry: on })} label="Has an expiry date" />
                  </div>
                </th>
                {staffTypes.map((t) => (
                  <td key={t.value} className="px-3 py-3 align-top">
                    <Select
                      aria-label={`${row.name} for ${t.label}`}
                      value={level(row, t.value)}
                      onChange={(event) => patch(row.id, { requirements: { ...row.requirements, [t.value]: event.target.value } })}
                      options={levels.map((l) => ({ value: l, label: humanize(l) }))}
                      className="h-9 min-w-[120px] text-[12.5px]"
                    />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {adding && <NewTypeDialog audiences={audiences} url={links.types} onClose={() => setAdding(false)} />}
    </div>
  );
}

function NewTypeDialog({ audiences, url, onClose }: { audiences: string[]; url: string; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [name, setName] = useState('');
  const [audience, setAudience] = useState(audiences[0] ?? 'all');
  const [hasExpiry, setHasExpiry] = useState(false);
  const [busy, setBusy] = useState(false);

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="sm"
      title="New document type"
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button
            tone="primary"
            loading={busy}
            disabled={name.trim() === ''}
            onClick={() => router.post(url, { name: name.trim(), audience, has_expiry: hasExpiry } as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose })}
          >
            Add
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Name" htmlFor="dt-name" required error={errors.name}><Input id="dt-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={120} placeholder="e.g. Indemnity insurance" /></Field>
        <Field label="Who it is for" htmlFor="dt-aud">
          <Select id="dt-aud" value={audience} onChange={(e) => setAudience(e.target.value)} options={audiences.map((a) => ({ value: a, label: humanize(a) }))} />
        </Field>
        <Checkbox checked={hasExpiry} onCheckedChange={setHasExpiry} label="It has an expiry date" />
      </div>
    </Modal>
  );
}
