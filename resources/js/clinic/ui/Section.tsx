import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { Card } from './Card';
import { SkeletonLines } from './Skeleton';

/**
 * A titled card that owns its own state: one section of a page loading,
 * failing or coming back empty never blanks the page around it.
 *
 * `loading` shows skeleton lines and marks the region busy; `state` replaces
 * the body (an EmptyState, ErrorState or PermissionState from ./states).
 */
export function Section({
  title,
  description,
  actions,
  loading = false,
  state,
  flush = false,
  className,
  children,
}: {
  title: ReactNode;
  description?: ReactNode;
  /** Up to two buttons or links for this section only. */
  actions?: ReactNode;
  loading?: boolean;
  state?: ReactNode;
  /** No body padding, for a table or a list that runs edge to edge. */
  flush?: boolean;
  className?: string;
  children?: ReactNode;
}) {
  const headingId = typeof title === 'string' ? `section-${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}` : undefined;

  return (
    <Card className={cn('flex min-w-0 flex-col', className)} aria-labelledby={headingId} aria-busy={loading || undefined}>
      <header className="flex flex-wrap items-start justify-between gap-3 px-5 pt-4">
        <div className="min-w-0">
          <h2 id={headingId} className="text-[15px] font-semibold tracking-tight text-cx-text">
            {title}
          </h2>
          {description && <p className="mt-0.5 text-[12.5px] text-cx-text-3">{description}</p>}
        </div>
        {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
      </header>
      <div className={cn('min-w-0 flex-1', flush ? 'mt-3' : 'px-5 pb-5 pt-3')}>
        {loading ? <SkeletonLines lines={4} className={flush ? 'px-5 pb-5' : undefined} /> : (state ?? children)}
      </div>
    </Card>
  );
}
