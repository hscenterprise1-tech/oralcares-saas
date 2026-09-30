import React, { useState } from 'react';
import { ChevronDown, ChevronUp, AlertCircle, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';

export type FormLayoutMode = 'full' | 'drawer' | 'dialog';
export type FormDensity = 'compact' | 'default' | 'comfortable';

/* ─── 1. OVS FORM SURFACE ─────────────────────────────────────────────────── */
export interface OVSFormSurfaceProps extends React.HTMLAttributes<HTMLDivElement> {
  mode?: FormLayoutMode;
  onSubmit?: (e: React.FormEvent) => void;
  children: React.ReactNode;
}

export const OVSFormSurface: React.FC<OVSFormSurfaceProps> = ({
  mode = 'full',
  onSubmit,
  className,
  children,
  ...props
}) => {
  const content = (
    <div
      className={cn(
        'font-sans text-slate-900',
        mode === 'full' && 'bg-white rounded-xl border border-slate-200/90 shadow-2xs overflow-hidden',
        mode === 'drawer' && 'flex flex-col h-full bg-white',
        mode === 'dialog' && 'space-y-4',
        className
      )}
      {...props}
    >
      {children}
    </div>
  );

  if (onSubmit) {
    return (
      <form onSubmit={onSubmit} className="w-full">
        {content}
      </form>
    );
  }

  return content;
};

/* ─── 2. OVS FORM SECTION ─────────────────────────────────────────────────── */
export interface OVSFormSectionProps extends React.HTMLAttributes<HTMLDivElement> {
  title?: string;
  description?: string;
  badge?: React.ReactNode;
  action?: React.ReactNode;
  divider?: boolean;
  columns?: 1 | 2 | 3;
  mode?: FormLayoutMode;
  density?: FormDensity;
  collapsible?: boolean;
  defaultOpen?: boolean;
  children: React.ReactNode;
}

export const OVSFormSection: React.FC<OVSFormSectionProps> = ({
  title,
  description,
  badge,
  action,
  divider = false,
  columns = 2,
  mode = 'full',
  density = 'default',
  collapsible = false,
  defaultOpen = true,
  className,
  children,
  ...props
}) => {
  const [isOpen, setIsOpen] = useState(defaultOpen);

  // Column layout grid classes
  const gridClasses = {
    1: 'grid grid-cols-1',
    2: 'grid grid-cols-1 md:grid-cols-2',
    3: 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
  }[columns];

  // Density gap classes
  const gapClasses = {
    compact: 'gap-3',
    default: 'gap-4',
    comfortable: 'gap-5',
  }[density];

  // Padding classes for full container vs drawer
  const containerPadding = {
    full: 'p-5 sm:p-6',
    drawer: 'p-4 sm:p-5',
    dialog: 'p-1',
  }[mode];

  // Divider keyline styling
  const dividerClasses = divider
    ? mode === 'drawer'
      ? 'border-t border-slate-200/80 pt-4 mt-4'
      : 'border-t border-slate-200/80 pt-6 mt-6'
    : '';

  return (
    <section
      className={cn(
        'font-sans',
        dividerClasses,
        !divider && containerPadding,
        divider && mode === 'full' && 'px-5 sm:px-6 pb-5 sm:pb-6',
        divider && mode === 'drawer' && 'px-4 sm:px-5 pb-4',
        className
      )}
      {...props}
    >
      {/* Section Header (Title Case, calm secondary description) */}
      {(title || description || action || badge) && (
        <div className="flex items-start justify-between gap-3 mb-4 select-none">
          <div className="space-y-0.5 min-w-0">
            <div className="flex items-center gap-2 flex-wrap">
              {title && (
                <h3 className="text-sm sm:text-base font-semibold text-slate-900 tracking-tight leading-snug">
                  {title}
                </h3>
              )}
              {badge}
            </div>
            {description && (
              <p className="text-xs text-slate-500 font-normal leading-normal max-w-2xl">
                {description}
              </p>
            )}
          </div>

          {/* Right Action / Collapse toggle */}
          <div className="flex items-center gap-2 shrink-0">
            {action}
            {collapsible && (
              <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="p-1 rounded-md text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
                title={isOpen ? 'Collapse Section' : 'Expand Section'}
              >
                {isOpen ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
              </button>
            )}
          </div>
        </div>
      )}

      {/* Field Grid Body */}
      {(!collapsible || isOpen) && (
        <div className={cn(gridClasses, gapClasses, 'animate-in fade-in duration-150')}>
          {children}
        </div>
      )}
    </section>
  );
};

/* ─── 3. OVS FORM FIELD ───────────────────────────────────────────────────── */
export interface OVSFormFieldProps extends React.HTMLAttributes<HTMLDivElement> {
  label?: string;
  required?: boolean;
  optional?: boolean;
  error?: string;
  helperText?: string;
  htmlFor?: string;
  colSpan?: 1 | 2 | 3 | 'full';
  children: React.ReactNode;
}

export const OVSFormField: React.FC<OVSFormFieldProps> = ({
  label,
  required = false,
  optional = false,
  error,
  helperText,
  htmlFor,
  colSpan,
  className,
  children,
  ...props
}) => {
  const colSpanClasses = {
    1: 'md:col-span-1',
    2: 'md:col-span-2',
    3: 'lg:col-span-3',
    full: 'col-span-full',
  }[colSpan || 1];

  return (
    <div className={cn('space-y-1.5 font-sans', colSpanClasses, className)} {...props}>
      {/* Label Row */}
      {label && (
        <div className="flex items-center justify-between gap-2">
          <label
            htmlFor={htmlFor}
            className="text-xs font-semibold text-slate-700 select-none block leading-tight"
          >
            {label}
            {required && <span className="text-rose-500 ml-0.5" title="Required field">*</span>}
          </label>
          {optional && (
            <span className="text-[10px] text-slate-400 font-normal select-none">Optional</span>
          )}
        </div>
      )}

      {/* Field Input Control */}
      <div className="relative">{children}</div>

      {/* Validation Message / Helper Subtext */}
      {error ? (
        <p className="text-2xs text-rose-600 font-medium leading-normal animate-in fade-in duration-150">
          {error}
        </p>
      ) : helperText ? (
        <p className="text-2xs text-slate-400 leading-normal select-none">
          {helperText}
        </p>
      ) : null}
    </div>
  );
};

/* ─── 4. OVS FORM FOOTER ──────────────────────────────────────────────────── */
export interface OVSFormFooterProps extends React.HTMLAttributes<HTMLDivElement> {
  mode?: FormLayoutMode;
  primaryLabel?: string;
  primaryLoading?: boolean;
  primaryDisabled?: boolean;
  onPrimaryClick?: (e: React.MouseEvent) => void;
  secondaryLabel?: string;
  onSecondaryClick?: (e: React.MouseEvent) => void;
  destructiveLabel?: string;
  onDestructiveClick?: (e: React.MouseEvent) => void;
  sticky?: boolean;
  children?: React.ReactNode;
}

export const OVSFormFooter: React.FC<OVSFormFooterProps> = ({
  mode = 'full',
  primaryLabel = 'Save Changes',
  primaryLoading = false,
  primaryDisabled = false,
  onPrimaryClick,
  secondaryLabel = 'Cancel',
  onSecondaryClick,
  destructiveLabel,
  onDestructiveClick,
  sticky = false,
  className,
  children,
  ...props
}) => {
  return (
    <div
      className={cn(
        'font-sans flex items-center justify-between gap-3 text-xs',
        mode === 'full' && 'bg-slate-50/80 border-t border-slate-200/90 p-4 sm:px-6 rounded-b-xl',
        mode === 'drawer' && 'sticky bottom-0 bg-white border-t border-slate-200/90 p-4 shadow-xs z-10',
        mode === 'dialog' && 'border-t border-slate-200/90 pt-4 mt-4',
        sticky && mode !== 'drawer' && 'sticky bottom-0 z-10 bg-white/95 backdrop-blur-xs',
        className
      )}
      {...props}
    >
      {/* Left Destructive Action or Custom Children */}
      <div>
        {destructiveLabel && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={onDestructiveClick}
            className="text-rose-600 hover:text-rose-700 hover:bg-rose-50 font-semibold text-xs h-8 px-3"
          >
            {destructiveLabel}
          </Button>
        )}
        {children}
      </div>

      {/* Right Primary & Secondary Actions */}
      <div className="flex items-center gap-2">
        {secondaryLabel && (
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={onSecondaryClick}
            className="font-medium text-xs h-8 px-3 text-slate-700"
          >
            {secondaryLabel}
          </Button>
        )}

        {primaryLabel && (
          <Button
            type="submit"
            variant="brand"
            size="sm"
            disabled={primaryDisabled || primaryLoading}
            onClick={onPrimaryClick}
            className="font-semibold text-xs h-8 px-4 gap-1.5 shadow-2xs"
          >
            {primaryLoading && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
            <span>{primaryLabel}</span>
          </Button>
        )}
      </div>
    </div>
  );
};

/* ─── 5. OVS FORM ALERT ───────────────────────────────────────────────────── */
export interface OVSFormAlertProps extends React.HTMLAttributes<HTMLDivElement> {
  title?: string;
  message: string;
  variant?: 'error' | 'warning' | 'info';
}

export const OVSFormAlert: React.FC<OVSFormAlertProps> = ({
  title = 'Please check the highlighted fields',
  message,
  variant = 'error',
  className,
  ...props
}) => {
  return (
    <div
      className={cn(
        'rounded-lg p-3 text-xs flex items-start gap-2.5 border font-sans',
        variant === 'error' && 'bg-rose-50/70 border-rose-200 text-rose-800',
        variant === 'warning' && 'bg-amber-50/70 border-amber-200 text-amber-800',
        variant === 'info' && 'bg-teal-50/70 border-teal-200 text-teal-800',
        className
      )}
      {...props}
    >
      <AlertCircle className="w-4 h-4 shrink-0 mt-0.5 text-rose-600" />
      <div className="space-y-0.5">
        {title && <h5 className="font-semibold">{title}</h5>}
        <p className="text-[11px] text-rose-700/90 leading-normal">{message}</p>
      </div>
    </div>
  );
};
