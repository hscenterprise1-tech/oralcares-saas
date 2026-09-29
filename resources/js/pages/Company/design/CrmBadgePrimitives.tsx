import React from 'react';
import { cn } from '@/lib/utils';
import { getBadgeVariantForStatus } from '@/ovs/primitives/badge';

/**
 * The CRM's badge primitives.
 *
 * An API-compatible fork of the `Badge`, `PriorityBadge` and `StageBadge`
 * exports the CRM actually uses — the shared file also carries alert badges and
 * semantic helpers that no CRM surface touches, so they are not copied.
 *
 * `getBadgeVariantForStatus` is imported from the shared module rather than
 * duplicated: it maps a status string onto a variant name and contains no
 * styling at all, so there is nothing about it to restyle and a second copy
 * would be one more place for the status vocabulary to drift.
 *
 * What changed: soft tinted fills with no border and no keyline shadow, one
 * weight instead of three, and — for the priority family — the shouty uppercase
 * tracking is gone. A badge is a label, not a warning light.
 */

export type BadgeVariant = 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'brand' | 'outline' | 'indigo';
export type BadgeSize = 'compact' | 'default' | 'lg';
export type BadgeFamily = 'status' | 'priority' | 'alert' | 'tag';

export interface BadgeProps extends React.HTMLAttributes<HTMLDivElement> {
  variant?: BadgeVariant;
  size?: BadgeSize;
  family?: BadgeFamily;
  dot?: boolean;
}

/** Soft fill + readable text, one pair per variant. */
const TINT: Record<BadgeVariant, string> = {
  success: 'bg-ovs-crm-success-soft text-ovs-crm-success-text',
  warning: 'bg-ovs-crm-warning-soft text-ovs-crm-warning-text',
  danger: 'bg-ovs-crm-danger-soft text-ovs-crm-danger-text',
  info: 'bg-ovs-crm-info-soft text-ovs-crm-info-text',
  brand: 'bg-ovs-crm-brand-soft text-ovs-crm-brand-text',
  indigo: 'bg-ovs-crm-accent-violet-soft text-ovs-crm-accent-violet-text',
  neutral: 'bg-ovs-crm-surface-inset text-ovs-crm-text-secondary',
  outline: 'border border-ovs-crm-border bg-transparent text-ovs-crm-text-primary',
};

/** The priority family fills solid, so it still outranks a status chip. */
const SOLID: Record<BadgeVariant, string> = {
  success: 'bg-ovs-crm-success text-white',
  warning: 'bg-ovs-crm-warning text-white',
  danger: 'bg-ovs-crm-danger text-white',
  info: 'bg-ovs-crm-info text-white',
  brand: 'bg-ovs-crm-brand text-white',
  indigo: 'bg-ovs-crm-accent-violet text-white',
  neutral: 'bg-ovs-crm-border-strong text-ovs-crm-text-primary',
  outline: 'border border-ovs-crm-border bg-transparent text-ovs-crm-text-primary',
};

const DOT: Record<BadgeVariant, string> = {
  success: 'bg-ovs-crm-success',
  warning: 'bg-ovs-crm-warning',
  danger: 'bg-ovs-crm-danger',
  info: 'bg-ovs-crm-info',
  brand: 'bg-ovs-crm-brand',
  indigo: 'bg-ovs-crm-accent-violet',
  neutral: 'bg-ovs-crm-text-muted',
  outline: 'bg-ovs-crm-text-secondary',
};

export const Badge = React.forwardRef<HTMLDivElement, BadgeProps>(
  ({ className, variant = 'neutral', size = 'default', family = 'status', dot = false, children, ...props }, ref) => {
    const sizes: Record<BadgeSize, string> = {
      compact: 'h-5 rounded-full px-2.5 text-[10px]',
      default: 'h-6 rounded-full px-2.5 text-[11.5px]',
      lg: 'h-7 rounded-full px-3 text-[13px]',
    };

    return (
      <div
        ref={ref}
        className={cn(
          'inline-flex shrink-0 select-none items-center justify-center gap-1.5 whitespace-nowrap font-medium leading-none',
          family === 'priority' ? SOLID[variant] : TINT[variant],
          sizes[size],
          className
        )}
        {...props}
      >
        {/*
          * A static dot, not a pulsing one. The shared version animated every
          * badge's dot forever, so a screen of them read as a dozen competing
          * alarms and nothing stood out.
          */}
        {dot && <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', family === 'priority' ? 'bg-white/80' : DOT[variant])} />}
        {children}
      </div>
    );
  }
);
Badge.displayName = 'Badge';

export const PriorityBadge: React.FC<{
  priority?: string | null;
  size?: BadgeSize;
  className?: string;
}> = ({ priority = 'cold', size = 'compact', className }) => {
  const p = (priority || 'cold').toLowerCase().trim();
  let variant: BadgeVariant = 'neutral';
  let label = 'Cold';

  if (['high', 'hot', 'critical', 'urgent'].includes(p)) {
    variant = 'danger';
    label = 'Hot';
  } else if (['medium', 'warm'].includes(p)) {
    variant = 'warning';
    label = 'Warm';
  }

  return (
    <Badge variant={variant} family="priority" size={size} className={className}>
      {label}
    </Badge>
  );
};

export const StageBadge: React.FC<{
  status?: string | null;
  label?: string | null;
  size?: BadgeSize;
  dot?: boolean;
  className?: string;
}> = ({ status, label, size = 'default', dot = false, className }) => {
  const variant = getBadgeVariantForStatus(status) as BadgeVariant;
  const displayLabel =
    label || (status ? status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : 'Unknown');

  return (
    <Badge variant={variant} family="status" size={size} dot={dot} className={cn('whitespace-nowrap', className)}>
      {displayLabel}
    </Badge>
  );
};
