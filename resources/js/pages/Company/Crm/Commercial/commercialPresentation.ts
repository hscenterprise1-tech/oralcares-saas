import type { CrmStatusTone } from '@/pages/Company/design';
import type { QuotationStatusValue, ReconciliationState } from './commercial';

/**
 * How a commercial record looks and reads.
 *
 * One table shared by the list and the detail page, so a quotation cannot be
 * amber in one place and green in the other.
 */
export const STATUS_TONE: Record<QuotationStatusValue, CrmStatusTone> = {
  negotiation: 'warn',
  sent: 'info',
  approved: 'good',
  draft: 'neutral',
  rejected: 'bad',
  expired: 'neutral',
  converted: 'good',
};

/**
 * Reconciliation, coloured by whether somebody has to do something.
 *
 * `overpaid` and `mismatch` are the two states where the record itself is
 * wrong rather than merely unfinished, so they are the only ones in red.
 */
export const RECONCILIATION_TONE: Record<ReconciliationState, CrmStatusTone> = {
  paid: 'good',
  partially_paid: 'warn',
  unpaid: 'neutral',
  overpaid: 'bad',
  mismatch: 'bad',
};

export const RECONCILIATION_LABEL: Record<ReconciliationState, string> = {
  paid: 'Settled',
  partially_paid: 'Part paid',
  unpaid: 'Unpaid',
  overpaid: 'Overpaid',
  mismatch: 'Totals do not add up',
};

/**
 * What the state means, in the words a person needs.
 *
 * Written out rather than left to a coloured pill because these two states are
 * the ones somebody has to act on, and a pill alone does not say what to do.
 */
export const RECONCILIATION_HELP: Record<ReconciliationState, string> = {
  paid: 'Money received matches the agreement.',
  partially_paid: 'Some money has arrived; the rest is still outstanding.',
  unpaid: 'Nothing has been received against this agreement yet.',
  overpaid: 'More money arrived than this agreement asks for. Someone needs to look at it.',
  mismatch: 'The document’s own subtotal, tax and discount do not add up to its total, so the amount owed cannot be trusted.',
};

/** These two mean the record is wrong, not merely unfinished. */
export const needsAttention = (state: ReconciliationState): boolean =>
  state === 'overpaid' || state === 'mismatch';

/** Stored dates arrive as ISO 8601; nobody should be shown one. */
export const writtenDate = (value: string | null | undefined): string => {
  if (!value) return '—';

  const d = new Date(value);

  return Number.isNaN(d.getTime())
    ? String(value)
    : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

export const writtenMoment = (value: string | null | undefined): string => {
  if (!value) return '—';

  const d = new Date(value);

  return Number.isNaN(d.getTime())
    ? String(value)
    : d.toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
};

/** Percentage of an agreement that has actually been settled, capped for the bar. */
export const settledPercent = (settled: number, expected: number): number => {
  if (expected <= 0) return 0;

  return Math.min(100, Math.round((settled / expected) * 100));
};
