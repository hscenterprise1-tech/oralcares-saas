import React from 'react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * Pagination for Laravel paginator payloads.
 *
 * Laravel emits `links` with pre-rendered HTML labels (`&laquo; Previous`), so
 * the markup is set rather than escaped — the strings come from the framework,
 * not from user data.
 */

export interface PaginatorLink {
  url: string | null;
  label: string;
  active: boolean;
}

export interface CrmPaginationProps {
  links?: PaginatorLink[];
  from?: number | null;
  to?: number | null;
  total?: number | null;
  /**
   * Prev / next mode, for a list whose total costs more than it is worth.
   *
   * Passing either of these switches the component over: it renders two steps
   * and whatever range it was given, and ignores `links`. Leave both undefined
   * and nothing changes — every page that already numbers its pages keeps
   * numbering them.
   */
  prevUrl?: string | null;
  nextUrl?: string | null;
  className?: string;
}

export function CrmPagination({ links, from, to, total, prevUrl, nextUrl, className }: CrmPaginationProps) {
  /*
   * Prev / next, for a list too long to count.
   *
   * Numbered links need a total, and a total needs a COUNT over the whole
   * filtered set — 440 ms of the Customer Success account list's 453 ms at a
   * hundred and twenty thousand accounts, spent so a reader could see a page
   * number they would never click. A list that deep is paged forwards, not
   * jumped into, so a page that knows it cannot afford the count says so by
   * handing over two urls instead of a list of links.
   */
  if (prevUrl !== undefined || nextUrl !== undefined) {
    if (!prevUrl && !nextUrl) {
      return typeof from === 'number' && typeof to === 'number' ? (
        <p className={cn('text-[11.5px] tabular-nums text-ovs-crm-text-muted', className)}>
          Showing {from}–{to}
        </p>
      ) : null;
    }

    return (
      <div className={cn('flex flex-wrap items-center justify-between gap-3', className)}>
        <p className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">
          Showing {from ?? 0}–{to ?? 0}
          {typeof total === 'number' ? ` of ${total}` : ''}
        </p>

        <nav
          className="flex flex-wrap items-center gap-0.5 rounded-full bg-white p-1 shadow-ovs-crmPanel"
          aria-label="Pagination"
        >
          {[
            { url: prevUrl, label: 'Previous' },
            { url: nextUrl, label: 'Next' },
          ].map((step) => (
            <button
              key={step.label}
              type="button"
              disabled={!step.url}
              onClick={() => step.url && router.visit(step.url, { preserveScroll: true, preserveState: true })}
              className={cn(
                'min-w-8 rounded-full px-3 py-1.5 text-[11.5px] font-medium transition-colors',
                step.url
                  ? 'text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary'
                  : 'cursor-not-allowed text-ovs-crm-text-muted/50'
              )}
            >
              {step.label}
            </button>
          ))}
        </nav>
      </div>
    );
  }

  // Fewer than three links means previous / page 1 / next — nothing to page.
  if (!links || links.length <= 3) {
    return total ? (
      <p className={cn('text-[11.5px] text-ovs-crm-text-muted', className)}>
        {total} result{total === 1 ? '' : 's'}
      </p>
    ) : null;
  }

  return (
    <div className={cn('flex flex-wrap items-center justify-between gap-3', className)}>
      {typeof total === 'number' && (
        <p className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">
          Showing {from ?? 0}–{to ?? 0} of {total}
        </p>
      )}

      <nav className="flex flex-wrap items-center gap-0.5 rounded-full bg-white p-1 shadow-ovs-crmPanel" aria-label="Pagination">
        {links.map((link, index) => (
          <button
            key={index}
            type="button"
            disabled={!link.url}
            aria-current={link.active ? 'page' : undefined}
            onClick={() => link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })}
            className={cn(
              'min-w-8 rounded-full px-3 py-1.5 text-[11.5px] font-medium transition-colors',
              link.active
                ? 'bg-ovs-crm-ink text-white'
                : link.url
                  ? 'text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary'
                  : 'cursor-not-allowed text-ovs-crm-text-muted/50'
            )}
            dangerouslySetInnerHTML={{ __html: link.label }}
          />
        ))}
      </nav>
    </div>
  );
}

/**
 * Accepts either a Laravel paginator or a plain array and always returns rows
 * plus paging metadata — controllers differ across the CRM and a page should
 * not care which shape it received.
 */
export function unwrapPaginated<T>(input: unknown): {
  rows: T[];
  links?: PaginatorLink[];
  from?: number | null;
  to?: number | null;
  total?: number | null;
} {
  if (Array.isArray(input)) {
    return { rows: input as T[], total: input.length };
  }

  const paginator = input as {
    data?: T[];
    links?: PaginatorLink[];
    from?: number;
    to?: number;
    total?: number;
  } | null;

  return {
    rows: paginator?.data ?? [],
    links: paginator?.links,
    from: paginator?.from ?? null,
    to: paginator?.to ?? null,
    total: paginator?.total ?? null,
  };
}
