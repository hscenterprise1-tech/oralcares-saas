import React from 'react';
import { Link } from '@inertiajs/react';
import { ShieldCheck, KeyRound, Users } from 'lucide-react';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmStatusPill,
  CrmDataState,
} from '@/pages/Company/design';
import { AdministrationShell } from '@/pages/Company/design';

/**
 * The roles this application defines, and who holds them.
 *
 * Fourteen roles and roughly ninety permissions have existed with no way to see
 * either. Roles are read-only here: `spatie_roles` carries no column marking a
 * system role, so there is no safe rule for who may edit one.
 */

interface RoleRow {
  id: number;
  name: string;
  guard_name: string;
  permissions_count: number;
  users_count: number;
  is_template: boolean;
}

interface Props {
  roles: RoleRow[];
  permissionCount: number;
}

export default function RolesWorkspace({ roles, permissionCount }: Props) {
  const unusedRoles = roles.filter((role) => role.users_count === 0).length;

  return (
    <AdministrationShell title="Roles">
      <CrmPageHead
        eyebrow="People"
        title="Roles and permissions"
        stats={
          <>
            <CrmPageStat icon={ShieldCheck} value={roles.length} caption="Roles" />
            <CrmPageStat icon={KeyRound} value={permissionCount} caption="Permissions" />
            <CrmPageStat icon={Users} value={unusedRoles} caption="Held by nobody" />
          </>
        }
      />

      {roles.length === 0 ? (
        <CrmDataState kind="empty" icon={ShieldCheck} title="No roles defined" description="Roles are created by the application's seeders." />
      ) : (
        <div className="space-y-2">
          {roles.map((role) => (
            <Link
              key={role.id}
              href={`/company/people/roles/${role.id}`}
              className="block"
            >
              <CrmCard className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 transition hover:border-ovs-crm-accent/40">
                <span className="min-w-[180px] flex-1">
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="text-[13.5px] font-medium text-ovs-crm-text-primary">{role.name}</span>
                    {role.is_template && <CrmStatusPill tone="neutral">Template</CrmStatusPill>}
                  </span>
                  <span className="mt-0.5 block text-[11.5px] text-ovs-crm-text-muted">
                    guard {role.guard_name}
                  </span>
                </span>

                <span className="text-[12.5px] tabular-nums text-ovs-crm-text-secondary">
                  {role.permissions_count} {role.permissions_count === 1 ? 'permission' : 'permissions'}
                </span>

                <CrmStatusPill tone={role.users_count > 0 ? 'good' : 'neutral'}>
                  {role.users_count} {role.users_count === 1 ? 'holder' : 'holders'}
                </CrmStatusPill>
              </CrmCard>
            </Link>
          ))}
        </div>
      )}
    </AdministrationShell>
  );
}
