import React from 'react';
import { cn } from '@/lib/utils';
import { buildWeekDays, toDateKey } from '../dateMath';

/**
 * A horizontal 7-day strip for mobile Week view — lets you jump between
 * days within the current week without leaving week context. Purely
 * presentational: it derives its 7 days from the `date` prop and hands a
 * tap straight to `onSelect`, which is `navigate()` in the parent — no
 * date state of its own to go stale.
 */
export function MobileDateStrip({ date, onSelect }: { date: string; onSelect: (date: string) => void }) {
  const days = buildWeekDays(date);
  const todayKey = toDateKey(new Date());

  return (
    <div className="flex gap-1.5 overflow-x-auto px-1 pb-1">
      {days.map((d) => {
        const isSelected = d.key === date;
        const isToday = d.key === todayKey;

        return (
          <button
            key={d.key}
            type="button"
            onClick={() => onSelect(d.key)}
            className={cn(
              'flex min-h-11 min-w-[46px] flex-1 flex-col items-center justify-center gap-0.5 rounded-[14px] py-1.5 transition-colors',
              isSelected ? 'bg-ovs-crm-ink text-white' : isToday ? 'bg-ovs-crm-accent-violet-soft' : 'hover:bg-ovs-crm-surface-inset'
            )}
          >
            <span className={cn('text-[9.5px] font-bold uppercase tracking-wide', isSelected ? 'text-white/70' : 'text-ovs-crm-text-muted')}>
              {d.date.toLocaleDateString('en-US', { weekday: 'short' })}
            </span>
            <span className={cn('text-[14px] font-bold', isSelected ? 'text-white' : 'text-ovs-crm-text-primary')}>{d.date.getDate()}</span>
          </button>
        );
      })}
    </div>
  );
}

export default MobileDateStrip;
