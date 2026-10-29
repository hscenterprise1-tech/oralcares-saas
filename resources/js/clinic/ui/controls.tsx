import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import * as RadioPrimitive from '@radix-ui/react-radio-group';
import * as SwitchPrimitive from '@radix-ui/react-switch';
import { Check, ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';

/*
 * The portal's form controls. Native elements where the browser's own control
 * is the accessible one (text, select, textarea); Radix where it is not
 * (checkbox, radio, switch), so every control is keyboard-operable, labelled
 * and focus-visible the same way.
 */

const CONTROL =
  'w-full rounded-xl border border-clinic-border bg-clinic-surface px-3 text-[13px] text-clinic-ink placeholder:text-clinic-muted focus:border-clinic-brand-strong focus:outline-none focus:ring-2 focus:ring-clinic-brand/25 disabled:bg-clinic-inset disabled:text-clinic-secondary aria-[invalid=true]:border-clinic-danger aria-[invalid=true]:ring-clinic-danger/20';

export const inputClass = cn(CONTROL, 'h-11');
export const selectClass = cn(CONTROL, 'h-11 pr-8');
export const textareaClass = cn(CONTROL, 'min-h-[76px] py-2.5');

/**
 * A label, the control, and its error or hint. The error is announced; pass
 * the same `htmlFor` as the control's `id` (or use `useFieldId`).
 */
export function Field({
  label,
  htmlFor,
  error,
  hint,
  required,
  children,
  className,
}: {
  label: string;
  htmlFor?: string;
  error?: string | null;
  hint?: ReactNode;
  required?: boolean;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('min-w-0', className)}>
      <label htmlFor={htmlFor} className="mb-1.5 block text-[12px] font-semibold text-clinic-ink">
        {label}
        {required && (
          <span className="ml-0.5 text-clinic-danger" aria-hidden="true">
            *
          </span>
        )}
      </label>
      {children}
      {error ? (
        <p role="alert" id={htmlFor ? `${htmlFor}-error` : undefined} className="mt-1.5 text-[12px] font-medium text-clinic-danger">
          {error}
        </p>
      ) : hint ? (
        <p id={htmlFor ? `${htmlFor}-hint` : undefined} className="mt-1.5 text-[11.5px] text-clinic-secondary">
          {hint}
        </p>
      ) : null}
    </div>
  );
}

/** A stable id for a control and its Field, when the caller has none. */
export function useFieldId(given?: string): string {
  const generated = useId();

  return given ?? `field-${generated.replace(/:/g, '')}`;
}

type InvalidProp = { invalid?: boolean };

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & InvalidProp>(function Input(
  { className, invalid, ...rest },
  ref,
) {
  return <input ref={ref} aria-invalid={invalid || undefined} className={cn(inputClass, className)} {...rest} />;
});

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & InvalidProp>(
  function Textarea({ className, invalid, ...rest }, ref) {
    return <textarea ref={ref} aria-invalid={invalid || undefined} className={cn(textareaClass, className)} {...rest} />;
  },
);

/**
 * The browser's own select, styled: fast with long lists, native on phones,
 * and accessible without extra work. Options come from the caller (bounded
 * lists only — a list that needs searching is a `Combobox`).
 */
export const Select = forwardRef<
  HTMLSelectElement,
  SelectHTMLAttributes<HTMLSelectElement> &
    InvalidProp & { options: { value: string; label: string; disabled?: boolean }[]; placeholder?: string }
>(function Select({ className, invalid, options, placeholder, ...rest }, ref) {
  return (
    <div className="relative">
      <select ref={ref} aria-invalid={invalid || undefined} className={cn(selectClass, 'appearance-none', className)} {...rest}>
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value} disabled={option.disabled}>
            {option.label}
          </option>
        ))}
      </select>
      <ChevronDown
        className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-clinic-secondary"
        aria-hidden="true"
      />
    </div>
  );
});

const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong focus-visible:ring-offset-2';

/** A checkbox with its label; the whole row is the target. */
export function Checkbox({
  checked,
  onCheckedChange,
  label,
  description,
  disabled,
  id,
  name,
}: {
  checked: boolean;
  onCheckedChange: (checked: boolean) => void;
  label: ReactNode;
  description?: ReactNode;
  disabled?: boolean;
  id?: string;
  name?: string;
}) {
  const controlId = useFieldId(id);

  return (
    <div className="flex items-start gap-3">
      <CheckboxPrimitive.Root
        id={controlId}
        name={name}
        checked={checked}
        onCheckedChange={(value) => onCheckedChange(value === true)}
        disabled={disabled}
        className={cn(
          'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border border-clinic-border-strong bg-clinic-surface',
          'data-[state=checked]:border-[#0F7C6E] data-[state=checked]:bg-[#0F7C6E] disabled:opacity-50',
          FOCUS,
        )}
      >
        <CheckboxPrimitive.Indicator>
          <Check className="h-3.5 w-3.5 text-white" aria-hidden="true" />
        </CheckboxPrimitive.Indicator>
      </CheckboxPrimitive.Root>
      <label htmlFor={controlId} className="min-w-0 cursor-pointer select-none text-[13px] text-clinic-ink">
        <span className="font-medium">{label}</span>
        {description && <span className="mt-0.5 block text-[12px] text-clinic-secondary">{description}</span>}
      </label>
    </div>
  );
}

