import type { ReactNode } from 'react';
import { AlertTriangle, Inbox, Lock, PackageX, SearchX, WifiOff, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Skeleton, Spinner } from './Spinner';

/**
 * The portal's global states, one look for each: nothing yet, loading,
 * failed, not allowed, not in the plan, not found, offline.
 *
 * Every state says what happened and, where there is one, what to do next.
 * None of them shows a raw server message or a stack trace.
 */
export type StateKind = 'empty' | 'error' | 'forbidden' | 'plan_unavailable' | 'not_found' | 'offline';

const PRESETS: Record<StateKind, { icon: LucideIcon; title: string; tone: string }> = {
  empty: { icon: Inbox, title: 'Nothing here yet', tone: 'bg-clinic-inset text-clinic-secondary' },
  error: { icon: AlertTriangle, title: 'Something went wrong', tone: 'bg-clinic-danger-soft text-clinic-danger' },
  forbidden: { icon: Lock, title: 'You do not have access', tone: 'bg-clinic-inset text-clinic-ink' },
  plan_unavailable: {
    icon: PackageX,
    title: 'Not included in your plan',
    tone: 'bg-clinic-warning-soft text-clinic-warning',
  },
  not_found: { icon: SearchX, title: 'Not found', tone: 'bg-clinic-inset text-clinic-secondary' },
  offline: { icon: WifiOff, title: 'You are offline', tone: 'bg-clinic-warning-soft text-clinic-warning' },
};

export function StatePanel({
  kind,
  title,
  description,
  action,
  compact = false,
  className,
}: {
  kind: StateKind;
  title?: string;
  description?: ReactNode;
  action?: ReactNode;
  compact?: boolean;
  className?: string;
}) {
  const preset = PRESETS[kind];
  const Icon = preset.icon;
  const isAlert = kind === 'error' || kind === 'offline';

  return (
    <div
      role={isAlert ? 'alert' : 'status'}
      className={cn(
        'flex flex-col items-center text-center',
        compact ? 'gap-2 px-4 py-6' : 'gap-3 px-6 py-12',
        className,
      )}
    >
      <span
        className={cn('flex items-center justify-center rounded-2xl', compact ? 'h-10 w-10' : 'h-12 w-12', preset.tone)}
      >
        <Icon className={compact ? 'h-5 w-5' : 'h-6 w-6'} aria-hidden="true" />
      </span>
      <div className="max-w-sm">
        <p className={cn('font-semibold text-clinic-ink', compact ? 'text-[13.5px]' : 'text-[17px]')}>
          {title ?? preset.title}
        </p>
        {description && <p className="mt-1 text-[13px] leading-relaxed text-clinic-secondary">{description}</p>}
      </div>
      {action && <div className="mt-1 flex flex-wrap justify-center gap-2">{action}</div>}
    </div>
  );
}

/** A working state that says what is loading, for screen readers as well. */
export function LoadingPanel({
  label = 'Loading…',
  rows = 0,
  className,
}: {
  label?: string;
  rows?: number;
  className?: string;
}) {
  if (rows > 0) {
    return (
      <div role="status" aria-label={label} className={cn('space-y-2.5 p-4', className)}>
        {Array.from({ length: rows }, (_, index) => (
          <Skeleton key={index} className="h-10 w-full" />
        ))}
      </div>
    );
  }

  return (
    <div
      role="status"
      className={cn('flex items-center justify-center gap-2 px-4 py-8 text-[13px] text-clinic-secondary', className)}
    >
      <Spinner />
      <span>{label}</span>
    </div>
  );
}
