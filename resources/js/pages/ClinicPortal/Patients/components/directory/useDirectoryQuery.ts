import { useCallback, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import type { DirectoryFilters } from '../types';

/** The props a directory visit reloads; the rest of the page stays as it is. */
const RELOADED = ['patients', 'filters'];
const SEARCH_DELAY_MS = 300;
const QUERY_KEYS = ['q', 'specialty', 'branch_id', 'practitioner_id', 'active_care'] as const;

export const NO_FILTERS = { q: '', status: '', specialty: '', branch_id: '', practitioner_id: '', active_care: '' } as const;

/**
 * Search, filters, views and pages for the directory, all run on the server
 * (`PatientController@index`). Every change builds on the latest filters,
 * including a search still waiting to be sent, so switching a view mid-search
 * keeps both. A newer visit cancels the one in flight (Inertia), and the box
 * is never overwritten while the member is still typing.
 */
export function useDirectoryQuery(filters: DirectoryFilters, indexUrl: string) {
  const [query, setQuery] = useState(filters.q);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const latest = useRef(filters);
  const timer = useRef<number | null>(null);
  const pending = useRef<string | null>(null);
  const lastParams = useRef<Record<string, string>>({});

  useEffect(() => {
    latest.current = filters;
  }, [filters]);
  useEffect(() => {
    if (timer.current === null) {
      setQuery(filters.q);
    }
  }, [filters.q]);
  useEffect(() => () => (timer.current !== null ? window.clearTimeout(timer.current) : undefined), []);

  const send = useCallback(
    (params: Record<string, string>) => {
      lastParams.current = params;
      setFailed(false);
      router.get(indexUrl, params, {
        only: RELOADED,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
        onHttpException: () => {
          setFailed(true);

          return false;
        },
        onNetworkError: () => {
          setFailed(true);

          return false;
        },
      });
    },
    [indexUrl],
  );

  const paramsFor = (next: DirectoryFilters, cursor?: string | null): Record<string, string> => {
    const params: Record<string, string> = { view: next.view };
    QUERY_KEYS.forEach((key) => {
      if (next[key]) {
        params[key] = next[key];
      }
    });
    if (cursor) {
      params.cursor = cursor;
    }

    return params;
  };

  const takePending = (): string | null => {
    if (timer.current !== null) {
      window.clearTimeout(timer.current);
      timer.current = null;
    }
    const typed = pending.current;
    pending.current = null;

    return typed;
  };

  const change = (patch: Partial<DirectoryFilters>) => {
    const typed = takePending();
    const next = { ...latest.current, ...(typed !== null ? { q: typed } : {}), ...patch };
    latest.current = next;
    send(paramsFor(next));
  };

  const search = (value: string) => {
    setQuery(value);
    if (timer.current !== null) {
      window.clearTimeout(timer.current);
    }
    pending.current = value.trim();
    timer.current = window.setTimeout(() => change({}), SEARCH_DELAY_MS);
  };

  const page = (cursor: string | null) => {
    if (!cursor) {
      return;
    }
    // A search still waiting starts again from the first page.
    if (pending.current !== null) {
      change({});

      return;
    }
    send(paramsFor(latest.current, cursor));
  };

  const clearAll = () => {
    setQuery('');
    change({ ...NO_FILTERS });
  };

  return { query, loading, failed, search, change, page, clearAll, retry: () => send(lastParams.current) };
}
