import React from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CrmDataState } from './CrmDataState';

/**
 * The one CRM table.
 *
 * Rows are borderless with generous height and a soft hover wash rather than
 * ruled lines — the reference language treats data as a list of objects, not a
 * grid of cells. Column definitions drive both the desktop table and the
 * mobile card list, so the two can never drift apart.
 */

export interface CrmColumn<T> {
  key: string;
  header: React.ReactNode;
  cell: (row: T) => React.ReactNode;
  align?: 'left' | 'center' | 'right';
  /** The row's identity column — becomes the card title on mobile. */
  primary?: boolean;
  hideOnMobile?: boolean;
  /**
   * The field label to use on the mobile card, when `header` is not plain text.
   *
   * A sortable column's header is a button, and the card layout renders the
   * header as each field's `<dt>` — so a table with six sortable columns put
   * six sort buttons inside every card on a phone. Sorting belongs to the table
   * header; the card wants the word.
   */
  mobileLabel?: React.ReactNode;
  /** A CSS length for this column, e.g. "132px" or "12%". Applied as a style. */
  width?: string;
  className?: string;
}

export interface CrmTableProps<T> {
  columns: CrmColumn<T>[];
  rows: T[];
  rowKey: (row: T) => React.Key;
  onRowClick?: (row: T) => void;
  isLoading?: boolean;
  error?: string | null;
  onRetry?: () => void;
  empty?: {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: React.ReactNode;
  };
  emptyFiltered?: {
    title: string;
    description?: string;
    action?: React.ReactNode;
  };
  isFiltered?: boolean;
  /**
   * Row selection, for lists that carry bulk actions.
   *
   * Opt-in: a table that cannot act on a selection should not spend a column
   * on one. Selection lives here rather than in each page so the checkbox
   * column, the select-all semantics and the keyboard behaviour are the same
   * wherever a CRM list offers it.
   */
  selectable?: boolean;
  selectedKeys?: React.Key[];
  onSelectionChange?: (keys: React.Key[]) => void;
  /** Row actions revealed on hover, rendered at the row's right edge. */
  rowActions?: (row: T) => React.ReactNode;
  /**
   * How the table is drawn.
   *
   * The default is the reference language this system was built to: borderless
   * rows floating on the tinted ground, separated by space rather than rules.
   * It reads as a list of objects — and on a dense operational list of twenty
   * rows it reads as text floating in whitespace, with no edge to the data, no
   * contrast under the headers and nothing telling the eye where a row starts.
   *
   * The ruled variant is the same table inside a real surface: one container
   * edge, a header band with its own ground, hairlines between rows. Opt-in,
   * so the short object-like lists keep the softer voice.
   */
  variant?: 'pill' | 'ruled';
  footer?: React.ReactNode;
  className?: string;
}

export function CrmTableEmpty(props: NonNullable<CrmTableProps<unknown>['empty']>) {
  return <CrmDataState kind="empty" {...props} />;
}

