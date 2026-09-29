import React from 'react';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  toSelectValue,
  EMPTY_SELECT_VALUE,
  fromSelectValue,
} from './CrmSelectPrimitives';

/**
 * Buttons and form controls, in the reference language.
 *
 * Two shapes and nothing else: a black pill for the one action a screen wants
 * you to take, and a white pill for everything else. Controls are fully round
 * rather than softly rounded, inputs sit on the inset surface with no visible
 * border until focus, and labels sit above their field rather than beside it.
 *
 * Every CRM page previously chose its own button height, radius, weight and
 * border. That is the largest single reason the product read as a set of
 * separately built screens.
 */

/* ────────────────────────────────────────────────────────────────── button */

type ButtonTone = 'primary' | 'secondary' | 'ghost' | 'danger';
type ButtonSize = 'sm' | 'md' | 'lg';

const TONE: Record<ButtonTone, string> = {
  primary: 'bg-ovs-crm-ink text-white hover:bg-ovs-crm-ink-soft',
  /*
   * The secondary button carries a border, not just a shadow.
   *
   * White on the page's tinted background reads fine, but most of these sit
   * inside a white card — a WhatsApp account's Test/Edit/Disconnect row, a
   * record's actions — where white-on-white left only a faint shadow to say
   * anything was there at all. They were legible if you knew to look and
   * easy to miss if you didn't. The border gives them an edge on any
   * surface; the shadow still does the lifting.
   */
  secondary:
    'border border-ovs-crm-border bg-white text-ovs-crm-text-primary shadow-ovs-crmPanel hover:border-ovs-crm-border-strong hover:bg-ovs-crm-surface-inset',
  ghost: 'text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary',
  danger: 'bg-ovs-crm-accent-coral text-white hover:brightness-95',
};

const SIZE: Record<ButtonSize, string> = {
  sm: 'h-8 gap-1.5 px-3 text-[11.5px]',
  md: 'h-10 gap-2 px-4 text-[13px]',
  lg: 'h-11 gap-2 px-5 text-[13px]',
};

export interface CrmButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  tone?: ButtonTone;
  size?: ButtonSize;
  icon?: LucideIcon;
  /** Renders an Inertia link styled as a button. */
  href?: string;
  /**
   * Leave the app rather than making an Inertia visit.
   *
   * Required for anything the browser owns instead of the router — a `tel:`
   * number, a WhatsApp address, a meeting link. Without it `Link` intercepts
   * the click and asks the server for an Inertia page that does not exist.
   */
  external?: boolean;
  target?: string;
  rel?: string;
  /** Disables the control and shows a working state. */
  loading?: boolean;
}

