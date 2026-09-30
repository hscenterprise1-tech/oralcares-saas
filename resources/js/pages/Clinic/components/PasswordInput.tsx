import React from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CrmInput } from '@/pages/Company/design';

type PasswordInputProps = Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> & { invalid?: boolean };

/**
 * A password field with a reveal toggle and a Caps Lock notice, shared by the
 * three clinic pre-auth forms (login, password setup, invitation accept).
 *
 * It is the direct child `CrmFormField` clones, so the generated `id`,
 * `aria-labelledby` and `aria-describedby` land on the real `<input>` rather
 * than on a wrapper `<div>` — which is where they went when each page wrapped
 * `CrmInput` in its own relative container, leaving the label pointing at
 * nothing a screen reader could announce.
 *
 * The toggle is a real, focusable button the full height of the field (44px),
 * not a `tabIndex={-1}` icon: keyboard and screen-reader users need it as much
 * as anyone, and on a phone it is the thumb target.
 */
export const PasswordInput = React.forwardRef<HTMLInputElement, PasswordInputProps>(function PasswordInput(
  { className, onKeyDown, onKeyUp, onBlur, disabled, ...rest },
  ref,
) {
  const [visible, setVisible] = React.useState(false);
  const [capsLockOn, setCapsLockOn] = React.useState(false);

  const trackCapsLock = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (typeof event.getModifierState === 'function') {
      setCapsLockOn(event.getModifierState('CapsLock'));
    }
  };

  return (
    <div className="min-w-0">
      <div className="relative">
        <CrmInput
          ref={ref}
          type={visible ? 'text' : 'password'}
          autoCapitalize="none"
          autoCorrect="off"
          spellCheck={false}
          disabled={disabled}
          className={cn('pr-12', className)}
          onKeyDown={(event) => {
            trackCapsLock(event);
            onKeyDown?.(event);
          }}
          onKeyUp={(event) => {
            trackCapsLock(event);
            onKeyUp?.(event);
          }}
          onBlur={(event) => {
            setCapsLockOn(false);
            onBlur?.(event);
          }}
          {...rest}
        />
        <button
          type="button"
          onClick={() => setVisible((current) => !current)}
          disabled={disabled}
          aria-label={visible ? 'Hide password' : 'Show password'}
          aria-pressed={visible}
          className="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-[16px] text-ovs-crm-text-muted transition-colors hover:text-ovs-crm-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ovs-crm-ink/25 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {visible ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
        </button>
      </div>

      <p aria-live="polite" className="text-[11.5px] font-medium leading-tight text-ovs-crm-warning-text">
        {capsLockOn && <span className="mt-1.5 block">Caps Lock is on</span>}
      </p>
    </div>
  );
});