export function CrmTable<T>({
  columns,
  rows,
  rowKey,
  onRowClick,
  isLoading = false,
  error = null,
  onRetry,
  empty,
  emptyFiltered,
  isFiltered = false,
  selectable = false,
  selectedKeys = [],
  onSelectionChange,
  rowActions,
  footer,
  variant = 'pill',
  className,
}: CrmTableProps<T>) {
  const ruled = variant === 'ruled';
  const mobileColumns = columns.filter((column) => !column.hideOnMobile);
  const primaryColumn = columns.find((column) => column.primary) ?? columns[0];

  const selected = new Set(selectedKeys);
  const allSelected = rows.length > 0 && rows.every((row) => selected.has(rowKey(row)));

  const toggleRow = (key: React.Key) => {
    if (! onSelectionChange) return;
    const next = new Set(selected);
    next.has(key) ? next.delete(key) : next.add(key);
    onSelectionChange([...next]);
  };

  const toggleAll = () => {
    if (! onSelectionChange) return;
    onSelectionChange(allSelected ? [] : rows.map(rowKey));
  };

  if (error) {
    return <div className={className}><CrmDataState kind="error" description={error} onRetry={onRetry} /></div>;
  }

  if (isLoading) {
    return <div className={className}><CrmDataState kind="loading" shape="table" rows={6} /></div>;
  }

  if (rows.length === 0) {
    const state = isFiltered && emptyFiltered ? emptyFiltered : empty;

    return (
      <div
        className={cn(
          ruled &&
            'rounded-[18px] border border-ovs-crm-border bg-ovs-crm-surface px-4 shadow-ovs-crmCard',
          className
        )}
      >
        <CrmDataState
          kind="empty"
          icon={empty?.icon}
          title={state?.title ?? 'Nothing to show'}
          description={state?.description}
          action={state?.action}
        />
      </div>
    );
  }

  return (
    <div
      className={cn(
        ruled &&
          'overflow-hidden rounded-[18px] border border-ovs-crm-border bg-ovs-crm-surface shadow-ovs-crmCard',
        className
      )}
    >
      {/* Desktop */}
      <div className="hidden overflow-x-auto md:block">
        <table
          className={cn(
            'w-full min-w-full text-left',
            ruled ? 'border-collapse' : 'border-separate border-spacing-y-1'
          )}
        >
          <thead>
            <tr className={cn(ruled && 'border-b border-ovs-crm-border bg-ovs-crm-surface-inset')}>
              {selectable && (
                <th scope="col" className={cn('w-10 px-4', ruled ? 'py-3' : 'pb-2.5')}>
                  <input
                    type="checkbox"
                    checked={allSelected}
                    onChange={toggleAll}
                    aria-label="Select all rows on this page"
                    className="h-4 w-4 rounded border-ovs-crm-border text-ovs-crm-ink focus:ring-ovs-crm-ink"
                  />
                </th>
              )}
              {columns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  /*
                   * `width` is a CSS length applied as a style, not a class.
                   * It used to be spread into `cn()`, so a caller passing
                   * "132px" produced `class="… 132px"` — an invalid class that
                   * did nothing, leaving every column to auto-size off its
                   * content. Columns drifted row to row and the header stopped
                   * lining up with what was under it.
                   */
                  style={column.width ? { width: column.width } : undefined}
                  className={cn(
                    'px-4 text-ovs-crm-text-muted',
                    ruled
                      ? 'py-3 text-[11px] font-semibold uppercase tracking-[0.06em]'
                      : 'pb-2.5 text-[11.5px] font-normal',
                    column.align === 'right' && 'text-right',
                    column.align === 'center' && 'text-center'
                  )}
                >
                  {column.header}
                </th>
              ))}
              {rowActions && (
                <th scope="col" className={cn('w-20 px-4', ruled ? 'py-3' : 'pb-2')} />
              )}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr
                key={rowKey(row)}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                tabIndex={onRowClick ? 0 : undefined}
                role={onRowClick ? 'button' : undefined}
                onKeyDown={
                  onRowClick
                    ? (event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        onRowClick(row);
                      }
                    }
                    : undefined
                }
                className={cn(
                  'group transition-colors',
                  ruled && 'border-b border-ovs-crm-line last:border-0',
                  onRowClick && 'cursor-pointer'
                )}
              >
                {selectable && (
                  <td
                    className={cn(
                      'px-4 py-3.5 align-middle transition-colors',
                      ! ruled && 'rounded-l-[18px]',
                      onRowClick && 'group-hover:bg-ovs-crm-surface-inset'
                    )}
                    onClick={(event) => event.stopPropagation()}
                  >
                    <input
                      type="checkbox"
                      checked={selected.has(rowKey(row))}
                      onChange={() => toggleRow(rowKey(row))}
                      aria-label="Select row"
                      className="h-4 w-4 rounded border-ovs-crm-border text-ovs-crm-ink focus:ring-ovs-crm-ink"
                    />
                  </td>
                )}
                {columns.map((column, index) => (
                  <td
                    key={column.key}
                    className={cn(
                      'px-4 py-3.5 align-middle text-[13px] text-ovs-crm-text-primary transition-colors',
                      onRowClick && 'group-hover:bg-ovs-crm-surface-inset',
                      // Pill row: round the outer edges of first/last cell.
                      ! ruled && index === 0 && ! selectable && 'rounded-l-[18px]',
                      ! ruled && index === columns.length - 1 && ! rowActions && 'rounded-r-[18px]',
                      column.align === 'right' && 'text-right',
                      column.align === 'center' && 'text-center',
                      column.className
                    )}
                  >
                    {column.cell(row)}
                  </td>
                ))}
                {rowActions && (
                  <td
                    className={cn(
                      'px-4 py-3.5 text-right align-middle transition-colors',
                      ! ruled && 'rounded-r-[18px]',
                      onRowClick && 'group-hover:bg-ovs-crm-surface-inset'
                    )}
                    onClick={(event) => event.stopPropagation()}
                  >
                    {/*
                      * Row actions stay visible at rest.
                      *
                      * They used to fade in on hover, which hides the only
                      * route to a row's actions from anyone who is not
                      * pushing a mouse around — touch users especially, but
                      * also anyone scanning the table to find out what they
                      * can do with a row. Hover now only lifts the row's
                      * background; it is no longer what makes the actions
                      * exist.
                      */}
                    <span className="inline-flex items-center gap-1">
                      {rowActions(row)}
                    </span>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Mobile: stacked cards — a wide table scrolled sideways on a phone is
          technically responsive and practically unusable. */}
      <ul className={cn('md:hidden', ruled ? 'divide-y divide-ovs-crm-line' : 'space-y-2')}>
        {rows.map((row) => (
          <li key={rowKey(row)}>
            <div
              className={cn(
                'p-4',
                ruled ? 'bg-ovs-crm-surface' : 'rounded-[18px] bg-ovs-crm-surface-inset',
                onRowClick && ruled && 'cursor-pointer hover:bg-ovs-crm-surface-inset',
                onRowClick && ! ruled && 'crm-lift cursor-pointer hover:bg-white hover:shadow-ovs-crmCard'
              )}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              role={onRowClick ? 'button' : undefined}
              tabIndex={onRowClick ? 0 : undefined}
              onKeyDown={
                onRowClick
                  ? (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault();
                      onRowClick(row);
                    }
                  }
                  : undefined
              }
            >
              <div className="mb-3 text-[13px] font-semibold text-ovs-crm-text-primary">
                {primaryColumn?.cell(row)}
              </div>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                {mobileColumns
                  .filter((column) => column.key !== primaryColumn?.key)
                  .map((column) => (
                    <div key={column.key} className="min-w-0">
                      <dt className="text-[11.5px] text-ovs-crm-text-muted">
                        {column.mobileLabel ?? column.header}
                      </dt>
                      <dd className="truncate text-[13px] text-ovs-crm-text-primary">{column.cell(row)}</dd>
                    </div>
                  ))}
              </dl>
              {rowActions && (
                <div
                  className={cn(
                    'mt-3 flex flex-wrap items-center gap-1.5 border-t pt-3',
                    ruled ? 'border-ovs-crm-line' : 'border-white/70'
                  )}
                  onClick={(event) => event.stopPropagation()}
                >
                  {rowActions(row)}
                </div>
              )}
            </div>
          </li>
        ))}
      </ul>

      {footer && (
        <div className={cn('px-4', ruled ? 'border-t border-ovs-crm-line py-3' : 'pt-4')}>
          {footer}
        </div>
      )}
    </div>
  );
}
