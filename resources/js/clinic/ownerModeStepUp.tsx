import { useCallback, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import type { Errors } from '@inertiajs/core';
import { useClinicShared } from './context';
import { OwnerModeDialog } from './shell/OwnerModeDialog';
import { toast } from './ui/Toast';

/** A change the server may ask the owner to confirm before it runs. */
export interface StepUpVisit {
  method: 'post' | 'put' | 'patch' | 'delete';
  url: string;
  data?: Record<string, unknown>;
  onSuccess?: () => void;
  onError?: (errors: Errors) => void;
}

type StepUpReason = 'inactive' | 'stale';

/**
 * The server's step-up contract (`App\Exceptions\OwnerModeRequired`): a 403
 * with `X-Owner-Mode-Required` and a body saying the action was not carried
 * out. Anything else, including a plain 403, is not a step-up.
 */
function stepUpReason(response: { status: number; headers: Record<string, string>; data: unknown }): StepUpReason | null {
  if (response.status !== 403) {
    return null;
  }
  const header = Object.entries(response.headers ?? {}).find(([name]) => name.toLowerCase() === 'x-owner-mode-required')?.[1];
  const body = typeof response.data === 'object' && response.data !== null ? (response.data as { owner_mode?: { required?: boolean; executed?: boolean; reason?: string } }).owner_mode : undefined;
  if ((header !== 'inactive' && header !== 'stale') || body?.required !== true || body.executed !== false || body.reason !== header) {
    return null;
  }

  return header;
}

/**
 * Send an owner action; when the server answers that Owner Mode is needed
 * (off, or its password confirmation too old), ask for the password with the
 * existing Owner Mode dialog and send the same action again, once.
 *
 * The resend is safe because the server's answer states the action was not
 * carried out. A second step-up answer to the resend is not retried again.
 * Every other refusal, validation error or missing page is left to the usual
 * handling. `busy` covers the request and the dialog, so a button stays
 * disabled until the action has finished or been abandoned.
 */
export function useOwnerModeStepUp(): { submit: (visit: StepUpVisit) => void; busy: boolean; dialog: ReactNode } {
  const { clinic, portalLinks } = useClinicShared();
  const [processing, setProcessing] = useState(false);
  const [pending, setPending] = useState<{ visit: StepUpVisit; reason: StepUpReason } | null>(null);
  const pendingRef = useRef<{ visit: StepUpVisit; reason: StepUpReason } | null>(null);

  const send = useCallback((visit: StepUpVisit, isRetry: boolean) => {
    router.visit(visit.url, {
      method: visit.method,
      data: (visit.data ?? {}) as Record<string, never>,
      preserveScroll: true,
      preserveState: true,
      onStart: () => setProcessing(true),
      onFinish: () => setProcessing(false),
      onSuccess: () => visit.onSuccess?.(),
      onError: (errors) => visit.onError?.(errors),
      onHttpException: (response) => {
        const reason = stepUpReason(response);
        if (reason === null) {
          return;
        }
        if (isRetry) {
          toast.error('Owner Mode is still needed', 'Nothing was changed. Enter Owner Mode from your account menu and try again.');

          return false;
        }
        pendingRef.current = { visit, reason };
        setPending(pendingRef.current);

        return false;
      },
    });
  }, []);

  const submit = useCallback((visit: StepUpVisit) => send(visit, false), [send]);

  const dialog =
    clinic && portalLinks ? (
      <OwnerModeDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open) {
            setPending(null);
          }
        }}
        action={portalLinks.ownerMode}
        idleMinutes={clinic.owner_mode.idle_timeout_minutes}
        reason={pending?.reason}
        onVerified={() => {
          const waiting = pendingRef.current;
          pendingRef.current = null;
          setPending(null);
          if (waiting) {
            send(waiting.visit, true);
          }
        }}
      />
    ) : null;

  return { submit, busy: processing || pending !== null, dialog };
}
