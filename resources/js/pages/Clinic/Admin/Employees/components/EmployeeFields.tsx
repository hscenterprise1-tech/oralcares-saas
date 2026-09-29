import React from 'react';
import { IdCard, MapPin } from 'lucide-react';
import { CrmFormField, CrmInput, CrmSection, CrmSelect, toneHex } from '@/pages/Company/design';
import { EmployeeBranch, EmployeeRole, EmploymentStatus } from '../types';

/**
 * The field set shared by Create and Edit — same shape, only the submit
 * action differs, so it lives once rather than twice.
 */

export interface EmployeeFormData {
  name: string;
  email: string;
  phone: string;
  status: EmploymentStatus;
  branches: number[];
  roles: number[];
}

const STATUS_LABEL: Record<EmploymentStatus, string> = {
  pending_invitation: 'Pending Invitation',
  invited: 'Invited',
  verified: 'Verified',
  active: 'Active',
  inactive: 'Inactive',
  suspended: 'Suspended',
  resigned: 'Resigned',
};

const AREA = {
  identity: toneHex('violet'),
  access: toneHex('teal'),
} as const;

interface EmployeeFieldsProps {
  data: EmployeeFormData;
  setData: <K extends keyof EmployeeFormData>(key: K, value: EmployeeFormData[K]) => void;
  errors: Partial<Record<keyof EmployeeFormData, string>>;
  branches: EmployeeBranch[];
  roles: EmployeeRole[];
  employmentStatuses: EmploymentStatus[];
}

export const EmployeeFields: React.FC<EmployeeFieldsProps> = ({
  data,
  setData,
  errors,
  branches,
  roles,
  employmentStatuses,
}) => {
  const toggleBranch = (id: number) => {
    setData('branches', data.branches.includes(id) ? data.branches.filter((b) => b !== id) : [...data.branches, id]);
  };

  const toggleRole = (id: number) => {
    setData('roles', data.roles.includes(id) ? data.roles.filter((r) => r !== id) : [...data.roles, id]);
  };

  return (
    <div className="space-y-4">
      <CrmSection title="Basic Info" icon={IdCard} hue={AREA.identity}>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <CrmFormField label="Full name" required error={errors.name}>
            <CrmInput value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Dr Asha Verify" autoFocus />
          </CrmFormField>

          <CrmFormField label="Employment status" required error={errors.status}>
            <CrmSelect value={data.status} onValueChange={(v) => setData('status', v as EmploymentStatus)}>
              {employmentStatuses.map((status) => (
                <option key={status} value={status}>{STATUS_LABEL[status]}</option>
              ))}
            </CrmSelect>
          </CrmFormField>

          <CrmFormField label="Email" error={errors.email} hint="Used to send the portal invite, if you send one.">
            <CrmInput
              type="email"
              value={data.email}
              onChange={(e) => setData('email', e.target.value)}
              placeholder="name@clinic.com"
            />
          </CrmFormField>

          <CrmFormField label="Phone" error={errors.phone}>
            <CrmInput value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="98765 43210" />
          </CrmFormField>
        </div>
      </CrmSection>

      <CrmSection
        title="Branch & role access"
        description="Which branches this person works from, and what they're allowed to do there."
        icon={MapPin}
        hue={AREA.access}
        step={1}
      >
        <CrmFormField label="Branches" error={errors.branches}>
          {branches.length === 0 ? (
            <p className="text-[12.5px] text-ovs-crm-text-muted">No branches yet — create one first.</p>
          ) : (
            <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
              {branches.map((branch) => (
                <label
                  key={branch.id}
                  className="flex cursor-pointer items-center gap-2 rounded-[16px] border border-ovs-crm-border bg-white px-3 py-2 text-[13px] text-ovs-crm-text-primary hover:border-ovs-crm-border-strong"
                >
                  <input
                    type="checkbox"
                    checked={data.branches.includes(branch.id)}
                    onChange={() => toggleBranch(branch.id)}
                    className="h-3.5 w-3.5 rounded border-ovs-crm-border"
                  />
                  {branch.name}
                </label>
              ))}
            </div>
          )}
        </CrmFormField>

        <div className="mt-4">
          <CrmFormField label="Roles" error={errors.roles} hint="Controls what this person can see and do once they have portal access.">
            <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
              {roles.map((role) => (
                <label
                  key={role.id}
                  className="flex cursor-pointer items-center gap-2 rounded-[16px] border border-ovs-crm-border bg-white px-3 py-2 text-[13px] text-ovs-crm-text-primary hover:border-ovs-crm-border-strong"
                >
                  <input
                    type="checkbox"
                    checked={data.roles.includes(role.id)}
                    onChange={() => toggleRole(role.id)}
                    className="h-3.5 w-3.5 rounded border-ovs-crm-border"
                  />
                  {role.name}
                </label>
              ))}
            </div>
          </CrmFormField>
        </div>
      </CrmSection>
    </div>
  );
};

export default EmployeeFields;
