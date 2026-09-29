import React from 'react';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { inr, toneClasses, type CrmTone } from './crmTheme';

/**
 * CRM layout primitives, tuned to the reference design language.
 *
 * The references share a specific structural voice: a very large near-black
 * page title, translucent panels with generous radii floating on a tinted
 * ground, metric figures set big and light, and a small number of saturated
 * accent tiles among otherwise neutral surfaces.
 *
 * Depth comes from translucency over the tinted canvas, not from heavy drop
 * shadows — that is what keeps these layouts feeling soft rather than boxed.
 */

/* ────────────────────────────────────────────────────────────── page header */

export function CrmSplitWorkspace({
  main,
  rail,
  className,
}: {
  main: React.ReactNode;
  rail: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_340px]', className)}>
      <div className="min-w-0 space-y-4">{main}</div>
      <aside className="order-first min-w-0 space-y-4 xl:order-none xl:sticky xl:top-24 xl:self-start">
        {rail}
      </aside>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────── data display */

export function CrmField({
  label,
  value,
  icon: Icon,
  className,
}: {
  label: string;
  value: React.ReactNode;
  icon?: LucideIcon;
  className?: string;
}) {
  return (
    <div className={cn('flex items-center gap-3 py-2', className)}>
      {Icon && (
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ovs-crm-surface-inset text-ovs-crm-text-muted">
          <Icon className="h-3.5 w-3.5" aria-hidden="true" />
        </span>
      )}
      <div className="min-w-0 flex-1">
        <dt className="text-[10px] font-semibold uppercase tracking-[0.08em] text-ovs-crm-text-muted">
          {label}
        </dt>
        <dd className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">
          {value ?? <span className="font-normal text-ovs-crm-text-muted">—</span>}
        </dd>
      </div>
    </div>
  );
}