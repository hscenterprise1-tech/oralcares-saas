import type { CrmStatusTone } from '@/pages/Company/design';
import { PatientStatus } from './types';

/**
 * One place for every colour this module assigns to a patient status —
 * `Index.tsx`, `Show.tsx` and anything that follows all read this rather
 * than each hard-coding its own copy, the same reason `crmTheme.ts` exists
 * for the CRM at large.
 */

export const STATUS_TONE: Record<PatientStatus, CrmStatusTone> = {
  active: 'good',
  inactive: 'neutral',
  deceased: 'bad',
};

export const STATUS_LABEL: Record<PatientStatus, string> = {
  active: 'Active',
  inactive: 'Inactive',
  deceased: 'Deceased',
};

export const SEVERITY_TONE: Record<string, CrmStatusTone> = {
  critical: 'bad',
  high: 'bad',
  medium: 'warn',
  low: 'neutral',
};
