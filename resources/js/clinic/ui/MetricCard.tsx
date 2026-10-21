import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { cxTone } from './tones';

/**
 * One figure on a pastel tile: what it counts, the number, one line of
 * context. The tone says what kind of figure it is (see `tones.ts`), never
 * whether it is good or bad.
 */
export function MetricCard({ label, value, detail, tone, icon: Icon, loading }: {
  label: string;
  value: ReactNode;
  detail?: ReactNode;
  tone?: string;
  icon?: LucideIcon;
  loading?: boolean;
}) {
  const colors = cxTone(tone);

  return (
    <div className={cn('relative overflow-hidden rounded-2xl px-4 py-3.5 sm:px-5 sm:py-4', colors.bg)}>
      <div className="flex items-center justify-between gap-2">
        <p className={cn('text-[12.5px] font-semibold', colors.ink)}>{label}</p>
        {Icon && (
          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/70">
            <Icon className={cn('h-4 w-4', colors.ink)} aria-hidden="true" />
          </span>
        )}
      </div>
      {loading ? (
        <div className="mt-2 h-8 w-16 animate-pulse rounded-lg bg-white/70" aria-hidden="true" />
      ) : (
        <p className="mt-1.5 text-[26px] font-semibold leading-none tracking-tight text-cx-text tabular-nums sm:text-[30px]">{value}</p>
      )}
      {detail && !loading && <p className="mt-1.5 truncate text-[12px] text-cx-text-2">{detail}</p>}
    </div>
  );
}
