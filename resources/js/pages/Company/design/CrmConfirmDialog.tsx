import React from 'react';
import { CrmConfirm } from './CrmOverlay';

/**
 * The one confirmation dialog for the CRM.
 *
 * Destructive and irreversible CRM actions were once confirmed with the
 * browser's native `confirm()`, which cannot be styled, cannot explain
 * consequences, and reads as a bug in a product that otherwise looks designed.
 *
 * This file used to build its own dialog out of the shared overlay kit, which
 * meant the CRM had two confirmation looks: this one, and `CrmConfirm`. It is
 * now a thin adapter over `CrmConfirm` — the surface, the scrim, the focus
 * return and the Escape handling all come from one place, and the four pages
 * calling `useCrmConfirm` did not have to change a line.
 *
 * A confirmation is for actions the user cannot simply undo. Reversible
 * actions should just happen — a dialog on every click trains people to
 * dismiss dialogs without reading them.
 */

export interface CrmConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  /** What will happen, in plain language. */
  description: string;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Styles the confirm button as destructive. */
  destructive?: boolean;
  /** Disables the confirm button while a request is in flight. */
  busy?: boolean;
  onConfirm: () => void;
}

export function CrmConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel = 'Confirm',
  destructive = false,
  busy = false,
  onConfirm,
}: CrmConfirmDialogProps) {
  return (
    <CrmConfirm
      open={open}
      onClose={() => onOpenChange(false)}
      onConfirm={onConfirm}
      title={title}
      description={description}
      confirmLabel={confirmLabel}
      tone={destructive ? 'danger' : 'primary'}
      loading={busy}
    />
  );
}

/**
 * Wires the open/close plumbing so callers only supply the action.
 *
 * Returns the dialog element to render plus a `confirm()` that opens it, which
 * keeps a page's confirmation logic to two lines instead of three pieces of
 * local state per action.
 */
export function useCrmConfirm() {
  const [state, setState] = React.useState<
    (Omit<CrmConfirmDialogProps, 'open' | 'onOpenChange'> & { open: boolean }) | null
  >(null);

  const confirm = React.useCallback(
    (options: Omit<CrmConfirmDialogProps, 'open' | 'onOpenChange'>) => {
      setState({ ...options, open: true });
    },
    []
  );

  const dialog = state ? (
    <CrmConfirmDialog
      {...state}
      open={state.open}
      onOpenChange={(open) => {
        if (!open) setState(null);
      }}
      onConfirm={() => {
        state.onConfirm();
        setState(null);
      }}
    />
  ) : null;

  return { confirm, dialog };
}
