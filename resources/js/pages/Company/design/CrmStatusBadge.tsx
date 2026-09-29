import React from 'react';
import { Badge, BadgeVariant } from './CrmBadgePrimitives';

/**
 * One status vocabulary for the whole CRM.
 *
 * Lead stages, demo states, trial lifecycle and quotation states were each
 * mapped to colours independently on the page that rendered them, so the same
 * word could arrive amber on one screen and grey on the next. This is the
 * single map, which is what makes a status mean the same thing everywhere.
 *
 * Semantic colour is used sparingly and only where it carries meaning: things
 * that need attention, things that went wrong, things that closed well.
 * Everything in normal progress stays neutral so the exceptions stand out.
 */

const STATUS_VARIANTS: Record<string, BadgeVariant> = {
  // Lead stages — progression is neutral/informational; outcomes are semantic.
  new: 'neutral',
  assigned: 'neutral',
  contacted: 'info',
  demo_scheduled: 'info',
  demo_completed: 'info',
  trial_active: 'indigo',
  negotiation: 'warning',
  quotation_sent: 'warning',
  quotation_approved: 'success',
  payment_received: 'success',
  won: 'success',
  lost: 'danger',

  // Demo lifecycle
  requested: 'neutral',
  scheduled: 'info',
  confirmed: 'info',
  completed: 'success',
  no_show: 'danger',
  rescheduled: 'warning',
  cancelled: 'neutral',

  // Trial lifecycle
  preparing: 'neutral',
  active: 'success',
  ending_soon: 'warning',
  extended: 'info',
  converted: 'success',
  expired: 'danger',

  // Quotation lifecycle
  draft: 'neutral',
  sent: 'info',
  pending_approval: 'warning',
  approved: 'success',
  rejected: 'danger',
  accepted: 'success',

  // Task / generic
  pending: 'warning',
  overdue: 'danger',
  in_progress: 'info',
};

/** Turn a snake_case backend value into readable text. */
export function humanizeStatus(status: string): string {
  return status
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

export function statusVariant(status?: string | null): BadgeVariant {
  if (!status) return 'neutral';
  return STATUS_VARIANTS[status.toLowerCase()] ?? 'neutral';
}

export interface CrmStatusBadgeProps {
  status?: string | null;
  /** Backend-supplied label; falls back to a humanized status value. */
  label?: string | null;
  size?: 'compact' | 'default';
  className?: string;
}

export function CrmStatusBadge({ status, label, size = 'compact', className }: CrmStatusBadgeProps) {
  if (!status && !label) return null;

  return (
    <Badge variant={statusVariant(status)} size={size} className={className}>
      {label ?? humanizeStatus(status ?? '')}
    </Badge>
  );
}

/**
 * Priority uses its own restrained scale — high is worth noticing, medium and
 * low are not, so only high carries colour.
 */
export function CrmPriorityBadge({ priority, className }: { priority?: string | null; className?: string }) {
  if (!priority) return null;

  const normalized = priority.toLowerCase();
  const variant: BadgeVariant =
    normalized === 'high' || normalized === 'hot' ? 'danger'
      : normalized === 'medium' || normalized === 'warm' ? 'warning'
        : 'neutral';

  return (
    <Badge variant={variant} size="compact" family="priority" className={className}>
      {humanizeStatus(normalized)}
    </Badge>
  );
}
