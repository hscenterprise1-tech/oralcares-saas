import * as React from "react"
import { Search } from "lucide-react"
import { Input } from "@/components/ui/input"
import { cn } from "@/lib/utils"

export interface FiltersProps extends React.HTMLAttributes<HTMLDivElement> {
  onSearch?: (value: string) => void;
  placeholder?: string;
  actions?: React.ReactNode;
}

export function Filters({
  onSearch,
  placeholder = "Search...",
  actions,
  className,
  ...props
}: FiltersProps) {
  return (
    <div className={cn("flex flex-col sm:flex-row items-center justify-between gap-4 py-4", className)} {...props}>
      <div className="relative w-full sm:max-w-sm">
        <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-ovs-secondary" />
        <Input
          type="search"
          placeholder={placeholder}
          className="w-full pl-9 bg-ovs-background"
          onChange={(e) => onSearch?.(e.target.value)}
        />
      </div>
      {actions && (
        <div className="flex w-full sm:w-auto items-center gap-2">
          {actions}
        </div>
      )}
    </div>
  )
}
