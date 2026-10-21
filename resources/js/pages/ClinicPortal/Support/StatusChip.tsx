import { cn } from '@/lib/utils';
import type { SupportStatus } from './types';

const TONE: Record<SupportStatus['value'], string> = {
  open: 'bg-cx-blue-bg text-cx-blue-ink',
  pending: 'bg-cx-amber-bg text-cx-amber-ink',
  resolved: 'bg-cx-green-bg text-cx-green-ink',
  closed: 'bg-cx-slate-bg text-cx-slate-ink',
};

/** A request's status in the support desk's own words. */
export function StatusChip({ status, className }: { status: SupportStatus | null; className?: string }) {
  if (!status) {
    return null;
  }

  return <span className={cn('inline-flex shrink-0 whitespace-nowrap rounded-md px-2 py-0.5 text-[12px] font-semibold', TONE[status.value], className)}>{status.label}</span>;
}

export function formatWhen(value: string | null): string {
  return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '';
}
