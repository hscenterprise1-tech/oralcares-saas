import * as React from "react"
import { Badge, BadgeProps } from "@/components/ui/badge"

type StatusType = "active" | "inactive" | "pending" | "trial" | "warning" | "error" | "success" | "default";

export interface StatusBadgeProps extends Omit<BadgeProps, "variant"> {
  status: StatusType;
  label?: string;
}

export function StatusBadge({ status, label, className, ...props }: StatusBadgeProps) {
  const getVariant = (status: StatusType): BadgeProps["variant"] => {
    switch (status) {
      case "active":
      case "success":
        return "default";
      case "inactive":
      case "error":
        return "destructive";
      case "pending":
      case "trial":
      case "warning":
        return "outline";
      default:
        return "secondary";
    }
  }

  return (
    <Badge variant={getVariant(status)} className={className} {...props}>
      {label || status.charAt(0).toUpperCase() + status.slice(1)}
    </Badge>
  )
}
