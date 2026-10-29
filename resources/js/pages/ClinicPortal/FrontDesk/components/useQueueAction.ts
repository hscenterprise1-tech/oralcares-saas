import { useCallback, useState } from 'react';
import { router } from '@inertiajs/react';
import { ERROR_COPY, kindForStatus } from '@/clinic/http';

/** What a queue action is doing for one row, so only that row shows it running. */
export interface QueueActionState {
  busy: string | null;
  error: { rowId: number; message: string } | null;
}

/**
 * Sends one front-desk queue action through the existing reception routes
 * (`PATCH clinic.reception.queue.*`). The server answers by sending the desk
 * back with a flash message, so the queue on screen is always the server's
 * after the action; nothing here changes a row ahead of that answer. A
 * refusal (the doctor got there first, the Queue module is off) is kept
 * against the row it happened on.
 */
export function useQueueAction() {
  const [state, setState] = useState<QueueActionState>({ busy: null, error: null });

  const run = useCallback(
    (rowId: number, key: string, url: string, data: Record<string, string | number>, onDone?: (ok: boolean) => void) => {
      setState({ busy: `${rowId}:${key}`, error: null });
      router.patch(url, data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          setState({ busy: null, error: null });
          onDone?.(true);
        },
        onError: (errors) => {
          const message = Object.values(errors)[0] ?? ERROR_COPY.validation;
          setState({ busy: null, error: { rowId, message: String(message) } });
          onDone?.(false);
        },
        onHttpException: (response) => {
          const status = (response as { status?: number } | undefined)?.status ?? 500;
          setState({ busy: null, error: { rowId, message: ERROR_COPY[kindForStatus(status)] } });
          onDone?.(false);

          return false;
        },
        onNetworkError: () => {
          setState({ busy: null, error: { rowId, message: `${ERROR_COPY.network} Nothing was changed.` } });
          onDone?.(false);

          return false;
        },
      });
    },
    [],
  );

  const dismiss = useCallback(() => setState((current) => ({ ...current, error: null })), []);

  return { ...state, run, dismiss };
}
