import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

const STEP = cn(
  'inline-flex min-h-9 items-center gap-1 rounded-full border border-cx-line bg-cx-surface px-3.5 text-[12.5px] font-semibold text-cx-text',
  'hover:bg-cx-nav-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan [@media(pointer:coarse)]:min-h-11',
);
const OFF = 'pointer-events-none opacity-45';

/**
 * Previous / next over a server-paged list. Works for both page numbers and
 * cursors: pass the URLs the server gave (`prev_page_url`, `next_cursor`
 * links); a missing one is shown disabled, never guessed.
 */
export function Pagination({
  prevHref,
  nextHref,
  summary,
  only,
  className,
}: {
  prevHref?: string | null;
  nextHref?: string | null;
  /** "Showing 21–40 of 312", as the server can say it. */
  summary?: string | null;
  /** Inertia partial reload keys, so paging refetches just the list. */
  only?: string[];
  className?: string;
}) {
  if (!prevHref && !nextHref && !summary) {
    return null;
  }

  return (
    <nav aria-label="Pagination" className={cn('flex flex-wrap items-center justify-between gap-3 px-4 py-3', className)}>
      <p className="text-[12.5px] text-cx-text-3" aria-live="polite">
        {summary}
      </p>
      <div className="flex items-center gap-2">
        {prevHref ? (
          <Link href={prevHref} only={only} preserveScroll preserveState className={STEP} rel="prev">
            <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Previous
          </Link>
        ) : (
          <span aria-disabled="true" className={cn(STEP, OFF)}>
            <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Previous
          </span>
        )}
        {nextHref ? (
          <Link href={nextHref} only={only} preserveScroll preserveState className={STEP} rel="next">
            Next <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </Link>
        ) : (
          <span aria-disabled="true" className={cn(STEP, OFF)}>
            Next <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </span>
        )}
      </div>
    </nav>
  );
}
