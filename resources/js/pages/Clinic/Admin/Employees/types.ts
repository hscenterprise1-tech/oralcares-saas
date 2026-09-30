export type EmploymentStatus =
  | 'pending_invitation'
  | 'invited'
  | 'verified'
  | 'active'
  | 'inactive'
  | 'suspended'
  | 'resigned';

export interface EmployeeBranch {
  id: number;
  name: string;
}

export interface EmployeeRole {
  id: number;
  name: string;
}

/** The one active, unexpired, unrevoked, unaccepted invite for an employee — if any. */
export interface PendingInvitation {
  id: number;
  email: string;
  expires_at: string;
}

export interface Employee {
  id: number;
  clinic_id: number;
  user_id: number | null;
  name: string;
  email: string | null;
  phone: string | null;
  status: EmploymentStatus;
  branches: EmployeeBranch[];
  roles: EmployeeRole[];
  invitations: PendingInvitation[];
  created_at: string;
  updated_at: string;
}

export interface EmployeesIndexProps {
  employees: Employee[];
  employmentStatuses: EmploymentStatus[];
}

export interface EmployeeCreateProps {
  branches: EmployeeBranch[];
  roles: EmployeeRole[];
  employmentStatuses: EmploymentStatus[];
}

export interface EmployeeEditProps extends EmployeeCreateProps {
  employee: Employee;
}
