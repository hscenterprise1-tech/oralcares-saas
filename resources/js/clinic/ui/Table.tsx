import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { Skeleton } from './Skeleton';

export interface Column<Row> {
  key: string;
  header: ReactNode;
  cell: (row: Row) => ReactNode;
  /** Right-aligned and tabular figures (money, counts). */
  numeric?: boolean;
  /** Hidden below this width; the row's mobile card still shows what matters. */
  hideBelow?: 'md' | 'lg' | 'xl';
  className?: string;
}

const HIDE: Record<NonNullable<Column<unknown>['hideBelow']>, string> = {
  md: 'hidden md:table-cell',
  lg: 'hidden lg:table-cell',
  xl: 'hidden xl:table-cell',
};

/**
 * A list of records: a table from `lg` up, and on a phone or tablet each row
 * as a card (`mobileCard`), so nothing is read by scrolling sideways. The
 * first column stays put when a wide table does scroll.
 *
 * The rows are the server's; this never sorts or filters them itself. While
 * `loading`, skeleton rows keep the layout from jumping. `empty` is shown in
 * place of the rows (an EmptyState from ./states).
 */
export function Table<Row>({
  columns,
  rows,
  rowKey,
  caption,
  mobileCard,
  onRowClick,
  loading = false,
  empty,
  className,
}: {
  columns: Column<Row>[];
  rows: Row[];
  rowKey: (row: Row) => string | number;
  /** Read by screen readers; say what the list is. */
  caption: string;
  mobileCard: (row: Row) => ReactNode;
  /** Opens the row; the row's first cell should also hold a real link. */
  onRowClick?: (row: Row) => void;
  loading?: boolean;
  empty?: ReactNode;
  className?: string;
}) {
  if (!loading && rows.length === 0 && empty) {
    return <div className={className}>{empty}</div>;
  }

  return (
    <div className={cn('min-w-0', className)} aria-busy={loading || undefined}>
      <div className="hidden overflow-x-auto lg:block">
        <table className="w-full border-separate border-spacing-0 text-left text-[13px]">
          <caption className="sr-only">{caption}</caption>
          <thead>
            <tr>
              {columns.map((column, index) => (
                <th
                  key={column.key}
                  scope="col"
                  className={cn(
                    'border-b border-[#E6EBF1] bg-white px-4 py-2.5 text-[12px] font-semibold text-[#64748B]',
                    index === 0 && 'sticky left-0 z-[1]',
                    column.numeric && 'text-right',
                    column.hideBelow && HIDE[column.hideBelow],
                    column.className,
                  )}
                >
                  {column.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading
              ? Array.from({ length: 5 }, (_, index) => (
                  <tr key={index}>
                    {columns.map((column) => (
                      <td key={column.key} className={cn('border-b border-[#E6EBF1] px-4 py-3.5', column.hideBelow && HIDE[column.hideBelow])}>
                        <Skeleton className="h-3.5 w-3/4" />
                      </td>
                    ))}
                  </tr>
                ))
              : rows.map((row) => (
                  <tr
                    key={rowKey(row)}
                    // A click on a control inside the row (a link, a "show number" button) is that control's own.
                    onClick={onRowClick ? (event) => !(event.target as HTMLElement).closest('a,button,input,select,textarea,label') && onRowClick(row) : undefined}
                    className={cn('group', onRowClick && 'cursor-pointer')}
                  >
                    {columns.map((column, index) => (
                      <td
                        key={column.key}
                        className={cn(
                          'border-b border-[#E6EBF1] bg-white px-4 py-3 align-middle text-[#173B60] group-hover:bg-[#F5F8FC]',
                          index === 0 && 'sticky left-0 z-[1]',
                          column.numeric && 'text-right tabular-nums',
                          column.hideBelow && HIDE[column.hideBelow],
                          column.className,
                        )}
                      >
                        {column.cell(row)}
                      </td>
                    ))}
                  </tr>
                ))}
          </tbody>
        </table>
      </div>

      <ul aria-label={caption} className="divide-y divide-cx-line lg:hidden">
        {loading
          ? Array.from({ length: 4 }, (_, index) => (
              <li key={index} className="space-y-2 px-4 py-4">
                <Skeleton className="h-4 w-1/2" />
                <Skeleton className="h-3 w-3/4" />
              </li>
            ))
          : rows.map((row) => (
              <li key={rowKey(row)} className="px-4 py-3.5">
                {mobileCard(row)}
              </li>
            ))}
      </ul>
    </div>
  );
}
