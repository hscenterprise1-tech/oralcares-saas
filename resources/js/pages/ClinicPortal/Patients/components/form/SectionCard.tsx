import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type SectionTone = 'blue' | 'teal' | 'lavender' | 'pink' | 'yellow';

interface SectionCardProps {
  id?: string;
  title: string;
  subtitle?: string;
  icon: LucideIcon;
  tone: SectionTone;
  badge?: string;
  actions?: ReactNode;
  children: ReactNode;
  className?: string;
}

const TONE_STYLES: Record<
  SectionTone,
  {
    iconBg: string;
    badgeStyle: string;
  }
> = {
  blue: {
    iconBg: 'bg-gradient-to-br from-[#1E78C8] to-[#125898] text-white',
    badgeStyle: 'bg-[#E7F0FD] text-[#1769AA] border-[#BFDBFE]',
  },
  teal: {
    iconBg: 'bg-gradient-to-br from-[#179B9B] to-[#0E6C79] text-white',
    badgeStyle: 'bg-[#E3F7F5] text-[#0D9488] border-[#99F6E4]',
  },
  lavender: {
    iconBg: 'bg-gradient-to-br from-[#3B5BDB] to-[#2B44A8] text-white',
    badgeStyle: 'bg-[#EDE9FE] text-[#6366F1] border-[#DDD6FE]',
  },
  pink: {
    iconBg: 'bg-gradient-to-br from-[#DC3545] to-[#A81F2E] text-white',
    badgeStyle: 'bg-[#FFE4E6] text-[#E11D48] border-[#FECDD3]',
  },
  yellow: {
    iconBg: 'bg-gradient-to-br from-[#E08A1E] to-[#B4620E] text-white',
    badgeStyle: 'bg-[#FEF3C7] text-[#D97706] border-[#FDE68A]',
  },
};

export function SectionCard({
  id,
  title,
  subtitle,
  icon: Icon,
  tone,
  badge,
  actions,
  children,
  className,
}: SectionCardProps) {
  const styles = TONE_STYLES[tone];
  const sectionId = id ?? `section-${title.toLowerCase().replace(/\W+/g, '-')}`;

  return (
    <section
      id={sectionId}
      aria-labelledby={`${sectionId}-heading`}
      className={cn(
        'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card',
        className,
      )}
    >
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-cx-line/80 px-5 py-3.5 sm:px-6">
        <div className="flex items-center gap-3 min-w-0">
          <span
            className={cn(
              'grid h-9 w-9 shrink-0 place-items-center rounded-[10px] shadow-sm',
              styles.iconBg,
            )}
            aria-hidden="true"
          >
            <Icon className="h-4 w-4" strokeWidth={1.75} />
          </span>
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <h2
                id={`${sectionId}-heading`}
                className="text-[16px] font-semibold leading-6 text-[#173B60]"
              >
                {title}
              </h2>
              {badge && (
                <span
                  className={cn(
                    'rounded-full border px-2.5 py-0.5 text-[11px] font-medium uppercase tracking-wider',
                    styles.badgeStyle,
                  )}
                >
                  {badge}
                </span>
              )}
            </div>
            {subtitle && (
              <p className="mt-0.5 text-[12px] leading-4 text-[#5F6B7E] line-clamp-1">{subtitle}</p>
            )}
          </div>
        </div>
        {actions && <div className="shrink-0">{actions}</div>}
      </header>

      <div className="p-5 sm:p-6">{children}</div>
    </section>
  );
}
