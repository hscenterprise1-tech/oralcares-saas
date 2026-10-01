import { CalendarPlus, Repeat } from 'lucide-react';
import { cn } from '@/lib/utils';
import { longDate, minutesOf, timeOf } from '../lib/dates';
import { initials, providerTone, statusStyle } from '../lib/status';
import { personName, type AppointmentItem, type BranchDay } from '../lib/types';

/**
 * One day as a time-ordered list: the phone's Day and Week views, where a
 * grid would be too narrow to read. Provider is part of each appointment.
 */
export function DayList({
  date,
  today,
  day,
  appointments,
  onSelect,
  onCreate,
}: {
  date: string;
  today: string;
  day: BranchDay | undefined;
  appointments: AppointmentItem[];
  onSelect: (appointment: AppointmentItem) => void;
  onCreate?: () => void;
}) {
  const items = [...appointments].sort((a, b) => minutesOf(a.start) - minutesOf(b.start));

  return (
    <div className="px-3 py-3 sm:px-5">
      <div className="mb-2 flex items-center justify-between gap-2">
        <h3 className={cn('text-[13px] font-bold', date === today ? 'text-clinic-brand-strong' : 'text-clinic-ink')}>
          {date === today ? 'Today · ' : ''}
          {longDate(date)}
          <span className="ml-2 text-[11.5px] font-medium text-clinic-secondary">
            {items.length} {items.length === 1 ? 'appointment' : 'appointments'}
          </span>
        </h3>
        {day && !day.open && day.enforced ? (
          <span className="rounded-full bg-clinic-inset px-2 py-0.5 text-[11px] font-semibold text-clinic-secondary">{day.reason}</span>
        ) : (
          onCreate &&
          date >= today && (
            <button
              type="button"
              onClick={onCreate}
              aria-label={`Book on ${longDate(date)}`}
              className="inline-flex h-11 w-11 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
            >
              <CalendarPlus className="h-4 w-4" aria-hidden="true" />
            </button>
          )
        )}
      </div>
      {items.length === 0 ? (
        <p className="rounded-xl bg-clinic-canvas px-3 py-3 text-[12.5px] text-clinic-secondary">Nothing booked.</p>
      ) : (
        <ul className="space-y-1.5">
          {items.map((item) => {
            const style = statusStyle(item.display_status);

            return (
              <li key={item.id}>
                <button
                  type="button"
                  onClick={() => onSelect(item)}
                  className={cn(
                    'flex min-h-[60px] w-full items-stretch gap-3 overflow-hidden rounded-2xl text-left ring-1 ring-black/[0.05] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
                    style.card,
                  )}
                >
                  <span className={cn('w-1 shrink-0', style.bar)} aria-hidden="true" />
                  <span className="w-[58px] shrink-0 py-2 text-[12.5px] font-bold tabular-nums">
                    {timeOf(item.start)}
                    <span className="block text-[11px] font-medium opacity-75">{item.duration_minutes} min</span>
                  </span>
                  <span className="min-w-0 flex-1 py-2">
                    <span className="flex items-center gap-1.5">
                      <span className="truncate text-[13px] font-bold">{personName(item)}</span>
                      {item.new_case && <span className="shrink-0 rounded-full bg-white/80 px-1.5 text-[9px] font-bold uppercase">New</span>}
                      {item.series_id && <Repeat className="h-3 w-3 shrink-0 opacity-70" aria-label="Recurring" />}
                    </span>
                    <span className="block truncate text-[11.5px] font-medium opacity-85">{item.service?.name ?? 'General appointment'}</span>
                    <span className="mt-0.5 flex min-w-0 items-center gap-1.5">
                      <span className={cn('flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[8px] font-bold', providerTone(item.provider_id))}>
                        {initials(item.provider_name)}
                      </span>
                      <span className="truncate text-[11.5px] font-semibold opacity-90">{item.provider_name ?? 'No provider'}</span>
                    </span>
                  </span>
                  <span className="shrink-0 self-center pr-3">
                    <span className="rounded-full bg-white/75 px-2 py-0.5 text-[10.5px] font-bold">{style.label}</span>
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
