import * as React from 'react';
import { Calendar as CalendarIcon, X, Check } from 'lucide-react';
import {
  format,
  subDays,
  startOfMonth,
  endOfMonth,
  startOfQuarter,
  endOfQuarter,
  startOfYear,
  startOfDay,
  endOfDay,
} from 'date-fns';
import { DateRange } from 'react-day-picker';
import { cn } from '@/lib/utils';
import { Popover, PopoverTrigger, PopoverContent } from '@/ovs/overlays/popover';
import { MiniCalendar } from '@/ovs/calendar/mini-calendar';
import { Button } from '@/ovs/primitives/button';

export type DateRangePickerDensity = 'compact' | 'default' | 'comfortable';

export interface DateRangePreset {
  label: string;
  getRange: () => { from: Date; to: Date };
}

export interface OVSDateRangePickerProps {
  value?: DateRange;
  onChange?: (range: DateRange | undefined) => void;
  placeholder?: string;
  className?: string;
  disabled?: boolean;
  clearable?: boolean;
  density?: DateRangePickerDensity;
  presets?: boolean | DateRangePreset[];
  showFooter?: boolean;
  minDate?: Date;
  maxDate?: Date;
  disabledDates?: (date: Date) => boolean;
  id?: string;
}

const DEFAULT_PRESETS: DateRangePreset[] = [
  {
    label: 'Today',
    getRange: () => {
      const now = new Date();
      return { from: startOfDay(now), to: endOfDay(now) };
    },
  },
  {
    label: 'Yesterday',
    getRange: () => {
      const y = subDays(new Date(), 1);
      return { from: startOfDay(y), to: endOfDay(y) };
    },
  },
  {
    label: 'Last 7 Days',
    getRange: () => {
      const now = new Date();
      return { from: startOfDay(subDays(now, 6)), to: endOfDay(now) };
    },
  },
  {
    label: 'This Month',
    getRange: () => {
      const now = new Date();
      return { from: startOfMonth(now), to: endOfMonth(now) };
    },
  },
  {
    label: 'Last 30 Days',
    getRange: () => {
      const now = new Date();
      return { from: startOfDay(subDays(now, 29)), to: endOfDay(now) };
    },
  },
  {
    label: 'This Quarter',
    getRange: () => {
      const now = new Date();
      return { from: startOfQuarter(now), to: endOfQuarter(now) };
    },
  },
];

export function OVSDateRangePicker({
  value,
  onChange,
  placeholder = 'Select date range…',
  className,
  disabled = false,
  clearable = true,
  density = 'default',
  presets = true,
  showFooter = true,
  minDate,
  maxDate,
  disabledDates,
  id,
}: OVSDateRangePickerProps) {
  const [open, setOpen] = React.useState(false);
  const [draftRange, setDraftRange] = React.useState<DateRange | undefined>(value);

  // Sync draft when opened
  React.useEffect(() => {
    if (open) {
      setDraftRange(value);
    }
  }, [open, value]);

  const activePresets = React.useMemo(() => {
    if (presets === false) return [];
    if (Array.isArray(presets)) return presets;
    return DEFAULT_PRESETS;
  }, [presets]);

  const handleApply = () => {
    if (onChange) onChange(draftRange);
    setOpen(false);
  };

  const handleCancel = () => {
    setDraftRange(value);
    setOpen(false);
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    setDraftRange(undefined);
    if (onChange) onChange(undefined);
  };

  const handlePresetClick = (preset: DateRangePreset) => {
    const range = preset.getRange();
    setDraftRange(range);
    if (!showFooter) {
      if (onChange) onChange(range);
      setOpen(false);
    }
  };

  const formatDisplayRange = () => {
    if (!value?.from) return placeholder;
    if (value.from && !value.to) {
      return `${format(value.from, 'MMM d, yyyy')} – …`;
    }
    if (value.from && value.to) {
      return `${format(value.from, 'MMM d, yyyy')} – ${format(value.to, 'MMM d, yyyy')}`;
    }
    return placeholder;
  };

  const densityClasses: Record<DateRangePickerDensity, string> = {
    compact: 'h-8 px-2.5 text-xs rounded-md',
    default: 'h-9 px-3 text-xs rounded-lg',
    comfortable: 'h-10 px-3.5 text-sm rounded-lg',
  };

  const hasValue = Boolean(value?.from);

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
            !hasValue && 'text-slate-400',
            className
          )}
        >
          <div className="flex items-center gap-2 min-w-0 flex-1">
            <CalendarIcon className="h-3.5 w-3.5 text-slate-400 group-hover:text-slate-600 transition-colors shrink-0" />
            <span className="truncate font-medium">{formatDisplayRange()}</span>
          </div>

          {clearable && hasValue && !disabled && (
            <span
              role="button"
              tabIndex={0}
              aria-label="Clear range"
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
        className="w-auto p-0 border border-slate-200/90 rounded-xl shadow-xl bg-white font-sans text-slate-900 overflow-hidden"
        align="start"
      >
        <div className="flex flex-col sm:flex-row">
          {/* Preset Chips Sidebar */}
          {activePresets.length > 0 && (
            <div className="p-3 bg-slate-50 border-b sm:border-b-0 sm:border-r border-slate-100 flex sm:flex-col gap-1 overflow-x-auto sm:w-36 shrink-0">
              <span className="text-[10px] font-mono font-bold uppercase tracking-wider text-slate-400 px-2 py-1 hidden sm:block">
                Presets
              </span>
              {activePresets.map((preset, i) => (
                <button
                  key={i}
                  type="button"
                  onClick={() => handlePresetClick(preset)}
                  className="px-2.5 py-1.5 rounded-lg text-left text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-200/70 transition-colors shrink-0 cursor-pointer whitespace-nowrap"
                >
                  {preset.label}
                </button>
              ))}
            </div>
          )}

          {/* MiniCalendar Body */}
          <div className="p-2">
            <MiniCalendar
              mode="range"
              selected={showFooter ? draftRange : value}
              onSelect={(r) => {
                setDraftRange(r);
                if (!showFooter && onChange) onChange(r);
              }}
              disabled={disabledDates}
            />
          </div>
        </div>

        {/* Footer with Apply / Cancel Actions */}
        {showFooter && (
          <div className="p-3 bg-slate-50/80 border-t border-slate-100 flex items-center justify-between gap-3 text-xs">
            <div className="text-[11.5px] text-slate-500 font-mono truncate">
              {draftRange?.from ? (
                <span>
                  {format(draftRange.from, 'MMM d, yyyy')}
                  {draftRange.to ? ` → ${format(draftRange.to, 'MMM d, yyyy')}` : ' (Select end)'}
                </span>
              ) : (
                <span>No range selected</span>
              )}
            </div>

            <div className="flex items-center gap-2 shrink-0">
              <Button size="sm" variant="outline" onClick={handleCancel} className="h-7 text-xs px-2.5">
                Cancel
              </Button>
              <Button
                size="sm"
                variant="primary"
                onClick={handleApply}
                disabled={!draftRange?.from}
                className="h-7 text-xs px-3 gap-1"
              >
                <Check className="w-3.5 h-3.5" />
                <span>Apply Range</span>
              </Button>
            </div>
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
}

export const DateRangePicker = OVSDateRangePicker;
