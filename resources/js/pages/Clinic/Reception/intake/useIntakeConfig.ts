import { useCallback, useEffect, useState } from 'react';
import { requestJson } from '@/clinic/http';
import type { IntakeConfig } from './types';

/**
 * How long a loaded configuration counts as fresh. It carries today's
 * doctors, their shifts and leave: an opening after this still shows the
 * copy it has at once and re-reads it behind the scenes.
 */
const FRESH_MS = 30_000;

/**
 * The intake configuration, kept for the page's life per branch (and per
 * booking, which carries that booking). One request at a time per key; a
 * failed one is forgotten so it can be retried.
 */
const cache = new Map<string, { at: number; config: IntakeConfig | null; promise: Promise<IntakeConfig> | null }>();

function keyOf(branchKey: string, appointmentId: number | null): string {
  return `${branchKey}:${appointmentId ?? ''}`;
}

function load(branchKey: string, appointmentId: number | null): Promise<IntakeConfig> {
  const key = keyOf(branchKey, appointmentId);
  const entry = cache.get(key);
  if (entry?.promise) {
    return entry.promise;
  }
  const promise = requestJson<IntakeConfig>('GET', route('clinic.reception.intake'), appointmentId ? { query: { appointment: appointmentId } } : undefined)
    .then((config) => {
      cache.set(key, { at: Date.now(), config, promise: null });

      return config;
    })
    .catch((error: unknown) => {
      // Keep any copy already shown; only the failed request is forgotten.
      const previous = cache.get(key);
      cache.set(key, { at: previous?.at ?? 0, config: previous?.config ?? null, promise: null });
      throw error;
    });
  cache.set(key, { at: entry?.at ?? 0, config: entry?.config ?? null, promise });

  return promise;
}

/**
 * Read the configuration ahead of the first opening (the portal frame calls
 * this once it is idle), so New Case and Repeat Case open with their form
 * already there. Failure is silent: the opening loads it again.
 */
export function prefetchIntakeConfig(branchKey: string): void {
  const entry = cache.get(keyOf(branchKey, null));
  if (!entry?.config && !entry?.promise) {
    load(branchKey, null).catch(() => undefined);
  }
}

/**
 * The configuration for a dialog opening: a copy already read is shown at
 * once (stale or not) and refreshed in the background when older than
 * `FRESH_MS`; the form waits only when nothing has been read yet for this
 * branch. A failed first load can be retried.
 */
export function useIntakeConfig(enabled: boolean, branchKey: string, appointmentId: number | null = null) {
  const key = keyOf(branchKey, appointmentId);
  const [config, setConfig] = useState<IntakeConfig | null>(() => cache.get(key)?.config ?? null);
  const [failed, setFailed] = useState(false);

  const fetchNow = useCallback(() => {
    setFailed(false);
    load(branchKey, appointmentId)
      .then((fresh) => setConfig(fresh))
      .catch(() => setFailed(!cache.get(key)?.config));
  }, [branchKey, appointmentId, key]);

  useEffect(() => {
    if (!enabled) {
      return;
    }
    const entry = cache.get(key);
    if (entry?.config) {
      setConfig(entry.config);
      setFailed(false);
    } else {
      setConfig(null);
    }
    if (!entry?.config || Date.now() - entry.at > FRESH_MS) {
      fetchNow();
    }
  }, [enabled, key, fetchNow]);

  return { config, failed, retry: fetchNow };
}
