import type { ReactNode } from 'react';
import { RotateCcw } from 'lucide-react';
import { Button } from './Button';
import { LoadingPanel, StatePanel } from './StatePanel';

/*
 * The named states every list, panel and page uses, so "nothing yet",
 * "no matches", "failed", "not allowed" and "archived" look and read the
 * same everywhere. Each says what happened and, where there is one, the
 * next step. None shows a raw server message.
 */

export function EmptyState({
  title,
  description,
  action,
  filtered = false,
  onClearFilters,
  compact,
}: {
  title?: string;
  description?: ReactNode;
  action?: ReactNode;
  /** Empty because of the search/filters, not because there is nothing. */
  filtered?: boolean;
  onClearFilters?: () => void;
  compact?: boolean;
}) {
  return (
    <StatePanel
      kind={filtered ? 'filtered' : 'empty'}
      title={title}
      description={description ?? (filtered ? 'Nothing matches these filters.' : undefined)}
      compact={compact}
      action={
        filtered && onClearFilters ? (
          <Button size="sm" onClick={onClearFilters}>
            Clear filters
          </Button>
        ) : (
          action
        )
      }
    />
  );
}

export function LoadingState({ label, rows, className }: { label?: string; rows?: number; className?: string }) {
  return <LoadingPanel label={label} rows={rows} className={className} />;
}

export function ErrorState({
  title,
  description = 'It could not be loaded. Check your connection and try again.',
  onRetry,
  compact,
}: {
  title?: string;
  description?: ReactNode;
  onRetry?: () => void;
  compact?: boolean;
}) {
  return (
    <StatePanel
      kind="error"
      title={title}
      description={description}
      compact={compact}
      action={
        onRetry ? (
          <Button size="sm" icon={RotateCcw} onClick={onRetry}>
            Try again
          </Button>
        ) : undefined
      }
    />
  );
}

export function PermissionState({
  title,
  description = 'Your role does not include this. Ask the clinic owner or an administrator if you need it.',
  compact,
}: {
  title?: string;
  description?: ReactNode;
  compact?: boolean;
}) {
  return <StatePanel kind="forbidden" title={title} description={description} compact={compact} />;
}

export function ArchivedState({
  title,
  description = 'It is kept for the record and can be read, but not changed.',
  action,
  compact,
}: {
  title?: string;
  description?: ReactNode;
  action?: ReactNode;
  compact?: boolean;
}) {
  return <StatePanel kind="archived" title={title} description={description} action={action} compact={compact} />;
}
