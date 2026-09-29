import React, { useMemo, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { DoorOpen, Pencil, Plus, Trash2 } from 'lucide-react';
import { CrmButton, CrmPageHead, CrmPageStat, CrmTable, useCrmConfirm } from '@/pages/Company/design';
import type { CrmColumn } from '@/pages/Company/design';
import { CrmModal } from '@/pages/Company/design/CrmOverlay';
import { Input, Switch } from '@/pages/Company/design/CrmFormPrimitives';
import { Badge } from '@/pages/Company/design/CrmBadgePrimitives';
import { useClinicPermission } from '@/hooks/useClinicPermission';
import { ClinicShell } from '../../components/ClinicShell';
import { InfrastructureTabs } from './components/InfrastructureTabs';
import { Room, RoomsPageProps } from './types';

/*
 * Rooms belong to one branch: consultation rooms, procedure rooms, chairs,
 * wards. Every room has a type; it is free text, and the suggestions are the ones provisioning
 * already creates, so new rooms match existing ones.
 */

const ROOM_TYPE_SUGGESTIONS = ['consultation', 'procedure', 'operatory', 'xray', 'lab', 'waiting', 'emergency', 'ward'];

const inputClass =
  'w-full rounded-[16px] border border-ovs-crm-border bg-white px-3 py-2 text-[13px] text-ovs-crm-text-primary';

function toShortCode(value: string): string {
  return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 50);
}

function humanise(value: string | null): string {
  if (!value) return '—';

  return value.charAt(0).toUpperCase() + value.slice(1).replace(/[_-]/g, ' ');
}

function RoomFormModal({ room, branches, open, onClose }: { room: Room | null; branches: RoomsPageProps['branches']; open: boolean; onClose: () => void }) {
  const initial = () => ({
    branch_id: room?.branch_id ?? branches[0]?.id ?? '',
    name: room?.name ?? '',
    key: room?.key ?? '',
    type: room?.type ?? '',
    is_active: room?.is_active ?? true,
  });
  const [codeTouched, setCodeTouched] = useState(Boolean(room));
  const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm(initial());

  React.useEffect(() => {
    if (open) {
      clearErrors();
      setCodeTouched(Boolean(room));
      setData(initial());
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, room]);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
    if (room) {
      put(route('admin.rooms.update', room.id), options);
    } else {
      post(route('admin.rooms.store'), options);
    }
  };

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title={room ? 'Edit room' : 'New room'}
      description="A room belongs to one branch."
      footer={
        <>
          <CrmButton tone="ghost" onClick={onClose}>Cancel</CrmButton>
          <CrmButton tone="primary" type="submit" form="room-form" loading={processing} disabled={!data.name.trim() || !data.key.trim() || !data.type.trim() || !data.branch_id}>
            {room ? 'Save changes' : 'Create room'}
          </CrmButton>
        </>
      }
    >
      <form id="room-form" onSubmit={submit} className="space-y-4">
        <div className="space-y-1.5">
          <label htmlFor="room-branch" className="block text-2xs text-ovs-crm-text-muted">
            Branch <span className="text-ovs-crm-danger">*</span>
          </label>
          <select id="room-branch" value={data.branch_id} onChange={(e) => setData('branch_id', Number(e.target.value))} className={inputClass}>
            {branches.map((branch) => (
              <option key={branch.id} value={branch.id}>{branch.name}</option>
            ))}
          </select>
          {errors.branch_id && <p className="text-2xs text-ovs-crm-danger">{errors.branch_id}</p>}
        </div>
        <div className="space-y-1.5">
          <label htmlFor="room-name" className="block text-2xs text-ovs-crm-text-muted">
            Name <span className="text-ovs-crm-danger">*</span>
          </label>
          <Input id="room-name" value={data.name} onChange={(e) => setData((current) => ({ ...current, name: e.target.value, key: codeTouched ? current.key : toShortCode(e.target.value) }))} placeholder="e.g. Consultation Room 2" />
          {errors.name && <p className="text-2xs text-ovs-crm-danger">{errors.name}</p>}
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <label htmlFor="room-type" className="block text-2xs text-ovs-crm-text-muted">
              Type <span className="text-ovs-crm-danger">*</span>
            </label>
            <Input id="room-type" list="room-type-suggestions" value={data.type} onChange={(e) => setData('type', e.target.value)} placeholder="e.g. consultation" />
            <datalist id="room-type-suggestions">
              {ROOM_TYPE_SUGGESTIONS.map((type) => <option key={type} value={type} />)}
            </datalist>
            {errors.type && <p className="text-2xs text-ovs-crm-danger">{errors.type}</p>}
          </div>
          <div className="space-y-1.5">
            <label htmlFor="room-key" className="block text-2xs text-ovs-crm-text-muted">
              Short code <span className="text-ovs-crm-danger">*</span>
            </label>
            <Input id="room-key" value={data.key} onChange={(e) => { setCodeTouched(true); setData('key', e.target.value); }} placeholder="e.g. consult-2" />
            {errors.key && <p className="text-2xs text-ovs-crm-danger">{errors.key}</p>}
          </div>
        </div>
        <label className="flex items-center justify-between gap-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset px-3 py-2.5">
          <span className="text-[13px] text-ovs-crm-text-primary">Active</span>
          <Switch checked={data.is_active} onCheckedChange={(value) => setData('is_active', value)} />
        </label>
      </form>
    </CrmModal>
  );
}

