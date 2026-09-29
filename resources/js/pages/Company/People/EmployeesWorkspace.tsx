import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
  Users,
  UserCheck,
  Building2,
  Search,
  Plus,
} from 'lucide-react';
import { PeopleShell } from './components/PeopleShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmFilterBar,
  CrmInput,
  CrmSelect,
  CrmButton,
  CrmModal,
  CrmStatusPill,
  CrmAvatar,
  CrmDatePicker,
} from '@/pages/Company/design';

interface Employee {
  id: number;
  employee_code: string;
  designation: string;
  employment_status: string;
  joining_date: string;
  user?: { id: number; name: string; email: string };
  department?: { id: number; name: string; code: string };
  team?: { id: number; name: string; code: string };
  manager?: { user?: { name: string } };
}

interface Department {
  id: number;
  name: string;
  code: string;
}

interface Props {
  employees: Employee[] | { data: Employee[] };
  departments?: Department[];
  kpi?: {
    total_employees: number;
    active_employees: number;
    managers_count: number;
  };
  filters?: { search?: string; employment_status?: string };
}

const STATUS_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'probation', label: 'Probation' },
  { key: 'inactive', label: 'Inactive' },
] as const;

const shortDate = (iso: string | null | undefined) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: '2-digit' });
};

export default function EmployeesWorkspace({
  employees,
  departments = [],
  kpi,
  filters = {},
}: Props) {
  const employeeList = Array.isArray(employees) ? employees : employees?.data || [];

  const [search, setSearch] = useState(filters.search || '');
  const [statusFilter, setStatusFilter] = useState(filters.employment_status || 'all');
  const [showAddModal, setShowAddModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // Form State
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [employeeCode, setEmployeeCode] = useState(`EMP-${Math.floor(1000 + Math.random() * 9000)}`);
  const [designation, setDesignation] = useState('Software Engineer');
  const [departmentId, setDepartmentId] = useState<number>(departments[0]?.id ?? 1);
  const [employmentStatus, setEmploymentStatus] = useState('active');
  const [joiningDate, setJoiningDate] = useState(new Date().toISOString().split('T')[0]);

  const apply = (changes: Record<string, string | undefined>) => {
    const next: Record<string, string | undefined> = { ...filters, ...changes };
    for (const key of Object.keys(next)) {
      if (!next[key] || next[key] === 'all') delete next[key];
    }
    router.get('/company/people/employees', next as any, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const searchTimer = React.useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const onSearchChange = (value: string) => {
    setSearch(value);
    clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => apply({ search: value }), 300);
  };
  React.useEffect(() => () => clearTimeout(searchTimer.current), []);

  const submitAddEmployee = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    router.post(
      '/company/people/employees',
      {
        name,
        email,
        employee_code: employeeCode,
        designation,
        department_id: departmentId,
        employment_status: employmentStatus,
        joining_date: joiningDate,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setShowAddModal(false);
          setSubmitting(false);
          setName('');
          setEmail('');
          setEmployeeCode(`EMP-${Math.floor(1000 + Math.random() * 9000)}`);
        },
        onError: () => {
          setSubmitting(false);
        },
      }
    );
  };

  return (
    <PeopleShell title="Employees">
      <CrmPageHead
        eyebrow="People"
        title="Employees"
        actions={
          <CrmButton tone="primary" onClick={() => setShowAddModal(true)}>
            <Plus className="mr-1.5 h-4 w-4" aria-hidden="true" />
            Add employee
          </CrmButton>
        }
        stats={
          <>
            <CrmPageStat
              icon={Users}
              value={kpi?.total_employees ?? employeeList.length}
              caption="Total workforce"
            />
            <CrmPageStat
              icon={UserCheck}
              value={kpi?.active_employees ?? employeeList.filter((e) => e.employment_status === 'active').length}
              chip="Active"
              chipHue="#2FA96B"
              caption="Active staff"
            />
            <CrmPageStat
              icon={Building2}
              value={departments.length}
              caption="Departments"
            />
          </>
        }
      />

      <CrmSection
        title="Staff directory"
        description={`${employeeList.length} member${employeeList.length === 1 ? '' : 's'}`}
        actions={
          <div className="relative">
            <Search
              className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-ovs-crm-text-muted"
              aria-hidden="true"
            />
            <CrmInput
              type="search"
              value={search}
              onChange={(e) => onSearchChange(e.target.value)}
              placeholder="Search by name, email or code"
              aria-label="Search employees"
              className="w-full pl-9 sm:w-64"
            />
          </div>
        }
      >
        <CrmFilterBar
          className="mb-4"
          segments={STATUS_FILTERS.map((s) => ({
            key: s.key,
            label: s.label,
          }))}
          segmentValue={filters.employment_status || 'all'}
          onSegmentChange={(key) => {
            setStatusFilter(key);
            apply({ employment_status: key });
          }}
          inlineSegments
        />

        {employeeList.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-[13px] text-ovs-crm-text-muted">
              {search || filters.employment_status
                ? 'No employees match your search criteria.'
                : 'No employees found in the directory. Use "Add employee" to onboard staff.'}
            </p>
          </div>
        ) : (
          <div className="-mx-2.5">
            {employeeList.map((emp) => (
              <div
                key={emp.id}
                className="group flex cursor-default items-center gap-3 rounded-[14px] px-2.5 py-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
              >
                {/* Avatar */}
                <CrmAvatar name={emp.user?.name || 'Staff'} size="md" />

                {/* Name & Designation */}
                <div className="min-w-0 flex-[1.5]">
                  <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">
                    {emp.user?.name || 'Unnamed Employee'}
                  </p>
                  <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                    {emp.designation} {emp.department ? `• ${emp.department.name}` : ''}
                  </p>
                </div>

                {/* Code */}
                <div className="hidden min-w-0 w-28 shrink-0 font-mono text-[12px] text-ovs-crm-text-secondary sm:block">
                  {emp.employee_code}
                </div>

                {/* Email */}
                <div className="hidden min-w-0 flex-1 truncate text-[12px] text-ovs-crm-text-secondary lg:block">
                  {emp.user?.email || '—'}
                </div>

                {/* Joining date */}
                <span className="hidden w-24 shrink-0 text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted md:block">
                  {shortDate(emp.joining_date)}
                </span>

                {/* Status */}
                <span className="hidden w-24 shrink-0 justify-end md:flex">
                  <CrmStatusPill tone={emp.employment_status === 'active' ? 'green' : 'neutral'}>
                    {emp.employment_status}
                  </CrmStatusPill>
                </span>
              </div>
            ))}
          </div>
        )}
      </CrmSection>

      {/* Add Employee Modal */}
      <CrmModal
        open={showAddModal}
        onClose={() => setShowAddModal(false)}
        title="Add HQ Employee"
        description="Create an employee record and provision corporate access."
        size="sm"
        footer={
          <>
            <CrmButton tone="ghost" onClick={() => setShowAddModal(false)}>
              Cancel
            </CrmButton>
            <CrmButton tone="primary" onClick={submitAddEmployee} loading={submitting}>
              Create employee
            </CrmButton>
          </>
        }
      >
        <form onSubmit={submitAddEmployee} className="space-y-4 pt-1">
          <div>
            <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
              Full Name
            </label>
            <CrmInput
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Dr. Aryan Sharma"
              className="mt-1 w-full"
            />
          </div>

          <div>
            <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
              Email Address
            </label>
            <CrmInput
              required
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="aryan.sharma@oralcares.com"
              className="mt-1 w-full"
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
                Employee Code
              </label>
              <CrmInput
                required
                value={employeeCode}
                onChange={(e) => setEmployeeCode(e.target.value)}
                className="mt-1 w-full font-mono tabular-nums"
              />
            </div>

            <div>
              <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
                Designation
              </label>
              <CrmInput
                required
                value={designation}
                onChange={(e) => setDesignation(e.target.value)}
                placeholder="Product Specialist"
                className="mt-1 w-full"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
                Department
              </label>
              <CrmSelect
                value={String(departmentId)}
                onChange={(e) => setDepartmentId(Number(e.target.value))}
                className="mt-1 w-full"
              >
                {departments.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                  </option>
                ))}
              </CrmSelect>
            </div>

            <div>
              <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
                Joining Date
              </label>
              <CrmDatePicker
                required
                value={joiningDate}
                onChange={(e) => setJoiningDate(e.target.value)}
                className="mt-1 w-full"
              />
            </div>
          </div>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
