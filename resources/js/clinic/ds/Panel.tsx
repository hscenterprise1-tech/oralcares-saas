import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { TONE, type DsTone } from './tones';

/**
 * A titled white surface with its category's tinted icon, a navy title,
 * an optional count and actions on the right. `flush` drops the body
 * padding for lists that run edge to edge.
 */
export function Panel({
  title,
  icon: Icon,
  tone = 'slate',
  count,
  description,
  actions,
  children,
  flush = false,
  className,
  id,
}: {
  title: string;
  icon?: LucideIcon;
  tone?: DsTone;
  count?: number | string;
  description?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
  flush?: boolean;
  className?: string;
  id?: string;
}) {
  const headingId = id ? `${id}-title` : undefined;

  return (
    <section id={id} aria-labelledby={headingId} className={cn('min-w-0 overflow-hidden rounded-2xl border border-[#E6EBF1] bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04),0_8px_20px_-16px_rgba(23,59,96,0.3)]', className)}>
      <header className="flex items-center justify-between gap-3 px-4 pb-2 pt-4">
        <div className="flex min-w-0 items-center gap-2.5">
          {Icon && (
            <span className={cn('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', TONE[tone].soft, TONE[tone].ink)}>
              <Icon className="h-4 w-4" strokeWidth={2} aria-hidden="true" />
            </span>
          )}
          <div className="min-w-0">
            <h2 id={headingId} className="flex items-baseline gap-1.5 text-[15px] font-semibold leading-6 text-[#173B60]">
              {title}
              {count !== undefined && <span className={cn('text-[13px] font-semibold tabular-nums', TONE[tone].ink)}>{count}</span>}
            </h2>
            {description && <p className="text-[12.5px] leading-5 text-[#64748B]">{description}</p>}
          </div>
        </div>
        {actions && <div className="flex shrink-0 items-center gap-1">{actions}</div>}
      </header>
      <div className={flush ? '' : 'px-4 pb-4'}>{children}</div>
    </section>
  );
}
