import * as React from "react"
import { cn } from "@/lib/utils"

export interface EmptyStateProps extends React.HTMLAttributes<HTMLDivElement> {
  icon?: React.ReactNode
  title: string
  description?: string
  action?: React.ReactNode
}

export function EmptyState({
  icon,
  title,
  description,
  action,
  className,
  ...props
}: EmptyStateProps) {
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center rounded-xl border border-[#E5EAF0] bg-white p-12 text-center shadow-[0_1px_2px_rgba(15,23,42,0.02)]",
        className
      )}
      {...props}
    >
      {icon && (
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl border border-[#E5EAF0] bg-[#F5F7FB] text-[#64748B] shadow-sm mb-5">
          {icon}
        </div>
      )}
      <h3 className="text-lg font-semibold text-[#0A2540]">{title}</h3>
      {description && (
        <p className="mt-2 text-sm text-[#64748B] max-w-sm mx-auto leading-relaxed">
          {description}
        </p>
      )}
      {action && <div className="mt-6">{action}</div>}
    </div>
  )
}