/** A labelled group of radio options. Arrow keys move between them. */
export function RadioGroup({
  value,
  onValueChange,
  options,
  label,
  name,
  orientation = 'vertical',
  id,
  invalid = false,
  describedBy,
}: {
  value: string;
  onValueChange: (value: string) => void;
  options: { value: string; label: ReactNode; description?: ReactNode; disabled?: boolean }[];
  label: string;
  name?: string;
  orientation?: 'vertical' | 'horizontal';
  id?: string;
  /** Marks the group as failing validation; pair with `describedBy` pointing at the message. */
  invalid?: boolean;
  describedBy?: string;
}) {
  const baseId = useFieldId();

  return (
    <RadioPrimitive.Root
      id={id}
      value={value}
      onValueChange={onValueChange}
      name={name}
      aria-label={label}
      aria-invalid={invalid || undefined}
      aria-describedby={describedBy}
      orientation={orientation}
      className={cn('flex gap-3', orientation === 'vertical' ? 'flex-col' : 'flex-wrap')}
    >
      {options.map((option) => {
        const optionId = `${baseId}-${option.value}`;

        return (
          <div key={option.value} className="flex items-start gap-3">
            <RadioPrimitive.Item
              id={optionId}
              value={option.value}
              disabled={option.disabled}
              className={cn(
                'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-clinic-border-strong bg-clinic-surface',
                'data-[state=checked]:border-clinic-ink disabled:opacity-50',
                FOCUS,
              )}
            >
              <RadioPrimitive.Indicator className="h-2.5 w-2.5 rounded-full bg-[#0F7C6E]" />
            </RadioPrimitive.Item>
            <label htmlFor={optionId} className="min-w-0 cursor-pointer select-none text-[13px] text-clinic-ink">
              <span className="font-medium">{option.label}</span>
              {option.description && <span className="mt-0.5 block text-[12px] text-clinic-secondary">{option.description}</span>}
            </label>
          </div>
        );
      })}
    </RadioPrimitive.Root>
  );
}

/**
 * An on/off setting that takes effect at once. With `label` it carries its
 * own visible label; without one (a switch in a row that already says what it
 * is) it must be named with `aria-label` or `aria-labelledby`.
 */
export function Switch({
  checked,
  onCheckedChange,
  label,
  description,
  disabled,
  id,
  'aria-label': ariaLabel,
  'aria-labelledby': ariaLabelledBy,
}: {
  checked: boolean;
  onCheckedChange: (checked: boolean) => void;
  disabled?: boolean;
  id?: string;
  description?: ReactNode;
} & ({ label: ReactNode; 'aria-label'?: string; 'aria-labelledby'?: string } | { label?: undefined; 'aria-label': string; 'aria-labelledby'?: string } | { label?: undefined; 'aria-label'?: string; 'aria-labelledby': string })) {
  const controlId = useFieldId(id);
  const control = (
    <SwitchPrimitive.Root
      id={controlId}
      checked={checked}
      onCheckedChange={onCheckedChange}
      disabled={disabled}
      aria-label={ariaLabel}
      aria-labelledby={ariaLabelledBy}
      className={cn(
        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full bg-clinic-border-strong motion-safe:transition-colors',
        'data-[state=checked]:bg-clinic-brand-strong disabled:opacity-50',
        FOCUS,
      )}
    >
      <SwitchPrimitive.Thumb className="block h-5 w-5 translate-x-0.5 rounded-full bg-white shadow motion-safe:transition-transform data-[state=checked]:translate-x-[22px]" />
    </SwitchPrimitive.Root>
  );

  if (label === undefined) {
    return control;
  }

  return (
    <div className="flex items-start justify-between gap-4">
      <label htmlFor={controlId} className="min-w-0 cursor-pointer select-none text-[13px] text-clinic-ink">
        <span className="font-medium">{label}</span>
        {description && <span className="mt-0.5 block text-[12px] text-clinic-secondary">{description}</span>}
      </label>
      {control}
    </div>
  );
}
