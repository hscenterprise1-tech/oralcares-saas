import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { DashboardWidget } from '../types';
import { iconFor, toneFor } from '../ui/visuals';

/**
 * The frame every widget shares: a white card on the canvas, its icon in a
 * tinted chip, the title, and at most one link. Compact by design: the
 * dashboard shows what is happening, the module pages hold the detail.
 *
 * What goes inside depends on the widget's kind, never on the user's role.
 */
export function WidgetCard({
  widget,
  aside,
  children,
}: {
  widget: DashboardWidget;
  /** A small figure beside the title, e.g. a list's total. */
  aside?: ReactNode;
  children: ReactNode;
}) {
  const tone = toneFor(widget.tone);
  const Icon = iconFor(widget.icon);

  return (
    <section
      aria-labelledby={`widget-${widget.key}`}
      className="flex min-w-0 flex-col rounded-[22px] border border-white/80 bg-white/[0.93] shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-16px_rgba(16,24,40,0.12)] backdrop-blur-sm"
    >
      <header className="flex items-center gap-2.5 px-4 pt-4">
        <span className={cn('flex h-8 w-8 shrink-0 items-center justify-center rounded-full', tone.chip)}>
          <Icon className="h-4 w-4" aria-hidden="true" />
        </span>
        <h2
          id={`widget-${widget.key}`}
          className="min-w-0 flex-1 truncate text-[14px] font-bold tracking-tight text-clinic-ink"
        >
          {widget.title}
        </h2>
        {aside}
        {widget.action && (
          <Link
            href={widget.action.href}
            className={cn(
              'inline-flex min-h-11 shrink-0 items-center gap-0.5 rounded-full px-1 text-[12px] font-semibold sm:min-h-0',
              tone.text,
              'hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
            )}
          >
            {widget.action.label}
            <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
        )}
      </header>
      <div className="flex flex-1 flex-col px-4 pb-4 pt-3">{children}</div>
    </section>
  );
}

/**
 * A summary figure: small card, tinted icon, the number, one line of detail.
 * Four of these make the top of the dashboard.
 */
export function KpiCard({ widget, children }: { widget: DashboardWidget; children: ReactNode }) {
  const tone = toneFor(widget.tone);
  const Icon = iconFor(widget.icon);

  return (
    <section
      aria-labelledby={`widget-${widget.key}`}
      className={cn(
        'relative min-w-0 overflow-hidden rounded-[22px] border border-white/80 bg-white/[0.93] p-4 backdrop-blur-sm',
        'shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-16px_rgba(16,24,40,0.12)]',
      )}
    >
      <span aria-hidden="true" className={cn('absolute inset-y-0 left-0 w-1', tone.fill)} />
      <div className="flex items-center gap-2">
        <span className={cn('flex h-7 w-7 shrink-0 items-center justify-center rounded-full', tone.chip)}>
          <Icon className="h-3.5 w-3.5" aria-hidden="true" />
        </span>
        <h2 id={`widget-${widget.key}`} className="min-w-0 truncate text-[12.5px] font-semibold text-clinic-secondary">
          {widget.title}
        </h2>
      </div>
      <div className="mt-2.5">{children}</div>
    </section>
  );
}