export function CrmButton({
  tone = 'secondary',
  size = 'md',
  icon: Icon,
  href,
  external = false,
  target,
  rel,
  loading = false,
  disabled,
  className,
  children,
  ...rest
}: CrmButtonProps) {
  const classes = cn(
    'crm-lift inline-flex shrink-0 items-center justify-center rounded-full font-medium',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/25',
    'disabled:pointer-events-none disabled:opacity-45',
    TONE[tone],
    SIZE[size],
    className
  );

  const inner = (
    <>
      {Icon && <Icon className={size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4'} aria-hidden="true" />}
      {children}
    </>
  );

  /*
   * An Inertia visit is the right default and the wrong thing for a link that
   * leaves the app. `Link` intercepts the click and asks the server for an
   * Inertia page, so a `wa.me` address, a `tel:` number or a Google Meet URL
   * came back as an error instead of opening — the button looked live and did
   * nothing. `external` renders the anchor the browser already knows how to
   * follow.
   */
  if (href && external) {
    return (
      <a href={href} className={classes} target={target} rel={rel ?? (target === '_blank' ? 'noreferrer' : undefined)}>
        {inner}
      </a>
    );
  }

  if (href) {
    return <Link href={href} className={classes}>{inner}</Link>;
  }

  return (
    <button type="button" disabled={disabled || loading} className={classes} {...rest}>
      {loading ? <span className="animate-pulse">Working…</span> : inner}
    </button>
  );
}

/* ───────────────────────────────────────────────────────────────── fields */

/**
 * Label, control, and its error, as one unit.
 *
 * The error owns a reserved line so a form does not jump as messages appear —
 * validation shifting the layout underneath a cursor is the classic way a form
 * loses someone mid-entry.
 */
export function CrmFormField({
  label,
  hint,
  error,
  required = false,
  htmlFor,
  children,
  className,
}: {
  label?: string;
  hint?: string;
  error?: string;
  required?: boolean;
  htmlFor?: string;
  children: React.ReactNode;
  className?: string;
}) {
  /*
   * The label is tied to the control it labels.
   *
   * `htmlFor` was accepted and never passed by any caller, so every field in
   * the CRM rendered a label with no `for` beside a control with no `id` — a
   * screen reader announced "edit text" and nothing else, and clicking the
   * label did not focus the field. The id is generated here and given to the
   * child unless the caller supplied its own.
   */
  const generatedId = React.useId();
  const controlId = htmlFor ?? generatedId;
  const labelId = `${controlId}-label`;
  /*
   * The error/hint line is also tied to the control, so a screen reader reads
   * "Password, invalid entry, Password is required" rather than stopping at
   * "invalid entry" and leaving the reason silently on screen.
   */
  const descriptionId = `${controlId}-description`;
  const hasDescription = Boolean(error || hint);

  /*
   * `aria-labelledby` as well as the id, because a Radix-backed select renders
   * a button trigger with a hidden native select behind it: `htmlFor` reaches
   * the hidden element, and the control a person actually tabs to announces
   * nothing without this.
   */
  const labelled = React.isValidElement(children) && ! (children.props as { id?: string }).id
    ? React.cloneElement(children as React.ReactElement<{ id?: string; 'aria-labelledby'?: string; 'aria-describedby'?: string }>, {
      id: controlId,
      'aria-labelledby': label ? labelId : undefined,
      'aria-describedby': hasDescription ? descriptionId : undefined,
    })
    : children;

  return (
    <div className={cn('min-w-0', className)}>
      {label && (
        <label id={labelId} htmlFor={controlId} className="mb-1.5 block text-[11.5px] font-medium text-ovs-crm-text-secondary">
          {label}
          {required && <span className="ml-0.5 text-ovs-crm-accent-coral">*</span>}
        </label>
      )}

      {labelled}

      {hasDescription && (
        <p
          id={descriptionId}
          className={cn(
            'mt-1.5 text-[11.5px] leading-tight',
            error ? 'text-ovs-crm-accent-coral-text' : 'text-ovs-crm-text-muted'
          )}
        >
          {error ?? hint}
        </p>
      )}
    </div>
  );
}

/*
 * A field looks like a field, and grey means you cannot type in it.
 *
 * Three things were wrong and they compounded. The resting state was a grey
 * fill four per cent darker than the card it sat on, with no border at all, so
 * a field read as a grey patch rather than a box. White was the *focus* state,
 * which is the opposite of the convention every other form on the web teaches
 * — so an untouched form looked switched off. And disabled was expressed as
 * `opacity-55` over that same pale grey, which on this palette is very nearly
 * invisible: a genuinely disabled control was indistinguishable from a usable
 * one sitting beside it.
 *
 * Now: white ground with a real border at rest, a ring on focus, and grey
 * reserved for disabled — where it carries meaning rather than decoration.
 */
const CONTROL_BASE =
  'w-full rounded-[16px] border border-ovs-crm-border bg-white px-4 text-[13px] text-ovs-crm-text-primary ' +
  'placeholder:text-ovs-crm-text-muted transition-[box-shadow,border-color,background-color] ' +
  'hover:border-ovs-crm-border-strong ' +
  'focus:border-ovs-crm-ink/30 focus:outline-none focus:ring-2 focus:ring-ovs-crm-ink/15 ' +
  'disabled:cursor-not-allowed disabled:border-ovs-crm-border/60 disabled:bg-ovs-crm-surface-inset ' +
  'disabled:text-ovs-crm-text-muted disabled:hover:border-ovs-crm-border/60';

export const CrmInput = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }>(
  function CrmInput({ className, invalid, ...rest }, ref) {
    return (
      <input
        ref={ref}
        aria-invalid={invalid || undefined}
        className={cn(CONTROL_BASE, 'h-11', invalid && 'ring-2 ring-ovs-crm-accent-coral/40', className)}
        {...rest}
      />
    );
  }
);

export const CrmTextarea = React.forwardRef<HTMLTextAreaElement, React.TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }>(
  function CrmTextarea({ className, invalid, rows = 4, ...rest }, ref) {
    return (
      <textarea
        ref={ref}
        rows={rows}
        aria-invalid={invalid || undefined}
        className={cn(CONTROL_BASE, 'resize-y py-3 leading-relaxed', invalid && 'ring-2 ring-ovs-crm-accent-coral/40', className)}
        {...rest}
      />
    );
  }
);

interface ParsedSelectOption {
  value: string;
  label: React.ReactNode;
  disabled?: boolean;
}

interface ParsedSelectGroup {
  label: string;
  options: ParsedSelectOption[];
}

