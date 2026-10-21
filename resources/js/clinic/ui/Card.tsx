import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/** The rebuilt portal's one surface: white, rounded, a hairline and a soft shadow. */
export function Card({ as: Tag = 'section', className, children, ...rest }: { as?: 'section' | 'div' | 'article'; className?: string; children: ReactNode } & Record<string, unknown>) {
  return (
    <Tag className={cn('rounded-2xl border border-cx-line bg-cx-surface shadow-cx-card', className)} {...rest}>
      {children}
    </Tag>
  );
}

/** A card's title row: what it is, a short note, and at most one way onward. */
export function CardHeader({ title, description, action, id }: { title: ReactNode; description?: ReactNode; action?: { label: string; href: string } | null; id?: string }) {
  return (
    <header className="flex items-start justify-between gap-3 px-5 pt-4">
      <div className="min-w-0">
        <h3 id={id} className="text-[14.5px] font-semibold tracking-tight text-cx-text">
          {title}
        </h3>
        {description && <p className="mt-0.5 text-[12.5px] text-cx-text-3">{description}</p>}
      </div>
      {action && (
        <Link
          href={action.href}
          className="inline-flex min-h-9 shrink-0 items-center gap-1 rounded-full px-2.5 text-[12.5px] font-semibold text-cx-cyan-strong hover:bg-cx-cyan-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan"
        >
          {action.label} <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
        </Link>
      )}
    </header>
  );
}
