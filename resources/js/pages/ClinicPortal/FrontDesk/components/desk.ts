import { isDental, isPhysio } from '@/clinic/ds';
import type { ClinicContextProps } from '@/clinic/types';

/**
 * What this desk works with, from the clinic's own context: which of its
 * specialties are Dental and which Physio, the extensions it has (the
 * tooth chart, session packages) and the member's billing permission. The
 * wording follows: a Physio-only desk speaks of sessions, a Dental-only
 * desk of the dentist, a mixed one of the doctor.
 */
export interface DeskMode {
  dental: boolean;
  physio: boolean;
  toothChart: boolean;
  packages: boolean;
  canSeeBilling: boolean;
  active: string;
  activeCaption: string;
  doctor: string;
}

export function deskMode(clinic: ClinicContextProps | null): DeskMode {
  const specialties = clinic?.specialties ?? [];
  const extensions = clinic?.extensions ?? [];
  const dental = specialties.some(isDental);
  const physio = specialties.some(isPhysio);
  const physioOnly = physio && !dental;
  const dentalOnly = dental && !physio;

  return {
    dental,
    physio,
    toothChart: dental && extensions.includes('odontogram'),
    packages: physio && extensions.includes('session_packages'),
    canSeeBilling: (clinic?.permissions ?? []).includes('billing.view'),
    active: physioOnly ? 'In session' : dentalOnly ? 'With dentist' : 'With doctor',
    activeCaption: physioOnly ? 'Sessions under way' : 'In consultation',
    doctor: physioOnly ? 'Therapist' : dentalOnly ? 'Dentist' : 'Doctor',
  };
}

/** A Patient 360 address opened at one of its sections (`?section=`); the page falls back to the member's own section when that one is not theirs. */
export function patientSection(patientUrl: string, section: string): string {
  return `${patientUrl}${patientUrl.includes('?') ? '&' : '?'}section=${section}`;
}
