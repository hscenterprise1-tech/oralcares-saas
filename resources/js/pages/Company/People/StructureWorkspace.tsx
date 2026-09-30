import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Building2, Plus, Users } from 'lucide-react';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmCard,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  CrmStatusPill,
  CrmDataState,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * The HQ org chart.
 *
 * Nine departments and nine teams have existed in this database with no way to
 * see or change either, while `company_employees` filed every person against
 * both. This is that structure.
 *
 * These are not the CRM's Sales Teams. That is a different table with a
 * different job — routing leads by territory and round-robin, scoped per
 * tenant. This one describes the vendor's own staff.
 */

interface Team {
  id: number;
  name: string;
  code: string;
  description: string | null;
  status: string;
  employees_count: number;
}

interface Department {
  id: number;
  name: string;
  code: string;
  description: string | null;
  status: string;
  sort_order: number;
  teams_count: number;
  employees_count: number;
  teams: Team[];
}

interface Props {
  departments: Department[];
  can: { manageDepartments: boolean; manageTeams: boolean };
}

const STATUS_TONE: Record<string, CrmStatusTone> = {
  active: 'good',
  inactive: 'neutral',
};

const label = (value: string) => (value ? value.charAt(0).toUpperCase() + value.slice(1) : '—');

export default function StructureWorkspace({ departments, can }: Props) {
  const [deptForm, setDeptForm] = useState<{
    open: boolean; id: number | null; name: string; code: string; description: string; status: string; sortOrder: string;
  }>({ open: false, id: null, name: '', code: '', description: '', status: 'active', sortOrder: '0' });

  const [teamForm, setTeamForm] = useState<{
    open: boolean; departmentId: number | null; id: number | null; name: string; code: string; description: string; status: string;
  }>({ open: false, departmentId: null, id: null, name: '', code: '', description: '', status: 'active' });

  const openCreateDept = () =>
    setDeptForm({ open: true, id: null, name: '', code: '', description: '', status: 'active', sortOrder: '0' });

  const openEditDept = (d: Department) =>
    setDeptForm({
      open: true, id: d.id, name: d.name, code: d.code,
      description: d.description ?? '', status: d.status, sortOrder: String(d.sort_order),
    });

  const openCreateTeam = (d: Department) =>
    setTeamForm({ open: true, departmentId: d.id, id: null, name: '', code: '', description: '', status: 'active' });

  const openEditTeam = (d: Department, t: Team) =>
    setTeamForm({
      open: true, departmentId: d.id, id: t.id, name: t.name, code: t.code,
      description: t.description ?? '', status: t.status,
    });

  const submitDept = (e: React.FormEvent) => {
    e.preventDefault();
    const body = {
      name: deptForm.name,
      code: deptForm.code,
      description: deptForm.description || null,
      status: deptForm.status,
      sort_order: Number(deptForm.sortOrder) || 0,
    };
    const done = { preserveScroll: true, onSuccess: () => setDeptForm((f) => ({ ...f, open: false })) };

    if (deptForm.id) {
      router.put(`/company/people/structure/departments/${deptForm.id}`, body, done);

      return;
    }

    router.post('/company/people/structure/departments', body, done);
  };

  const submitTeam = (e: React.FormEvent) => {
    e.preventDefault();
    const base = `/company/people/structure/departments/${teamForm.departmentId}/teams`;
    const body = {
      name: teamForm.name,
      code: teamForm.code,
      description: teamForm.description || null,
      status: teamForm.status,
    };
    const done = { preserveScroll: true, onSuccess: () => setTeamForm((f) => ({ ...f, open: false })) };

    if (teamForm.id) {
      router.put(`${base}/${teamForm.id}`, body, done);

      return;
    }

    router.post(base, body, done);
  };

  const totalTeams = departments.reduce((sum, d) => sum + d.teams_count, 0);
  const totalPeople = departments.reduce((sum, d) => sum + d.employees_count, 0);

  return (
    <PeopleShell title="Org structure">
      <CrmPageHead
        eyebrow="People"
        title="Org structure"
        stats={
          <>
            <CrmPageStat icon={Building2} value={departments.length} caption="Departments" />
            <CrmPageStat icon={Users} value={totalTeams} caption="Teams" />
            <CrmPageStat icon={Users} value={totalPeople} caption="People placed" />
          </>
        }
        actions={
          can.manageDepartments ? (
            <CrmButton tone="primary" icon={Plus} onClick={openCreateDept}>
              Add department
            </CrmButton>
          ) : undefined
        }
      />

      {departments.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={Building2}
          title="No departments yet"
          description="A department groups the teams people are assigned to."
        />
      ) : (
        <div className="space-y-3">
          {departments.map((department) => (
            <CrmCard key={department.id} className="px-5 py-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-[14px] font-semibold text-ovs-crm-text-primary">{department.name}</h3>
                    <span className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">{department.code}</span>
                    <CrmStatusPill tone={STATUS_TONE[department.status] ?? 'neutral'}>
                      {label(department.status)}
                    </CrmStatusPill>
                  </div>
                  {department.description && (
                    <p className="mt-0.5 text-[12px] text-ovs-crm-text-muted">{department.description}</p>
                  )}
                  <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                    {department.teams_count} {department.teams_count === 1 ? 'team' : 'teams'} ·{' '}
                    {department.employees_count} {department.employees_count === 1 ? 'person' : 'people'}
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  {can.manageDepartments && (
                    <CrmButton size="sm" onClick={() => openEditDept(department)}>
                      Edit
                    </CrmButton>
                  )}
                  {can.manageTeams && (
                    <CrmButton size="sm" icon={Plus} onClick={() => openCreateTeam(department)}>
                      Add team
                    </CrmButton>
                  )}
                </div>
              </div>

              <div className="mt-3 space-y-2 border-t border-ovs-crm-line pt-3">
                {department.teams.length === 0 ? (
                  <p className="text-[12.5px] text-ovs-crm-text-muted">No teams in this department yet.</p>
                ) : (
                  department.teams.map((team) => (
                    <div
                      key={team.id}
                      className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[16px] border border-ovs-crm-border px-4 py-2.5"
                    >
                      <span className="min-w-[160px] flex-1">
                        <span className="flex flex-wrap items-center gap-2">
                          <span className="text-[13px] font-medium text-ovs-crm-text-primary">{team.name}</span>
                          <span className="text-[11px] tabular-nums text-ovs-crm-text-muted">{team.code}</span>
                          <CrmStatusPill tone={STATUS_TONE[team.status] ?? 'neutral'}>
                            {label(team.status)}
                          </CrmStatusPill>
                        </span>
                        <span className="mt-0.5 block text-[11.5px] text-ovs-crm-text-muted">
                          {team.employees_count} {team.employees_count === 1 ? 'person' : 'people'}
                        </span>
                      </span>

                      {can.manageTeams && (
                        <CrmButton size="sm" onClick={() => openEditTeam(department, team)}>
                          Edit
                        </CrmButton>
                      )}
                    </div>
                  ))
                )}
              </div>
            </CrmCard>
          ))}
        </div>
      )}

      <CrmModal
        open={deptForm.open}
        onClose={() => setDeptForm((f) => ({ ...f, open: false }))}
        title={deptForm.id ? 'Edit department' : 'Add a department'}
        description="Departments group the teams people are assigned to."
        footer={
          <>
            <CrmButton onClick={() => setDeptForm((f) => ({ ...f, open: false }))}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="department-form">
              {deptForm.id ? 'Save' : 'Add department'}
            </CrmButton>
          </>
        }
      >
        <form id="department-form" onSubmit={submitDept} className="flex flex-col gap-3.5">
          <CrmFormField label="Name" required>
            <CrmInput required value={deptForm.name} onChange={(e) => setDeptForm({ ...deptForm, name: e.target.value })} />
          </CrmFormField>

          <CrmFormField label="Code" required hint="Short and unique, used on reports.">
            <CrmInput required value={deptForm.code} onChange={(e) => setDeptForm({ ...deptForm, code: e.target.value })} />
          </CrmFormField>

          <CrmFormField label="Description">
            <CrmTextarea rows={2} value={deptForm.description} onChange={(e) => setDeptForm({ ...deptForm, description: e.target.value })} />
          </CrmFormField>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="Status" required>
              <CrmSelect required value={deptForm.status} onChange={(e) => setDeptForm({ ...deptForm, status: e.target.value })}>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </CrmSelect>
            </CrmFormField>

            <CrmFormField label="Sort order" hint="Lower shows first.">
              <CrmInput type="number" min={0} value={deptForm.sortOrder} onChange={(e) => setDeptForm({ ...deptForm, sortOrder: e.target.value })} />
            </CrmFormField>
          </div>
        </form>
      </CrmModal>

      <CrmModal
        open={teamForm.open}
        onClose={() => setTeamForm((f) => ({ ...f, open: false }))}
        title={teamForm.id ? 'Edit team' : 'Add a team'}
        description="A team sits inside a department, and people are assigned to it."
        footer={
          <>
            <CrmButton onClick={() => setTeamForm((f) => ({ ...f, open: false }))}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="team-form">
              {teamForm.id ? 'Save' : 'Add team'}
            </CrmButton>
          </>
        }
      >
        <form id="team-form" onSubmit={submitTeam} className="flex flex-col gap-3.5">
          <CrmFormField label="Name" required>
            <CrmInput required value={teamForm.name} onChange={(e) => setTeamForm({ ...teamForm, name: e.target.value })} />
          </CrmFormField>

          <CrmFormField label="Code" required>
            <CrmInput required value={teamForm.code} onChange={(e) => setTeamForm({ ...teamForm, code: e.target.value })} />
          </CrmFormField>

          <CrmFormField label="Description">
            <CrmTextarea rows={2} value={teamForm.description} onChange={(e) => setTeamForm({ ...teamForm, description: e.target.value })} />
          </CrmFormField>

          <CrmFormField label="Status" required>
            <CrmSelect required value={teamForm.status} onChange={(e) => setTeamForm({ ...teamForm, status: e.target.value })}>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </CrmSelect>
          </CrmFormField>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
