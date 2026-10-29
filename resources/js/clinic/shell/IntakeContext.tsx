import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ReceptionIntakeDialog, type IntakeMode, type PatientIdentity } from '@/pages/Clinic/Reception/intake/ReceptionIntakeDialog';
import { prefetchIntakeConfig } from '@/pages/Clinic/Reception/intake/useIntakeConfig';
import { refreshLiveProps } from '../live';
import type { ClinicContextProps } from '../types';

interface IntakeApi {
  /** Whether New Case / Repeat Case may be started here (the server's `ClinicNavigation::intake`). */
  available: boolean;
  /** Whether New Case may (registering a patient); Repeat Case needs only `available`. */
  canRegister: boolean;
  /** `specialty`: the module a module-specific page opens New Case for; preselected only when the branch offers it. */
  open: (mode: IntakeMode, patient?: PatientIdentity | null, options?: { specialty?: string | null }) => void;
}

const IntakeContext = createContext<IntakeApi>({ available: false, canRegister: false, open: () => undefined });

/** New Case / Repeat Case from anywhere in the portal: the dashboard's quick actions, a search result. */
export function useIntake(): IntakeApi {
  return useContext(IntakeContext);
}

/**
 * The one canonical intake (`ReceptionIntakeDialog`), hosted once by the
 * portal frame so every entry point opens the same flow. Offered only where
 * the server allows New Case / Repeat Case (`clinic.intake`: the front office,
 * at the branch being worked); the intake's own routes check again.
 */
export function IntakeProvider({ clinic, children }: { clinic: ClinicContextProps; children: ReactNode }) {
  const [state, setState] = useState<{ mode: IntakeMode; patient: PatientIdentity | null; specialty: string | null } | null>(null);
  // Whether this opening of the intake checked anyone in, so closing it refreshes only when something changed.
  const changed = useRef(false);
  const branchId = clinic.active_branch?.id ?? null;
  const available = branchId !== null && (clinic.intake?.available ?? false);
  const canRegister = available && (clinic.intake?.can_register ?? false);

  const open = useCallback(
    (mode: IntakeMode, patient: PatientIdentity | null = null, options: { specialty?: string | null } = {}) => {
      if (available && (mode !== 'new' || canRegister)) {
        changed.current = false;
        setState({ mode, patient, specialty: options.specialty ?? null });
      }
    },
    [available, canRegister],
  );
  const api = useMemo(() => ({ available, canRegister, open }), [available, canRegister, open]);

  // Read New Case / Repeat Case's settings once the page has settled, so the first opening shows its form at once.
  useEffect(() => {
    if (!available || branchId === null) {
      return;
    }
    const timer = window.setTimeout(() => prefetchIntakeConfig(String(branchId)), 1200);

    return () => window.clearTimeout(timer);
  }, [available, branchId]);

  return (
    <IntakeContext.Provider value={api}>
      {children}
      {available && branchId !== null && (
        <ReceptionIntakeDialog
          open={state !== null}
          mode={state?.mode ?? 'repeat'}
          initialPatient={state?.patient ?? null}
          initialSpecialty={state?.specialty ?? null}
          appointmentId={null}
          branchKey={String(branchId)}
          onCheckedIn={() => {
            changed.current = true;
          }}
          onOpenChange={(next) => {
            if (!next) {
              setState(null);
              // The open page re-reads only its live figures (the queue, the counts), and only if someone was checked in.
              if (changed.current) {
                changed.current = false;
                refreshLiveProps();
              }
            }
          }}
        />
      )}
    </IntakeContext.Provider>
  );
}
