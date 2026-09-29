import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, ShieldCheck, Check, UserPlus, X } from 'lucide-react';
import {
  CrmPageHead,
  CrmSection,
  CrmCard,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmSelect,
  CrmStatusPill,
  CrmDataState,
  CrmAvatar,
} from '@/pages/Company/design';
import { AdministrationShell } from '@/pages/Company/design';

/**
 * One role: what it grants, and who holds it.
 *
 * The permission matrix is grouped by the subject each permission names.
 * Permission names come in four conventions in this application — `view-crm`,
 * `crm.view`, `view_crm` and `manage billing` — and the server groups them
 * without renaming any of them, because the aliases are load-bearing.
 *
 * The matrix is read-only. `spatie_roles` has no column marking a system role,
 * so there is no safe rule for who may strip permissions from a seeded one.
 */

interface MatrixGroup {
  module: string;
  permissions: Array<{ id: number; name: string; granted: boolean }>;
}

interface Holder {
  id: number;
  name: string;
  email: string;
  user_type: string;
}

interface Props {
  role: { id: number; name: string; guard_name: string; is_template: boolean; permissions_count: number };
  matrix: MatrixGroup[];
  holders: Holder[];
  assignableUsers: Array<{ id: number; name: string; email: string }>;
}

export default function RoleDetail({ role, matrix, holders, assignableUsers }: Props) {
  const [assigning, setAssigning] = useState(false);
  const [userId, setUserId] = useState('');

  const submitAssign = (e: React.FormEvent) => {
    e.preventDefault();
    if (!userId) return;

    router.post(
      `/company/people/roles/${role.id}/assign`,
      { user_id: userId },
      { preserveScroll: true, onSuccess: () => { setAssigning(false); setUserId(''); } },
    );
  };

  const revoke = (holder: Holder) =>
    router.post(
      `/company/people/roles/${role.id}/revoke`,
      { user_id: holder.id },
      { preserveScroll: true },
    );

  return (
    <AdministrationShell title={role.name}>
      <Link
        href="/company/people/roles"
        className="mb-3 inline-flex items-center gap-1.5 text-[12.5px] font-medium text-ovs-crm-text-secondary transition hover:text-ovs-crm-text-primary"
      >
        <ArrowLeft className="h-3.5 w-3.5" aria-hidden="true" />
        All roles
      </Link>

      <CrmPageHead
        eyebrow="Role"
        title={role.name}
        stats={
          <>
            <span className="text-[12.5px] text-ovs-crm-text-secondary">
              {role.permissions_count} {role.permissions_count === 1 ? 'permission' : 'permissions'}
            </span>
            {role.is_template && <CrmStatusPill tone="neutral">Template</CrmStatusPill>}
          </>
        }
        actions={
          <CrmButton tone="primary" icon={UserPlus} onClick={() => setAssigning(true)}>
            Assign to someone
          </CrmButton>
        }
      />

      <CrmSection
        title={`Who holds this role (${holders.length})`}
        description="Assignments are written inside this role's own permission-team scope."
        className="mb-4"
      >
        {holders.length === 0 ? (
          <CrmDataState kind="empty" compact icon={ShieldCheck} title="Nobody holds this role" description="It grants nothing until somebody does." />
        ) : (
          <ul className="space-y-2">
            {holders.map((holder) => (
              <li
                key={holder.id}
                className="flex flex-wrap items-center gap-3 rounded-[16px] border border-ovs-crm-border px-4 py-2.5"
              >
                <CrmAvatar name={holder.name} size="sm" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13px] font-medium text-ovs-crm-text-primary">{holder.name}</span>
                  <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
                    {holder.email} · {holder.user_type.replace(/_/g, ' ')}
                  </span>
                </span>
                <CrmButton size="sm" icon={X} onClick={() => revoke(holder)}>
                  Remove
                </CrmButton>
              </li>
            ))}
          </ul>
        )}
      </CrmSection>

      <CrmSection
        title="What it grants"
        description="Read-only. There is no column marking a system role, so no rule decides who may edit one."
      >
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
          {matrix.map((group) => (
            <CrmCard key={group.module} className="px-4 py-3.5">
              <h4 className="mb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                {group.module}
              </h4>
              <ul className="space-y-1">
                {group.permissions.map((permission) => (
                  <li key={permission.id} className="flex items-start gap-2 text-[12.5px]">
                    <span
                      className={
                        permission.granted
                          ? 'mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-ovs-crm-success-soft text-ovs-crm-success-text'
                          : 'mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-ovs-crm-surface-inset text-ovs-crm-text-muted'
                      }
                      aria-hidden="true"
                    >
                      {permission.granted ? <Check className="h-2.5 w-2.5" /> : null}
                    </span>
                    <span className={permission.granted ? 'text-ovs-crm-text-primary' : 'text-ovs-crm-text-muted'}>
                      {permission.name}
                    </span>
                  </li>
                ))}
              </ul>
            </CrmCard>
          ))}
        </div>
      </CrmSection>

      <CrmModal
        open={assigning}
        onClose={() => setAssigning(false)}
        title={`Assign ${role.name}`}
        description="The person gains everything this role grants."
        footer={
          <>
            <CrmButton onClick={() => setAssigning(false)}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="assign-role">
              Assign
            </CrmButton>
          </>
        }
      >
        <form id="assign-role" onSubmit={submitAssign}>
          <CrmFormField label="Person" required>
            <CrmSelect required value={userId} onChange={(e) => setUserId(e.target.value)}>
              <option value="">Choose someone</option>
              {assignableUsers.map((user) => (
                <option key={user.id} value={user.id}>
                  {user.name} — {user.email}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </form>
      </CrmModal>
    </AdministrationShell>
  );
}
