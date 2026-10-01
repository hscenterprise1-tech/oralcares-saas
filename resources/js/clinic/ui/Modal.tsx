import type { ReactNode } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

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
  size?: 'sm' | 'md' | 'lg';
}) {
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-50 bg-clinic-ink/30 backdrop-blur-[2px] motion-safe:animate-in motion-safe:fade-in-0" />
        <Dialog.Content
          className={cn(
            'fixed left-1/2 top-1/2 z-50 flex max-h-[calc(100dvh-32px)] w-[calc(100vw-24px)] -translate-x-1/2 -translate-y-1/2 flex-col rounded-3xl border border-clinic-border bg-clinic-surface shadow-[0_24px_64px_-16px_rgba(16,24,40,0.35)] focus:outline-none',
            size === 'sm' && 'max-w-[440px]',
            size === 'md' && 'max-w-[560px]',
            size === 'lg' && 'max-w-[720px]',
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

const CONTROL =
  'w-full rounded-xl border border-clinic-border bg-clinic-surface px-3 text-[13px] text-clinic-ink placeholder:text-clinic-muted focus:border-clinic-brand-strong focus:outline-none focus:ring-2 focus:ring-clinic-brand/25 disabled:bg-clinic-inset disabled:text-clinic-secondary';

export const inputClass = cn(CONTROL, 'h-11');
export const selectClass = cn(CONTROL, 'h-11 pr-8');
export const textareaClass = cn(CONTROL, 'min-h-[76px] py-2.5');

export function Field({
  label,
  htmlFor,
  error,
  hint,
  children,
  className,
}: {
  label: string;
  htmlFor?: string;
  error?: string | null;
  hint?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('min-w-0', className)}>
      <label htmlFor={htmlFor} className="mb-1.5 block text-[12px] font-semibold text-clinic-ink">
        {label}
      </label>
      {children}
      {error ? (
        <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
          {error}
        </p>
      ) : hint ? (
        <p className="mt-1.5 text-[11.5px] text-clinic-secondary">{hint}</p>
      ) : null}
    </div>
  );
}
