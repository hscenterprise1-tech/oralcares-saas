import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Network, Pencil, Plus, Trash2 } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Switch } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState } from '@/clinic/ui/states';
import { cn } from '@/lib/utils';
import { InfraTabs, keyOf, type InfraLinks } from './components/InfraTabs';

interface Department {
  id: number;
  name: string;
  key: string;
  is_active: boolean;
}

/** `DepartmentController::index`: the server is the contract. */
interface Props {
  departments: Department[];
  links: InfraLinks;
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';

export default function Departments(props: Props) {
  return (
    <ClinicPortalShell title="Departments">
      <DepartmentsPage {...props} />
    </ClinicPortalShell>
  );
}

/** The clinic's departments (Dental, Orthodontics, Radiology…): staff are placed in them. */
function DepartmentsPage({ departments, links }: Props) {
  const [editing, setEditing] = useState<Department | 'new' | null>(null);
  const [removing, setRemoving] = useState<Department | null>(null);
  const [busy, setBusy] = useState(false);

  return (
    <div className="w-full pb-12">
      <PageHeader
        icon={Network}
        title="Departments"
        description="Group your staff by department."
        actions={<Button tone="primary" onClick={() => setEditing('new')}><Plus className="h-4 w-4" aria-hidden="true" /> New department</Button>}
      />
      <InfraTabs active="departments" links={links} />

      {departments.length === 0 ? (
        <EmptyState title="No departments yet" description="Departments are optional. Add one when you want to group staff." action={<Button tone="primary" onClick={() => setEditing('new')}>New department</Button>} />
      ) : (
        <ul className={cn(SURFACE, 'divide-y divide-cx-line/60')}>
          {departments.map((department) => (
            <li key={department.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
              <div className="min-w-0 flex-1 basis-48">
                <p className={cn('truncate text-[14px] font-semibold', department.is_active ? 'text-cx-nav-ink' : 'text-cx-text-3')}>{department.name}</p>
                <p className="text-[12px] text-cx-text-3">{department.key}{department.is_active ? '' : ' · switched off'}</p>
              </div>
              <div className="flex items-center gap-1">
                <button type="button" aria-label={`Edit ${department.name}`} onClick={() => setEditing(department)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-slate-bg hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35">
                  <Pencil className="h-4 w-4" aria-hidden="true" />
                </button>
                <button type="button" aria-label={`Delete ${department.name}`} onClick={() => setRemoving(department)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35">
                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {editing && <DepartmentDialog department={editing === 'new' ? null : editing} base={links.departments} onClose={() => setEditing(null)} />}
      {removing && (
        <Modal
          open
          onOpenChange={(open) => !open && setRemoving(null)}
          size="sm"
          title={`Delete ${removing.name}?`}
          description="Staff in this department stay on file, without a department."
          footer={
            <>
              <Button onClick={() => setRemoving(null)} disabled={busy}>Cancel</Button>
              <Button tone="danger" loading={busy} onClick={() => router.delete(`${links.departments}/${removing.id}`, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setRemoving(null); } })}>Delete</Button>
            </>
          }
        >
          <p className="text-[13px] text-cx-text-2">This cannot be undone.</p>
        </Modal>
      )}
    </div>
  );
}

function DepartmentDialog({ department, base, onClose }: { department: Department | null; base: string; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [name, setName] = useState(department?.name ?? '');
  const [key, setKey] = useState(department?.key ?? '');
  const [active, setActive] = useState(department?.is_active ?? true);
  const [busy, setBusy] = useState(false);
  const submit = () => {
    const data = { name: name.trim(), key: key.trim() || keyOf(name), is_active: active };
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose };
    if (department) router.put(`${base}/${department.id}`, data, options);
    else router.post(base, data, options);
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="sm"
      title={department ? 'Edit department' : 'New department'}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button tone="primary" loading={busy} disabled={name.trim() === ''} onClick={submit}>{department ? 'Save changes' : 'Add department'}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Name" htmlFor="dp-name" required error={errors.name}><Input id="dp-name" value={name} maxLength={255} onChange={(e) => setName(e.target.value)} placeholder="e.g. Orthodontics" /></Field>
        <Field label="Short key" htmlFor="dp-key" error={errors.key} hint="Used inside the system. Leave empty to make one from the name."><Input id="dp-key" value={key} maxLength={50} onChange={(e) => setKey(e.target.value)} /></Field>
        <Switch checked={active} onCheckedChange={setActive} label="In use" />
      </div>
    </Modal>
  );
}
