import { useRef, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import { ShieldCheck, X } from 'lucide-react';
import { Button } from '../ui/Button';

/**
 * The step-up check before Owner Mode: the owner confirms their own
 * password. Nothing changes until the server has checked it; the server
 * decides whether this user may enter Owner Mode at all.
 */
export function OwnerModeDialog({
  open,
  onOpenChange,
  action,
  idleMinutes,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  action: string;
  idleMinutes: number;
}) {
  const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ password: '' });
  const passwordRef = useRef<HTMLInputElement>(null);

  // Closing clears the field and any message, so a reopened dialog starts clean.
  const changeOpen = (next: boolean) => {
    if (!next) {
      reset();
      clearErrors();
    }
    onOpenChange(next);
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(action, {
      preserveScroll: true,
      onSuccess: () => changeOpen(false),
      onFinish: () => reset('password'),
    });
  };

  return (
    <Dialog.Root open={open} onOpenChange={changeOpen}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-50 bg-clinic-ink/30 motion-safe:animate-in motion-safe:fade-in-0" />
        <Dialog.Content
          onOpenAutoFocus={(event) => {
            // Straight to the password, not the close button.
            event.preventDefault();
            passwordRef.current?.focus();
          }}
          className="fixed left-1/2 top-1/2 z-50 w-[calc(100vw-32px)] max-w-[420px] -translate-x-1/2 -translate-y-1/2 rounded-3xl border border-clinic-border bg-clinic-surface p-6 shadow-[0_24px_64px_-16px_rgba(16,24,40,0.35)] focus:outline-none"
        >
          <div className="flex items-start justify-between gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-clinic-ink text-white">
              <ShieldCheck className="h-5 w-5" aria-hidden="true" />
            </span>
            <Dialog.Close
              aria-label="Close"
              className="-mr-2 -mt-2 flex h-10 w-10 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
            >
              <X className="h-4 w-4" aria-hidden="true" />
            </Dialog.Close>
          </div>
          <Dialog.Title className="mt-4 text-[18px] font-bold tracking-tight text-clinic-ink">
            Enter Owner Mode
          </Dialog.Title>
          <Dialog.Description className="mt-1.5 text-[13px] leading-relaxed text-clinic-secondary">
            Confirm your password to open the owner workspace. It ends by itself after {idleMinutes} minutes without
            activity, and you can leave it at any time. Your roles do not change.
          </Dialog.Description>

          <form onSubmit={submit} noValidate className="mt-5 space-y-4">
            <div>
              <label htmlFor="owner-mode-password" className="mb-1.5 block text-[12.5px] font-semibold text-clinic-ink">
                Your password
              </label>
              <input
                ref={passwordRef}
                id="owner-mode-password"
                type="password"
                name="password"
                autoComplete="current-password"
                required
                value={data.password}
                onChange={(event) => setData('password', event.target.value)}
                aria-invalid={errors.password ? true : undefined}
                aria-describedby={errors.password ? 'owner-mode-password-error' : undefined}
                className="block h-11 w-full rounded-xl border border-clinic-border bg-clinic-surface px-3.5 text-[13.5px] text-clinic-ink hover:border-clinic-border-strong focus:border-clinic-brand-strong focus:outline-none focus:ring-2 focus:ring-clinic-brand/25 aria-[invalid=true]:border-clinic-danger"
              />
              {errors.password && (
                <p
                  id="owner-mode-password-error"
                  role="alert"
                  className="mt-1.5 text-[12px] font-medium text-clinic-danger"
                >
                  {errors.password}
                </p>
              )}
            </div>
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Dialog.Close asChild>
                <Button type="button">Cancel</Button>
              </Dialog.Close>
              <Button type="submit" tone="primary" loading={processing} loadingLabel="Checking…">
                Enter Owner Mode
              </Button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
