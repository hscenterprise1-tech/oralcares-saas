import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { TONE, type DsTone } from './tones';

/** Each family's icon tile: the Owner Dashboard's gradient tiles (`CommandCenter/sections` IconChip), so a panel reads the same on every page. */
const CHIP: Record<DsTone, string> = {
  blue: 'from-[#4F9BF0] to-[#2B6FD8]',
  navy: 'from-[#3E5F86] to-[#173B60]',
  teal: 'from-[#2CC6B4] to-[#0E9F87]',
  amber: 'from-[#F7B451] to-[#E9821A]',
  rose: 'from-[#F58A94] to-[#E5484D]',
  green: 'from-[#5FD9A6] to-[#12A877]',
  slate: 'from-[#94A3B8] to-[#64748B]',
  violet: 'from-[#9B7BF2] to-[#6B48DE]',
  cyan: 'from-[#4FC9DC] to-[#1497A8]',
  coral: 'from-[#F59A80] to-[#E0654A]',
};

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
    <section id={id} aria-labelledby={headingId} className={cn('min-w-0 overflow-hidden rounded-2xl border border-cx-line/80 bg-white shadow-cx-card', className)}>
      <header className="flex min-h-[56px] items-center justify-between gap-3 px-5 pb-2 pt-5">
        <div className="flex min-w-0 flex-1 items-center gap-3">
          {Icon && (
            <span aria-hidden="true" className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-[10px] bg-gradient-to-br text-white shadow-[0_4px_10px_-6px_rgba(30,50,110,0.45)]', CHIP[tone])}>
              <Icon className="h-[18px] w-[18px]" strokeWidth={1.75} />
            </span>
          )}
          <div className="min-w-0">
            <h2 id={headingId} className="flex min-w-0 items-baseline gap-1.5 text-[15px] font-semibold leading-5 text-cx-nav-ink">
              <span className="truncate">{title}</span>
              {count !== undefined && <span className={cn('text-[13px] font-semibold tabular-nums', TONE[tone].ink)}>{count}</span>}
            </h2>
            {description && <p className="mt-0.5 text-[12px] leading-4 text-cx-text-3">{description}</p>}
          </div>
        </div>
        {actions && <div className="flex shrink-0 items-center gap-1">{actions}</div>}
      </header>
      <div className={flush ? 'pt-2' : 'px-5 pb-5 pt-3'}>{children}</div>
    </section>
  );
}
