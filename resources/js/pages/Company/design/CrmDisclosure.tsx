import React from 'react';
import { ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Reference material, one line high until you want it.
 *
 * Pages kept giving equal weight to everything they held: a deal showed its
 * facility details, its contacts, its documents and its files as four full
 * cards, so a screen that should answer "what do I do next" answered eleven
 * questions at once and led with none of them. Cutting the material is not the
 * answer — a rep does need the address eventually — but showing it all, always,
 * is what makes a page feel complicated.
 *
 * A disclosure states that something exists and how much of it there is, in one
 * line, and opens when asked. That is the difference between a page you scan
 * and a page you survey.
 *
 * The count is deliberately part of the closed state: "Contacts 0" and
 * "Contacts 3" are different facts, and hiding the number behind the toggle
 * means opening it just to find out there was nothing there.
 */
export function CrmDisclosure({
  label,
  count,
  hint,
  defaultOpen = false,
  children,
  className,
}: {
  label: string;
  /** Shown beside the label. `undefined` when the thing is not countable. */
  count?: number;
  /** A few words of context, shown only while closed. */
  hint?: string;
  defaultOpen?: boolean;
  children: React.ReactNode;
  className?: string;
}) {
  const [open, setOpen] = React.useState(defaultOpen);

  return (
    <div
      className={cn(
        'overflow-hidden rounded-[16px] bg-white shadow-ovs-crmPanel transition-shadow',
        open && 'shadow-ovs-crmLift',
        className
      )}
    >
      <button
        type="button"
        onClick={() => setOpen((value) => ! value)}
        aria-expanded={open}
        className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-ovs-crm-surface-inset/60"
      >
        <ChevronRight
          className={cn(
            'h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted transition-transform duration-200',
            open && 'rotate-90'
          )}
          aria-hidden="true"
        />

        <span className="text-[13px] font-medium text-ovs-crm-text-primary">{label}</span>

        {typeof count === 'number' && (
          <span
            className={cn(
              'rounded-full px-1.5 text-[10.5px] font-semibold tabular-nums',
              count > 0
                ? 'bg-ovs-crm-surface-inset text-ovs-crm-text-secondary'
                : 'text-ovs-crm-text-muted'
            )}
          >
            {count}
          </span>
        )}

        {/* Context is only worth space while the thing itself is not on screen. */}
        {hint && ! open && (
          <span className="ml-auto truncate text-[11.5px] text-ovs-crm-text-muted">{hint}</span>
        )}
      </button>

      {open && <div className="border-t border-ovs-crm-border-soft px-4 py-4">{children}</div>}
    </div>
  );
}
