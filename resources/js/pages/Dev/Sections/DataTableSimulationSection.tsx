import * as React from "react"
import { ColumnDef } from "@tanstack/react-table"
import { useDemoData } from "@/hooks/useDemoData"
import { DataTable } from "@/components/DataTable/DataTable"
import { DataTableColumnHeader } from "@/components/DataTable/DataTableColumnHeader"
import { Badge } from "@/components/ui/badge"

// Define the shape of our mock data
type PatientData = {
  id: string
  name: string
  status: string
  branch: string
  lastVisit: string
  revenue: number
}

// Define the columns for the DataTable
export const columns: ColumnDef<PatientData>[] = [
  {
    accessorKey: "id",
    header: ({ column }) => (
      <DataTableColumnHeader column={column} title="Patient ID" />
    ),
    cell: ({ row }) => <div className="w-[80px] font-medium">{row.getValue("id")}</div>,
    enableSorting: true,
    enableHiding: false,
  },
  {
    accessorKey: "name",
    header: ({ column }) => (
      <DataTableColumnHeader column={column} title="Name" />
    ),
    cell: ({ row }) => {
      return (
        <div className="flex space-x-2">
          <span className="max-w-[500px] truncate font-medium">
            {row.getValue("name")}
          </span>
        </div>
      )
    },
  },
  {
    accessorKey: "status",
    header: ({ column }) => (
      <DataTableColumnHeader column={column} title="Status" />
    ),
    cell: ({ row }) => {
      const status = row.getValue("status") as string
      return (
        <Badge variant={status === "Active" ? "default" : "secondary"}>
          {status}
        </Badge>
      )
    },
    filterFn: (row, id, value) => {
      return value.includes(row.getValue(id))
    },
  },
  {
    accessorKey: "branch",
    header: ({ column }) => (
      <DataTableColumnHeader column={column} title="Branch" />
    ),
    cell: ({ row }) => {
      return (
        <div className="flex items-center">
          <span>{row.getValue("branch")}</span>
        </div>
      )
    },
    filterFn: (row, id, value) => {
      return value.includes(row.getValue(id))
    },
  },
  {
    accessorKey: "lastVisit",
    header: ({ column }) => (
      <DataTableColumnHeader column={column} title="Last Visit" />
    ),
    cell: ({ row }) => {
      return (
        <div className="flex items-center text-slate-500">
          <span>{row.getValue("lastVisit")}</span>
        </div>
      )
    },
  },
  {
    accessorKey: "revenue",
    header: ({ column }) => (
      <DataTableColumnHeader column={column} title="Lifetime Revenue" />
    ),
    cell: ({ row }) => {
      const amount = parseFloat(row.getValue("revenue"))
      const formatted = new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: "INR",
      }).format(amount)
 
      return <div className="font-medium text-emerald-600">{formatted}</div>
    },
  },
]

export function DataTableSimulationSection() {
    const { mockTableData } = useDemoData();

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold tracking-tight mb-2">Enterprise Data Platform (Phase 3)</h2>
                <p className="text-slate-500">
                    This is the highly reusable TanStack DataTable. It is rendering <strong>{mockTableData.length.toLocaleString()} rows</strong> in real-time.
                </p>
            </div>

            <div className="bg-white rounded-lg shadow-sm">
                <DataTable data={mockTableData} columns={columns} />
            </div>
        </div>
    )
}
