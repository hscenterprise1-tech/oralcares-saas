import * as React from 'react';
import {
  ColumnDef,
  ColumnFiltersState,
  SortingState,
  VisibilityState,
  flexRender,
  getCoreRowModel,
  getFacetedRowModel,
  getFacetedUniqueValues,
  getFilteredRowModel,
  getPaginationRowModel,
  getSortedRowModel,
  useReactTable,
} from '@tanstack/react-table';
import { SearchX } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  TableDensity,
} from '@/ovs/tables/table';
import { EmptyState } from '@/ovs/feedback/empty-state';
import { Skeleton } from '@/ovs/feedback/skeleton';

export interface OVSDataTableProps<TData, TValue> {
  columns: ColumnDef<TData, TValue>[];
  data: TData[];
  toolbarFilters?: React.ReactNode;
  density?: TableDensity;
  seamless?: boolean;
  stickyHeader?: boolean;
  isLoading?: boolean;
  emptyState?: React.ReactNode;
  className?: string;
  onRowClick?: (row: TData) => void;
}

const MemoizedTableRow = React.memo(
  ({ row, onRowClick }: { row: any; onRowClick?: (r: any) => void }) => (
    <TableRow
      data-state={row.getIsSelected() ? 'selected' : undefined}
      isSelected={row.getIsSelected()}
      isClickable={Boolean(onRowClick)}
      onClick={() => onRowClick && onRowClick(row.original)}
      className="font-sans"
    >
      {row.getVisibleCells().map((cell: any) => (
        <TableCell
          key={cell.id}
          align={cell.column.columnDef.meta?.align || 'left'}
          isNumeric={cell.column.columnDef.meta?.isNumeric || false}
        >
          {flexRender(cell.column.columnDef.cell, cell.getContext())}
        </TableCell>
      ))}
    </TableRow>
  ),
  (prevProps, nextProps) => {
    return (
      prevProps.row.id === nextProps.row.id &&
      prevProps.row.getIsSelected() === nextProps.row.getIsSelected()
    );
  }
);

export function OVSDataTable<TData, TValue>({
  columns,
  data,
  toolbarFilters,
  density = 'default',
  seamless = false,
  stickyHeader = false,
  isLoading = false,
  emptyState,
  className,
  onRowClick,
}: OVSDataTableProps<TData, TValue>) {
  const [rowSelection, setRowSelection] = React.useState({});
  const [columnVisibility, setColumnVisibility] = React.useState<VisibilityState>({});
  const [columnFilters, setColumnFilters] = React.useState<ColumnFiltersState>([]);
  const [sorting, setSorting] = React.useState<SortingState>([]);

  const table = useReactTable({
    data,
    columns,
    state: {
      sorting,
      columnVisibility,
      rowSelection,
      columnFilters,
    },
    enableRowSelection: true,
    onRowSelectionChange: setRowSelection,
    onSortingChange: setSorting,
    onColumnFiltersChange: setColumnFilters,
    onColumnVisibilityChange: setColumnVisibility,
    getCoreRowModel: getCoreRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getPaginationRowModel: getPaginationRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFacetedRowModel: getFacetedRowModel(),
    getFacetedUniqueValues: getFacetedUniqueValues(),
  });

  return (
    <div className={cn('space-y-3 w-full font-sans', className)}>
      {toolbarFilters}
      <Table density={density} seamless={seamless} stickyHeader={stickyHeader}>
        <TableHeader>
          {table.getHeaderGroups().map((headerGroup) => (
            <TableRow key={headerGroup.id} isClickable={false} className="hover:bg-transparent border-b border-slate-200/80">
              {headerGroup.headers.map((header) => {
                const canSort = header.column.getCanSort();
                const isSorted = header.column.getIsSorted();

                return (
                  <TableHead
                    key={header.id}
                    colSpan={header.colSpan}
                    align={(header.column.columnDef.meta as any)?.align || 'left'}
                    isSortable={canSort}
                    sortDirection={isSorted ? (isSorted === 'asc' ? 'asc' : 'desc') : null}
                    onSort={header.column.getToggleSortingHandler()}
                  >
                    {header.isPlaceholder
                      ? null
                      : flexRender(header.column.columnDef.header, header.getContext())}
                  </TableHead>
                );
              })}
            </TableRow>
          ))}
        </TableHeader>
        <TableBody>
          {isLoading ? (
            // Exact 56px skeleton loading rows
            Array.from({ length: 5 }).map((_, i) => (
              <TableRow key={i} isClickable={false}>
                {columns.map((col, idx) => (
                  <TableCell key={idx}>
                    {idx === 0 ? (
                      <div className="space-y-1.5 py-1">
                        <Skeleton className="h-3.5 w-44" />
                        <Skeleton className="h-2.5 w-24" />
                      </div>
                    ) : (
                      <Skeleton className="h-3.5 w-20" />
                    )}
                  </TableCell>
                ))}
              </TableRow>
            ))
          ) : table.getRowModel().rows?.length ? (
            table.getRowModel().rows.map((row) => (
              <MemoizedTableRow key={row.id} row={row} onRowClick={onRowClick} />
            ))
          ) : (
            <TableRow isClickable={false}>
              <TableCell colSpan={columns.length} className="h-44 text-center">
                {emptyState || (
                  <EmptyState
                    icon={SearchX}
                    title="No records found"
                    description="No data matches your current search criteria or active filters."
                  />
                )}
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  );
}
