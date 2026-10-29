import { useState } from 'react';

/**
 * The new Clinic Portal runs beside the current one until it is ready for a
 * controlled switch-over. A member opts in with `?ui=next` (remembered in
 * this browser) and goes back with `?ui=classic` or the frame's "Classic
 * view". Only pages that have a new screen look at this; the server, its
 * routes and its data are the same either way.
 */
const KEY = 'oralcares.clinic-ui';

function readPreference(): boolean {
  if (typeof window === 'undefined') {
    return false;
  }
  const asked = new URLSearchParams(window.location.search).get('ui');
  try {
    if (asked === 'next') {
      window.localStorage.setItem(KEY, 'next');
    } else if (asked === 'classic') {
      window.localStorage.removeItem(KEY);
    }

    return window.localStorage.getItem(KEY) === 'next';
  } catch {
    // Storage blocked (private window): the address alone decides.
    return asked === 'next';
  }
}

/** Whether this page should draw its new screen. Read once per page visit. */
export function useNextUi(): boolean {
  const [next] = useState(readPreference);

  return next;
}

/** Back to the current Clinic Portal on this browser. */
export function leaveNextUi(): void {
  try {
    window.localStorage.removeItem(KEY);
  } catch {
    // Nothing stored: the reload below is enough.
  }
  const url = new URL(window.location.href);
  url.searchParams.delete('ui');
  window.location.assign(url.toString());
}
