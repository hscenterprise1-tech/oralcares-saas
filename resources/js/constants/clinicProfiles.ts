export type ClinicProfileType = 
    | 'solo-dentist'
    | 'small-clinic'
    | 'medium-clinic'
    | 'multi-branch'
    | 'dental-hospital'
    | 'dental-college'
    | 'corporate';

export interface ClinicProfileConfig {
    name: string;
    description: string;
    defaultSubscription: string;
    defaultBranchCount: number;
    defaultStaffCount: number;
    defaultPatientVolume: number;
    dashboardFocus: 'appointments' | 'performance' | 'operations' | 'executive' | 'academic';
    availableRoles: string[];
}

export const CLINIC_PROFILES: Record<ClinicProfileType, ClinicProfileConfig> = {
    'solo-dentist': {
        name: 'Solo Dentist',
        description: '1 Owner Doctor, 1 Reception, Single Branch',
        defaultSubscription: 'starter',
        defaultBranchCount: 1,
        defaultStaffCount: 2,
        defaultPatientVolume: 500,
        dashboardFocus: 'appointments',
        availableRoles: ['master-admin', 'clinic-owner', 'reception']
    },
    'small-clinic': {
        name: 'Small Clinic',
        description: '3 Doctors, 2 Reception, 1 Billing, Single Branch',
        defaultSubscription: 'professional',
        defaultBranchCount: 1,
        defaultStaffCount: 6,
        defaultPatientVolume: 1500,
        dashboardFocus: 'performance',
        availableRoles: ['master-admin', 'clinic-owner', 'doctor', 'reception', 'billing']
    },
    'medium-clinic': {
        name: 'Medium Clinic',
        description: '6 Doctors, Reception Team, Billing, Lab, X-Ray',
        defaultSubscription: 'professional',
        defaultBranchCount: 1,
        defaultStaffCount: 15,
        defaultPatientVolume: 5000,
        dashboardFocus: 'operations',
        availableRoles: ['master-admin', 'clinic-owner', 'doctor', 'reception', 'billing', 'lab', 'x-ray']
    },
    'multi-branch': {
        name: 'Multi Branch Clinic',
        description: 'Multiple Branches, Separate Billing, Combined Analytics',
        defaultSubscription: 'enterprise',
        defaultBranchCount: 3,
        defaultStaffCount: 30,
        defaultPatientVolume: 10000,
        dashboardFocus: 'operations',
        availableRoles: ['master-admin', 'clinic-owner', 'clinic-admin', 'doctor', 'reception', 'billing', 'lab']
    },
    'dental-hospital': {
        name: 'Dental Hospital',
        description: 'High volume, Large Departments, Admin Roles',
        defaultSubscription: 'enterprise-plus',
        defaultBranchCount: 1,
        defaultStaffCount: 100,
        defaultPatientVolume: 20000,
        dashboardFocus: 'operations',
        availableRoles: ['master-admin', 'clinic-owner', 'clinic-admin', 'doctor', 'reception', 'billing', 'accounts', 'lab', 'x-ray']
    },
    'dental-college': {
        name: 'Dental College',
        description: 'Professors, Students, Academic Reports',
        defaultSubscription: 'enterprise-plus',
        defaultBranchCount: 5,
        defaultStaffCount: 200,
        defaultPatientVolume: 50000,
        dashboardFocus: 'academic',
        availableRoles: ['master-admin', 'clinic-admin', 'doctor', 'reception'] // Mocking professors/students as doctors for now
    },
    'corporate': {
        name: 'Corporate Healthcare Group',
        description: 'Multiple Clinics, Regional Managers, Global Reports',
        defaultSubscription: 'enterprise-plus',
        defaultBranchCount: 20,
        defaultStaffCount: 500,
        defaultPatientVolume: 100000,
        dashboardFocus: 'executive',
        availableRoles: ['master-admin', 'clinic-owner', 'clinic-admin', 'finance', 'doctor', 'reception', 'billing', 'accounts']
    }
};
