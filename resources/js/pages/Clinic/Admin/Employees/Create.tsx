import React from 'react';
import { useForm } from '@inertiajs/react';
import { ArrowLeft, Check } from 'lucide-react';
import { CrmButton, CrmCard, CrmIconButton, CrmPageHead } from '@/pages/Company/design';
import { ClinicShell } from '../../components/ClinicShell';
import { EmployeeFields, EmployeeFormData } from './components/EmployeeFields';
import { EmployeeCreateProps } from './types';

export default function CreateEmployee({ branches, roles, employmentStatuses }: EmployeeCreateProps) {
  const { data, setData, post, processing, errors } = useForm<EmployeeFormData>({
    name: '',
    email: '',
    phone: '',
    status: 'active',
    branches: [],
    roles: [],
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('admin.employees.store'));
  };

  return (
    <ClinicShell title="Add employee">
      <div className="mx-auto min-w-0 max-w-[900px] space-y-4 pb-16">
        <CrmIconButton icon={ArrowLeft} label="Back to employees" tone="ghost" href={route('admin.employees.index')} className="mb-2" />

        <CrmPageHead
          className="mb-1"
          eyebrow="Admin"
          title="Add employee"
          description="Create the staff profile first — invite them to the portal separately once you're ready."
        />

        <form onSubmit={handleSubmit} className="space-y-4">
          <EmployeeFields data={data} setData={setData} errors={errors} branches={branches} roles={roles} employmentStatuses={employmentStatuses} />

          <CrmCard className="flex flex-wrap items-center gap-3 px-5 py-4">
            <p className="min-w-0 flex-1 text-[12.5px] text-ovs-crm-text-muted">
              You can send a portal invite from the employee list once this is saved.
            </p>
            <CrmButton href={route('admin.employees.index')}>Cancel</CrmButton>
            <CrmButton tone="primary" icon={Check} loading={processing} disabled={! data.name.trim()} type="submit">
              Create employee
            </CrmButton>
          </CrmCard>
        </form>
      </div>
    </ClinicShell>
  );
}
