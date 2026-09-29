import * as React from 'react';
import {
  DayPicker,
  DateRange,
  DayPickerProps,
  useDayPicker,
  MonthCaptionProps,
} from 'react-day-picker';
import { format } from 'date-fns';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

export type MiniCalendarSingleProps = {
  mode?: 'single';
  selected?: Date;
  onSelect?: (date: Date | undefined) => void;
  className?: string;
  disabled?: (date: Date) => boolean;
};

export type MiniCalendarRangeProps = {
  mode: 'range';
  selected?: DateRange;
  onSelect?: (range: DateRange | undefined) => void;
  className?: string;
  disabled?: (date: Date) => boolean;
};

export type MiniCalendarProps = MiniCalendarSingleProps | MiniCalendarRangeProps;

function CustomMonthCaption(props: MonthCaptionProps) {
  const { calendarMonth } = props;
  const { previousMonth, nextMonth, goToMonth } = useDayPicker();

  return (
    <div className="flex items-center justify-center gap-2.5 h-8 mb-1 select-none">
      <button
        type="button"
        disabled={!previousMonth}
        onClick={() => previousMonth && goToMonth(previousMonth)}
        className="h-7 w-7 bg-transparent p-0 text-slate-400 hover:text-slate-900 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none rounded-md flex items-center justify-center transition-colors cursor-pointer"
        aria-label="Previous month"
      >
        <ChevronLeft className="h-4 w-4 text-slate-600" />
      </button>

      <span className="text-xs font-bold text-slate-900 tracking-tight">
        {format(calendarMonth.date, 'MMMM yyyy')}
      </span>

      <button
        type="button"
        disabled={!nextMonth}
        onClick={() => nextMonth && goToMonth(nextMonth)}
        className="h-7 w-7 bg-transparent p-0 text-slate-400 hover:text-slate-900 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none rounded-md flex items-center justify-center transition-colors cursor-pointer"
        aria-label="Next month"
      >
        <ChevronRight className="h-4 w-4 text-slate-600" />
      </button>
    </div>
  );
}

export function MiniCalendar(props: MiniCalendarProps) {
  const { mode = 'single', className, disabled, ...rest } = props;

  const defaultClassNames = {
    root: 'font-sans inline-block',
    months: 'flex flex-col sm:flex-row space-y-4 sm:space-x-4 sm:space-y-0',
    month: 'space-y-2 font-sans w-[252px]',
    month_grid: 'w-full border-collapse space-y-1 mt-1',
    weekdays: 'flex justify-between border-b border-slate-100 pb-1 mb-1 w-full',
    weekday: 'text-slate-400 w-8 font-mono font-semibold text-[0.68rem] uppercase text-center',
    week: 'flex w-full mt-1 justify-between',
    day: 'h-8 w-8 p-0 font-medium rounded-lg text-slate-800 hover:bg-slate-100 aria-selected:opacity-100 transition-colors flex items-center justify-center text-xs cursor-pointer select-none',
    day_button: 'h-full w-full flex items-center justify-center text-xs font-medium',
    range_start: 'bg-teal-700 text-white rounded-l-lg font-bold shadow-2xs',
    range_end: 'bg-teal-700 text-white rounded-r-lg font-bold shadow-2xs',
    selected:
      'bg-teal-700 text-white font-bold hover:bg-teal-800 focus:bg-teal-700 focus:text-white rounded-lg shadow-2xs',
    today: 'border border-teal-600 text-teal-700 font-bold',
    outside: 'text-slate-400 opacity-40 pointer-events-none',
    disabled: 'text-slate-300 opacity-30 cursor-not-allowed pointer-events-none',
    range_middle: 'aria-selected:bg-teal-50 aria-selected:text-teal-900 rounded-none font-medium',
    hidden: 'invisible',
  };

  return (
    <div className={cn('p-3 bg-white border border-slate-200/90 rounded-xl shadow-2xs inline-block font-sans', className)}>
      <DayPicker
        mode={mode as any}
        disabled={disabled}
        classNames={defaultClassNames}
        components={{
          MonthCaption: CustomMonthCaption,
          Nav: () => null,
        }}
        {...(rest as DayPickerProps)}
      />
    </div>
  );
}

export const OVSMiniCalendar = MiniCalendar;
