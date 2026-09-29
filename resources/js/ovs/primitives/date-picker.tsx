import * as React from 'react';
import { Calendar as CalendarIcon, X } from 'lucide-react';
import { format, parseISO, isBefore, isAfter, startOfDay } from 'date-fns';
import { cn } from '@/lib/utils';
import { Popover, PopoverTrigger, PopoverContent } from '@/ovs/overlays/popover';
import { MiniCalendar } from '@/ovs/calendar/mini-calendar';

export type DatePickerDensity = 'compact' | 'default' | 'comfortable';

export interface DatePickerProps {
  value?: string | Date;
  onChange?: (date: string) => void;
  onDateChange?: (date: Date | undefined) => void;
  placeholder?: string;
  className?: string;
  disabled?: boolean;
  clearable?: boolean;
  density?: DatePickerDensity;
  minDate?: Date | string;
  maxDate?: Date | string;
  disabledDates?: (date: Date) => boolean;
  id?: string;
}

export function DatePicker({
  value,
  onChange,
  onDateChange,
  placeholder = 'Select date…',
  className,
  disabled = false,
  clearable = true,
  density = 'default',
  minDate,
  maxDate,
  disabledDates,
  id,
}: DatePickerProps) {
  const [open, setOpen] = React.useState(false);

  const selectedDate = React.useMemo(() => {
    if (!value) return undefined;
    if (value instanceof Date) return isNaN(value.getTime()) ? undefined : value;
    const parsed = parseISO(value);
    return isNaN(parsed.getTime()) ? undefined : parsed;
  }, [value]);

  const parsedMinDate = React.useMemo(() => {
    if (!minDate) return undefined;
    if (minDate instanceof Date) return startOfDay(minDate);
    const parsed = parseISO(minDate);
    return isNaN(parsed.getTime()) ? undefined : startOfDay(parsed);
  }, [minDate]);

  const parsedMaxDate = React.useMemo(() => {
    if (!maxDate) return undefined;
    if (maxDate instanceof Date) return startOfDay(maxDate);
    const parsed = parseISO(maxDate);
    return isNaN(parsed.getTime()) ? undefined : startOfDay(parsed);
  }, [maxDate]);

  const isDateDisabled = React.useCallback(
    (date: Date) => {
      const day = startOfDay(date);
      if (parsedMinDate && isBefore(day, parsedMinDate)) return true;
      if (parsedMaxDate && isAfter(day, parsedMaxDate)) return true;
      if (disabledDates && disabledDates(date)) return true;
      return false;
    },
    [parsedMinDate, parsedMaxDate, disabledDates]
  );

  const handleSelect = (date: Date | undefined) => {
    if (date) {
      const formatted = format(date, 'yyyy-MM-dd');
      if (onChange) onChange(formatted);
      if (onDateChange) onDateChange(date);
    } else {
      if (onChange) onChange('');
      if (onDateChange) onDateChange(undefined);
    }
    setOpen(false);
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (onChange) onChange('');
    if (onDateChange) onDateChange(undefined);
  };

  const densityClasses: Record<DatePickerDensity, string> = {
    compact: 'h-8 px-2.5 text-xs rounded-md',
    default: 'h-9 px-3 text-xs rounded-lg',
    comfortable: 'h-10 px-3.5 text-sm rounded-lg',
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          id={id}
          type="button"
          disabled={disabled}
          className={cn(
            'flex w-full items-center justify-between border border-slate-200/90 bg-white text-left font-normal text-slate-900 shadow-2xs transition-all duration-150 font-sans cursor-pointer group',
            'hover:border-slate-300 focus:outline-none focus:border-teal-600 focus:ring-1 focus:ring-teal-500/20',
            'disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-slate-50',
            densityClasses[density],
            !selectedDate && 'text-slate-400',
            className
          )}
        >
          <div className="flex items-center gap-2 min-w-0 flex-1">
            <CalendarIcon className="h-3.5 w-3.5 text-slate-400 group-hover:text-slate-600 transition-colors shrink-0" />
            <span className="truncate font-medium">
              {selectedDate ? format(selectedDate, 'PPP') : placeholder}
            </span>
          </div>

          {clearable && selectedDate && !disabled && (
            <span
              role="button"
              tabIndex={0}
              aria-label="Clear date"
              onClick={handleClear}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault();
                  handleClear(e as any);
                }
              }}
              className="p-0.5 rounded hover:bg-slate-100 text-slate-400 hover:text-slate-700 transition-colors shrink-0 cursor-pointer ml-1.5"
            >
              <X className="h-3 w-3" />
            </span>
          )}
        </button>
      </PopoverTrigger>

      <PopoverContent
        className="w-auto p-0 border border-slate-200/90 rounded-xl shadow-lg bg-white"
        align="start"
      >
        <MiniCalendar
          mode="single"
          selected={selectedDate}
          onSelect={handleSelect}
          disabled={isDateDisabled}
        />
      </PopoverContent>
    </Popover>
  );
}

export const OVSDatePicker = DatePicker;
