/**
 * The portal's small JSON client, for the few calls that are not page visits
 * (branch search, notifications).
 *
 * Every failure is turned into one typed `ClinicHttpError`, so a component
 * decides what to show from a `kind`, never from a raw status or a message
 * the server did not write for users. A 401 or 419 means the session is
 * gone: the page is reloaded and the server's own middleware sends the user
 * to sign in, in path and domain mode alike.
 */

export type ClinicHttpErrorKind =
  | 'unauthenticated'
  | 'forbidden'
  | 'not_found'
  | 'conflict'
  | 'validation'
  | 'rate_limited'
  | 'session_expired'
  | 'server'
  | 'network'
  | 'aborted';

export class ClinicHttpError extends Error {
  constructor(
    public readonly kind: ClinicHttpErrorKind,
    public readonly status: number | null,
  ) {
    super(kind);
    this.name = 'ClinicHttpError';
  }
}

const KIND_BY_STATUS: Record<number, ClinicHttpErrorKind> = {
  401: 'unauthenticated',
  403: 'forbidden',
  404: 'not_found',
  409: 'conflict',
  419: 'session_expired',
  422: 'validation',
  429: 'rate_limited',
};

export function kindForStatus(status: number): ClinicHttpErrorKind {
  return KIND_BY_STATUS[status] ?? 'server';
}

/** A sentence a user can act on, for each failure. Never the server's text. */
export const ERROR_COPY: Record<ClinicHttpErrorKind, string> = {
  unauthenticated: 'Your session has ended. Sign in again to continue.',
  forbidden: 'You do not have access to this.',
  not_found: 'This could not be found. It may have been removed.',
  conflict: 'This changed while you were working. Refresh and try again.',
  validation: 'Some details need attention.',
  rate_limited: 'Too many requests. Wait a moment and try again.',
  session_expired: 'Your session has expired. Sign in again to continue.',
  server: 'Something went wrong on our side. Try again in a moment.',
  network: 'You appear to be offline. Check your connection and try again.',
  aborted: 'The request was cancelled.',
};

function xsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

  return match ? decodeURIComponent(match[1]) : null;
}

type Query = Record<string, string | number | null | undefined>;

function withQuery(url: string, query?: Query): string {
  if (!query) {
    return url;
  }

  const target = new URL(url, window.location.origin);
  Object.entries(query).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      target.searchParams.set(key, String(value));
    }
  });

  return target.toString();
}

export async function requestJson<T>(
  method: 'GET' | 'POST' | 'PATCH',
  url: string,
  options: { query?: Query; body?: Record<string, unknown>; signal?: AbortSignal } = {},
): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };
  const token = xsrfToken();
  if (token) {
    headers['X-XSRF-TOKEN'] = token;
  }
  if (options.body) {
    headers['Content-Type'] = 'application/json';
  }

  let response: Response;
  try {
    response = await fetch(withQuery(url, options.query), {
      method,
      headers,
      credentials: 'same-origin',
      body: options.body ? JSON.stringify(options.body) : undefined,
      signal: options.signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw new ClinicHttpError('aborted', null);
    }
    throw new ClinicHttpError('network', null);
  }

  if (!response.ok) {
    const kind = kindForStatus(response.status);
    if (kind === 'unauthenticated' || kind === 'session_expired') {
      window.location.reload();
    }
    throw new ClinicHttpError(kind, response.status);
  }

  return (await response.json()) as T;
}

export function errorKind(error: unknown): ClinicHttpErrorKind {
  return error instanceof ClinicHttpError ? error.kind : 'server';
}
