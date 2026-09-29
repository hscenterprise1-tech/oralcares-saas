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
} from "@tanstack/react-table"
import * as React from "react"
import { EmptyState } from "@/components/ui/empty-state"
import { SearchX } from "lucide-react"

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"

import { DataTablePagination } from "./DataTablePagination"
import { DataTableToolbar } from "./DataTableToolbar"

interface DataTableProps<TData, TValue> {
  columns: ColumnDef<TData, TValue>[]
  data: TData[]
  toolbarFilters?: React.ReactNode
  searchKey?: string
  searchPlaceholder?: string
}

const MemoizedTableRow = React.memo(({ row }: { row: any }) => (
  <TableRow
    data-state={row.getIsSelected() && "selected"}
    className="hover:bg-slate-50 transition-colors"
  >
    {row.getVisibleCells().map((cell: any) => (
      <TableCell key={cell.id} className="py-3">
        {flexRender(
          cell.column.columnDef.cell,
          cell.getContext()
        )}
      </TableCell>
    ))}
  </TableRow>
), (prevProps, nextProps) => {
  return prevProps.row.id === nextProps.row.id && 
         prevProps.row.getIsSelected() === nextProps.row.getIsSelected();
});

export function DataTable<TData, TValue>({
  columns,
  data,
  toolbarFilters,
  searchKey,
  searchPlaceholder,
}: DataTableProps<TData, TValue>) {
  const [rowSelection, setRowSelection] = React.useState({})
  const [columnVisibility, setColumnVisibility] = React.useState<VisibilityState>({})
  const [columnFilters, setColumnFilters] = React.useState<ColumnFiltersState>([])
  const [sorting, setSorting] = React.useState<SortingState>([])

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
  })

  return (
    <div className="space-y-4">
      <DataTableToolbar table={table} searchKey={searchKey} searchPlaceholder={searchPlaceholder}>
        {toolbarFilters}
      </DataTableToolbar>
      <div className="rounded-md border bg-white">
        <Table>
          <TableHeader>
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id}>
                {headerGroup.headers.map((header) => {
                  return (
                    <TableHead key={header.id} colSpan={header.colSpan}>
                      {header.isPlaceholder
                        ? null
                        : flexRender(
                            header.column.columnDef.header,
                            header.getContext()
                          )}
                    </TableHead>
                  )
                })}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {table.getRowModel().rows?.length ? (
              table.getRowModel().rows.map((row) => (
                <MemoizedTableRow key={row.id} row={row} />
              ))
            ) : (
              <TableRow>
                <TableCell
                  colSpan={columns.length}
                  className="h-64 text-center"
                >
                  <EmptyState 
                    icon={SearchX} 
                    title="No results found" 
                    description="We couldn't find anything matching your current filters." 
                    className="border-none bg-transparent py-8"
                  />
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </div>
      <DataTablePagination table={table} />
    </div>
  )
}
