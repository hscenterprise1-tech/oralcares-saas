import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * A portal page's title row: standard clean typography, or the Front Desk's hero card
 * with a soft blue-lavender wash, an optional icon, and title/description/actions.
 */
export function PageHeader({
  title,
  description,
  actions,
  eyebrow,
  icon: Icon,
  variant = 'default',
  className,
}: {
  title: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  eyebrow?: ReactNode;
  icon?: LucideIcon;
  variant?: 'default' | 'hero';
  className?: string;
}) {
  if (variant === 'hero') {
    return (
      <section
        aria-label={typeof title === 'string' ? title : 'Page header'}
        className={cn(
          'mb-5 rounded-3xl border border-[#DCE6F3] bg-gradient-to-br from-[#EAF2FF] via-[#F5F1FF] to-[#E6F6F2] px-4 py-5 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_18px_40px_-28px_rgba(23,59,96,0.45)] sm:px-6',
          className,
        )}
      >
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="flex min-w-0 items-center gap-3">
            {Icon && (
              <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#E3F6F2] to-[#E6F0FB] text-[#0F7C6E] ring-1 ring-inset ring-white/70">
                <Icon className="h-5 w-5" strokeWidth={2} aria-hidden="true" />
              </span>
            )}
            <div className="min-w-0">
              {eyebrow && <div className="mb-1">{eyebrow}</div>}
              <h1 className="text-[22px] font-bold leading-8 tracking-tight text-[#173B60] sm:text-[24px]">
                {title}
              </h1>
              {description && <p className="text-[14px] leading-[22px] text-[#475569]">{description}</p>}
            </div>
          </div>
          {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
      </section>
    );
  }

  return (
    <header
      className={cn(
        'mb-4 flex flex-col gap-3 rounded-2xl border border-cx-line/80 bg-white px-5 py-4 shadow-cx-card sm:flex-row sm:items-center sm:justify-between',
        className,
      )}
    >
      <div className="flex min-w-0 items-center gap-3.5">
        {Icon && (
          <span aria-hidden="true" className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-[#2CC6B4] to-[#0E9F87] text-white shadow-[0_4px_12px_-4px_rgba(14,159,135,0.45)]">
            <Icon className="h-5 w-5" strokeWidth={1.9} />
          </span>
        )}
        <div className="min-w-0">
          {eyebrow && <div className="mb-1">{eyebrow}</div>}
          <h1 className="break-words text-[20px] font-semibold leading-7 tracking-[-0.01em] text-cx-nav-ink">{title}</h1>
          {description && <p className="text-[12.5px] leading-5 text-cx-text-3">{description}</p>}
        </div>
      </div>
      {actions && <div className="flex shrink-0 flex-wrap items-center gap-2.5">{actions}</div>}
    </header>
  );
}
