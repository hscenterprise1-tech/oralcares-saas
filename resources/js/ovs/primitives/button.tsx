import React from 'react';
import { cn } from '@/lib/utils';

export type ButtonVariant = 'primary' | 'brand' | 'brand-gradient' | 'secondary' | 'outline' | 'ghost' | 'destructive' | 'danger' | 'glass';
export type ButtonSize = 'sm' | 'md' | 'lg' | 'icon';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  isLoading?: boolean;
}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant = 'primary', size = 'md', isLoading, children, ...props }, ref) => {
    const baseStyles =
      'inline-flex items-center justify-center font-semibold font-sans tracking-tight transition-all duration-150 active:scale-[0.98] active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-ovs-accent/30 focus-visible:border-ovs-accent disabled:opacity-50 disabled:pointer-events-none disabled:active:scale-100 select-none cursor-pointer';

    const variants: Record<ButtonVariant, string> = {
      primary: 'bg-ovs-primary text-ovs-primary-foreground hover:bg-ovs-primary-hover shadow-ovs-interactive hover:shadow-ovs-medium hover:-translate-y-0.5 border border-ovs-primary',
      brand: 'bg-[#0A2540] text-white hover:bg-[#071A2E] shadow-ovs-interactive hover:shadow-ovs-medium hover:-translate-y-0.5 border border-[#0A2540]',
      'brand-gradient': 'bg-gradient-to-r from-indigo-600 via-indigo-600 to-indigo-700 text-white hover:from-indigo-700 hover:to-indigo-800 shadow-[0_4px_16px_rgba(99,102,241,0.35),0_2px_4px_rgba(99,102,241,0.2)] hover:shadow-[0_8px_24px_rgba(99,102,241,0.45)] hover:-translate-y-0.5 border border-indigo-500/40 font-semibold',
      secondary: 'bg-ovs-surface border border-ovs-border text-ovs-text-primary hover:bg-ovs-background hover:text-ovs-text-primary hover:border-ovs-border-strong shadow-ovs-keyline hover:shadow-ovs-surface hover:-translate-y-0.5',
      outline: 'bg-transparent border border-ovs-border text-ovs-text-primary hover:bg-ovs-background hover:border-ovs-border-strong',
      ghost: 'bg-transparent text-ovs-text-secondary hover:bg-ovs-background hover:text-ovs-text-primary border border-transparent',
      destructive: 'bg-ovs-semantic-danger text-white hover:bg-rose-700 shadow-ovs-interactive hover:shadow-ovs-medium hover:-translate-y-0.5 border border-ovs-semantic-danger',
      danger: 'bg-ovs-semantic-danger text-white hover:bg-rose-700 shadow-ovs-interactive hover:shadow-ovs-medium hover:-translate-y-0.5 border border-ovs-semantic-danger',
      glass: 'bg-ovs-glass-light backdrop-blur-md border border-white/60 text-ovs-text-primary hover:bg-white/95 shadow-ovs-keyline hover:-translate-y-0.5',
    };

    const sizes: Record<ButtonSize, string> = {
      sm: 'h-[32px] min-h-[32px] px-3 text-xs font-medium rounded-lg gap-1.5',
      md: 'h-[38px] min-h-[38px] px-4 text-xs rounded-xl gap-2',
      lg: 'h-[44px] min-h-[44px] px-5 text-sm rounded-xl gap-2.5',
      icon: 'h-[38px] w-[38px] min-h-[38px] min-w-[38px] p-0 rounded-xl flex items-center justify-center',
    };

    return (
      <button
        ref={ref}
        className={cn(baseStyles, variants[variant], sizes[size], className)}
        disabled={isLoading || props.disabled}
        aria-busy={isLoading ? true : undefined}
        {...props}
      >
        {isLoading ? (
          <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent shrink-0" />
        ) : null}
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
  ({ icon: Icon, children, className, size = 'icon', variant = 'secondary', ...props }, ref) => {
    return (
      <Button
        ref={ref}
        size={size}
        variant={variant}
        className={cn('shrink-0', className)}
        {...props}
      >
        {Icon ? (typeof Icon === 'function' ? <Icon className="w-4 h-4" /> : Icon) : children}
      </Button>
    );
  }
);
IconButton.displayName = 'IconButton';
