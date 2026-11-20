import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '@/clinic/http';

/*
 * Patient 360's data: the page arrives carrying each section's first page
 * (`preloaded`); anything else is fetched once, in parallel, as soon as the
 * page opens, and kept here. Opening a zone then draws from
 * memory at once — no skeletons, no wait on click. A page change or an
 * action refreshes one endpoint in the background; the previous data stays
 * on screen until the new answer arrives. Endpoints, payloads and the
 * server's permission checks are unchanged.
 */

type Entry = { data: unknown; error: ClinicHttpErrorKind | null; loading: boolean };
type Store = {
  get: (key: string) => Entry | undefined;
  load: (url: string, query?: Record<string, string | number>) => Promise<void>;
  version: number;
};

const Context = createContext<Store | null>(null);

function keyOf(url: string, query?: Record<string, string | number>): string {
  const params = new URLSearchParams(Object.entries(query ?? {}).map(([key, value]) => [key, String(value)]));

  return params.toString() ? `${url}?${params.toString()}` : url;
}

/** `initial`: data the page already carries, by endpoint URL — drawn at once, never fetched again on open. */
export function Patient360DataProvider({ prefetch, initial = {}, children }: { prefetch: string[]; initial?: Record<string, unknown>; children: ReactNode }) {
  const [map] = useState(() => new Map<string, Entry>(Object.entries(initial).map(([url, data]) => [url, { data, error: null, loading: false }])));
  const [version, setVersion] = useState(0);
  const bump = useCallback(() => setVersion((value) => value + 1), []);
  // After an action the page comes back with fresh first pages: take them in.
  const seeded = useRef(initial);
  useEffect(() => {
    if (seeded.current === initial) {
      return;
    }
    seeded.current = initial;
    Object.entries(initial).forEach(([url, data]) => map.set(url, { data, error: null, loading: false }));
    bump();
  }, [initial, bump, map]);

  const load = useCallback(
    async (url: string, query?: Record<string, string | number>) => {
      const key = keyOf(url, query);
      const previous = map.get(key);
      map.set(key, { data: previous?.data ?? null, error: null, loading: true });
      bump();
      try {
        const data = await requestJson<unknown>('GET', url, { query: query ?? {} });
        map.set(key, { data, error: null, loading: false });
      } catch (failure) {
        const kind = errorKind(failure);
        map.set(key, { data: previous?.data ?? null, error: kind === 'aborted' ? null : kind, loading: false });
      }
      bump();
    },
    [bump, map],
  );

  // Everything this member may open, fetched together at once.
  const list = prefetch.join('|');
  useEffect(() => {
    list.split('|').filter(Boolean).forEach((url) => {
      if (!map.has(url)) {
        void load(url);
      }
    });
  }, [list, load, map]);

  const store = useMemo<Store>(() => ({ get: (key) => map.get(key), load, version }), [load, version, map]);

  return <Context.Provider value={store}>{children}</Context.Provider>;
}

/** One endpoint's data from the store (fetched now if it was not prefetched). */
export function useEndpoint<T>(url: string | null | undefined, query?: Record<string, string | number>) {
  const store = useContext(Context);
  const key = url ? keyOf(url, query) : '';
  const entry = url ? store?.get(key) : undefined;
  const queryKey = JSON.stringify(query ?? {});
  useEffect(() => {
    if (url && store && !store.get(key)) {
      void store.load(url, query);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [url, queryKey, store?.version === undefined]);
  const reload = useCallback(() => (url && store ? store.load(url, query) : Promise.resolve()), [url, queryKey, store]); // eslint-disable-line react-hooks/exhaustive-deps
  // A new page or filter keeps the last answer on screen until its own arrives.
  const last = useRef<{ url: string | null | undefined; data: unknown }>({ url, data: null });
  if (last.current.url !== url) {
    last.current = { url, data: null };
  }
  if (entry?.data) {
    last.current.data = entry.data;
  }

  return { data: (entry?.data ?? last.current.data ?? null) as T | null, error: entry?.error ?? null, loading: entry?.loading ?? (Boolean(url) && !entry), reload };
}

export { ERROR_COPY };
