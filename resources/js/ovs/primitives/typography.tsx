import React from 'react';
import { cn } from '@/lib/utils';

// ─────────────────────────────────────────────────────────────────────────────
// 1. PAGE & SECTION TYPOGRAPHY PRIMITIVES (UNIFIED INTER 4-WEIGHT STANDARD)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Display Title (High-Impact Dashboard / Hero Heading).
 * Canonical: 32px / Bold (700) / Inter.
 */
export const DisplayTitle = React.forwardRef<
  HTMLHeadingElement,
  React.HTMLAttributes<HTMLHeadingElement>
>(({ className, children, ...props }, ref) => (
  <h1
    ref={ref}
    className={cn(
      'font-display text-3xl sm:text-[32px] font-bold text-ovs-text-primary tracking-tight leading-tight',
      className
    )}
    {...props}
  >
    {children}
  </h1>
));
DisplayTitle.displayName = 'DisplayTitle';

/**
 * Page Title (ExecutiveShell Primary Workspace Heading).
 * Canonical: 20px / Bold (700) / Plus Jakarta Sans / Slate-900.
 */
export const PageTitle = React.forwardRef<
  HTMLHeadingElement,
  React.HTMLAttributes<HTMLHeadingElement>
>(({ className, children, ...props }, ref) => (
  <h1
    ref={ref}
    className={cn(
      'font-display text-xl sm:text-[20px] font-bold text-ovs-text-primary tracking-tight leading-snug',
      className
    )}
    {...props}
  >
    {children}
  </h1>
));
PageTitle.displayName = 'PageTitle';

/**
 * Page Subtitle (Workspace Context Briefing).
 * Canonical: 12px / Regular (400) / Slate-500.
 */
export const PageSubtitle = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, children, ...props }, ref) => (
  <p
    ref={ref}
    className={cn('font-sans text-xs text-ovs-text-secondary font-normal leading-normal mt-0.5', className)}
    {...props}
  >
    {children}
  </p>
));
PageSubtitle.displayName = 'PageSubtitle';

/**
 * Section Title (Major Container / Section Heading).
 * Canonical: 16px / Bold (700) / Plus Jakarta Sans / Slate-900.
 */
export const SectionTitle = React.forwardRef<
  HTMLHeadingElement,
  React.HTMLAttributes<HTMLHeadingElement>
>(({ className, children, ...props }, ref) => (
  <h2
    ref={ref}
    className={cn(
      'font-display text-base sm:text-[16px] font-bold text-ovs-text-primary tracking-tight leading-snug',
      className
    )}
    {...props}
  >
    {children}
  </h2>
));
SectionTitle.displayName = 'SectionTitle';

/**
 * Card Title (Container Card / Widget Header).
 * Canonical: 14px / Bold (700) / Plus Jakarta Sans / Slate-900.
 */
export const CardTitle = React.forwardRef<
  HTMLHeadingElement,
  React.HTMLAttributes<HTMLHeadingElement>
>(({ className, children, ...props }, ref) => (
  <h3
    ref={ref}
    className={cn(
      'font-display text-sm font-bold text-ovs-text-primary tracking-tight leading-snug',
      className
    )}
    {...props}
  >
    {children}
  </h3>
));
CardTitle.displayName = 'CardTitle';

// ─────────────────────────────────────────────────────────────────────────────
// 2. ENTITY HIERARCHY PRIMITIVES (CLINIC / DOCTOR / METADATA)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Dominant Primary Entity Title (Clinic / Company / Customer / Lead Name).
 * Canonical: 14px / Bold (700) / Plus Jakarta Sans / Slate-900.
 * Must ALWAYS visually dominate secondary context and metadata.
 */
export const EntityTitle = React.forwardRef<
  HTMLHeadingElement,
  React.HTMLAttributes<HTMLHeadingElement> & { as?: 'h1' | 'h2' | 'h3' | 'h4' | 'span' | 'div' }
>(({ className, as: Component = 'span', children, ...props }, ref) => (
  <Component
    ref={ref as any}
    className={cn(
      'text-sm font-bold text-ovs-text-primary leading-snug tracking-tight font-display block truncate',
      className
    )}
    {...props}
  >
    {children}
  </Component>
));
EntityTitle.displayName = 'EntityTitle';

/**
 * Secondary Entity Context (Doctor / Principal Contact Name / Facility Role).
 * Canonical: 12px / Regular (400) / Inter / Slate-600.
 */
export const EntitySubtitle = React.forwardRef<
  HTMLSpanElement,
  React.HTMLAttributes<HTMLSpanElement>
>(({ className, children, ...props }, ref) => (
  <span
    ref={ref}
    className={cn('text-xs text-slate-600 font-normal font-sans block truncate leading-tight', className)}
    {...props}
  >
    {children}
  </span>
));
EntitySubtitle.displayName = 'EntitySubtitle';

/**
 * Tertiary Metadata (City, State / Branch Count / Relative Timestamps).
 * Canonical: 11px / Regular (400) / Inter / Slate-500.
 */
export const MetadataText = React.forwardRef<
  HTMLSpanElement,
  React.HTMLAttributes<HTMLSpanElement>
