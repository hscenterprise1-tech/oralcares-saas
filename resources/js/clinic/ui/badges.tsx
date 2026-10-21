import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { CONTEXTS, contextForSpecialty, humanize, statusTone, toneFor, type ContextKey } from './visuals';

const BASE = 'inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold';

/**
 * A state, in its state colour: green done/paid, amber waiting/due, red
 * overdue/cancelled, blue scheduled, violet in progress. The word is always
 * shown — colour is never the only signal.
 */
export function StatusBadge({ status, label, className }: { status: string; label?: ReactNode; className?: string }) {
  const tone = toneFor(statusTone(status));

  return (
    <span className={cn(BASE, tone.chip, className)}>
      <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', tone.fill)} aria-hidden="true" />
      <span className="truncate">{label ?? humanize(status)}</span>
    </span>
  );
}

/** Which part of the product something belongs to, in that area's one colour. */
export function ContextBadge({ context, label, className }: { context: ContextKey; label?: ReactNode; className?: string }) {
  const tone = CONTEXTS[context];

  return <span className={cn(BASE, tone.chip, className)}>{label ?? tone.label}</span>;
}

/** A specialty registry key as its area: dental (cyan), physiotherapy (indigo), other clinical (emerald). */
export function SpecialtyBadge({ specialty, label, className }: { specialty: string; label?: ReactNode; className?: string }) {
  return <ContextBadge context={contextForSpecialty(specialty)} label={label ?? humanize(specialty)} className={className} />;
}

const PRIORITIES = {
  urgent: 'bg-rose-50 text-rose-700',
  high: 'bg-amber-50 text-amber-800',
  normal: 'bg-slate-100 text-slate-700',
  low: 'bg-slate-50 text-slate-600',
} as const;

export function PriorityBadge({ priority, className }: { priority: string; className?: string }) {
  const classes = PRIORITIES[priority as keyof typeof PRIORITIES] ?? PRIORITIES.normal;

  return <span className={cn(BASE, classes, className)}>{humanize(priority)}</span>;
}

/** A staff role. The owner is set apart; other roles are neutral. */
export function RoleBadge({ role, className }: { role: string; className?: string }) {
  const owner = /owner/i.test(role);

  return (
    <span className={cn(BASE, owner ? 'bg-clinic-ink text-white' : 'border border-clinic-border bg-clinic-surface text-clinic-ink', className)}>
      {role}
    </span>
  );
}
