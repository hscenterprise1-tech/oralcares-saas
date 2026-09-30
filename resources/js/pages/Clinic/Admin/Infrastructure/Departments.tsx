import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Layers, Pencil, Plus, Trash2 } from 'lucide-react';
import { CrmButton, CrmPageHead, CrmPageStat, CrmTable, useCrmConfirm } from '@/pages/Company/design';
import type { CrmColumn } from '@/pages/Company/design';
import { CrmModal } from '@/pages/Company/design/CrmOverlay';
import { Input, Switch } from '@/pages/Company/design/CrmFormPrimitives';
import { Badge } from '@/pages/Company/design/CrmBadgePrimitives';
import { useClinicPermission } from '@/hooks/useClinicPermission';
import { ClinicShell } from '../../components/ClinicShell';
import { InfrastructureTabs } from './components/InfrastructureTabs';
import { Department, DepartmentsPageProps } from './types';

/*
 * Departments a clinic routes patients through (Reception, Consultation,
 * Radiology…). Clinic-wide: every branch shares the same list.
 */

function toShortCode(value: string): string {
  return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 50);
}

function DepartmentFormModal({ department, open, onClose }: { department: Department | null; open: boolean; onClose: () => void }) {
  const [codeTouched, setCodeTouched] = useState(Boolean(department));
  const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
    name: department?.name ?? '',
    key: department?.key ?? '',
    is_active: department?.is_active ?? true,
  });

  React.useEffect(() => {
    if (open) {
      clearErrors();
      setCodeTouched(Boolean(department));
      setData({ name: department?.name ?? '', key: department?.key ?? '', is_active: department?.is_active ?? true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, department]);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
    if (department) {
      put(route('admin.departments.update', department.id), options);
    } else {
      post(route('admin.departments.store'), options);
    }
  };

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title={department ? 'Edit department' : 'New department'}
      description="Departments are shared by every branch of the clinic."
      footer={
        <>
          <CrmButton tone="ghost" onClick={onClose}>Cancel</CrmButton>
          <CrmButton tone="primary" type="submit" form="department-form" loading={processing} disabled={!data.name.trim() || !data.key.trim()}>
            {department ? 'Save changes' : 'Create department'}
          </CrmButton>
        </>
      }
    >
      <form id="department-form" onSubmit={submit} className="space-y-4">
        <div className="space-y-1.5">
          <label htmlFor="department-name" className="block text-2xs text-ovs-crm-text-muted">
            Name <span className="text-ovs-crm-danger">*</span>
          </label>
          <Input id="department-name" value={data.name} onChange={(e) => setData((current) => ({ ...current, name: e.target.value, key: codeTouched ? current.key : toShortCode(e.target.value) }))} placeholder="e.g. Radiology" />
          {errors.name && <p className="text-2xs text-ovs-crm-danger">{errors.name}</p>}
        </div>
        <div className="space-y-1.5">
          <label htmlFor="department-key" className="block text-2xs text-ovs-crm-text-muted">
            Short code <span className="text-ovs-crm-danger">*</span>
          </label>
          <Input id="department-key" value={data.key} onChange={(e) => { setCodeTouched(true); setData('key', e.target.value); }} placeholder="e.g. radiology" />
          {errors.key && <p className="text-2xs text-ovs-crm-danger">{errors.key}</p>}
        </div>
        <label className="flex items-center justify-between gap-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset px-3 py-2.5">
          <span className="text-[13px] text-ovs-crm-text-primary">Active</span>
          <Switch checked={data.is_active} onCheckedChange={(value) => setData('is_active', value)} />
        </label>
      </form>
    </CrmModal>
  );
}

export default function Departments({ departments }: DepartmentsPageProps) {
  const hasPermission = useClinicPermission();
  const canManage = hasPermission('branch.manage');
  const [editing, setEditing] = useState<Department | null>(null);
  const [open, setOpen] = useState(false);
  const { confirm, dialog } = useCrmConfirm();

  const openForm = (department: Department | null) => {
    setEditing(department);
    setOpen(true);
  };

  const remove = (department: Department) =>
    confirm({
      title: `Remove ${department.name}?`,
      description: 'Staff and visits already linked to this department keep their records.',
      confirmLabel: 'Remove department',
      destructive: true,
      onConfirm: () => router.delete(route('admin.departments.destroy', department.id), { preserveScroll: true }),
    });

  const columns: CrmColumn<Department>[] = [
    { key: 'name', header: 'Department', primary: true, cell: (row) => <span className="font-semibold text-ovs-crm-text-primary">{row.name}</span> },
    { key: 'key', header: 'Short code', hideOnMobile: true, cell: (row) => <span className="text-ovs-crm-text-secondary">{row.key ?? '—'}</span> },
    {
      key: 'status',
      header: 'Status',
      cell: (row) => <Badge variant={row.is_active ? 'success' : 'neutral'} size="compact">{row.is_active ? 'Active' : 'Inactive'}</Badge>,
    },
  ];

  return (
    <ClinicShell title="Departments">
      <CrmPageHead
        eyebrow="Admin"
        title="Departments"
        description="The departments patients move through during a visit."
        stats={<CrmPageStat icon={Layers} value={departments.filter((d) => d.is_active).length} caption="Active departments" />}
        actions={canManage ? <CrmButton tone="primary" icon={Plus} onClick={() => openForm(null)}>New Department</CrmButton> : undefined}
      />

      <InfrastructureTabs current="Departments" />

      <div className="pb-12">
        <CrmTable
          columns={columns}
          rows={departments}
          rowKey={(row) => row.id}
          empty={{ icon: Layers, title: 'No departments yet', description: 'Add the departments your clinic routes patients through.' }}
          rowActions={canManage ? (row) => (
            <div className="flex items-center gap-1">
              <CrmButton size="sm" tone="ghost" icon={Pencil} aria-label={`Edit ${row.name}`} onClick={() => openForm(row)} />
              <CrmButton size="sm" tone="ghost" icon={Trash2} aria-label={`Remove ${row.name}`} onClick={() => remove(row)} />
            </div>
          ) : undefined}
        />
      </div>

      <DepartmentFormModal department={editing} open={open} onClose={() => setOpen(false)} />
      {dialog}
    </ClinicShell>
  );
}
