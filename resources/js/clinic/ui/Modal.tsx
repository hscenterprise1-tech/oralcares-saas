import type { ReactNode } from 'react';
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
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl' | 'wide';
}) {
  const returnFocus = useReturnFocus();

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-50 bg-clinic-ink/30 backdrop-blur-[2px] motion-safe:animate-in motion-safe:fade-in-0" />
        <Dialog.Content
          {...returnFocus}
          className={cn(
            'fixed left-1/2 top-1/2 z-50 flex max-h-[calc(100dvh-32px)] w-[calc(100vw-24px)] -translate-x-1/2 -translate-y-1/2 flex-col rounded-3xl border border-clinic-border bg-clinic-surface shadow-[0_24px_64px_-16px_rgba(16,24,40,0.35)] focus:outline-none',
            size === 'sm' && 'max-w-[440px]',
            size === 'md' && 'max-w-[560px]',
            size === 'lg' && 'max-w-[720px]',
            // Operational dialogs (Reception's New Case / Repeat Case): near full screen on a phone.
            size === 'xl' && 'max-w-[880px] max-sm:h-[calc(100dvh-16px)] max-sm:max-h-[calc(100dvh-16px)] max-sm:w-[calc(100vw-16px)] max-sm:rounded-2xl',
            // Two-column operational dialogs: wide on a desktop, a full-height sheet on a phone.
            size === 'wide' && 'w-[calc(100vw-32px)] max-w-[1120px] max-sm:inset-0 max-sm:h-[100dvh] max-sm:max-h-[100dvh] max-sm:w-full max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none max-sm:border-0',
          )}
        >
          <div className="flex items-start justify-between gap-3 border-b border-clinic-border px-5 pb-4 pt-5 sm:px-6">
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
          <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4 sm:px-6">{children}</div>
          {footer && (
            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-clinic-border px-5 py-3.5 sm:px-6">
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