>(({ className, children, ...props }, ref) => (
  <span
    ref={ref}
    className={cn('text-[11px] text-slate-500 font-normal font-sans block truncate leading-tight', className)}
    {...props}
  >
    {children}
  </span>
));
MetadataText.displayName = 'MetadataText';

/**
 * Standard Body Copy Text.
 * Canonical: 13px / Regular (400) / Inter / Slate-600.
 */
export const BodyText = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, children, ...props }, ref) => (
  <p
    ref={ref}
    className={cn('text-[13px] text-slate-600 font-normal font-sans leading-relaxed', className)}
    {...props}
  >
    {children}
  </p>
));
BodyText.displayName = 'BodyText';

/**
 * Micro Label (Column Headers, Pinned Stage Headers, Form Over-Labels).
 * Canonical: 10px / Bold (700) / Uppercase / Tracking-Wider / Slate-500.
 */
export const MicroLabel = React.forwardRef<
  HTMLSpanElement,
  React.HTMLAttributes<HTMLSpanElement>
>(({ className, children, ...props }, ref) => (
  <span
    ref={ref}
    className={cn(
      'text-2xs font-bold uppercase tracking-wider text-slate-500 font-sans block select-none',
      className
    )}
    {...props}
  >
    {children}
  </span>
));
MicroLabel.displayName = 'MicroLabel';

// ─────────────────────────────────────────────────────────────────────────────
// 3. FINANCIAL TYPOGRAPHY PRIMITIVE (ZERO-VALUE SUPPRESSION)
// ─────────────────────────────────────────────────────────────────────────────

export interface FinancialTextProps extends React.HTMLAttributes<HTMLDivElement> {
  value?: number | null;
  frequency?: 'mo' | 'yr' | 'none';
  variant?: 'compact' | 'standard' | 'prominent';
  placeholder?: string;
  hideIfEmpty?: boolean;
}

/**
 * Canonical OVS Financial Value.
 * Displays formatted INR amounts with tabular-nums bold numbers and quiet Sans units.
 * STRICT ZERO-VALUE RULE: Never renders fake ₹0 or ARR ₹0.
 */
export const FinancialText: React.FC<FinancialTextProps> = ({
  value,
  frequency = 'mo',
  variant = 'standard',
  placeholder = 'Commercials pending',
  hideIfEmpty = false,
  className,
}) => {
  if (value === null || value === undefined || value <= 0) {
    if (hideIfEmpty) return null;
    return (
      <span className={cn('text-[11px] text-slate-400 font-normal font-sans select-none', className)}>
        {placeholder}
      </span>
    );
  }

  const formattedAmount = '₹ ' + Math.round(value).toLocaleString('en-IN');

  const sizeStyles = {
    compact: 'text-xs',
    standard: 'text-xs sm:text-sm',
    prominent: 'text-base sm:text-lg',
  };

  return (
    <div className={cn('inline-flex items-baseline gap-1 font-sans text-slate-900', sizeStyles[variant], className)}>
      <span className="font-bold tabular-nums tracking-tight">{formattedAmount}</span>
      {frequency !== 'none' && (
        <span className="text-[10px] font-normal text-slate-500">/{frequency}</span>
      )}
    </div>
  );
};

// ─────────────────────────────────────────────────────────────────────────────
// 4. DATE / TIME PRIMITIVE (STRICT SANS & OVERDUE EMPHASIS)
// ─────────────────────────────────────────────────────────────────────────────

export interface FollowupDateTextProps extends React.HTMLAttributes<HTMLSpanElement> {
  dateString?: string | null;
  formattedText?: string | null;
  isOverdue?: boolean;
  emptyLabel?: string;
}

/**
 * Canonical OVS Follow-up Date / Action Timestamp.
 * Renders 11px Sans with automatic red danger emphasis when overdue.
 * Monospace is strictly prohibited for commercial business dates.
 */
export const FollowupDateText: React.FC<FollowupDateTextProps> = ({
  dateString,
  formattedText,
  isOverdue = false,
  emptyLabel = 'No scheduled date',
  className,
}) => {
  const display =
    formattedText ||
    (dateString
      ? new Date(dateString).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })
      : emptyLabel);

  return (
    <span
      className={cn(
        'text-[11px] font-sans truncate',
        isOverdue ? 'text-rose-600 font-semibold' : 'text-slate-600 font-normal',
        className
      )}
    >
      {display}
    </span>
  );
};

// ─────────────────────────────────────────────────────────────────────────────
// 5. SHARED FORMATTING HELPERS
// ─────────────────────────────────────────────────────────────────────────────

export function formatINR(val?: number | null): string {
  if (val === null || val === undefined || val <= 0) return 'Not set';
  return '₹ ' + Math.round(val).toLocaleString('en-IN');
}

export function formatLakhs(val?: number | null): string {
  if (val === null || val === undefined || val <= 0) return '₹ 0';
  if (val >= 10000000) {
    return `₹ ${(val / 10000000).toFixed(2)} Cr`;
  }
  if (val >= 100000) {
    return `₹ ${(val / 100000).toFixed(2)} L`;
  }
  return `₹ ${Math.round(val).toLocaleString('en-IN')}`;
}
