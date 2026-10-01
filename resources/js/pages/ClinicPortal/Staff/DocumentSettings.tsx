import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PageHeader } from '@/clinic/page/PageHeader';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Field, inputClass, Modal, selectClass } from '@/clinic/ui/Modal';
import type { StaffTypeOption } from './lib/types';

interface TypeRow {
  id: number;
  key: string;
  name: string;
  audience: string;
  has_expiry: boolean;
  is_active: boolean;
  requirements: Record<string, string>;
}

const LEVEL = {
  required: { label: 'Required', chip: 'bg-rose-50 text-rose-700 ring-rose-200' },
  optional: { label: 'Optional', chip: 'bg-sky-50 text-sky-700 ring-sky-200' },
  not_applicable: { label: 'Not needed', chip: 'bg-slate-100 text-slate-500 ring-slate-200' },
} as const;

const NEXT: Record<string, keyof typeof LEVEL> = { required: 'optional', optional: 'not_applicable', not_applicable: 'required' };

/**
 * Which documents each staff type must provide. Stored on the server and
 * checked there: a staff member's file shows what is required from this.
 */
export default function DocumentSettings({
  types,
  staffTypes,
  audiences,
  links,
}: {
  types: TypeRow[];
  staffTypes: StaffTypeOption[];
  levels: string[];
  audiences: string[];
  links: { back: string; update: string; types: string };
}) {
  const [rows, setRows] = useState(types);
  const [saving, setSaving] = useState(false);
  const [adding, setAdding] = useState(false);

  const cycle = (typeId: number, staffType: string) =>
    setRows((current) => current.map((row) => (row.id === typeId ? { ...row, requirements: { ...row.requirements, [staffType]: NEXT[row.requirements[staffType] ?? 'optional'] } } : row)));

  const save = () => {
    setSaving(true);
    router.put(links.update, { types: rows.map((row) => ({ id: row.id, is_active: row.is_active, has_expiry: row.has_expiry, requirements: Object.fromEntries(staffTypes.map((type) => [type.value, row.requirements[type.value] ?? 'optional'])) })) }, { preserveScroll: true, onFinish: () => setSaving(false) });
  };

  return (
    <ClinicPortalShell title="Document checklist">
      <Link href={links.back} className="mb-3 inline-flex min-h-11 items-center gap-1.5 rounded-full text-[12.5px] font-semibold text-clinic-secondary hover:text-clinic-ink">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Staff
      </Link>
      <PageHeader
        eyebrow="Staff"
        title="Document checklist"
        description="Tap a cell to switch between Required, Optional and Not needed for that staff type."
        actions={
          <>
            <Button icon={Plus} onClick={() => setAdding(true)}>Add document type</Button>
            <Button tone="primary" loading={saving} loadingLabel="Saving…" onClick={save}>Save checklist</Button>
          </>
        }
      />

      <section aria-label="Document checklist" className="overflow-x-auto rounded-3xl border border-white/70 bg-white/[0.93] backdrop-blur-sm">
        <table className="w-full min-w-[920px] text-left">
          <thead>
            <tr className="border-b border-clinic-border text-[11px] font-bold uppercase tracking-wide text-clinic-secondary">
              <th className="sticky left-0 z-10 bg-white px-4 py-3">Document</th>
              <th className="px-2 py-3">In use</th>
              <th className="px-2 py-3">Expires</th>
              {staffTypes.map((type) => (
                <th key={type.value} className="px-1.5 py-3 text-center">{type.label}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-clinic-border">
            {rows.map((row) => (
              <tr key={row.id} className={cn(!row.is_active && 'opacity-60')}>
                <td className="sticky left-0 z-10 bg-white px-4 py-2.5">
                  <span className="block text-[13px] font-bold text-clinic-ink">{row.name}</span>
                  <span className="block text-[11px] text-clinic-secondary">{row.audience === 'practitioner' ? 'Practitioners' : row.audience === 'staff' ? 'Non-clinical staff' : 'Everyone'}</span>
                </td>
                <td className="px-2 py-2.5">
                  <input type="checkbox" aria-label={`${row.name} in use`} checked={row.is_active} onChange={(e) => setRows((current) => current.map((item) => (item.id === row.id ? { ...item, is_active: e.target.checked } : item)))} className="h-5 w-5 rounded border-clinic-border-strong text-clinic-brand-strong" />
                </td>
                <td className="px-2 py-2.5">
                  <input type="checkbox" aria-label={`${row.name} has an expiry date`} checked={row.has_expiry} onChange={(e) => setRows((current) => current.map((item) => (item.id === row.id ? { ...item, has_expiry: e.target.checked } : item)))} className="h-5 w-5 rounded border-clinic-border-strong text-clinic-brand-strong" />
                </td>
                {staffTypes.map((type) => {
                  const level = (row.requirements[type.value] ?? 'optional') as keyof typeof LEVEL;

                  return (
                    <td key={type.value} className="px-1.5 py-2 text-center">
                      <button
                        type="button"
                        onClick={() => cycle(row.id, type.value)}
                        aria-label={`${row.name} for ${type.label}: ${LEVEL[level].label}. Change`}
                        className={cn('min-h-11 w-full min-w-[84px] rounded-full px-2 text-[11px] font-bold ring-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:min-h-8', LEVEL[level].chip)}
                      >
                        {LEVEL[level].label}
                      </button>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      {adding && <AddTypeDialog url={links.types} audiences={audiences} onClose={() => setAdding(false)} />}
    </ClinicPortalShell>
  );
}

function AddTypeDialog({ url, audiences, onClose }: { url: string; audiences: string[]; onClose: () => void }) {
  const [form, setForm] = useState({ name: '', audience: 'all', has_expiry: false });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const submit = () => {
    setSaving(true);
    router.post(url, form, { preserveScroll: true, onSuccess: onClose, onError: (received) => setErrors(received as Record<string, string>), onFinish: () => setSaving(false) });
  };

  return (
    <Modal
      open
      size="sm"
      onOpenChange={(open) => !open && onClose()}
      title="Add document type"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>Cancel</Button>
          <Button tone="primary" loading={saving} loadingLabel="Adding…" onClick={submit}>Add</Button>
        </>
      }
    >
      <div className="grid gap-4">
        <Field label="Name" htmlFor="dt-name" error={errors.name}>
          <input id="dt-name" className={inputClass} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field label="Who it is for" htmlFor="dt-audience" error={errors.audience}>
          <select id="dt-audience" className={selectClass} value={form.audience} onChange={(e) => setForm({ ...form, audience: e.target.value })}>
            {audiences.map((audience) => (
              <option key={audience} value={audience}>{audience === 'practitioner' ? 'Practitioners' : audience === 'staff' ? 'Non-clinical staff' : 'Everyone'}</option>
            ))}
          </select>
        </Field>
        <label className="flex min-h-11 cursor-pointer items-center gap-2.5 text-[13px] text-clinic-ink">
          <input type="checkbox" checked={form.has_expiry} onChange={(e) => setForm({ ...form, has_expiry: e.target.checked })} className="h-4 w-4 rounded border-clinic-border-strong" />
          This document has an expiry date
        </label>
      </div>
    </Modal>
  );
}
