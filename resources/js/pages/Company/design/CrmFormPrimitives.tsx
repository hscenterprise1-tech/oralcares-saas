import React from 'react';
import * as LabelPrimitive from '@radix-ui/react-label';
import * as SwitchPrimitives from '@radix-ui/react-switch';
import { cn } from '@/lib/utils';

/**
 * The CRM's fork of the shared button, input, textarea, label, switch and
 * icon-chip primitives.
 *
 * Same component names, same props, same variant and size vocabularies — pages
 * move across by changing one import line, exactly as with `CrmDataTable` and
 * `CrmCardPrimitives`. The shared originals stay untouched because Marketing,
 * Finance, Visual Studio and Deployments all depend on them.
 *
 * What changed:
 *
 *   - controls are fully round, not softly rounded, and sit on the inset
 *     surface with no border until focus
 *   - `primary` is the ink pill; `secondary` is white with a soft shadow
 *   - the raw slate/teal/rose literals become `ovs-crm-*` tokens
 *   - `IconChip`'s glossy three-stop gradients become a flat tinted disc, which
 *     is what every reference does — a gradient bevel on a 24px square reads as
 *     decoration from a previous decade
 */

/* ────────────────────────────────────────────────────────────────── button */

export type ButtonVariant =
  | 'primary' | 'brand' | 'brand-gradient' | 'secondary' | 'outline' | 'ghost' | 'destructive' | 'danger' | 'glass';
export type ButtonSize = 'sm' | 'md' | 'lg' | 'icon';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  isLoading?: boolean;
}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant = 'primary', size = 'md', isLoading, children, ...props }, ref) => {
    const base =
      'crm-lift inline-flex select-none cursor-pointer items-center justify-center font-medium ' +
      'focus:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/25 ' +
      'disabled:pointer-events-none disabled:opacity-45';

    /*
     * `brand` and `brand-gradient` collapse onto the ink pill. They existed to
     * make one button louder than `primary`; with a single black pill as the
     * primary action, a second louder treatment only competes with it.
     */
    const variants: Record<ButtonVariant, string> = {
      primary: 'bg-ovs-crm-ink text-white hover:bg-ovs-crm-ink-soft',
      brand: 'bg-ovs-crm-ink text-white hover:bg-ovs-crm-ink-soft',
      'brand-gradient': 'bg-ovs-crm-ink text-white hover:bg-ovs-crm-ink-soft',
      secondary: 'bg-white text-ovs-crm-text-primary shadow-ovs-crmPanel hover:bg-ovs-crm-surface-inset',
      outline: 'border border-ovs-crm-border bg-transparent text-ovs-crm-text-primary hover:bg-ovs-crm-surface-inset',
      ghost: 'bg-transparent text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary',
      destructive: 'bg-ovs-crm-accent-coral text-white hover:brightness-95',
      danger: 'bg-ovs-crm-accent-coral text-white hover:brightness-95',
      glass: 'bg-ovs-crm-surface-veil-strong text-ovs-crm-text-primary backdrop-blur-xl hover:bg-white',
    };

    const sizes: Record<ButtonSize, string> = {
      sm: 'h-8 gap-1.5 rounded-full px-3 text-[11.5px]',
      md: 'h-10 gap-2 rounded-full px-4 text-[13px]',
      lg: 'h-11 gap-2 rounded-full px-5 text-[13px]',
      icon: 'h-10 w-10 shrink-0 rounded-full p-0',
    };

    return (
      <button
        ref={ref}
        className={cn(base, variants[variant], sizes[size], className)}
        disabled={isLoading || props.disabled}
        aria-busy={isLoading ? true : undefined}
        {...props}
      >
        {isLoading && (
          <span className="h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent" />
        )}
        {children}
      </button>
    );
  }
);
Button.displayName = 'Button';

export interface IconButtonProps extends Omit<ButtonProps, 'children'> {
  icon?: React.ComponentType<{ className?: string }> | React.ReactNode;
  'aria-label': string;
  children?: React.ReactNode;
}

export const IconButton = React.forwardRef<HTMLButtonElement, IconButtonProps>(
  ({ icon: Icon, children, className, size = 'icon', variant = 'secondary', ...props }, ref) => (
    <Button ref={ref} size={size} variant={variant} className={cn('shrink-0', className)} {...props}>
      {Icon ? (typeof Icon === 'function' ? <Icon className="h-4 w-4" /> : Icon) : children}
    </Button>
  )
);
IconButton.displayName = 'IconButton';

/* ─────────────────────────────────────────────────────────────────── input */

const CONTROL =
  'w-full bg-ovs-crm-surface-inset text-[13px] text-ovs-crm-text-primary ' +
  'placeholder:text-ovs-crm-text-muted transition-shadow ' +
  'focus-visible:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/20 ' +
  'disabled:cursor-not-allowed disabled:opacity-55';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  error?: boolean;
  iconPrefix?: React.ReactNode;
  iconSuffix?: React.ReactNode;
}

