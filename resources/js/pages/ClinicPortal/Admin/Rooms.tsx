import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { DoorOpen, Pencil, Plus, Trash2 } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select, Switch } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState } from '@/clinic/ui/states';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { InfraTabs, keyOf, type InfraLinks } from './components/InfraTabs';

interface Room {
  id: number;
  branch_id: number;
  branch_name: string | null;
  name: string;
  key: string;
  type: string;
  is_active: boolean;
}

/** `RoomController::index`: the server is the contract. */
interface Props {
  rooms: Room[];
  branches: { id: number; name: string }[];
  links: InfraLinks;
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';
const TYPES: [string, string][] = [
  ['chair', 'Dental chair'],
  ['consultation', 'Consultation room'],
  ['treatment_bed', 'Treatment bed'],
  ['procedure', 'Procedure room'],
  ['xray', 'X-ray room'],
  ['other', 'Other'],
];

export default function Rooms(props: Props) {
  return (
    <ClinicPortalShell title="Rooms & chairs">
      <RoomsPage {...props} />
    </ClinicPortalShell>
  );
}

/** The chairs, beds and rooms at each branch. Appointments are given a free one automatically. */
function RoomsPage({ rooms, branches, links }: Props) {
  const [editing, setEditing] = useState<Room | 'new' | null>(null);
  const [removing, setRemoving] = useState<Room | null>(null);
  const [branch, setBranch] = useState('');
  const [busy, setBusy] = useState(false);
  const shown = useMemo(() => rooms.filter((room) => branch === '' || String(room.branch_id) === branch), [rooms, branch]);
  const typeLabel = (type: string) => TYPES.find(([key]) => key === type)?.[1] ?? humanize(type);

  return (
    <div className="w-full pb-12">
      <PageHeader
        icon={DoorOpen}
        title="Rooms & chairs"
        description={`${rooms.filter((r) => r.is_active).length} in use across ${branches.length} ${branches.length === 1 ? 'branch' : 'branches'}`}
        actions={<Button tone="primary" onClick={() => setEditing('new')} disabled={branches.length === 0}><Plus className="h-4 w-4" aria-hidden="true" /> New room or chair</Button>}
      />
      <InfraTabs active="rooms" links={links} />

      {branches.length > 1 && (
        <div className="mb-4 w-full sm:w-64">
          <Select aria-label="Branch" value={branch} onChange={(e) => setBranch(e.target.value)} options={[{ value: '', label: 'All branches' }, ...branches.map((b) => ({ value: String(b.id), label: b.name }))]} />
        </div>
      )}

      {shown.length === 0 ? (
        <EmptyState title="No rooms or chairs yet" description="Add the chairs and rooms you treat patients in, so appointments can be given one." action={<Button tone="primary" onClick={() => setEditing('new')}>New room or chair</Button>} />
      ) : (
        <ul className={cn(SURFACE, 'divide-y divide-cx-line/60')}>
          {shown.map((room) => (
            <li key={room.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
              <div className="min-w-0 flex-1 basis-48">
                <p className={cn('truncate text-[14px] font-semibold', room.is_active ? 'text-cx-nav-ink' : 'text-cx-text-3')}>{room.name}</p>
                <p className="text-[12px] text-cx-text-3">{typeLabel(room.type)}{branches.length > 1 && room.branch_name ? ` · ${room.branch_name}` : ''}{room.is_active ? '' : ' · switched off'}</p>
              </div>
              <div className="flex items-center gap-1">
                <button type="button" aria-label={`Edit ${room.name}`} onClick={() => setEditing(room)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-slate-bg hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35">
                  <Pencil className="h-4 w-4" aria-hidden="true" />
                </button>
                <button type="button" aria-label={`Delete ${room.name}`} onClick={() => setRemoving(room)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35">
                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {editing && <RoomDialog room={editing === 'new' ? null : editing} branches={branches} base={links.rooms} defaultBranch={branch} onClose={() => setEditing(null)} />}
      {removing && (
        <Modal
          open
          onOpenChange={(open) => !open && setRemoving(null)}
          size="sm"
          title={`Delete ${removing.name}?`}
          description="Switch it off instead if you only want to stop using it for now."
          footer={
            <>
              <Button onClick={() => setRemoving(null)} disabled={busy}>Cancel</Button>
              <Button tone="danger" loading={busy} onClick={() => router.delete(`${links.rooms}/${removing.id}`, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setRemoving(null); } })}>Delete</Button>
            </>
          }
        >
          <p className="text-[13px] text-cx-text-2">This cannot be undone.</p>
        </Modal>
      )}
    </div>
  );
}

function RoomDialog({ room, branches, base, defaultBranch, onClose }: { room: Room | null; branches: Props['branches']; base: string; defaultBranch: string; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [name, setName] = useState(room?.name ?? '');
  const [key, setKey] = useState(room?.key ?? '');
  const [type, setType] = useState(room?.type ?? 'chair');
  const [branchId, setBranchId] = useState(String(room?.branch_id ?? (defaultBranch || branches[0]?.id || '')));
  const [active, setActive] = useState(room?.is_active ?? true);
  const [busy, setBusy] = useState(false);
  const types = TYPES.some(([k]) => k === type) ? TYPES : [...TYPES, [type, humanize(type)] as [string, string]];

  const submit = () => {
    const data = { branch_id: Number(branchId), name: name.trim(), key: key.trim() || keyOf(name), type, is_active: active };
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose };
    if (room) router.put(`${base}/${room.id}`, data, options);
    else router.post(base, data, options);
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="sm"
      title={room ? 'Edit room or chair' : 'New room or chair'}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button tone="primary" loading={busy} disabled={name.trim() === '' || branchId === ''} onClick={submit}>{room ? 'Save changes' : 'Add'}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Name" htmlFor="rm-name" required error={errors.name}><Input id="rm-name" value={name} maxLength={255} onChange={(e) => setName(e.target.value)} placeholder="e.g. Chair 1" /></Field>
        <Field label="Kind" htmlFor="rm-type" error={errors.type}><Select id="rm-type" value={type} onChange={(e) => setType(e.target.value)} options={types.map(([value, label]) => ({ value, label }))} /></Field>
        {branches.length > 1 && (
          <Field label="Branch" htmlFor="rm-branch" error={errors.branch_id}><Select id="rm-branch" value={branchId} onChange={(e) => setBranchId(e.target.value)} options={branches.map((b) => ({ value: String(b.id), label: b.name }))} /></Field>
        )}
        <Field label="Short key" htmlFor="rm-key" error={errors.key} hint="Unique at the branch. Leave empty to make one from the name."><Input id="rm-key" value={key} maxLength={50} onChange={(e) => setKey(e.target.value)} /></Field>
        <Switch checked={active} onCheckedChange={setActive} label="In use" />
      </div>
    </Modal>
  );
}
