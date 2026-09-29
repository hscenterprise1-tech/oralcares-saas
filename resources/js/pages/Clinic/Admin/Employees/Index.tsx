import React, { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Mail, Pencil, Plus, Trash2, UserCheck, Users } from 'lucide-react';
import {
  CrmButton,
  CrmFilterBar,
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmTable,
  useCrmConfirm,
  type CrmColumn,
  type CrmFilterDef,
} from '@/pages/Company/design';
import { Badge, BadgeVariant } from '@/pages/Company/design/CrmBadgePrimitives';
import { useClinicPermission } from '@/hooks/useClinicPermission';
import { ClinicShell } from '../../components/ClinicShell';
import { InviteModal } from './components/InviteModal';
import { Employee, EmployeesIndexProps, EmploymentStatus } from './types';

/*
 * Employee admin — a personnel list, so a table (comparable columns:
 * branches, roles, status) rather than the card grid Branches and Packages
 * use for discrete records. Row click opens the full Edit page, the same
 * way Teams opens its detail page — there's no separate "show" route here.
 */

const STATUS_LABEL: Record<EmploymentStatus, string> = {
  pending_invitation: 'Pending Invitation',
  invited: 'Invited',
  verified: 'Verified',
  active: 'Active',
  inactive: 'Inactive',
  suspended: 'Suspended',
  resigned: 'Resigned',
};

const STATUS_VARIANT: Record<EmploymentStatus, BadgeVariant> = {
  pending_invitation: 'warning',
  invited: 'warning',
  verified: 'info',
  active: 'success',
  inactive: 'neutral',
  suspended: 'danger',
  resigned: 'neutral',
};

const STATUS_OPTIONS = [
  { value: 'all', label: 'All statuses' },
  ...(Object.keys(STATUS_LABEL) as EmploymentStatus[]).map((value) => ({ value, label: STATUS_LABEL[value] })),
];