export const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ className, type, error, iconPrefix, iconSuffix, ...props }, ref) => (
    <div className="relative flex w-full items-center">
      {iconPrefix && (
        <div className="pointer-events-none absolute left-4 flex items-center text-ovs-crm-text-muted">{iconPrefix}</div>
      )}
      <input
        type={type}
        ref={ref}
        aria-invalid={error ? true : props['aria-invalid']}
        className={cn(
          CONTROL,
          'h-11 rounded-[16px] px-4',
          iconPrefix && 'pl-11',
          iconSuffix && 'pr-11',
          error && 'ring-2 ring-ovs-crm-accent-coral/40',
          className
        )}
        {...props}
      />
      {iconSuffix && (
        <div className="pointer-events-none absolute right-4 flex items-center text-ovs-crm-text-muted">{iconSuffix}</div>
      )}
    </div>
  )
);
Input.displayName = 'Input';

export interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  error?: boolean;
}

const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, error, ...props }, ref) => (
    <textarea
      ref={ref}
      aria-invalid={error ? true : props['aria-invalid']}
      className={cn(
        CONTROL,
        'min-h-[96px] resize-y rounded-[16px] px-4 py-3 leading-relaxed',
        error && 'ring-2 ring-ovs-crm-accent-coral/40',
        className
      )}
      {...props}
    />
  )
);
Textarea.displayName = 'Textarea';

/* ─────────────────────────────────────────────────────────── label & switch */

const Label = React.forwardRef<
  React.ElementRef<typeof LabelPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof LabelPrimitive.Root>
>(({ className, ...props }, ref) => (
  <LabelPrimitive.Root
    ref={ref}
    className={cn(
      'select-none text-[11.5px] font-medium leading-none text-ovs-crm-text-secondary',
      'peer-disabled:cursor-not-allowed peer-disabled:opacity-70',
      className
    )}
    {...props}
  />
));
Label.displayName = LabelPrimitive.Root.displayName;

const Switch = React.forwardRef<
  React.ElementRef<typeof SwitchPrimitives.Root>,
  React.ComponentPropsWithoutRef<typeof SwitchPrimitives.Root>
>(({ className, ...props }, ref) => (
  <SwitchPrimitives.Root
    ref={ref}
    className={cn(
      'peer inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors duration-200',
      'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/20 focus-visible:ring-offset-2',
      'disabled:cursor-not-allowed disabled:opacity-50',
      'data-[state=checked]:bg-ovs-crm-ink data-[state=unchecked]:bg-ovs-crm-border',
      className
    )}
    {...props}
  >
    <SwitchPrimitives.Thumb className="pointer-events-none block h-4 w-4 rounded-full bg-white ring-0 transition-transform duration-200 data-[state=checked]:translate-x-4 data-[state=unchecked]:translate-x-0" />
  </SwitchPrimitives.Root>
));
Switch.displayName = SwitchPrimitives.Root.displayName;

/* ──────────────────────────────────────────────────────────────── icon chip */

export type IconChipTone =
  | 'teal' | 'indigo' | 'blue' | 'emerald' | 'amber' | 'rose' | 'purple' | 'slate' | 'neutral' | 'brand';
export type IconChipSize = 'xs' | 'sm' | 'md' | 'lg';

export interface IconChipProps extends React.HTMLAttributes<HTMLDivElement> {
  tone?: IconChipTone;
  size?: IconChipSize;
  icon?: React.ReactNode;
}

/** Tone → the CRM hue it means. Legacy names are kept so callers need no edit. */
const CHIP_HUE: Record<IconChipTone, string> = {
  teal: '#3E93A3',
  indigo: '#7C6FE0',
  brand: '#7C6FE0',
  purple: '#7C6FE0',
  blue: '#2F6BE4',
  emerald: '#2FA96B',
  amber: '#E0B21E',
  rose: '#EF5B39',
  slate: '#6B7488',
  neutral: '#6B7488',
};

export const IconChip: React.FC<IconChipProps> = ({
  tone = 'indigo',
  size = 'md',
  icon,
  className,
  children,
  ...props
}) => {
  const sizeClasses: Record<IconChipSize, string> = {
    xs: 'h-7 w-7 text-[10px]',
    sm: 'h-8 w-8 text-[11.5px]',
    md: 'h-10 w-10 text-[13px]',
    lg: 'h-11 w-11 text-[13px]',
  };

  const hue = CHIP_HUE[tone];

  return (
    <div
      className={cn(
        'inline-flex shrink-0 select-none items-center justify-center rounded-full font-semibold',
        sizeClasses[size],
        className
      )}
      style={{ backgroundColor: `${hue}1A`, color: hue }}
      {...props}
    >
      {icon || children}
    </div>
  );
};

export { Textarea, Label, Switch };
