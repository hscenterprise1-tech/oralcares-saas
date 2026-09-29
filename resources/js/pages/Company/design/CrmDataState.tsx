import React from 'react';
import { AlertTriangle, Inbox, RefreshCw, ShieldAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from './CrmFormPrimitives';
import { Skeleton } from '@/ovs/feedback/skeleton';

/**
 * The single loading / empty / error / forbidden surface for the CRM.
 *
 * Before this, no CRM page had a skeleton state and only three had an empty
 * state, so a slow list looked broken and an empty one looked identical to a
 * failed one. Every CRM list and panel routes through here, which is what makes
 * those three situations distinguishable at a glance and consistent between
 * pages.
 *
 * Colours come from OVS tokens rather than raw palette classes, so the CRM
 * tracks the design system rather than drifting from it.
 */

export type CrmStateKind = 'loading' | 'empty' | 'error' | 'forbidden';

export type CrmSkeletonShape = 'table' | 'cards' | 'list';

export interface CrmDataStateProps {
  kind: CrmStateKind;
  /** Skeleton silhouette to show while loading. */
  shape?: CrmSkeletonShape;
  rows?: number;
  icon?: LucideIcon;
  title?: string;
  description?: string;
  action?: React.ReactNode;
  onRetry?: () => void;
  compact?: boolean;
  className?: string;
}

function TableSkeleton({ rows }: { rows: number }) {
  return (
    <div className="space-y-px" aria-hidden="true">
      {Array.from({ length: rows }).map((_, index) => (
        <div
          key={index}
          className="flex h-14 items-center gap-4 border-b border-white/70 px-4 last:border-b-0"
        >
          <Skeleton className="h-8 w-8 shrink-0 rounded-[14px]" />
          <div className="min-w-0 flex-1 space-y-1.5">
            <Skeleton className="h-3.5 w-2/5" />
            <Skeleton className="h-2.5 w-1/4" />
          </div>
          <Skeleton className="hidden h-5 w-24 rounded-full sm:block" />
          <Skeleton className="hidden h-3.5 w-20 md:block" />
          <Skeleton className="hidden h-3.5 w-16 lg:block" />
        </div>
      ))}
    </div>
  );
}

function CardSkeleton({ rows }: { rows: number }) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-hidden="true">
      {Array.from({ length: rows }).map((_, index) => (
        <div key={index} className="rounded-[18px] bg-ovs-crm-surface-inset p-4">
          <Skeleton className="h-2.5 w-20" />
          <Skeleton className="mt-3 h-6 w-28" />
          <Skeleton className="mt-2 h-2.5 w-24" />
        </div>
      ))}
    </div>
  );
}

function ListSkeleton({ rows }: { rows: number }) {
  return (
    <div className="space-y-2" aria-hidden="true">
      {Array.from({ length: rows }).map((_, index) => (
        <div
          key={index}
          className="flex items-center gap-3 rounded-[18px] bg-ovs-crm-surface-inset px-3 py-2.5"
        >
          <div className="min-w-0 flex-1 space-y-1.5">
            <Skeleton className="h-3.5 w-1/2" />
            <Skeleton className="h-2.5 w-1/3" />
          </div>
          <Skeleton className="h-5 w-16 rounded-full" />
        </div>
      ))}
    </div>
  );
}

export function CrmDataState({
  kind,
  shape = 'table',
  rows = 5,
  icon: Icon = Inbox,
  title,
  description,
  action,
  onRetry,
  compact = false,
  className,
}: CrmDataStateProps) {
  if (kind === 'loading') {
    return (
      <div
        className={cn('w-full', className)}
        role="status"
        aria-live="polite"
        aria-busy="true"
      >
        <span className="sr-only">Loading</span>
        {shape === 'cards' && <CardSkeleton rows={rows} />}
        {shape === 'list' && <ListSkeleton rows={rows} />}
        {shape === 'table' && <TableSkeleton rows={rows} />}
      </div>
    );
  }

  if (kind === 'error') {
    return (
      <div
        className={cn(
          'flex flex-col items-center gap-3 px-6 py-10 text-center',
          className
        )}
        role="alert"
      >
        <div className="flex h-11 w-11 items-center justify-center rounded-full bg-ovs-crm-accent-coral-soft text-ovs-crm-accent-coral-text">
          <AlertTriangle className="h-5 w-5" aria-hidden="true" />
        </div>
        <div className="space-y-1">
          <h3 className="text-[15px] font-semibold text-ovs-crm-text-primary">
            {title ?? 'This section could not be loaded'}
          </h3>
          <p className="mx-auto max-w-sm text-[13px] leading-relaxed text-ovs-crm-text-muted">
            {description ?? 'The request did not complete. Your data has not been changed — you can try again.'}
          </p>
        </div>
        {onRetry && (
          <Button size="sm" variant="secondary" onClick={onRetry} className="gap-1.5">
            <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
            Try again
          </Button>
        )}
        {action}
      </div>
    );
  }

  if (kind === 'forbidden') {
    return (
      <div className={cn('flex flex-col items-center gap-3 px-6 py-10 text-center', className)}>
        <div className="flex h-11 w-11 items-center justify-center rounded-full bg-ovs-crm-accent-yellow-soft text-ovs-crm-accent-yellow-text">
          <ShieldAlert className="h-5 w-5" aria-hidden="true" />
        </div>
        <div className="space-y-1">
          <h3 className="text-[15px] font-semibold text-ovs-crm-text-primary">
            {title ?? 'You do not have access to this'}
          </h3>
          <p className="mx-auto max-w-sm text-[13px] leading-relaxed text-ovs-crm-text-muted">
            {description ?? 'This view is limited to sales management. Ask an administrator if you need access.'}
          </p>
        </div>
      </div>
    );
  }

  return (
    <div
      className={cn(
        'flex flex-col items-center text-center',
        compact ? 'gap-2 px-4 py-6' : 'gap-3 px-6 py-12',
        className
      )}
    >
      <div className="flex h-11 w-11 items-center justify-center rounded-full bg-ovs-crm-surface-inset text-ovs-crm-text-secondary">
        <Icon className="h-5 w-5" aria-hidden="true" />
      </div>
      <div className="space-y-1">
        <h3 className="text-[15px] font-semibold text-ovs-crm-text-primary">{title ?? 'Nothing here yet'}</h3>
        {description && (
          <p className="mx-auto max-w-sm text-[13px] leading-relaxed text-ovs-crm-text-muted">{description}</p>
        )}
      </div>
      {action && <div className="pt-1">{action}</div>}
    </div>
  );
}
