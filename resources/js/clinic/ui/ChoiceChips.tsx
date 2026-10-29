import { useRef, type KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';

const CHIP =
  'inline-flex items-center gap-1.5 rounded-full border font-semibold motion-safe:transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong focus-visible:ring-offset-1 disabled:opacity-40 [@media(pointer:coarse)]:min-h-11';

/** `sm` for dense settings lists; `md` (the default) where a choice is made at speed. */
const CHIP_SIZE = { sm: 'min-h-8 px-3 text-[12px]', md: 'min-h-9 px-3.5 text-[13px]' } as const;

export function chipClass(selected: boolean, size: keyof typeof CHIP_SIZE = 'md'): string {
  return cn(CHIP, CHIP_SIZE[size], selected ? 'border-cx-nav-teal/60 bg-cx-nav-active font-semibold text-cx-nav-teal-strong' : 'border-clinic-border bg-clinic-surface text-clinic-ink hover:border-clinic-border-strong');
}

/**
 * One choice from a few, as chips: a radio group with arrow-key movement and
 * one tab stop, so the keyboard path is Tab → arrows → Tab.
 */
export function ChoiceChips({
  label,
  options,
  value,
  onChange,
  id,
  describedBy,
  invalid,
}: {
  label: string;
  options: { value: string; label: string }[];
  value: string;
  onChange: (value: string) => void;
  id?: string;
  describedBy?: string;
  invalid?: boolean;
}) {
  const refs = useRef<(HTMLButtonElement | null)[]>([]);
  const current = Math.max(0, options.findIndex((option) => option.value === value));

  const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    // Enter only chooses this option; it never submits the form (the step
    // moves on from its Continue button or a text field).
    if (event.key === 'Enter') {
      event.preventDefault();
      onChange(options[index].value);

      return;
    }
    const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 0;
    if (step === 0) {
      return;
    }
    event.preventDefault();
    const next = (index + step + options.length) % options.length;
    onChange(options[next].value);
    refs.current[next]?.focus();
  };

  return (
    <div id={id} role="radiogroup" aria-label={label} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="flex flex-wrap gap-2">
      {options.map((option, index) => {
        const selected = option.value === value;

        return (
          <button
            key={option.value}
            ref={(element) => {
              refs.current[index] = element;
            }}
            type="button"
            role="radio"
            aria-checked={selected}
            tabIndex={index === current ? 0 : -1}
            onClick={() => onChange(option.value)}
            onKeyDown={(event) => onKeyDown(event, index)}
            className={chipClass(selected)}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

/**
 * Several choices from a few, as toggle chips, with an "all"/"none" chip
 * that clears the list (`null` means no limit). Each chip is a toggle
 * button (`aria-pressed`).
 */
export function MultiChoiceChips({
  label,
  options,
  value,
  onChange,
  noneLabel,
  size = 'md',
}: {
  label: string;
  options: { value: string; label: string }[];
  value: string[] | null;
  onChange: (next: string[] | null) => void;
  noneLabel: string;
  size?: 'sm' | 'md';
}) {
  const chosen = value ?? [];

  return (
    <div role="group" aria-label={label} className="flex flex-wrap gap-1.5">
      <button type="button" aria-pressed={value === null} onClick={() => onChange(null)} className={chipClass(value === null, size)}>
        {noneLabel}
      </button>
      {options.map((option) => {
        const on = chosen.includes(option.value);

        return (
          <button
            key={option.value}
            type="button"
            aria-pressed={on}
            onClick={() => {
              const next = on ? chosen.filter((item) => item !== option.value) : [...chosen, option.value];
              onChange(next.length === 0 ? null : next);
            }}
            className={chipClass(on, size)}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}
