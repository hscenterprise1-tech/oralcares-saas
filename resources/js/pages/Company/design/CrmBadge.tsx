import React from 'react';
import { cn } from '@/lib/utils';
import {
  channelTone,
  humanize,
  priorityTone,
  stageTone,
  statusTone,
  toneClasses,
  STAGE_SHORT,
  type CrmTone,
} from './crmTheme';

/**
 * The CRM badge family.
 *
 * Compact rectangular chips rather than large pills: at CRM density a table
 * row carries two or three of these, and pills waste horizontal space and read
 * as decoration. Colour always comes from crmTheme, so a stage means the same
 * thing on every surface.
 */

export interface CrmBadgeProps {
  children: React.ReactNode;
  tone?: CrmTone;
  /** `soft` for tinted chips, `solid` for high-emphasis, `outline` for quiet. */
  variant?: 'soft' | 'solid' | 'outline';
  size?: 'xs' | 'sm';
  /** Leading status dot — useful when the chip sits among plain text. */
  dot?: boolean;
  icon?: React.ReactNode;
  className?: string;
}

export function CrmBadge({
  children,
  tone = 'slate',
  variant = 'soft',
  size = 'xs',
  dot = false,
  icon,
  className,
}: CrmBadgeProps) {
  const t = toneClasses(tone);

  return (
    <span
      className={cn(
        'inline-flex shrink-0 items-center gap-1 rounded-[14px] border font-semibold leading-none tracking-tight whitespace-nowrap',
        size === 'xs' ? 'px-1.5 py-[3px] text-[11.5px]' : 'px-2 py-1 text-[11.5px]',
        variant === 'soft' && t.soft,
        variant === 'solid' && cn(t.solid, 'border-transparent text-white'),
        variant === 'outline' &&
          'border-ovs-crm-border bg-ovs-crm-surface text-ovs-crm-text-secondary',
        className
      )}
    >
      {dot && (
        <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', t.solid)} aria-hidden="true" />
      )}
      {icon}
      {children}
    </span>
  );
}

/** Lead pipeline stage. */
export function CrmStageBadge({
  stage,
  label,
  short = false,
  className,
}: {
  stage?: string | null;
  label?: string | null;
  short?: boolean;
  className?: string;
}) {
  if (!stage && !label) return null;

  const key = String(stage ?? '').toLowerCase();
  const text = short ? STAGE_SHORT[key] ?? label ?? humanize(key) : label ?? humanize(key);

  return (
    <CrmBadge tone={stageTone(stage)} dot className={className}>
      {text}
    </CrmBadge>
  );
}

/** Demo / trial / quotation / task lifecycle status. */
export function CrmStatusBadge({
  status,
  label,
  className,
}: {
  status?: string | null;
  label?: string | null;
  className?: string;
}) {
  if (!status && !label) return null;

  return (
    <CrmBadge tone={statusTone(status)} className={className}>
      {label ?? humanize(status)}
    </CrmBadge>
  );
}

/**
 * Priority. Low priority renders nothing at all — an "everything is normal"
 * chip on every row is noise that makes the genuine exceptions harder to spot.
 */
export function CrmPriorityBadge({
  priority,
  showLow = false,
  className,
}: {
  priority?: string | null;
  showLow?: boolean;
  className?: string;
}) {
  if (!priority) return null;

  const key = priority.toLowerCase();
  if (!showLow && (key === 'low' || key === 'cold')) return null;

  return (
    <CrmBadge tone={priorityTone(priority)} className={className}>
      {humanize(key)}
    </CrmBadge>
  );
}

/** Activity / communication channel. */
export function CrmChannelBadge({
  channel,
  label,
  className,
}: {
  channel?: string | null;
  label?: string | null;
  className?: string;
}) {
  if (!channel) return null;

  return (
    <CrmBadge tone={channelTone(channel)} className={className}>
      {label ?? humanize(channel)}
    </CrmBadge>
  );
}

/**
 * A 0–100 score as a compact bar plus figure. More scannable than a bare
 * number and cheaper than a chart at row density.
 */
export function CrmScore({ value, className }: { value?: number | null; className?: string }) {
  if (value === null || value === undefined) {
    return <span className={cn('text-[11.5px] text-ovs-crm-text-muted', className)}>—</span>;
  }

  const score = Math.max(0, Math.min(100, value));
  const tone: CrmTone = score >= 70 ? 'emerald' : score >= 40 ? 'amber' : 'coral';

  return (
    <span className={cn('inline-flex items-center gap-1.5', className)}>
      <span
        className="h-1.5 w-10 overflow-hidden rounded-full bg-ovs-crm-surface-muted"
        role="img"
        aria-label={`Score ${score} out of 100`}
      >
        <span
          className={cn('block h-full rounded-full', toneClasses(tone).solid)}
          style={{ width: `${score}%` }}
        />
      </span>
      <span className="text-[11.5px] font-semibold tabular-nums text-ovs-crm-text-secondary">{score}</span>
    </span>
  );
}

/**
 * Horizontal progress with an optional tone override — used for trial elapsed
 * time, onboarding completion and rep attainment.
 */
export function CrmProgress({
  value,
  tone = 'teal',
  label,
  className,
}: {
  value: number;
  tone?: CrmTone;
  label?: string;
  className?: string;
}) {
  const pct = Math.max(0, Math.min(100, value));

  return (
    <div className={cn('flex items-center gap-2', className)}>
      <div
        className="h-1.5 flex-1 overflow-hidden rounded-full bg-ovs-crm-surface-muted"
        role="progressbar"
        aria-valuenow={pct}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={label}
      >
        <div className={cn('h-full rounded-full transition-all', toneClasses(tone).solid)} style={{ width: `${pct}%` }} />
      </div>
      <span className="w-9 shrink-0 text-right text-[11.5px] font-semibold tabular-nums text-ovs-crm-text-secondary">
        {Math.round(pct)}%
      </span>
    </div>
  );
}
