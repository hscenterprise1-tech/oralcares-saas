import React from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CrmButton } from './CrmControls';

/**
 * Modals.
 *
 * There is deliberately no drawer here, and there should not be one anywhere in
 * the CRM. A slide-over is the default an interface reaches for when nobody
 * decided what a screen should be, and it reads that way. Anything worth
 * opening is either small enough to be a centred modal or substantial enough to
 * deserve its own page with its own URL.
 *
 * The behaviour contract is the reason this exists as a primitive: Escape
 * closes, a click on the scrim closes, focus moves into the panel on open and
 * returns to whatever opened it on close, and the page behind cannot scroll
 * while one is up. Every hand-rolled overlay in the CRM got some part of that
 * wrong — usually the focus return — and each got it wrong differently.
 */

function useOverlayBehaviour(open: boolean, onClose: () => void) {
  const panelRef = React.useRef<HTMLDivElement>(null);
  const restoreTo = React.useRef<HTMLElement | null>(null);

  React.useEffect(() => {
    if (!open) return;

    restoreTo.current = document.activeElement as HTMLElement | null;

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onClose();
      }
    };

    const { overflow } = document.body.style;
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKey);

    // Focus the panel itself rather than guessing at a first field: a text
    // input taking focus on open pops the keyboard on a phone before the
    // reader has seen what opened.
    panelRef.current?.focus();

    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = overflow;
      restoreTo.current?.focus?.();
    };
  }, [open, onClose]);

  return panelRef;
}

function Scrim({ onClose }: { onClose: () => void }) {
  return (
    <div
      className="crm-fade fixed inset-0 bg-ovs-crm-ink/25 backdrop-blur-[2px]"
      onClick={onClose}
      aria-hidden="true"
    />
  );
}

function OverlayHead({
  title,
  description,
  onClose,
}: {
  title: string;
  description?: string;
  onClose: () => void;
}) {
  return (
    <div className="flex items-start gap-3 px-5 pb-4 pt-5">
      <div className="min-w-0 flex-1">
        <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">{title}</h2>
        {description && <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">{description}</p>}
      </div>

      <button
        type="button"
        onClick={onClose}
        aria-label="Close"
        className="crm-lift flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ovs-crm-surface-inset text-ovs-crm-text-secondary hover:bg-white hover:text-ovs-crm-text-primary"
      >
        <X className="h-4 w-4" aria-hidden="true" />
      </button>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────────────── modal */

export function CrmModal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
  size?: 'sm' | 'md' | 'lg';
}) {
  const panelRef = useOverlayBehaviour(open, onClose);

  if (!open) return null;

  const width = { sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl' }[size];

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-6" role="presentation">
      <Scrim onClose={onClose} />

      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        className={cn(
          'crm-pop relative flex max-h-[92vh] w-full flex-col overflow-hidden bg-white shadow-ovs-crmLift focus:outline-none',
          'rounded-t-[28px] sm:rounded-[28px]',
          width
        )}
      >
        <OverlayHead title={title} description={description} onClose={onClose} />

        <div className="min-h-0 flex-1 overflow-y-auto px-5 pb-5">{children}</div>

        {footer && (
          <div className="flex items-center justify-end gap-2 border-t border-ovs-crm-border/60 px-5 py-4">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────── confirm */

/**
 * Destructive confirmation. The action's own verb goes on the button — "Delete
 * lead", never "OK" — so the button still reads correctly to someone who
 * skipped the sentence above it.
 */
export function CrmConfirm({
  open,
  onClose,
  onConfirm,
  title,
  description,
  confirmLabel,
  tone = 'danger',
  loading = false,
}: {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  description?: string;
  confirmLabel: string;
  tone?: 'danger' | 'primary';
  loading?: boolean;
}) {
  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title={title}
      description={description}
      size="sm"
      footer={
        <>
          <CrmButton tone="ghost" onClick={onClose}>Cancel</CrmButton>
          <CrmButton tone={tone} onClick={onConfirm} loading={loading}>{confirmLabel}</CrmButton>
        </>
      }
    >
      <span className="sr-only">{description}</span>
    </CrmModal>
  );
}
