import { useCallback, useEffect, useState } from 'react';
import { requestJson } from '@/clinic/http';
import type { IntakeConfig } from './types';

/**
 * How long a loaded configuration is reused. It carries today's doctors,
 * their shifts and leave, so each dialog opening after this re-reads it: one
 * small request when reception starts a case, never a poll.
 */
const FRESH_MS = 30_000;

/**
 * The intake configuration for a dialog opening. Openings close together
 * share one request; a later opening (or a booking's own copy, which carries
 * that booking) fetches again. A failed load can be retried.
 */
let cached: { key: string; at: number; promise: Promise<IntakeConfig> } | null = null;

function fetchConfig(key: string, appointmentId: number | null): Promise<IntakeConfig> {
  if (!cached || cached.key !== key || Date.now() - cached.at > FRESH_MS) {
    cached = { key, at: Date.now(), promise: requestJson<IntakeConfig>('GET', route('clinic.reception.intake'), appointmentId ? { query: { appointment: appointmentId } } : undefined) };
  }

  return cached.promise;
}

export function useIntakeConfig(enabled: boolean, branchKey: string, appointmentId: number | null = null) {
  const key = `${branchKey}:${appointmentId ?? ''}`;
  const [loaded, setLoaded] = useState<{ key: string; config: IntakeConfig } | null>(null);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setFailed(false);
    fetchConfig(key, appointmentId)
      .then((config) => setLoaded({ key, config }))
      .catch(() => {
        cached = null;
        setFailed(true);
      });
  }, [key, appointmentId]);

  // Every opening reads a fresh-enough copy; while the dialog stays open the copy it started with is kept.
  useEffect(() => {
    if (enabled) {
      load();
    } else {
      setLoaded(null);
    }
  }, [enabled, load]);

  return { config: loaded?.key === key ? loaded.config : null, failed, retry: load };
}