/**
 * Reads the exact `<option>`/`<optgroup>` children a `CrmSelect` call site
 * already passes and turns them into what the Radix-based primitives render
 * — so no call site has to change its JSX to get the premium popover. Plain
 * `React.Children.forEach` rather than manual array handling, so it copes
 * the same way with a static list, a `.map()`-built list, and a
 * conditionally-rendered option (`{x.length === 0 && <option ... />}`)
 * sitting next to one — all three patterns are in real use today.
 */
function parseSelectChildren(children: React.ReactNode): { options: ParsedSelectOption[]; groups: ParsedSelectGroup[] } {
  const options: ParsedSelectOption[] = [];
  const groups: ParsedSelectGroup[] = [];

  React.Children.forEach(children, (child) => {
    if (!React.isValidElement(child)) {
      return;
    }

    if (child.type === 'option') {
      const props = child.props as React.OptionHTMLAttributes<HTMLOptionElement>;
      options.push({
        value: toSelectValue(String(props.value ?? '')),
        label: props.children,
        disabled: props.disabled,
      });
      return;
    }

    if (child.type === 'optgroup') {
      const props = child.props as React.OptgroupHTMLAttributes<HTMLOptGroupElement>;
      const groupOptions: ParsedSelectOption[] = [];

      React.Children.forEach(props.children, (grandchild) => {
        if (!React.isValidElement(grandchild) || grandchild.type !== 'option') {
          return;
        }
        const optionProps = grandchild.props as React.OptionHTMLAttributes<HTMLOptionElement>;
        groupOptions.push({
          value: toSelectValue(String(optionProps.value ?? '')),
          label: optionProps.children,
          disabled: optionProps.disabled,
        });
      });

      groups.push({ label: String(props.label ?? ''), options: groupOptions });
    }
  });

  return { options, groups };
}

export interface CrmSelectOption {
  value: string;
  label: React.ReactNode;
  disabled?: boolean;
}

export interface CrmSelectProps {
  value?: string;
  defaultValue?: string;
  onChange?: (event: React.ChangeEvent<HTMLSelectElement>) => void;
  /**
   * Value-first alternative to `onChange`, for call sites that only want the
   * chosen value and never read the event. Both fire, so mixing is safe.
   */
  onValueChange?: (value: string) => void;
  /**
   * Declarative alternative to `<option>` children. Children still win when
   * both are supplied — they are the older form and carry optgroups.
   */
  options?: CrmSelectOption[];
  disabled?: boolean;
  required?: boolean;
  name?: string;
  id?: string;
  placeholder?: string;
  invalid?: boolean;
  className?: string;
  children?: React.ReactNode;
  'aria-label'?: string;
  'aria-describedby'?: string;
}

/**
 * The premium CRM select, everywhere a plain `<select>` used to sit.
 *
 * Built on the same Radix-based primitives Campaign Detail and the Contacts
 * filters already use — the closed trigger and the opened popover are both
 * app-rendered now, instead of a styled trigger falling back to the
 * browser's own system-styled option list the moment it opens.
 *
 * The external contract is unchanged on purpose: every existing call site —
 * `<CrmSelect value={v} onChange={(e) => setV(e.target.value)}><option
 * value="a">A</option></CrmSelect>` — keeps working without touching its
 * JSX. `onChange` receives a fabricated `ChangeEvent` carrying just
 * `target.value` (and `name`), which is all any current call site reads.
 */
