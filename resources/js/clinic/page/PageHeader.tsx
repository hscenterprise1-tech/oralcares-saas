import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface Crumb {
  label: string;
  /** Omitted for the current page, the last crumb. */
  href?: string;
}

/** Where this page sits, for pages more than one level deep. */
export function Breadcrumbs({ items }: { items: Crumb[] }) {
  if (items.length === 0) {
    return null;
  }

  return (
    <nav aria-label="Breadcrumb" className="mb-2">
      <ol className="flex flex-wrap items-center gap-1 text-[12.5px] text-clinic-secondary">
        {items.map((item, index) => (
          <li key={`${item.label}-${index}`} className="flex items-center gap-1">
            {index > 0 && <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />}
            {item.href ? (
              <Link
                href={item.href}
                className="rounded font-medium hover:text-clinic-ink hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
              >
                {item.label}
              </Link>
            ) : (
              <span aria-current="page" className="font-semibold text-clinic-ink">
                {item.label}
              </span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}

/**
 * The top of every portal page: where it is, what it is called, what it is
 * for, and its main actions. Actions wrap under the title on narrow screens.
 */
export function PageHeader({
  title,
  description,
  eyebrow,
  breadcrumbs = [],
  actions,
}: {
  title: string;
  description?: ReactNode;
  eyebrow?: ReactNode;
  breadcrumbs?: Crumb[];
  actions?: ReactNode;
}) {
  return (
    <div className="mb-6 flex flex-col gap-4 sm:mb-8 md:flex-row md:items-end md:justify-between">
      <div className="min-w-0">
        <Breadcrumbs items={breadcrumbs} />
        {eyebrow && (
          <p className="mb-1 text-[10px] font-bold uppercase tracking-[0.08em] text-clinic-secondary">{eyebrow}</p>
        )}
        <h1 className="text-[24px] font-bold leading-tight tracking-tight text-clinic-ink sm:text-[28px]">{title}</h1>
        {description && (
          <p className="mt-1.5 max-w-2xl text-[13.5px] leading-relaxed text-clinic-secondary">{description}</p>
        )}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

/** A white panel on the canvas: the unit pages are built from. */
export function Panel({
  title,
  action,
  children,
  className,
}: {
  title?: string;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={cn('rounded-3xl border border-clinic-border bg-clinic-surface', className)} aria-label={title}>
      {(title || action) && (
        <div className="flex items-center justify-between gap-3 px-5 pb-1 pt-4 sm:px-6">
          {title && <h2 className="text-[17px] font-bold tracking-tight text-clinic-ink">{title}</h2>}
          {action}
        </div>
      )}
      {children}
    </section>
  );
}
