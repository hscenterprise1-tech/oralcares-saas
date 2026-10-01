import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { DashboardWidget } from '../types';
import { iconFor, toneFor } from '../ui/visuals';

const SPAN: Record<DashboardWidget['span'], string> = {
  1: 'col-span-1',
  2: 'col-span-2',
  4: 'col-span-2 xl:col-span-4',
};

/**
 * The frame every widget shares: a white card, a tinted wash in the widget's
 * tone, its icon in a coloured chip, the title, and its one action link.
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
  // Half-width on phones (two cards per row).
  const narrow = widget.span === 1;

  return (
    <section
      aria-labelledby={`widget-${widget.key}`}
      className={cn(
        'relative flex min-w-0 flex-col overflow-hidden rounded-3xl border border-clinic-border bg-clinic-surface',
        'shadow-[0_1px_2px_rgba(16,24,40,0.04)]',
        SPAN[widget.span],
      )}
    >
      <div
        aria-hidden="true"
        className={cn('pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b to-transparent', tone.accent)}
      />
      {/* On a phone a half-width card stacks its icon over the title, so the title is read in full. */}
      <header
        className={cn(
          'relative flex items-start gap-2.5 px-4 pt-4 sm:flex-row sm:gap-3 sm:px-5 sm:pt-5',
          narrow && 'flex-col',
        )}
      >
        <span
          className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-2xl sm:h-10 sm:w-10', tone.chip)}
        >
          <Icon className="h-5 w-5" aria-hidden="true" />
        </span>
        <div className="min-w-0 flex-1">
          <h2
            id={`widget-${widget.key}`}
            className="line-clamp-2 text-[14.5px] font-bold leading-snug tracking-tight text-clinic-ink sm:line-clamp-1"
          >
            {widget.title}
          </h2>
          <p className={cn('truncate text-[12px] text-clinic-secondary', narrow && 'hidden sm:block')}>
            {widget.description}
          </p>
        </div>
        {aside}
      </header>
      <div className="relative flex flex-1 flex-col px-4 pb-4 pt-3 sm:px-5 sm:pb-5 sm:pt-4">{children}</div>
      {widget.action && (
        <Link
          href={widget.action.href}
          className={cn(
            'relative mx-4 mb-3 -mt-1 inline-flex sm:mx-5 sm:mb-4 min-h-11 items-center gap-1 self-start rounded-full text-[12.5px] font-semibold sm:min-h-0',
            tone.text,
            'hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
          )}
        >
          {widget.action.label}
          <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
        </Link>
      )}
    </section>
  );
}

export function widgetSpanClass(span: DashboardWidget['span']): string {
  return SPAN[span];
}
