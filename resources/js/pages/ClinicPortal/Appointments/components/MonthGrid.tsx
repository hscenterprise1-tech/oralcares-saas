import { useMemo } from 'react';
import { cn } from '@/lib/utils';
import { dayNumber, monthGrid, timeOf } from '../lib/dates';
import { statusStyle } from '../lib/status';
import { personName, type AppointmentItem, type BranchDay } from '../lib/types';

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const VISIBLE = 3;

/**
 * The month: one cell per day with its first appointments and a count of
 * the rest. A day opens in Day view; an appointment opens its details.
 */
export function MonthGrid({
  date,
  today,
  days,
  appointments,
  onOpenDay,
  onSelect,
}: {
  date: string;
  today: string;
  days: Record<string, BranchDay>;
  appointments: AppointmentItem[];
  onOpenDay: (date: string) => void;
  onSelect: (appointment: AppointmentItem) => void;
}) {
  const grid = useMemo(() => monthGrid(date), [date]);
  const month = date.slice(0, 7);
  const byDay = useMemo(() => {
    const map = new Map<string, AppointmentItem[]>();
    appointments.forEach((appointment) => {
      const key = appointment.start.slice(0, 10);
      map.set(key, [...(map.get(key) ?? []), appointment]);
    });

    return map;
  }, [appointments]);

  return (
    <div className="p-2 sm:p-3">
      <div className="grid grid-cols-7 gap-px pb-1">
        {WEEKDAYS.map((day) => (
          <p key={day} className="py-1 text-center text-[10.5px] font-bold uppercase tracking-wide text-clinic-secondary">
            {day}
          </p>
        ))}
      </div>
      <div className="grid grid-cols-7 overflow-hidden rounded-2xl border border-clinic-border bg-clinic-border [gap:1px]">
        {grid.map((key) => {
          const items = (byDay.get(key) ?? []).filter((item) => item.display_status !== 'cancelled');
          const inMonth = key.slice(0, 7) === month;
          const closed = days[key] && !days[key].open;
          const isToday = key === today;

          return (
            <div
              key={key}
              className={cn(
                'flex min-h-[72px] min-w-0 flex-col bg-white p-1 sm:min-h-[112px] sm:p-1.5',
                !inMonth && 'bg-clinic-canvas/70',
                closed && 'bg-[repeating-linear-gradient(135deg,rgba(148,156,172,0.10)_0_6px,rgba(148,156,172,0.04)_6px_12px)]',
              )}
            >
              <button
                type="button"
                onClick={() => onOpenDay(key)}
                aria-label={`Open ${key}${items.length ? `, ${items.length} appointments` : ''}`}
                className={cn(
                  'mb-1 flex h-8 w-8 items-center justify-center self-start rounded-full text-[12px] font-bold tabular-nums focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:h-7 sm:w-7',
                  isToday ? 'bg-[#0F7C6E] text-white' : inMonth ? 'text-clinic-ink hover:bg-clinic-inset' : 'text-clinic-muted hover:bg-clinic-inset',
                )}
              >
                {dayNumber(key)}
              </button>

              {/* Phones: a count, tapping opens the day. */}
              {items.length > 0 && (
                <button
                  type="button"
                  onClick={() => onOpenDay(key)}
                  className="mx-auto mt-auto min-h-6 rounded-full bg-blue-50 px-2 text-[11px] font-bold tabular-nums text-blue-700 sm:hidden"
                >
                  {items.length}
                </button>
              )}

              <div className="hidden min-w-0 flex-1 flex-col gap-0.5 sm:flex">
                {items.slice(0, VISIBLE).map((item) => {
                  const style = statusStyle(item.display_status);

                  return (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => onSelect(item)}
                      className={cn(
                        'flex min-w-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-left text-[11px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
                        style.card,
                      )}
                    >
                      <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', style.dot)} aria-hidden="true" />
                      <span className="shrink-0 tabular-nums opacity-80">{timeOf(item.start)}</span>
                      <span className="truncate">{personName(item)}</span>
                    </button>
                  );
                })}
                {items.length > VISIBLE && (
                  <button
                    type="button"
                    onClick={() => onOpenDay(key)}
                    className="rounded-md px-1.5 py-0.5 text-left text-[11px] font-semibold text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink"
                  >
                    +{items.length - VISIBLE} more
                  </button>
                )}
                {closed && items.length === 0 && (
                  <span className="px-1.5 text-[10.5px] font-medium text-clinic-secondary">{days[key].reason}</span>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
