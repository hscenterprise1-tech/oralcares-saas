import { useRef, type ReactNode } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useReturnFocus } from './focus';

/**
 * The calendar's centred dialog. On a phone it fills the screen width and
 * scrolls inside; its header and footer stay put.
 */
export function Modal({
  open,
  onOpenChange,
  title,
  description,
  children,
  footer,
  size = 'md',
  variant = 'default',
  bodyClassName,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl' | 'wide' | 'form' | 'case';
  /** Extra classes for the scrolling body (e.g. a plain white body when the dialog itself is the only surface). */
  bodyClassName?: string;
  /**
   * `desk`: the front desk's look (New Case / Repeat Case) — a pale
   * blue-lavender body that white cards sit on, and a short fade-and-rise
   * on opening and closing (none with reduced motion). Every other dialog
   * keeps the default.
   */
  variant?: 'default' | 'desk';
}) {
  const returnFocus = useReturnFocus();
  const desk = variant === 'desk';
  const escapeForPopover = useRef(false);

  return (
    <Dialog.Root
      open={open}
      onOpenChange={(next) => {
        if (!next && escapeForPopover.current) {
          escapeForPopover.current = false;

          return;
        }
        escapeForPopover.current = false;
        onOpenChange(next);
      }}
    >
      <Dialog.Portal>
        <Dialog.Overlay
          className={cn(
            'fixed inset-0 z-50 bg-clinic-ink/30 backdrop-blur-[2px] motion-safe:animate-in motion-safe:fade-in-0',
            desk && 'bg-[#1E2A4A]/30 motion-safe:duration-200 motion-safe:data-[state=closed]:animate-out motion-safe:data-[state=closed]:fade-out-0',
          )}
        />
        <Dialog.Content
          {...returnFocus}
          // Escape with a popover open (a date picker's calendar, a menu) closes only that popover: the dialog's own
          // close for that key press is skipped. The event is left alone, so the popover still dismisses itself.
          onEscapeKeyDown={() => {
            escapeForPopover.current = document.querySelector('[data-radix-popper-content-wrapper]') !== null;
          }}
          className={cn(
            'fixed left-1/2 top-1/2 z-50 flex max-h-[calc(100dvh-32px)] w-[calc(100vw-24px)] -translate-x-1/2 -translate-y-1/2 flex-col rounded-3xl border border-clinic-border bg-clinic-surface shadow-[0_24px_64px_-16px_rgba(16,24,40,0.35)] focus:outline-none',
            // Centred: grow in from 98% around the centre; as a phone sheet: rise a little from below.
            desk &&
              'overflow-hidden border-[#E3E8F4] motion-safe:duration-200 motion-safe:ease-out motion-safe:data-[state=open]:animate-in motion-safe:data-[state=open]:fade-in-0 motion-safe:data-[state=open]:zoom-in-[0.98] motion-safe:data-[state=open]:slide-in-from-left-1/2 motion-safe:data-[state=open]:slide-in-from-top-[49%] motion-safe:data-[state=closed]:animate-out motion-safe:data-[state=closed]:fade-out-0 motion-safe:data-[state=closed]:zoom-out-[0.98] motion-safe:data-[state=closed]:slide-out-to-left-1/2 motion-safe:data-[state=closed]:slide-out-to-top-[49%] max-sm:motion-safe:data-[state=open]:slide-in-from-left-0 max-sm:motion-safe:data-[state=open]:slide-in-from-top-0 max-sm:motion-safe:data-[state=open]:slide-in-from-bottom-4 max-sm:motion-safe:data-[state=closed]:slide-out-to-left-0 max-sm:motion-safe:data-[state=closed]:slide-out-to-top-0 max-sm:motion-safe:data-[state=closed]:slide-out-to-bottom-4',
            size === 'sm' && 'max-w-[440px]',
            size === 'md' && 'max-w-[560px]',
            size === 'lg' && 'max-w-[720px]',
            // Operational dialogs (Reception's New Case / Repeat Case): near full screen on a phone.
            size === 'xl' && 'max-w-[880px] max-sm:h-[calc(100dvh-16px)] max-sm:max-h-[calc(100dvh-16px)] max-sm:w-[calc(100vw-16px)] max-sm:rounded-2xl',
            // Two-column operational dialogs: wide on a desktop, a full-height sheet on a phone.
            // One-column operational forms (Reception's New Case / Repeat Case): a readable width, a full-height sheet on a phone.
            size === 'form' && 'w-[calc(100vw-32px)] max-w-[780px] max-sm:inset-0 max-sm:h-[100dvh] max-sm:max-h-[100dvh] max-sm:w-full max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none max-sm:border-0',
            // New Case: wide enough to pair its fields and keep its health chips on one line; a full-height sheet on a phone.
            size === 'case' && 'w-[calc(100vw-32px)] max-w-[880px] max-sm:inset-0 max-sm:h-[100dvh] max-sm:max-h-[100dvh] max-sm:w-full max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none max-sm:border-0',
            size === 'wide' && 'w-[calc(100vw-32px)] max-w-[1120px] max-sm:inset-0 max-sm:h-[100dvh] max-sm:max-h-[100dvh] max-sm:w-full max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none max-sm:border-0',
          )}
        >
          <div className={cn('flex items-start justify-between gap-3 border-b border-clinic-border px-5 pb-4 pt-5 sm:px-6', desk && 'pb-3.5 pt-4')}>
            <div className="min-w-0">
              <Dialog.Title className="text-[17px] font-bold tracking-tight text-clinic-ink">{title}</Dialog.Title>
              {description ? (
                <Dialog.Description className="mt-1 text-[12.5px] leading-relaxed text-clinic-secondary">
                  {description}
                </Dialog.Description>
              ) : (
                <Dialog.Description className="sr-only">{typeof title === 'string' ? title : 'Dialog'}</Dialog.Description>
              )}
            </div>
            <Dialog.Close
              aria-label="Close"
              className="-mr-2 -mt-1 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
            >
              <X className="h-4 w-4" aria-hidden="true" />
            </Dialog.Close>
          </div>
          <div className={cn('min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4 sm:px-6', desk && 'bg-gradient-to-b from-[#F2F6FF] to-[#F6F3FF] px-3 py-3 sm:px-5 sm:py-4', bodyClassName)}>{children}</div>
          {footer && (
            <div className={cn('flex flex-wrap items-center justify-end gap-2 border-t border-clinic-border px-5 py-3.5 sm:px-6', desk && 'border-[#E3E8F4] bg-white')}>
              {footer}
            </div>
          )}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

// The form controls live in ./controls; existing imports from here keep working.
export { Field, inputClass, selectClass, textareaClass } from './controls';
