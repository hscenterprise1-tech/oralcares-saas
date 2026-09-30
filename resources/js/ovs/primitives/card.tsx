import React from 'react';
import { cn } from '@/lib/utils';

export type CardElevation = 'surface' | 'flat' | 'elevated' | 'medium' | 'floating' | 'interactive' | 'glass';

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  elevation?: CardElevation;
  glow?: boolean;
}

/**
 * Card Component: Reserved for standalone movable objects, Kanban deal cards,
 * and compact KPI telemetry blocks.
 */
export const Card = React.forwardRef<HTMLDivElement, CardProps>(
  ({ className, elevation = 'surface', glow = false, ...props }, ref) => {
    const elevations: Record<CardElevation, string> = {
      surface: 'bg-ovs-surface border border-ovs-border shadow-ovs-surface',
      flat: 'bg-ovs-background border border-ovs-border-subtle',
      elevated: 'bg-ovs-surface border border-ovs-border shadow-ovs-elevated',
      medium: 'bg-ovs-surface border border-ovs-border shadow-ovs-medium',
      floating: 'bg-ovs-surface border border-ovs-border shadow-ovs-floating',
      glass: 'bg-ovs-glass-light backdrop-blur-md border border-white/80 shadow-ovs-surface',
      interactive: 'bg-ovs-surface border border-ovs-border shadow-ovs-surface hover:border-ovs-border-strong hover:shadow-ovs-medium hover:-translate-y-0.5 transition-all duration-200 cursor-pointer',
    };

    return (
      <div
        ref={ref}
        className={cn(
          'rounded-2xl transition-all duration-200 relative overflow-hidden font-sans',
          elevations[elevation],
          glow && 'before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-teal-500/60 before:to-transparent',
          className
        )}
        {...props}
      />
    );
  }
);
Card.displayName = 'Card';

export const CardHeader = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn('flex flex-col space-y-1.5 p-5 sm:p-6', className)} {...props} />
  )
);
CardHeader.displayName = 'CardHeader';

export const CardTitle = React.forwardRef<HTMLHeadingElement, React.HTMLAttributes<HTMLHeadingElement>>(
  ({ className, ...props }, ref) => (
    <h3 ref={ref} className={cn('text-sm font-bold text-ovs-text-primary leading-snug tracking-tight font-display', className)} {...props} />
  )
);
CardTitle.displayName = 'CardTitle';

export const CardContent = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn('p-5 sm:p-6 pt-0', className)} {...props} />
  )
);
CardContent.displayName = 'CardContent';

export const CardFooter = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn('flex items-center p-5 sm:p-6 pt-0', className)} {...props} />
  )
);
CardFooter.displayName = 'CardFooter';

// ─────────────────────────────────────────────────────────────────────────────
// 2. FIRST-CLASS SURFACE PRIMITIVES (DIVIDER-FIRST ARCHITECTURE)
// ─────────────────────────────────────────────────────────────────────────────

export interface SurfaceProps extends React.HTMLAttributes<HTMLDivElement> {
  elevated?: boolean;
}

/**
 * Surface Component: Canonical working surface for multi-field forms, settings groups,
 * detail workspaces, and structured data with internal dividers.
 */
export const Surface = React.forwardRef<HTMLDivElement, SurfaceProps>(
  ({ className, elevated = false, ...props }, ref) => (
    <div
      ref={ref}
      className={cn(
        'bg-ovs-surface border border-ovs-border rounded-2xl overflow-hidden font-sans',
        elevated ? 'shadow-ovs-elevated' : 'shadow-ovs-surface',
        className
      )}
      {...props}
    />
  )
);
Surface.displayName = 'Surface';

export const SurfaceHeader = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      className={cn('px-5 py-4 border-b border-ovs-border-subtle flex items-center justify-between', className)}
      {...props}
    />
  )
);
SurfaceHeader.displayName = 'SurfaceHeader';

export const SurfaceSection = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn('p-5 sm:p-6', className)} {...props} />
  )
);
SurfaceSection.displayName = 'SurfaceSection';