export const CrmSelect = React.forwardRef<HTMLButtonElement, CrmSelectProps>(function CrmSelect(
  { className, invalid, children, options: optionsProp, value, defaultValue, onChange, onValueChange, disabled, required, name, id, placeholder, ...rest },
  ref
) {
  const parsed = parseSelectChildren(children);

  /*
   * Children win when both are supplied: they are the older, more expressive
   * form (they carry optgroups), so a call site passing both almost certainly
   * means the JSX it can actually see.
   */
  const options =
    parsed.options.length > 0 || parsed.groups.length > 0
      ? parsed.options
      : (optionsProp ?? []).map((option) => ({
          value: toSelectValue(String(option.value)),
          label: option.label,
          disabled: option.disabled,
        }));
  const groups = parsed.groups;

  /*
   * An uncontrolled select still needs its own record of what is chosen,
   * because the trigger's label is rendered here rather than by Radix (see
   * below). A controlled one ignores this and reads `value` every render.
   */
  const [uncontrolledValue, setUncontrolledValue] = React.useState<string | undefined>(
    defaultValue != null ? toSelectValue(String(defaultValue)) : undefined
  );
  const isControlled = value != null;

  /*
   * `String(...)` on both sides before comparing: a call site is free to
   * hand over a number (`value={someId}`) where the `<option value>` it
   * rendered became a string, and a select whose value only *looks* like
   * its option would otherwise silently show nothing.
   */
  const currentValue = isControlled ? toSelectValue(String(value)) : uncontrolledValue;

  const handleValueChange = (nextRadixValue: string) => {
    if (!isControlled) {
      setUncontrolledValue(nextRadixValue);
    }

    const nextValue = fromSelectValue(nextRadixValue);
    const syntheticEvent = {
      target: { value: nextValue, name },
      currentTarget: { value: nextValue, name },
    } as unknown as React.ChangeEvent<HTMLSelectElement>;

    onChange?.(syntheticEvent);
    onValueChange?.(nextValue);
  };

  const selectedOption = currentValue != null
    ? [...options, ...groups.flatMap((group) => group.options)].find((option) => option.value === currentValue)
    : undefined;

  /** Nothing chosen: no value at all, or the sentinel standing in for "". */
  const hasNoValue = currentValue == null || currentValue === '' || currentValue === EMPTY_SELECT_VALUE;

  return (
    <Select
      value={isControlled ? currentValue : undefined}
      defaultValue={defaultValue != null ? toSelectValue(String(defaultValue)) : undefined}
      onValueChange={handleValueChange}
      disabled={disabled}
      required={required}
      name={name}
    >
      <SelectTrigger
        ref={ref}
        id={id}
        error={invalid || undefined}
        className={cn('h-11', className)}
        {...rest}
      >
        {/*
          * The trigger paints its own label instead of delegating to
          * `SelectValue` — same reasoning as CrmFilterBar's FilterSelect:
          * Radix resolves the displayed text from the item collection,
          * which lives in a detached fragment while the popover is closed,
          * and that could leave the control blank after an update that
          * changed the value and rebuilt the options together. Rendering
          * the resolved label directly removes that whole path.
          */}
        {/*
          * Muted only when nothing is chosen.
          *
          * This keyed off `selectedOption == null`, so a select carrying a real
          * value whose option the lookup failed to resolve painted its label in
          * placeholder grey — the control read as empty and disabled while
          * actually being set. Placeholder styling should follow "the user has
          * not chosen anything", which is what an absent value means, not what
          * a failed lookup means.
          */}
        <span className={cn('truncate', hasNoValue && 'text-ovs-crm-text-muted')}>
          {selectedOption?.label ?? placeholder ?? ''}
        </span>
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value} disabled={option.disabled}>
            {option.label}
          </SelectItem>
        ))}
        {groups.map((group) => (
          <SelectGroup key={group.label}>
            <SelectLabel>{group.label}</SelectLabel>
            {group.options.map((option) => (
              <SelectItem key={option.value} value={option.value} disabled={option.disabled}>
                {option.label}
              </SelectItem>
            ))}
          </SelectGroup>
        ))}
      </SelectContent>
    </Select>
  );
});

/* ──────────────────────────────────────────────────────────── pill tabs */

/** Segmented pill group — the black-active-pill pattern, for in-page tabs. */
export function CrmTabs<T extends string>({
  tabs,
  value,
  onChange,
  className,
}: {
  tabs: { key: T; label: string; count?: React.ReactNode }[];
  value: T;
  onChange: (key: T) => void;
  className?: string;
}) {
  /*
   * Two elements, not one, and deliberately so.
   *
   * `overflow-x-auto` used to sit on the `inline-flex` pill row itself, which
   * never produces a scrollport: an inline-flex box is sized by its content, so
   * it simply grew past the viewport and got clipped by an ancestor. On a phone
   * that put the last segments beyond reach with no way to scroll to them.
   *
   * The scrollport has to be a block-level box the parent can constrain; the
   * pill row is then free to be wider than it and scroll inside. The negative
   * margin and matching padding keep the pill's shadow from being shaved off by
   * the scrollport's edge.
   */
  return (
    <div className={cn('rail-scroll -mx-1 max-w-full overflow-x-auto px-1', className)}>
      <div
        role="tablist"
        className="inline-flex items-center gap-0.5 rounded-full bg-white p-1 shadow-ovs-crmPanel"
      >
        {tabs.map((tab) => {
          const active = tab.key === value;

          return (
            <button
              key={tab.key}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => onChange(tab.key)}
              className={cn(
                'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-1.5 text-[13px] font-medium transition-colors',
                active ? 'bg-ovs-crm-ink text-white' : 'text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary'
              )}
            >
              {tab.label}
              {tab.count !== undefined && (
                <span
                  className={cn(
                    'rounded-full px-1.5 text-[10px] font-semibold tabular-nums',
                    active ? 'bg-white/25 text-white' : 'bg-ovs-crm-surface-inset text-ovs-crm-text-muted'
                  )}
                >
                  {tab.count}
                </span>
              )}
            </button>
          );
        })}
      </div>
    </div>
  );
}
