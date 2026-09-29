import React from 'react';
import { ChevronUp, SlidersHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CrmButton } from './CrmControls';

/**
 * The expanding filter panel, and the controls that live inside it.
 *
 * Marketing's Contacts list worked this out first — a toggle carrying the
 * active count, a panel that grows the page rather than floating over it, and
 * one control shape repeated inside — and it is the most mature filtering UX in
 * the product. These pieces were local to that page, so the CRM either had to
 * copy them or invent something different. Both are worse than lifting them
 * here, which is what the design system is for.
 *
 * What stays with each page is the part that is genuinely its own: which
 * filters exist, and what a chip says when one is on. Those know their domain.
 * The chrome does not, so the chrome lives here.
 */

/**
 * Opens the panel and reports how many filters are currently doing something.
 *
 * The count belongs on the trigger: a collapsed panel that is silently
 * filtering the list is how people conclude the data is wrong.
 */
export function CrmFiltersToggle({
  open,
  onToggle,
  activeCount,
  label = 'Filters',
}: {
  open: boolean;
  onToggle: () => void;
  activeCount: number;
  label?: string;
}) {
  return (
    <CrmButton
      icon={open ? ChevronUp : SlidersHorizontal}
      onClick={onToggle}
      aria-expanded={open}
    >
      {activeCount > 0 ? `${label} (${activeCount})` : label}
    </CrmButton>
  );
}

/**
 * The panel itself — in the page's own flow, never floating.
 *
 * Clicking the toggle grows the page by exactly this block's height and
 * clicking it again shrinks it back. Nothing here is positioned, flipped, or
 * clipped against a viewport edge, which is the failure mode of every filter
 * dropdown that has to guess where the bottom of the screen is.
 */
export function CrmFilterPanel({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('crm-pop mb-4 rounded-[20px] bg-ovs-crm-surface-inset p-4', className)}>
      <div className="gap-x-6 sm:columns-2 lg:columns-3">{children}</div>
    </div>
  );
}

/**
 * One group inside the panel: an icon, a name, a count of what it is doing,
 * and its controls on a white ground.
 *
 * Every group wears the same clothes so the panel reads as one thing rather
 * than as several unrelated widgets stacked together.
 */
export function CrmFilterGroup({
  icon: Icon,
  label,
  active,
  children,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  active?: number;
  children: React.ReactNode;
}) {
  return (
    <section className="mb-4 break-inside-avoid last:mb-0">
      <div className="mb-2 flex items-center gap-2">
        <Icon className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
        <span className="text-[11px] font-semibold uppercase tracking-[0.1em] text-ovs-crm-text-secondary">
          {label}
        </span>
        {active ? (
          <span className="ml-auto rounded-full bg-ovs-crm-ink px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-white">
            {active}
          </span>
        ) : null}
      </div>

      <div className="rounded-[16px] bg-white p-3">{children}</div>
    </section>
  );
}

/** A pill in a group of pills — the panel's one selectable control shape. */
export function CrmFilterPill({
  on,
  hue,
  onClick,
  children,
}: {
  on: boolean;
  hue?: string;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={on}
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[11.5px] font-medium transition-colors',
        on
          ? 'bg-ovs-crm-ink text-white'
          : 'bg-ovs-crm-surface-inset text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary'
      )}
    >
      {hue && (
        <span
          className="h-1.5 w-1.5 shrink-0 rounded-full"
          style={{ backgroundColor: on ? 'rgba(255,255,255,0.7)' : hue }}
          aria-hidden="true"
        />
      )}
      {children}
    </button>
  );
}
