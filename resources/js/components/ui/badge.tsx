import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"

const badgeVariants = cva(
  "inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ovs-accent/40 focus:ring-offset-2",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-ovs-primary text-ovs-primary-foreground shadow hover:bg-ovs-primary-hover",
        secondary:
          "border-transparent bg-ovs-background text-ovs-primary hover:bg-ovs-background",
        destructive:
          "border-transparent bg-ovs-semantic-danger text-white shadow hover:bg-ovs-semantic-danger",
        outline: "text-ovs-primary",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

export interface BadgeProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return (
    <div className={cn(badgeVariants({ variant }), className)} {...props} />
  )
}

export { Badge, badgeVariants }
