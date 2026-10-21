import { useEffect } from 'react';
import { router } from '@inertiajs/react';

/** What `router.reload`'s `onCancelToken` hands back. */
type CancelToken = { cancel: () => void };

/** Marks the portal's own figure refreshes, so they can tell themselves apart from the user's visits. */
const LIVE_HEADER = 'X-Clinic-Live';

interface LiveRegistration {
  refresh: () => void;
  cancel: () => void;
}

/** The live props the current page registered (`useLiveProps`): normally one page, one entry. */
const registrations = new Set<LiveRegistration>();

/**
 * Re-read the current page's live figures now, after an action that changed
 * them. Pages without live figures register none, so nothing is requested.
 */
export function refreshLiveProps(): void {
  registrations.forEach((registration) => registration.refresh());
}

/**
 * Drop any figures request still in flight. Called before an action whose own
 * response carries newer figures, so an older answer cannot land after it.
 */
export function cancelLiveRefresh(): void {
  registrations.forEach((registration) => registration.cancel());
}

/**
 * Declares which of this page's props are live operational figures, so the
 * portal refreshes exactly those — a partial reload through the page's own
 * controller, with its clinic, branch, permission and Owner Mode checks —
 * instead of reloading the whole page.
 *
 * With `pollMs` the figures also follow other people's changes:
 *
 * - one request at a time, the next scheduled only when the last has ended;
 * - paused while the tab is hidden, refreshed as soon as it is shown again;
 * - never while another visit (a navigation, a form, an action) is running —
 *   it is cancelled instead, so its older answer cannot land after the
 *   action's newer one;
 * - stopped for good after an authorization or server failure; an ended
 *   session is redirected to sign-in by the server as for any visit.
 */
export function useLiveProps(only: string[], options: { pollMs?: number } = {}): void {
  const key = only.join(',');
  const pollMs = options.pollMs ?? 0;

  useEffect(() => {
    const props = key.split(',');
    let token: CancelToken | null = null;
    let timer: number | null = null;
    let stopped = false;
    let otherVisits = 0;

    const clearTimer = () => {
      if (timer !== null) {
        window.clearTimeout(timer);
        timer = null;
      }
    };
    const cancel = () => {
      token?.cancel();
      token = null;
    };
    const schedule = () => {
      clearTimer();
      if (pollMs > 0 && !stopped && !document.hidden && otherVisits === 0) {
        timer = window.setTimeout(refresh, pollMs);
      }
    };
    function refresh() {
      clearTimer();
      if (stopped || otherVisits > 0) {
        return;
      }
      cancel();
      router.reload({
        only: props,
        headers: { [LIVE_HEADER]: '1' },
        onCancelToken: (cancelToken) => {
          token = cancelToken;
        },
        // A refused or failed refresh stops the refreshing, quietly: the page keeps what it shows.
        onHttpException: () => {
          stopped = true;

          return false;
        },
        onNetworkError: () => false,
        onFinish: () => {
          token = null;
          schedule();
        },
      });
    }

    const isOwn = (headers: Record<string, string> | undefined) => headers?.[LIVE_HEADER] === '1';
    const offStart = router.on('start', (event) => {
      if (!isOwn(event.detail.visit.headers)) {
        otherVisits += 1;
        cancel();
        clearTimer();
      }
    });
    const offFinish = router.on('finish', (event) => {
      if (!isOwn(event.detail.visit.headers)) {
        otherVisits = Math.max(0, otherVisits - 1);
        schedule();
      }
    });
    const onVisibility = () => {
      if (document.hidden) {
        clearTimer();
        cancel();
      } else if (pollMs > 0) {
        refresh();
      }
    };
    document.addEventListener('visibilitychange', onVisibility);

    const registration: LiveRegistration = { refresh, cancel };
    registrations.add(registration);
    schedule();

    return () => {
      stopped = true;
      clearTimer();
      cancel();
      offStart();
      offFinish();
      document.removeEventListener('visibilitychange', onVisibility);
      registrations.delete(registration);
    };
  }, [key, pollMs]);
}
