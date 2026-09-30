import React from 'react';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { DayPicker, type MonthCaptionProps, useDayPicker } from 'react-day-picker';
import { format } from 'date-fns';
import { Calendar as CalendarIcon, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The CRM's date field.
 *
 * `input[type="date"]` renders the browser's own calendar — a different
 * control on every OS, none of them this product's — so a page otherwise
 * built from CRM parts dropped into a grey system popup the moment someone
 * picked a date. This replaces it everywhere rather than per module.
 *
 * The contract is deliberately the native one: `value` is a `yyyy-mm-dd`
 * string, `onChange` receives an event whose `target.value` is that same
 * string, and `min`/`max`/`disabled`/`required`/`name` mean what they always
 * did. That is what lets it drop into an Inertia form, a URL-backed filter or
 * a plain controlled field without any of them learning a new shape.
 *
 * Built from parts already approved here — Radix Popover, the same family as
 * the select primitives, and the `react-day-picker` already in the project —
 * rather than a second calendar of its own.
 */

/**
 * The trigger, restyled to match `ovs/primitives/input`.
 *
 * Parts of CRM and Finance are still built from the older OVS field styling,
 * and a date field there has to line up with the text fields beside it rather
 * than import the CRM surface look into one control. Passed as `className` so
 * one picker serves both, instead of a second picker for the older forms.
 */
export const CRM_DATE_PICKER_OVS_FIELD =
  'h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs shadow-2xs hover:border-slate-300 focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20';

export interface CrmDatePickerProps {
  /** ISO `yyyy-mm-dd`, exactly as a native date input carries it. */
  value?: string;
  /** Receives a native-shaped change event; read `e.target.value`. */
  onChange?: (event: { target: { value: string; name?: string } }) => void;
  name?: string;
  id?: string;
  /** ISO `yyyy-mm-dd` bounds, as on the native control. */
  min?: string;
  max?: string;
  disabled?: boolean;
  required?: boolean;
  placeholder?: string;
  invalid?: boolean;
  /** Offer a control to empty the field. On by default when not required. */
  clearable?: boolean;
  className?: string;
  'aria-label'?: string;
  /**
   * Replaces the default field-styled trigger with custom markup — e.g. the
   * Appointments calendar's compact date-tile — while reusing this same
   * popover/`DayPicker` underneath rather than a second calendar. Omit for
   * the default trigger; every existing caller is unaffected.
   */
  trigger?: React.ReactNode;
}

/** `yyyy-mm-dd` → a Date at local midnight, so the day never shifts by timezone. */
function parseISO(value?: string): Date | undefined {
  if (!value) {
    return undefined;
  }

  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

  if (!match) {
    return undefined;
  }

  const [, year, month, day] = match;
  const date = new Date(Number(year), Number(month) - 1, Number(day));

  return Number.isNaN(date.getTime()) ? undefined : date;
}

/** Back to `yyyy-mm-dd` from local parts — never `toISOString()`, which is UTC. */
function toISO(date: Date): string {
  return format(date, 'yyyy-MM-dd');
}

function MonthCaption({ calendarMonth }: MonthCaptionProps) {
  const { previousMonth, nextMonth, goToMonth } = useDayPicker();

  const navButton =
    'flex h-7 w-7 items-center justify-center rounded-full text-ovs-crm-text-secondary transition-colors hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary disabled:pointer-events-none disabled:opacity-30';

  return (
    <div className="mb-1 flex h-8 select-none items-center justify-between gap-2">
      <button
        type="button"
        className={navButton}
        disabled={!previousMonth}
        onClick={() => previousMonth && goToMonth(previousMonth)}
        aria-label="Previous month"
      >
        <ChevronLeft className="h-4 w-4" aria-hidden="true" />
      </button>

      <span className="text-[13px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
        {format(calendarMonth.date, 'MMMM yyyy')}
      </span>

      <button
        type="button"
        className={navButton}
        disabled={!nextMonth}
        onClick={() => nextMonth && goToMonth(nextMonth)}
        aria-label="Next month"
      >
        <ChevronRight className="h-4 w-4" aria-hidden="true" />
      </button>
    </div>
  );
}

export function CrmDatePicker({
  value,
  onChange,
  name,
  id,
  min,
  max,
  disabled,
  required,
  placeholder = 'Pick a date',
  invalid,
  clearable,
  className,
  trigger,
  ...rest
}: CrmDatePickerProps) {
  const [open, setOpen] = React.useState(false);

  const selected = parseISO(value);
  const minDate = parseISO(min);
  const maxDate = parseISO(max);
  const showClear = (clearable ?? !required) && Boolean(value);

  const emit = (next: string) => onChange?.({ target: { value: next, name } });

  const handleSelect = (date: Date | undefined) => {
    if (!date) {
      return;
    }

    emit(toISO(date));
    setOpen(false);
  };

  /*
   * The same bounds the native control enforced, handed to the calendar so
   * an out-of-range day cannot be clicked in the first place.
   */
  const disabledMatcher = [
    ...(minDate ? [{ before: minDate }] : []),
    ...(maxDate ? [{ after: maxDate }] : []),
  ];

  return (
    <>
      {/*
        * Keeps a real form submission working — a plain `<form>` post reads
        * this, exactly as it read the native input it replaces.
        */}
      {name && <input type="hidden" name={name} value={value ?? ''} />}

      <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
        <PopoverPrimitive.Trigger asChild disabled={disabled}>
          {trigger ?? (
            <button
              type="button"
              id={id}
              aria-invalid={invalid || undefined}
              aria-required={required || undefined}
              className={cn(
                /* Same ground as every other control — see CONTROL_BASE. */
                'flex h-11 w-full items-center gap-2 rounded-[16px] border border-ovs-crm-border bg-white px-4 text-left text-[13px] transition-[box-shadow,border-color,background-color]',
                'hover:border-ovs-crm-border-strong',
                'focus:border-ovs-crm-ink/30 focus:outline-none focus:ring-2 focus:ring-ovs-crm-ink/15',
                'disabled:cursor-not-allowed disabled:border-ovs-crm-border/60 disabled:bg-ovs-crm-surface-inset disabled:text-ovs-crm-text-muted',
                invalid && 'ring-2 ring-ovs-crm-accent-coral/40',
                className
              )}
              {...rest}
            >
              <CalendarIcon className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
              <span
                className={cn(
                  'min-w-0 flex-1 truncate',
                  selected ? 'text-ovs-crm-text-primary' : 'text-ovs-crm-text-muted'
                )}
              >
                {selected ? format(selected, 'd MMM yyyy') : placeholder}
              </span>

              {showClear && (
                /*
                  * Rendered inside the trigger rather than beside it, so a
                  * field that can be emptied does not change the row's layout.
                  * `asChild` on the trigger means this cannot be a nested
                  * button — a span carrying the click does the same job without
                  * invalid markup.
                  */
                <span
                  role="button"
                  tabIndex={0}
                  aria-label="Clear date"
                  className="shrink-0 rounded-full p-0.5 text-ovs-crm-text-muted transition-colors hover:bg-white hover:text-ovs-crm-text-primary"
                  onClick={(event) => {
                    event.stopPropagation();
                    emit('');
                  }}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault();
                      event.stopPropagation();
                      emit('');
                    }
                  }}
                >
                  <X className="h-3.5 w-3.5" aria-hidden="true" />
                </span>
              )}
            </button>
          )}
        </PopoverPrimitive.Trigger>

        <PopoverPrimitive.Portal>
          <PopoverPrimitive.Content
            align="start"
            sideOffset={6}
            className={cn(
              'z-50 rounded-[20px] bg-white p-3 shadow-ovs-crmLift',
              'data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95'
            )}
          >
            <DayPicker
              mode="single"
              selected={selected}
              onSelect={handleSelect}
              defaultMonth={selected ?? minDate ?? undefined}
              disabled={disabledMatcher.length > 0 ? disabledMatcher : undefined}
              showOutsideDays
              components={{ MonthCaption }}
              classNames={{
                root: 'font-sans',
                /*
                 * DayPicker ships its own month arrows and the caption above
                 * draws its own, so without this the popover showed two sets.
                 * Hidden through `classNames` rather than by overriding the
                 * Nav component — the override is what leaves the calendar in
                 * `ovs/calendar` with a type error to this day.
                 */
                nav: 'hidden',
                month: 'w-[248px] space-y-2',
                month_grid: 'w-full border-collapse',
                weekdays: 'mb-1 flex w-full justify-between border-b border-ovs-crm-border pb-1',
                weekday: 'w-8 text-center text-[10px] font-bold uppercase tracking-[0.08em] text-ovs-crm-text-muted',
                week: 'mt-1 flex w-full justify-between',
                day: 'h-8 w-8 rounded-[10px] text-[12px] text-ovs-crm-text-primary transition-colors hover:bg-ovs-crm-surface-inset',
                day_button: 'flex h-full w-full cursor-pointer items-center justify-center',
                selected: 'bg-ovs-crm-ink text-white hover:bg-ovs-crm-ink',
                today: 'font-bold text-ovs-crm-text-primary ring-1 ring-inset ring-ovs-crm-border-strong',
                outside: 'text-ovs-crm-text-muted opacity-45',
                disabled: 'cursor-not-allowed text-ovs-crm-text-muted opacity-35 hover:bg-transparent',
                hidden: 'invisible',
              }}
            />

            <div className="mt-2 flex items-center justify-between border-t border-ovs-crm-border pt-2">
              <button
                type="button"
                className="rounded-full px-2 py-1 text-[11.5px] font-medium text-ovs-crm-text-secondary transition-colors hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary disabled:opacity-40"
                disabled={!value}
                onClick={() => {
                  emit('');
                  setOpen(false);
                }}
              >
                Clear
              </button>
              <button
                type="button"
                className="rounded-full px-2 py-1 text-[11.5px] font-medium text-ovs-crm-text-secondary transition-colors hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary"
                onClick={() => handleSelect(new Date())}
              >
                Today
              </button>
            </div>
          </PopoverPrimitive.Content>
        </PopoverPrimitive.Portal>
      </PopoverPrimitive.Root>
    </>
  );
}
