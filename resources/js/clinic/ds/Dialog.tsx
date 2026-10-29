import type { ReactNode } from 'react';
import * as AlertDialog from '@radix-ui/react-alert-dialog';
import * as RadixDialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { DsButton } from './Button';
import { FOCUS_RING } from './tones';

const OVERLAY = 'fixed inset-0 z-50 bg-[#173B60]/35 backdrop-blur-[2px] motion-safe:data-[state=open]:animate-in motion-safe:data-[state=open]:fade-in-0 motion-safe:data-[state=closed]:animate-out motion-safe:data-[state=closed]:fade-out-0';
const PANEL = cn(
  'fixed left-1/2 top-1/2 z-50 flex max-h-[calc(100dvh-32px)] w-[calc(100vw-24px)] -translate-x-1/2 -translate-y-1/2 flex-col rounded-3xl border border-cx-line bg-cx-surface shadow-cx-raised focus:outline-none',
  'motion-safe:data-[state=open]:animate-in motion-safe:data-[state=open]:fade-in-0 motion-safe:data-[state=open]:zoom-in-[0.97] motion-safe:data-[state=open]:duration-200',
  'motion-safe:data-[state=closed]:animate-out motion-safe:data-[state=closed]:fade-out-0 motion-safe:data-[state=closed]:zoom-out-[0.97] motion-safe:data-[state=closed]:duration-150',
);

/** A centred dialog (never a drawer). Focus returns to whatever opened it. */
export function DsDialog({
  open,
  onOpenChange,
  title,
  description,
  children,
  footer,
  width = 'max-w-[480px]',
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  width?: string;
}) {
  return (
    <RadixDialog.Root open={open} onOpenChange={onOpenChange}>
      <RadixDialog.Portal>
        <RadixDialog.Overlay className={OVERLAY} />
        <RadixDialog.Content className={cn(PANEL, width)}>
          <div className="flex items-start justify-between gap-3 px-6 pb-3 pt-5">
            <div className="min-w-0">
              <RadixDialog.Title className="text-[18px] font-semibold leading-7 text-cx-text">{title}</RadixDialog.Title>
              <RadixDialog.Description className={description ? 'mt-1 text-[13px] leading-5 text-[#64748B]' : 'sr-only'}>{description ?? title}</RadixDialog.Description>
            </div>
            <RadixDialog.Close aria-label="Close" className={cn('-mr-2 flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-[#64748B] hover:bg-cx-nav-hover', FOCUS_RING)}>
              <X className="h-4 w-4" aria-hidden="true" />
            </RadixDialog.Close>
          </div>
          <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 pb-4">{children}</div>
          {footer && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-cx-line px-6 py-4">{footer}</div>}
        </RadixDialog.Content>
      </RadixDialog.Portal>
    </RadixDialog.Root>
  );
}

/**
 * Asks before something that cannot simply be undone. The confirm button
 * shows the request running and the dialog stays open until the caller
 * closes it on the server's answer, so a failure is shown where it happened.
 */
export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  onConfirm,
  busy = false,
  error,
  danger = false,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: ReactNode;
  confirmLabel: string;
  onConfirm: () => void;
  busy?: boolean;
  error?: string | null;
  danger?: boolean;
}) {
  return (
    <AlertDialog.Root open={open} onOpenChange={(next) => !busy && onOpenChange(next)}>
      <AlertDialog.Portal>
        <AlertDialog.Overlay className={OVERLAY} />
        <AlertDialog.Content className={cn(PANEL, 'max-w-[440px]')}>
          <div className="px-6 pb-4 pt-6">
            <AlertDialog.Title className="text-[18px] font-semibold leading-7 text-cx-text">{title}</AlertDialog.Title>
            <AlertDialog.Description className="mt-1.5 text-[14px] leading-[22px] text-[#64748B]">{description}</AlertDialog.Description>
            {error && (
              <p role="alert" className="mt-3 rounded-xl bg-cx-rose-bg px-3 py-2 text-[13px] font-medium text-cx-rose-ink">
                {error}
              </p>
            )}
          </div>
          <div className="flex flex-wrap items-center justify-end gap-2 border-t border-cx-line px-6 py-4">
            <AlertDialog.Cancel asChild>
              <DsButton disabled={busy}>Keep it</DsButton>
            </AlertDialog.Cancel>
            <DsButton variant={danger ? 'danger' : 'primary'} loading={busy} onClick={onConfirm}>
              {confirmLabel}
            </DsButton>
          </div>
        </AlertDialog.Content>
      </AlertDialog.Portal>
    </AlertDialog.Root>
  );
}
