import React from 'react';
import { cn } from '@/lib/utils';

/**
 * The CRM's card and surface primitives.
 *
 * An API-compatible fork of `@/ovs/primitives/card`, for the same reason
 * `CrmDataTable` forks the shared table: that file paints the legacy palette
 * and Marketing alone imports it twenty-five times, so restyling it in place
 * would redecorate four modules nobody asked to change.
 *
 * Same component names, same props, same elevation vocabulary — so a page moves
 * across by changing one import line. What changed is the treatment: solid
 * white with a soft diffuse shadow and no border, a 24px radius, and the
 * `ovs-crm-*` tokens throughout.
 *
 * The elevation names survive because pages already choose between them, but
 * they now differ by shadow depth alone rather than by growing a border — a
 * hairline around a white card on a pale ground is what made the old surfaces
 * read as boxes stacked on boxes.
 */

export type CardElevation = 'surface' | 'flat' | 'elevated' | 'medium' | 'floating' | 'interactive' | 'glass';

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  elevation?: CardElevation;
  glow?: boolean;
}

export const Card = React.forwardRef<HTMLDivElement, CardProps>(
  ({ className, elevation = 'surface', glow = false, ...props }, ref) => {
    const elevations: Record<CardElevation, string> = {
      surface: 'bg-white shadow-ovs-crmPanel',
      flat: 'bg-ovs-crm-surface-inset',
      elevated: 'bg-white shadow-ovs-crmCard',
      medium: 'bg-white shadow-ovs-crmCard',
      floating: 'bg-white shadow-ovs-crmLift',
      glass: 'bg-ovs-crm-surface-veil-strong backdrop-blur-xl shadow-ovs-crmPanel',
      interactive: 'crm-lift cursor-pointer bg-white shadow-ovs-crmPanel hover:shadow-ovs-crmLift',
    };

    return (
      <div
        ref={ref}
        className={cn(
          'relative overflow-hidden rounded-[24px]',
          elevations[elevation],
          glow &&
            'before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-ovs-crm-brand/60 before:to-transparent',
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
    <div ref={ref} className={cn('flex flex-col space-y-1 p-5', className)} {...props} />
  )
);
CardHeader.displayName = 'CardHeader';

export const CardTitle = React.forwardRef<HTMLHeadingElement, React.HTMLAttributes<HTMLHeadingElement>>(
  ({ className, ...props }, ref) => (
    <h3
      ref={ref}
      className={cn('text-[15px] font-semibold leading-snug tracking-[-0.01em] text-ovs-crm-text-primary', className)}
      {...props}
    />
  )
);
CardTitle.displayName = 'CardTitle';

export const CardContent = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => <div ref={ref} className={cn('p-5 pt-0', className)} {...props} />
);
CardContent.displayName = 'CardContent';

export const CardFooter = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn('flex items-center p-5 pt-0', className)} {...props} />
  )
);
CardFooter.displayName = 'CardFooter';

/* ───────────────────────────────────────────────────────────────── surface */

export interface SurfaceProps extends React.HTMLAttributes<HTMLDivElement> {
  elevated?: boolean;
}

export const Surface = React.forwardRef<HTMLDivElement, SurfaceProps>(
  ({ className, elevated = false, ...props }, ref) => (
    <div
      ref={ref}
      className={cn(
        'overflow-hidden rounded-[24px] bg-white',
        elevated ? 'shadow-ovs-crmCard' : 'shadow-ovs-crmPanel',
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
      className={cn('flex items-center justify-between border-b border-ovs-crm-border px-5 py-4', className)}
      {...props}
    />
  )
);
SurfaceHeader.displayName = 'SurfaceHeader';

export const SurfaceSection = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => <div ref={ref} className={cn('p-5', className)} {...props} />
);
SurfaceSection.displayName = 'SurfaceSection';
