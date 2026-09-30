import * as React from 'react';
import * as SelectPrimitive from '@radix-ui/react-select';
import { Check, ChevronDown, ChevronUp } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The CRM's select primitives.
 *
 * An API-compatible fork of `@/ovs/primitives/select` — same Radix parts, same
 * names — so a page moves across on its import line alone. The shared version
 * paints raw slate and teal and is depended on by four other modules.
 *
 * What changed: the trigger matches the CRM's other controls (inset surface, no
 * border until focus, fully round), the popover is a soft-shadowed white sheet
 * rather than a bordered box, the checked mark takes the ink rather than teal,
 * and group labels lose the uppercase micro-caps.
 */

const Select = SelectPrimitive.Root;
const SelectGroup = SelectPrimitive.Group;
const SelectValue = SelectPrimitive.Value;

/**
 * Radix's `SelectItem` rejects an empty-string value outright — it reserves
 * `""` internally to mean "nothing selected." Several existing pages have a
 * real, selectable "no selection" option with `value=""` (e.g. "No default
 * — pick per campaign"), so any adapter built on these primitives needs one
 * shared stand-in for it. Kept here, next to the primitives it works around,
 * so `CrmSelect` and `CrmFilterBar` can't drift onto two different sentinels.
 *
 * This value never crosses a public value/onChange boundary — every adapter
 * using it translates back to a real `""` before a caller ever sees it.
 */
export const EMPTY_SELECT_VALUE = '__crm-select-empty__';

export const toSelectValue = (value: string): string => (value === '' ? EMPTY_SELECT_VALUE : value);

export const fromSelectValue = (value: string): string => (value === EMPTY_SELECT_VALUE ? '' : value);

const SelectTrigger = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.Trigger>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.Trigger> & { error?: boolean }
>(({ className, children, error, ...props }, ref) => (
  <SelectPrimitive.Trigger
    ref={ref}
    aria-invalid={error ? true : props['aria-invalid']}
    className={cn(
      /*
       * Matches CrmInput's ground exactly — see CONTROL_BASE in CrmControls.
       * A select and a text field sitting in the same row must look like the
       * same kind of thing, and both must look like something you can act on.
       */
      'flex h-11 w-full items-center justify-between whitespace-nowrap rounded-[16px] border border-ovs-crm-border bg-white px-4 text-[13px] text-ovs-crm-text-primary transition-[box-shadow,border-color,background-color]',
      'data-[placeholder]:text-ovs-crm-text-muted',
      'hover:border-ovs-crm-border-strong',
      'focus:border-ovs-crm-ink/30 focus:outline-none focus:ring-2 focus:ring-ovs-crm-ink/15',
      'disabled:cursor-not-allowed disabled:border-ovs-crm-border/60 disabled:bg-ovs-crm-surface-inset',
      'disabled:text-ovs-crm-text-muted disabled:hover:border-ovs-crm-border/60 [&>span]:line-clamp-1',
      error && 'ring-2 ring-ovs-crm-accent-coral/40',
      className
    )}
    {...props}
  >
    {children}
    <SelectPrimitive.Icon asChild>
      <ChevronDown className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" />
    </SelectPrimitive.Icon>
  </SelectPrimitive.Trigger>
));
SelectTrigger.displayName = SelectPrimitive.Trigger.displayName;

const SelectScrollUpButton = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.ScrollUpButton>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.ScrollUpButton>
>(({ className, ...props }, ref) => (
  <SelectPrimitive.ScrollUpButton
    ref={ref}
    className={cn('flex cursor-default items-center justify-center py-1 text-ovs-crm-text-muted', className)}
    {...props}
  >
    <ChevronUp className="h-3.5 w-3.5" />
  </SelectPrimitive.ScrollUpButton>
));
SelectScrollUpButton.displayName = SelectPrimitive.ScrollUpButton.displayName;

const SelectScrollDownButton = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.ScrollDownButton>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.ScrollDownButton>
>(({ className, ...props }, ref) => (
  <SelectPrimitive.ScrollDownButton
    ref={ref}
    className={cn('flex cursor-default items-center justify-center py-1 text-ovs-crm-text-muted', className)}
    {...props}
  >
    <ChevronDown className="h-3.5 w-3.5" />
  </SelectPrimitive.ScrollDownButton>
));
SelectScrollDownButton.displayName = SelectPrimitive.ScrollDownButton.displayName;

