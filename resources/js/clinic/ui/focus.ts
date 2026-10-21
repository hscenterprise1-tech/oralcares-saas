import { useCallback, useRef } from 'react';

/**
 * Returns focus to whatever had it when a dialog opened.
 *
 * Radix returns focus to its own `Trigger`. Portal dialogs here are opened
 * from state (a header button, Ctrl+K, a table row action) with no Trigger,
 * so without this focus fell to <body> on close and a keyboard user lost
 * their place. Spread the result onto `Dialog.Content` / `AlertDialog.Content`.
 */
export function useReturnFocus(): {
  onOpenAutoFocus: () => void;
  onCloseAutoFocus: (event: Event) => void;
} {
  const opener = useRef<HTMLElement | null>(null);

  // Radix calls this before it moves focus into the dialog, so the opener still has it.
  const onOpenAutoFocus = useCallback(() => {
    opener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  }, []);

  const onCloseAutoFocus = useCallback((event: Event) => {
    const target = opener.current;
    opener.current = null;
    if (target && target.isConnected && target !== document.body) {
      event.preventDefault();
      target.focus({ preventScroll: true });
    }
  }, []);

  return { onOpenAutoFocus, onCloseAutoFocus };
}
