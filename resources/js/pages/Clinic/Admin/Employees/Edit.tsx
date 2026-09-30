import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Mail, ShieldCheck, Trash2, UserCheck } from 'lucide-react';
import { Badge } from '@/pages/Company/design/CrmBadgePrimitives';
import { CrmButton, CrmCard, CrmIconButton, CrmPageHead, CrmSection, toneHex, useCrmConfirm } from '@/pages/Company/design';
import { ClinicShell } from '../../components/ClinicShell';
import { EmployeeFields, EmployeeFormData } from './components/EmployeeFields';
import { InviteModal } from './components/InviteModal';
import { EmployeeEditProps } from './types';

export default function EditEmployee({ employee, branches, roles, employmentStatuses }: EmployeeEditProps) {
  const { data, setData, put, processing, errors } = useForm<EmployeeFormData>({
    name: employee.name,
    email: employee.email ?? '',
    phone: employee.phone ?? '',
    status: employee.status,
    branches: employee.branches.map((b) => b.id),
    roles: employee.roles.map((r) => r.id),
  });

  const [inviteOpen, setInviteOpen] = useState(false);
  const { confirm, dialog } = useCrmConfirm();

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    put(route('admin.employees.update', employee.id));
  };

  const confirmDelete = () =>
    confirm({
      title: `Remove ${employee.name}?`,
      description: 'Their portal access is revoked immediately, if they had any. Records they created stay attributed to them. This cannot be undone.',
      confirmLabel: 'Remove employee',
      destructive: true,
      onConfirm: () => router.delete(route('admin.employees.destroy', employee.id)),
    });

  const pending = employee.invitations[0] ?? null;

  return (
    <ClinicShell title={`Edit ${employee.name}`}>
      <div className="mx-auto min-w-0 max-w-[900px] space-y-4 pb-16">
        <CrmIconButton icon={ArrowLeft} label="Back to employees" tone="ghost" href={route('admin.employees.index')} className="mb-2" />

        <CrmPageHead className="mb-1" eyebrow="Admin" title={employee.name} description="Edit their profile, branch access and role." />

        <CrmSection title="Portal access" icon={ShieldCheck} hue={toneHex('blue')}>
          {employee.user_id ? (
            <div className="flex items-center gap-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset p-4">
              <UserCheck className="h-4 w-4 shrink-0 text-ovs-crm-success-text" aria-hidden="true" />
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-medium text-ovs-crm-text-primary">Has an active portal account</p>
                <p className="mt-0.5 text-[12px] text-ovs-crm-text-muted">Signs in with {employee.email ?? 'their registered email'}.</p>
              </div>
              <Badge variant="success" size="compact">Active user</Badge>
            </div>
          ) : (
            <div className="flex flex-wrap items-center gap-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset p-4">
              <Mail className="h-4 w-4 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-medium text-ovs-crm-text-primary">
                  {pending ? `Invite sent to ${pending.email}` : 'No portal access yet'}
                </p>
                <p className="mt-0.5 text-[12px] text-ovs-crm-text-muted">
                  {pending ? 'Waiting for them to set a password.' : 'They can\'t sign in until you send an invite.'}
                </p>
              </div>
              <CrmButton size="sm" tone={pending ? 'secondary' : 'primary'} onClick={() => setInviteOpen(true)}>
                {pending ? 'Manage invite' : 'Send invite'}
              </CrmButton>
            </div>
          )}
        </CrmSection>

        <form onSubmit={handleSubmit} className="space-y-4">
          <EmployeeFields data={data} setData={setData} errors={errors} branches={branches} roles={roles} employmentStatuses={employmentStatuses} />

          <CrmCard className="flex flex-wrap items-center gap-3 px-5 py-4">
            <p className="min-w-0 flex-1 text-[12.5px] text-ovs-crm-text-muted">Changes to roles apply immediately if this person already has portal access.</p>
            <CrmButton href={route('admin.employees.index')}>Cancel</CrmButton>
            <CrmButton tone="primary" icon={Check} loading={processing} disabled={! data.name.trim()} type="submit">
              Save changes
            </CrmButton>
          </CrmCard>
        </form>

        <CrmCard className="flex flex-wrap items-center gap-3 border border-ovs-crm-danger-border bg-ovs-crm-danger-soft/40 px-5 py-4">
          <div className="min-w-0 flex-1">
            <p className="text-[13px] font-semibold text-ovs-crm-danger-text">Remove this employee</p>
            <p className="mt-0.5 text-[12px] text-ovs-crm-text-muted">Permanent. Their portal access, if any, is revoked immediately.</p>
          </div>
          <CrmButton tone="danger" icon={Trash2} onClick={confirmDelete}>Remove employee</CrmButton>
        </CrmCard>
      </div>

      <InviteModal isOpen={inviteOpen} onClose={() => setInviteOpen(false)} employee={employee} />
      {dialog}
    </ClinicShell>
  );
}