export default function EmployeesIndex({ employees, employmentStatuses }: EmployeesIndexProps) {
  const hasPermission = useClinicPermission();
  const canManage = hasPermission('staff.manage');

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('all');
  const [inviteEmployee, setInviteEmployee] = useState<Employee | null>(null);
  const { confirm, dialog } = useCrmConfirm();

  const confirmDelete = (employee: Employee) =>
    confirm({
      title: `Remove ${employee.name}?`,
      description: 'Their portal access is revoked immediately, if they had any. Records they created stay attributed to them. This cannot be undone.',
      confirmLabel: 'Remove employee',
      destructive: true,
      onConfirm: () => router.delete(route('admin.employees.destroy', employee.id), { preserveScroll: true }),
    });

  const filters: CrmFilterDef[] = [{ key: 'status', label: 'Status', value: status, options: STATUS_OPTIONS }];

  const visibleEmployees = useMemo(() => {
    const term = search.trim().toLowerCase();

    return employees.filter((employee) => {
      if (term) {
        const haystack = `${employee.name} ${employee.email ?? ''}`.toLowerCase();
        if (! haystack.includes(term)) return false;
      }
      if (status !== 'all' && employee.status !== status) return false;

      return true;
    });
  }, [employees, search, status]);

  const isFiltered = search.trim() !== '' || status !== 'all';
  const activeUserCount = employees.filter((e) => e.user_id !== null).length;
  const invitedCount = employees.filter((e) => e.user_id === null && e.invitations.length > 0).length;

  const columns: CrmColumn<Employee>[] = [
    {
      key: 'employee',
      header: 'Employee',
      primary: true,
      cell: (employee) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ovs-crm-text-primary">{employee.name}</p>
          <p className="truncate text-[11.5px] text-ovs-crm-text-secondary">{employee.email ?? 'No email on file'}</p>
        </div>
      ),
    },
    {
      key: 'branches',
      header: 'Branches',
      hideOnMobile: true,
      cell: (employee) =>
        employee.branches.length > 0 ? (
          <span className="truncate text-ovs-crm-text-secondary">{employee.branches.map((b) => b.name).join(', ')}</span>
        ) : (
          <span className="text-ovs-crm-text-muted">—</span>
        ),
    },
    {
      key: 'roles',
      header: 'Roles',
      hideOnMobile: true,
      cell: (employee) =>
        employee.roles.length > 0 ? (
          <span className="truncate text-ovs-crm-text-secondary">{employee.roles.map((r) => r.name).join(', ')}</span>
        ) : (
          <span className="text-ovs-crm-text-muted">—</span>
        ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (employee) => <Badge variant={STATUS_VARIANT[employee.status]} size="compact">{STATUS_LABEL[employee.status]}</Badge>,
    },
    {
      key: 'access',
      header: 'Portal',
      cell: (employee) =>
        employee.user_id ? (
          <Badge variant="success" size="compact">Active user</Badge>
        ) : employee.invitations.length > 0 ? (
          <Badge variant="warning" size="compact">Invited</Badge>
        ) : (
          <Badge variant="neutral" size="compact">No access</Badge>
        ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      hideOnMobile: true,
      cell: (employee) =>
        canManage ? (
          <div className="flex items-center justify-end gap-1">
            {! employee.user_id && (
              <button
                type="button"
                onClick={(event) => { event.stopPropagation(); setInviteEmployee(employee); }}
                aria-label={`Invite ${employee.name}`}
                className="flex h-7 w-7 items-center justify-center rounded-full border border-ovs-crm-border text-ovs-crm-text-secondary transition-colors hover:border-ovs-crm-border-strong hover:text-ovs-crm-text-primary"
              >
                <Mail className="h-3.5 w-3.5" aria-hidden="true" />
              </button>
            )}
            <button
              type="button"
              onClick={(event) => { event.stopPropagation(); router.visit(route('admin.employees.edit', employee.id)); }}
              aria-label={`Edit ${employee.name}`}
              className="flex h-7 w-7 items-center justify-center rounded-full border border-ovs-crm-border text-ovs-crm-text-secondary transition-colors hover:border-ovs-crm-border-strong hover:text-ovs-crm-text-primary"
            >
              <Pencil className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <button
              type="button"
              onClick={(event) => { event.stopPropagation(); confirmDelete(employee); }}
              aria-label={`Remove ${employee.name}`}
              className="flex h-7 w-7 items-center justify-center rounded-full border border-ovs-crm-border text-ovs-crm-text-secondary transition-colors hover:border-ovs-crm-danger-border hover:text-ovs-crm-danger-text"
            >
              <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </div>
        ) : null,
    },
  ];

  return (
    <ClinicShell title="Employees">
      <CrmPageHead
        eyebrow="Admin"
        title="Employees"
        stats={
          employees.length > 0 ? (
            <>
              <CrmPageStat icon={Users} value={employees.length} caption={`Employee${employees.length === 1 ? '' : 's'}`} />
              {activeUserCount > 0 && <CrmPageStat icon={UserCheck} value={activeUserCount} caption="With portal access" />}
              {invitedCount > 0 && <CrmPageStat icon={Mail} value={invitedCount} chip="pending" chipHue="#E0B21E" caption="Invited, not yet accepted" />}
            </>
          ) : undefined
        }
        actions={
          canManage ? (
            <CrmButton tone="primary" icon={Plus} href={route('admin.employees.create')}>
              Add Employee
            </CrmButton>
          ) : undefined
        }
      />

      <div className="space-y-4 pb-10">
        <CrmFilterBar
          searchValue={search}
          onSearchChange={setSearch}
          searchPlaceholder="Search employees…"
          filters={filters}
          onFilterChange={(key, value) => { if (key === 'status') setStatus(value); }}
          onClearAll={() => { setSearch(''); setStatus('all'); }}
          resultCount={visibleEmployees.length}
          resultNoun="employee"
        />

        <CrmSection flush>
          <CrmTable
            columns={columns}
            rows={visibleEmployees}
            rowKey={(employee) => employee.id}
            onRowClick={(employee) => router.visit(route('admin.employees.edit', employee.id))}
            isFiltered={isFiltered}
            empty={{
              icon: Users,
              title: 'No employees yet',
              description: 'Add your staff, assign them to branches and roles, then invite them to the portal when you\'re ready.',
              action: canManage ? (
                <CrmButton size="sm" icon={Plus} href={route('admin.employees.create')}>
                  Add your first employee
                </CrmButton>
              ) : undefined,
            }}
            emptyFiltered={{
              title: 'No employees match these filters',
              description: 'Try a different search or status.',
              action: (
                <CrmButton size="sm" onClick={() => { setSearch(''); setStatus('all'); }}>
                  Clear filters
                </CrmButton>
              ),
            }}
          />
        </CrmSection>
      </div>

      <InviteModal isOpen={inviteEmployee !== null} onClose={() => setInviteEmployee(null)} employee={inviteEmployee} />
      {dialog}
    </ClinicShell>
  );
}
