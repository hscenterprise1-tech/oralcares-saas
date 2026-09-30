import type { CrmStatusTone } from '@/pages/Company/design';
import type { TrialStatusValue, WindowState } from './trial';

/**
 * How a trial's phase looks and reads.
 *
 * One table, shared by the list and the detail page, so a trial cannot be amber
 * in one place and green in the other. The colours encode urgency rather than
 * sentiment: grace is the loudest thing on the board because it is the phase
 * where the customer is still working and the clock is running out — the days
 * when a decision actually gets made.
 */
export const PHASE_TONE: Record<TrialStatusValue, CrmStatusTone> = {
  in_grace: 'bad',
  ending_soon: 'warn',
  preparing: 'info',
  extended: 'good',
  active: 'good',
  expired: 'neutral',
  quotation_requested: 'blue',
  completed: 'neutral',
  cancelled: 'neutral',
};

/** Phases where the customer still has a working environment. */
export const LIVE_PHASES: TrialStatusValue[] = ['active', 'ending_soon', 'extended', 'in_grace'];

/** Phases nobody needs to act on any more. */
export const CLOSED_PHASES: TrialStatusValue[] = ['completed', 'quotation_requested', 'cancelled', 'expired'];

export const isLive = (phase: TrialStatusValue): boolean => LIVE_PHASES.includes(phase);
export const isClosed = (phase: TrialStatusValue): boolean => CLOSED_PHASES.includes(phase);

/**
 * A date somebody can read.
 *
 * Stored dates arrive as ISO 8601, and nobody should be shown one.
 */
export const writtenDate = (value: string | null | undefined): string => {
  if (!value) return '—';

  const d = new Date(value);

  return Number.isNaN(d.getTime())
    ? String(value)
    : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

/** The same, with the time — for things that happened at a moment. */
export const writtenMoment = (value: string | null | undefined): string => {
  if (!value) return '—';

  const d = new Date(value);

  return Number.isNaN(d.getTime())
    ? String(value)
    : d.toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
};

/** How long ago, in the roughest useful unit. */
export const sinceThen = (value: string | null | undefined): string => {
  if (!value) return 'never';

  const then = new Date(value).getTime();
  if (Number.isNaN(then)) return 'never';

  const days = Math.floor((Date.now() - then) / 86_400_000);

  if (days <= 0) return 'today';
  if (days === 1) return 'yesterday';
  if (days < 30) return `${days} days ago`;

  return writtenDate(value);
};

/**
 * What the remaining days mean, said plainly.
 *
 * The distinction that matters commercially: during grace the customer still
 * has everything and is deciding, so the copy says access rather than trial. A
 * rep who believes access is already gone sells differently, and worse.
 */
export const remainingCopy = (phase: TrialStatusValue, days: number | null, state?: WindowState): string => {
  if (phase === 'preparing') return 'Not started';
  if (isClosed(phase)) return phase === 'expired' ? 'Access ended' : 'Closed';

  /*
   * The deal is won and the window is a paid term now. Counting its length as
   * trial days told a rep "365 days left" on a trial that had already
   * converted — the trial is simply no longer the thing granting access.
   */
  if (state === 'paid') return 'Converted — now on a paid plan';
  if (state === 'lapsed') return 'Access ended';

  if (days === null || days === undefined) return '—';

  if (state === 'grace' || phase === 'in_grace') {
    return days === 0 ? 'Last day of access' : `${days} ${days === 1 ? 'day' : 'days'} of access left`;
  }

  if (days === 0) return 'Ends today';

  return `${days} ${days === 1 ? 'day' : 'days'} left`;
};

/** Bytes as something a person can size up at a glance. */
export const readableBytes = (bytes: number): string => {
  if (!bytes) return '0 MB';
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;

  const mb = bytes / (1024 * 1024);

  return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${Math.round(mb)} MB`;
};

/** Module keys are stored lowercase and snake_cased; nobody should read them that way. */
export const moduleLabel = (key: string): string =>
  ({ emr: 'EMR', xray: 'X-Ray', whatsapp: 'WhatsApp' } as Record<string, string>)[key]
  ?? key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
