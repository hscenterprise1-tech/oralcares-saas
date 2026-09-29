import React from 'react';
import { AlertCircle, Clock, UserX, CheckCircle2, ShieldAlert } from 'lucide-react';
import { cn } from '@/lib/utils';

export type BadgeVariant =
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'neutral'
  | 'brand'
  | 'outline'
  | 'indigo';

export type BadgeSize = 'compact' | 'default' | 'lg';
export type BadgeFamily = 'status' | 'priority' | 'alert' | 'tag';

export interface BadgeProps extends React.HTMLAttributes<HTMLDivElement> {
  variant?: BadgeVariant;
  size?: BadgeSize;
  family?: BadgeFamily;
  dot?: boolean;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. BASE CANONICAL BADGE PRIMITIVE
// ─────────────────────────────────────────────────────────────────────────────

export const Badge = React.forwardRef<HTMLDivElement, BadgeProps>(
  ({ className, variant = 'neutral', size = 'default', family = 'status', dot = false, children, ...props }, ref) => {
    // 1. Semantic Color Variants (Rich Pastel Status Chips)
    const statusVariants: Record<BadgeVariant, string> = {
      success: 'bg-ovs-semantic-success-bg text-ovs-semantic-success-text border-ovs-semantic-success-border font-medium',
      warning: 'bg-ovs-semantic-warning-bg text-ovs-semantic-warning-text border-ovs-semantic-warning-border font-medium',
      danger: 'bg-ovs-semantic-danger-bg text-ovs-semantic-danger-text border-ovs-semantic-danger-border font-medium',
      info: 'bg-ovs-semantic-info-bg text-ovs-semantic-info-text border-ovs-semantic-info-border font-medium',
      brand: 'bg-ovs-semantic-brand-bg text-ovs-semantic-brand-text border-ovs-semantic-brand-border font-medium',
      indigo: 'bg-ovs-brand text-white border-ovs-brand shadow-ovs-keyline font-semibold',
      neutral: 'bg-ovs-semantic-neutral-bg text-ovs-semantic-neutral-text border-ovs-semantic-neutral-border font-medium',
      outline: 'bg-transparent text-ovs-text-primary border-ovs-border-strong font-medium',
    };

    // 2. High-Contrast Priority Variants
    const priorityVariants: Record<BadgeVariant, string> = {
      danger: 'bg-ovs-semantic-danger text-white border-ovs-semantic-danger shadow-ovs-keyline font-bold tracking-wider uppercase',
      warning: 'bg-ovs-semantic-warning text-white border-ovs-semantic-warning shadow-ovs-keyline font-bold tracking-wider uppercase',
      neutral: 'bg-ovs-border-strong text-ovs-text-primary border-ovs-border-strong font-bold tracking-wider uppercase',
      success: 'bg-ovs-semantic-success text-white border-ovs-semantic-success shadow-ovs-keyline font-bold tracking-wider uppercase',
      info: 'bg-ovs-semantic-info text-white border-ovs-semantic-info shadow-ovs-keyline font-bold tracking-wider uppercase',
      brand: 'bg-ovs-brand text-white border-ovs-brand shadow-ovs-keyline font-bold tracking-wider uppercase',
      indigo: 'bg-ovs-brand text-white border-ovs-brand shadow-ovs-keyline font-bold tracking-wider uppercase',
      outline: 'bg-transparent text-ovs-text-primary border-ovs-border-strong font-bold tracking-wider uppercase',
    };

    // 3. Operational Alert Variants
    const alertVariants: Record<BadgeVariant, string> = {
      warning: 'bg-ovs-semantic-warning-bg text-ovs-semantic-warning-text border-ovs-semantic-warning-border font-bold',
      danger: 'bg-ovs-semantic-danger-bg text-ovs-semantic-danger-text border-ovs-semantic-danger-border font-bold',
      info: 'bg-ovs-semantic-info-bg text-ovs-semantic-info-text border-ovs-semantic-info-border font-bold',
      neutral: 'bg-ovs-semantic-neutral-bg text-ovs-semantic-neutral-text border-ovs-semantic-neutral-border font-bold',
      success: 'bg-ovs-semantic-success-bg text-ovs-semantic-success-text border-ovs-semantic-success-border font-bold',
      brand: 'bg-ovs-semantic-brand-bg text-ovs-semantic-brand-text border-ovs-semantic-brand-border font-bold',
      indigo: 'bg-ovs-semantic-brand-bg text-ovs-semantic-brand-text border-ovs-semantic-brand-border font-bold',
      outline: 'bg-transparent text-ovs-text-primary border-ovs-border-strong font-bold',
    };

    // Pick family styles
    let activeFamilyClasses = statusVariants[variant];
    if (family === 'priority') activeFamilyClasses = priorityVariants[variant];
    if (family === 'alert') activeFamilyClasses = alertVariants[variant];

    const sizes: Record<BadgeSize, string> = {
      compact: 'px-2.5 py-0.5 text-[11px] h-5 leading-none rounded-full',
      default: 'px-3 py-0.5 text-[11px] h-5.5 leading-none rounded-full',
      lg: 'px-3.5 py-1 text-xs h-6 leading-none rounded-full',
    };

    const dotColors: Record<BadgeVariant, string> = {
      success: 'bg-ovs-semantic-success',
      warning: 'bg-ovs-semantic-warning',
      danger: 'bg-ovs-semantic-danger',
      info: 'bg-ovs-semantic-info',
      brand: 'bg-ovs-brand',
      indigo: 'bg-white',
      neutral: 'bg-ovs-text-tertiary',
      outline: 'bg-ovs-text-secondary',
    };

    return (
      <div
        ref={ref}
        className={cn(
          'inline-flex items-center justify-center gap-1.5 border font-sans tracking-tight transition-colors shrink-0 select-none whitespace-nowrap font-medium shadow-ovs-keyline',
          activeFamilyClasses,
          sizes[size],
          className
        )}
        {...props}
      >
        {dot && (
          <span className="relative flex h-1.5 w-1.5 shrink-0">
            <span className={cn('animate-ping absolute inline-flex h-full w-full rounded-full opacity-75', dotColors[variant])} />
            <span className={cn('relative inline-flex rounded-full h-1.5 w-1.5', dotColors[variant])} />
          </span>
        )}
        {children}
      </div>
    );
  }
);
Badge.displayName = 'Badge';

// ─────────────────────────────────────────────────────────────────────────────
// 2. CANONICAL BADGE VISUAL FAMILIES (3 TIERS)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Family A: Priority Badge (High-Contrast Micro-Tag).
 * Renders HOT (Danger/Red), WARM (Warning/Amber), COLD (Neutral/Slate).
 */
export const PriorityBadge: React.FC<{
  priority?: string | null;
  size?: BadgeSize;
  className?: string;
}> = ({ priority = 'cold', size = 'compact', className }) => {
  const p = (priority || 'cold').toLowerCase().trim();
  let variant: BadgeVariant = 'neutral';
  let label = 'COLD';

  if (p === 'high' || p === 'hot' || p === 'critical' || p === 'urgent') {
    variant = 'danger';
    label = 'HOT';
  } else if (p === 'medium' || p === 'warm') {
    variant = 'warning';
    label = 'WARM';
  }

  return (
    <Badge variant={variant} family="priority" size={size} className={className}>
      {label}
    </Badge>
  );
};

/**
 * Family B: Lifecycle Stage Badge (Subtle Semantic Status Chip).
 * Renders quiet tinted background matching exact canonical stage.
 */
export const StageBadge: React.FC<{
  status?: string | null;
  label?: string | null;
  size?: BadgeSize;
  dot?: boolean;
  className?: string;
}> = ({ status, label, size = 'default', dot = false, className }) => {
  const variant = getBadgeVariantForStatus(status);
  const displayLabel =
    label ||
    (status ? status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : 'Unknown');

  return (
    <Badge
      variant={variant}
      family="status"
      size={size}
      dot={dot}
      className={cn('whitespace-nowrap font-medium text-[11px]', className)}
    >
      {displayLabel}
    </Badge>
  );
};

/**
 * Family C: Operational Alert Badge (Warning Micro-Indicator with Icon).
 * Renders Stalled, Overdue, or Unassigned alerts without visual noise.
 */
export const OperationalAlertBadge: React.FC<{
  type: 'stalled' | 'overdue' | 'unassigned' | 'healthy' | 'critical';
  label?: string;
  size?: BadgeSize;
  className?: string;
}> = ({ type, label, size = 'compact', className }) => {
  switch (type) {
    case 'stalled':
      return (
        <Badge variant="warning" family="alert" size={size} className={cn('gap-1', className)}>
          <Clock className="w-2.5 h-2.5" />
          <span>{label || 'Stalled'}</span>
        </Badge>
      );
    case 'overdue':
      return (
        <Badge variant="danger" family="alert" size={size} className={cn('gap-1', className)}>
          <AlertCircle className="w-2.5 h-2.5" />
          <span>{label || 'Overdue'}</span>
        </Badge>
      );
    case 'unassigned':
      return (
        <Badge variant="neutral" family="alert" size={size} className={cn('gap-1', className)}>
          <UserX className="w-2.5 h-2.5" />
          <span>{label || 'Unassigned'}</span>
        </Badge>
      );
    case 'healthy':
      return (
        <Badge variant="success" family="alert" size={size} className={cn('gap-1', className)}>
          <CheckCircle2 className="w-2.5 h-2.5" />
          <span>{label || 'Healthy'}</span>
        </Badge>
      );
    case 'critical':
      return (
        <Badge variant="danger" family="alert" size={size} className={cn('gap-1', className)}>
          <ShieldAlert className="w-2.5 h-2.5" />
          <span>{label || 'Critical Risk'}</span>
        </Badge>
      );
    default:
      return null;
  }
};

// ─────────────────────────────────────────────────────────────────────────────
// 3. SEMANTIC STATUS RESOLVER HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Canonical OVS Semantic Status Mapping Helper.
 * Maps any raw lead/deal/customer status string into a strict OVS BadgeVariant.
 */
export function getBadgeVariantForStatus(rawStatus?: string | null): BadgeVariant {
  if (!rawStatus) return 'neutral';
  const s = rawStatus.toLowerCase().trim().replace(/[\s-]+/g, '_');

  // Terminal Success
  if (s === 'won' || s === 'closed_won' || s === 'payment_received' || s === 'healthy' || s === 'active') {
    return 'success';
  }

  // Terminal Danger / Blocker
  if (s === 'lost' || s === 'closed_lost' || s === 'churned' || s === 'critical' || s === 'overdue') {
    return 'danger';
  }

  // Warning / Mid-Flight Commercial & Trial
  if (
    s === 'trial_active' ||
    s === 'trial' ||
    s === 'quotation_sent' ||
    s === 'proposal_sent' ||
    s === 'stalled' ||
    s === 'warning' ||
    s === 'expiring_soon'
  ) {
    return 'warning';
  }

  // Brand / Advanced Milestones
  if (
    s === 'demo_completed' ||
    s === 'negotiation' ||
    s === 'quotation_approved' ||
    s === 'agreement_signed' ||
    s === 'invoice_sent'
  ) {
    return 'brand';
  }

  // Info / Early Qualification & Demo
  if (
    s === 'contacted' ||
    s === 'qualified' ||
    s === 'demo_scheduled' ||
    s === 'demo' ||
    s === 'assigned'
  ) {
    return 'info';
  }

  // Neutral / Unassigned / Ingested
  return 'neutral';
}

/**
 * Canonical OVS Priority Semantic Mapping Helper.
 */
export function getBadgeVariantForPriority(rawPriority?: string | null): BadgeVariant {
  if (!rawPriority) return 'neutral';
  const p = rawPriority.toLowerCase().trim();
  if (p === 'high' || p === 'hot' || p === 'critical' || p === 'urgent') return 'danger';
  if (p === 'medium' || p === 'warm') return 'warning';
  return 'neutral';
}

/**
 * Self-resolving Semantic Badge Component.
 * Automatically resolves variant from status or priority.
 */
export const SemanticBadge: React.FC<{
  status?: string | null;
  priority?: string | null;
  label?: string | null;
  size?: BadgeSize;
  family?: BadgeFamily;
  dot?: boolean;
  className?: string;
}> = ({ status, priority, label, size = 'default', family = 'status', dot = false, className }) => {
  const variant: BadgeVariant = priority
    ? getBadgeVariantForPriority(priority)
    : getBadgeVariantForStatus(status);

  const displayLabel =
    label ||
    (priority ? priority.toUpperCase() : '') ||
    (status ? status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '');

  return (
    <Badge variant={variant} family={priority ? 'priority' : family} size={size} dot={dot} className={className}>
      {displayLabel}
    </Badge>
  );
};