export default function Rooms({ rooms, branches }: RoomsPageProps) {
  const hasPermission = useClinicPermission();
  const canManage = hasPermission('branch.manage');
  const [editing, setEditing] = useState<Room | null>(null);
  const [open, setOpen] = useState(false);
  const [branchFilter, setBranchFilter] = useState<number | 'all'>('all');
  const { confirm, dialog } = useCrmConfirm();

  const visible = useMemo(
    () => (branchFilter === 'all' ? rooms : rooms.filter((room) => room.branch_id === branchFilter)),
    [rooms, branchFilter],
  );

  const openForm = (room: Room | null) => {
    setEditing(room);
    setOpen(true);
  };

  const remove = (room: Room) =>
    confirm({
      title: `Remove ${room.name}?`,
      description: 'Appointments already booked in this room keep their records.',
      confirmLabel: 'Remove room',
      destructive: true,
      onConfirm: () => router.delete(route('admin.rooms.destroy', room.id), { preserveScroll: true }),
    });

  const columns: CrmColumn<Room>[] = [
    { key: 'name', header: 'Room', primary: true, cell: (row) => <span className="font-semibold text-ovs-crm-text-primary">{row.name}</span> },
    { key: 'branch', header: 'Branch', cell: (row) => <span className="text-ovs-crm-text-secondary">{row.branch_name ?? '—'}</span> },
    { key: 'type', header: 'Type', hideOnMobile: true, cell: (row) => <span className="text-ovs-crm-text-secondary">{humanise(row.type)}</span> },
    {
      key: 'status',
      header: 'Status',
      cell: (row) => <Badge variant={row.is_active ? 'success' : 'neutral'} size="compact">{row.is_active ? 'Active' : 'Inactive'}</Badge>,
    },
  ];

  return (
    <ClinicShell title="Rooms">
      <CrmPageHead
        eyebrow="Admin"
        title="Rooms"
        description="Consultation rooms, procedure rooms and other spaces at each branch."
        stats={<CrmPageStat icon={DoorOpen} value={rooms.filter((r) => r.is_active).length} caption="Active rooms" />}
        actions={
          canManage && branches.length > 0 ? (
            <CrmButton tone="primary" icon={Plus} onClick={() => openForm(null)}>New Room</CrmButton>
          ) : undefined
        }
      />

      <InfrastructureTabs current="Rooms" />

      <div className="space-y-4 pb-12">
        {branches.length > 1 && (
          <div className="flex flex-wrap items-center gap-2">
            <label htmlFor="room-branch-filter" className="text-[12px] font-semibold text-ovs-crm-text-secondary">Branch</label>
            <select
              id="room-branch-filter"
              value={branchFilter}
              onChange={(e) => setBranchFilter(e.target.value === 'all' ? 'all' : Number(e.target.value))}
              className="rounded-full border border-ovs-crm-border bg-white px-3 py-1.5 text-[12.5px] text-ovs-crm-text-primary"
            >
              <option value="all">All branches</option>
              {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
            </select>
          </div>
        )}

        <CrmTable
          columns={columns}
          rows={visible}
          rowKey={(row) => row.id}
          isFiltered={branchFilter !== 'all'}
          empty={{
            icon: DoorOpen,
            title: branches.length === 0 ? 'Add a branch first' : 'No rooms yet',
            description: branches.length === 0 ? 'Rooms belong to a branch.' : 'Add the rooms patients are seen in.',
          }}
          emptyFiltered={{ title: 'No rooms at this branch' }}
          rowActions={canManage ? (row) => (
            <div className="flex items-center gap-1">
              <CrmButton size="sm" tone="ghost" icon={Pencil} aria-label={`Edit ${row.name}`} onClick={() => openForm(row)} />
              <CrmButton size="sm" tone="ghost" icon={Trash2} aria-label={`Remove ${row.name}`} onClick={() => remove(row)} />
            </div>
          ) : undefined}
        />
      </div>

      <RoomFormModal room={editing} branches={branches} open={open} onClose={() => setOpen(false)} />
      {dialog}
    </ClinicShell>
  );
}
