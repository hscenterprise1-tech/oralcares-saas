import { useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { DayPicker, type MonthCaptionProps, useDayPicker } from 'react-day-picker';
import { format } from 'date-fns';
import { Calendar as CalendarIcon, ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { inputClass } from './controls';

/** `yyyy-mm-dd` → a Date at local midnight, so the day never shifts by timezone. */
export function parseIsoDate(value?: string | null): Date | undefined {
  const match = value ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(value) : null;
  if (!match) {
    return undefined;
  }
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

  return Number.isNaN(date.getTime()) ? undefined : date;
}

/** Back to `yyyy-mm-dd` from local parts — never `toISOString()`, which is UTC. */
export function toIsoDate(date: Date): string {
  return format(date, 'yyyy-MM-dd');
}

function MonthCaption({ calendarMonth }: MonthCaptionProps) {
  const { previousMonth, nextMonth, goToMonth } = useDayPicker();
  const nav =
    'flex h-8 w-8 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink disabled:pointer-events-none disabled:opacity-30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong';

  return (
    <div className="mb-1 flex h-8 select-none items-center justify-between gap-2">
      <button type="button" className={nav} disabled={!previousMonth} onClick={() => previousMonth && goToMonth(previousMonth)} aria-label="Previous month">
        <ChevronLeft className="h-4 w-4" aria-hidden="true" />
      </button>
      <span className="text-[13px] font-semibold text-clinic-ink">{format(calendarMonth.date, 'MMMM yyyy')}</span>
      <button type="button" className={nav} disabled={!nextMonth} onClick={() => nextMonth && goToMonth(nextMonth)} aria-label="Next month">
        <ChevronRight className="h-4 w-4" aria-hidden="true" />
      </button>
    </div>
  );
}

/**
 * The portal's date field: one calendar on every device instead of each
 * browser's own. The value is the native shape (`yyyy-mm-dd`), so it drops
 * into forms and URL filters unchanged; `min`/`max` bound what can be picked.
 */
export function DatePicker({
  value,
  onChange,
  min,
  max,
  placeholder = 'Pick a date',
  disabled,
  invalid,
  required,
  name,
  id,
  'aria-label': ariaLabel,
}: {
  value: string;
  onChange: (value: string) => void;
  min?: string;
  max?: string;
  placeholder?: string;
  disabled?: boolean;
  invalid?: boolean;
  required?: boolean;
  name?: string;
  id?: string;
  'aria-label'?: string;
}) {
  const [open, setOpen] = useState(false);
  const selected = parseIsoDate(value);
  const minDate = parseIsoDate(min);
  const maxDate = parseIsoDate(max);
  const bounds = [...(minDate ? [{ before: minDate }] : []), ...(maxDate ? [{ after: maxDate }] : [])];

  return (
    <>
      {name && <input type="hidden" name={name} value={value} />}
      <Popover.Root open={open} onOpenChange={setOpen}>
        <Popover.Trigger asChild disabled={disabled}>
          <button
            type="button"
            id={id}
            aria-label={ariaLabel}
            aria-invalid={invalid || undefined}
            aria-required={required || undefined}
            // In a dialog focus stays here while the calendar is open: Escape closes the calendar, not the dialog.
            onKeyDown={(event) => {
              if (event.key === 'Escape' && open) {
                setOpen(false);
              }
            }}
            className={cn(inputClass, 'flex items-center gap-2 text-left')}
          >
            <CalendarIcon className="h-4 w-4 shrink-0 text-clinic-secondary" aria-hidden="true" />
            <span className={cn('min-w-0 flex-1 truncate', !selected && 'text-clinic-muted')}>
              {selected ? format(selected, 'd MMM yyyy') : placeholder}
            </span>
          </button>
        </Popover.Trigger>
        <Popover.Portal>
          <Popover.Content
            align="start"
            sideOffset={6}
            collisionPadding={12}
            // Inside a dialog, the dialog takes Escape first and marks it handled, so the calendar closes itself here (the dialog stays).
            onKeyDown={(event) => {
              if (event.key === 'Escape') {
                setOpen(false);
              }
            }}
            className="z-50 rounded-2xl border border-clinic-border bg-clinic-surface p-3 shadow-[0_16px_40px_-16px_rgba(16,24,40,0.3)]"
          >
            <DayPicker
              mode="single"
              selected={selected}
              onSelect={(date) => {
                if (date) {
                  onChange(toIsoDate(date));
                  setOpen(false);
                }
              }}
              defaultMonth={selected ?? minDate}
              disabled={bounds.length > 0 ? bounds : undefined}
              showOutsideDays
              components={{ MonthCaption }}
              classNames={{
                nav: 'hidden',
                month: 'w-[264px] space-y-2',
                month_grid: 'w-full border-collapse',
                weekdays: 'mb-1 flex w-full justify-between border-b border-clinic-border pb-1',
                weekday: 'w-9 text-center text-[11px] font-semibold text-clinic-secondary',
                week: 'mt-1 flex w-full justify-between',
                day: 'h-9 w-9 rounded-xl text-[12.5px] text-clinic-ink hover:bg-clinic-inset',
                day_button:
                  'flex h-full w-full cursor-pointer items-center justify-center rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
                selected: 'bg-[#0F7C6E] text-white hover:bg-[#0F7C6E]',
                today: 'font-bold ring-1 ring-inset ring-clinic-border-strong',
                outside: 'text-clinic-muted opacity-60',
                disabled: 'cursor-not-allowed opacity-35 hover:bg-transparent',
                hidden: 'invisible',
              }}
            />
            {!required && value && (
              <div className="mt-2 flex justify-start border-t border-clinic-border pt-2">
                <button
                  type="button"
                  onClick={() => {
                    onChange('');
                    setOpen(false);
                  }}
                  className="rounded-full px-2.5 py-1 text-[12px] font-semibold text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
                >
                  Clear date
                </button>
              </div>
            )}
          </Popover.Content>
        </Popover.Portal>
      </Popover.Root>
    </>
  );
}