const SelectContent = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.Content>
>(({ className, children, position = 'popper', ...props }, ref) => (
  <SelectPrimitive.Portal>
    <SelectPrimitive.Content
      ref={ref}
      position={position}
      className={cn(
        'relative z-50 max-h-96 min-w-[8rem] overflow-hidden rounded-[20px] bg-white text-ovs-crm-text-primary shadow-ovs-crmLift',
        /*
         * Enter animation only. The absent exit animation is load-bearing,
         * not a style preference.
         *
         * `SelectContentImpl` in @radix-ui/react-select@2.3.4 mounts a
         * `DismissableLayer` with `disableOutsidePointerEvents: true`,
         * wrapped in `RemoveScroll`. Neither is configurable — this version
         * exposes no `modal` prop on Root or Content. That layer is what
         * sets `body.style.pointerEvents = "none"`, and it restores the
         * previous value from its effect cleanup, which runs only when the
         * content actually unmounts. Unmounting is in turn gated by Radix's
         * `Presence`, which holds the node until a closing animation reports
         * finished.
         *
         * Measured both ways on this page: with an exit animation declared,
         * closing left the node mounted at `data-state="closed"` with
         * `pointer-events: none` still on <body> — the page unclickable, so
         * a click-drag becomes a document-wide text selection and the screen
         * reads as going dark. Without one, the same close unmounted
         * immediately and the body was restored. An exit animation only
         * finishes reliably while the tab is visible and animations run;
         * anything that interrupts that window strands the lock, so the
         * close path is kept synchronous instead.
         */
        'data-[state=open]:animate-in data-[state=open]:fade-in-0',
        'data-[state=open]:zoom-in-95',
        'data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
        position === 'popper' &&
          'data-[side=bottom]:translate-y-1.5 data-[side=left]:-translate-x-1.5 data-[side=right]:translate-x-1.5 data-[side=top]:-translate-y-1.5',
        className
      )}
      {...props}
    >
      <SelectScrollUpButton />
      <SelectPrimitive.Viewport
        className={cn(
          'p-1.5',
          position === 'popper' && 'h-[var(--radix-select-trigger-height)] w-full min-w-[var(--radix-select-trigger-width)]'
        )}
      >
        {children}
      </SelectPrimitive.Viewport>
      <SelectScrollDownButton />
    </SelectPrimitive.Content>
  </SelectPrimitive.Portal>
));
SelectContent.displayName = SelectPrimitive.Content.displayName;

const SelectLabel = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.Label>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.Label>
>(({ className, ...props }, ref) => (
  <SelectPrimitive.Label
    ref={ref}
    className={cn('px-3 py-1.5 text-[11.5px] text-ovs-crm-text-muted', className)}
    {...props}
  />
));
SelectLabel.displayName = SelectPrimitive.Label.displayName;

const SelectItem = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.Item>
>(({ className, children, ...props }, ref) => (
  <SelectPrimitive.Item
    ref={ref}
    className={cn(
      // `min-h-11` (44px), not just `py-*` — a touch target floor that holds
      // regardless of the text's actual line-height, for the 44×44px
      // minimum every tappable control needs on an in-clinic tablet/iPad.
      'relative flex w-full min-h-11 cursor-pointer select-none items-center rounded-[14px] py-2 pl-3 pr-9 text-[13px] text-ovs-crm-text-primary outline-none transition-colors',
      'hover:bg-ovs-crm-surface-inset focus:bg-ovs-crm-surface-inset',
      'data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
      className
    )}
    {...props}
  >
    <span className="absolute right-3 flex h-3.5 w-3.5 items-center justify-center">
      <SelectPrimitive.ItemIndicator>
        <Check className="h-3.5 w-3.5 stroke-[2.5] text-ovs-crm-ink" />
      </SelectPrimitive.ItemIndicator>
    </span>
    <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
  </SelectPrimitive.Item>
));
SelectItem.displayName = SelectPrimitive.Item.displayName;

const SelectSeparator = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.Separator>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.Separator>
>(({ className, ...props }, ref) => (
  <SelectPrimitive.Separator ref={ref} className={cn('-mx-1 my-1 h-px bg-ovs-crm-border', className)} {...props} />
));
SelectSeparator.displayName = SelectPrimitive.Separator.displayName;

export interface SimpleSelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  error?: boolean;
}

export const SimpleSelect = React.forwardRef<HTMLSelectElement, SimpleSelectProps>(
  ({ className, error, children, ...props }, ref) => (
    <div className="relative">
      <select
        ref={ref}
        aria-invalid={error ? true : props['aria-invalid']}
        className={cn(
          /* The native fallback follows the same rule as the Radix trigger. */
          'h-11 w-full cursor-pointer appearance-none rounded-[16px] border border-ovs-crm-border bg-white px-4 pr-10 text-[13px] text-ovs-crm-text-primary transition-[box-shadow,border-color,background-color]',
          'hover:border-ovs-crm-border-strong',
          'focus:border-ovs-crm-ink/30 focus:outline-none focus:ring-2 focus:ring-ovs-crm-ink/15',
          'disabled:cursor-not-allowed disabled:border-ovs-crm-border/60 disabled:bg-ovs-crm-surface-inset disabled:text-ovs-crm-text-muted',
          error && 'ring-2 ring-ovs-crm-accent-coral/40',
          className
        )}
        {...props}
      >
        {children}
      </select>
      <ChevronDown
        className="pointer-events-none absolute right-4 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-ovs-crm-text-muted"
        aria-hidden="true"
      />
    </div>
  )
);
SimpleSelect.displayName = 'SimpleSelect';

export {
  Select,
  SelectGroup,
  SelectValue,
  SelectTrigger,
  SelectContent,
  SelectLabel,
  SelectItem,
  SelectSeparator,
  SelectScrollUpButton,
  SelectScrollDownButton,
};
