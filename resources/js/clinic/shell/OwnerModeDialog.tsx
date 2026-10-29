import { useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { Field, Input } from '../ui/controls';

/**
 * The owner confirms their password. A doctor-owner does this to enter Owner
 * Mode (the clinic's management, other doctors' patients) from Doctor Mode.
 * Any owner does it when a sensitive action asks for a recent confirmation.
 * The server re-verifies the owner, audits every attempt, and checks the
 * owner's privileges itself on every owner-only route.
 *
 * `reason` is set when an action asked for it (`useOwnerModeStepUp`): `stale`
 * means the action needs the password confirmed again; `inactive`, that a
 * doctor-owner must enter Owner Mode for it. `onVerified` runs once the
 * server has accepted the password.
 */
export function OwnerModeDialog({ open, onOpenChange, action, idleMinutes, reason, onVerified }: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  action: string;
  idleMinutes: number;
  reason?: 'inactive' | 'stale';
  onVerified?: () => void;
}) {
  const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ password: '' });
  const close = (next: boolean) => {
    if (!next) {
      reset();
      clearErrors();
    }
    onOpenChange(next);
  };

  return (
    <Modal
      open={open}
      onOpenChange={close}
      size="sm"
      title={reason === 'stale' ? 'Confirm your password' : 'Enter Owner Mode'}
      description={
        reason === 'stale'
          ? 'This action needs your password confirmed in the last few minutes. Nothing has been changed yet.'
          : reason === 'inactive'
            ? `This action needs Owner Mode. Nothing has been changed yet. It turns off after ${idleMinutes} minutes without activity.`
            : `Confirm your password to leave Doctor Mode for the clinic's management and every patient. It turns off after ${idleMinutes} minutes without activity, back to Doctor Mode.`
      }
    >
      <form
        onSubmit={(event) => {
          event.preventDefault();
          post(action, {
            preserveScroll: true,
            onSuccess: () => {
              close(false);
              onVerified?.();
            },
          });
        }}
      >
        <Field label="Password" htmlFor="owner-mode-password" error={errors.password} required>
          <Input
            id="owner-mode-password"
            type="password"
            autoComplete="current-password"
            autoFocus
            value={data.password}
            onChange={(event) => setData('password', event.target.value)}
            invalid={Boolean(errors.password)}
          />
        </Field>
        <div className="mt-5 flex justify-end gap-2">
          <Button type="button" onClick={() => close(false)}>
            Cancel
          </Button>
          <Button type="submit" tone="primary" icon={ShieldCheck} loading={processing} disabled={data.password === ''}>
            {reason === 'stale' ? 'Confirm and continue' : reason === 'inactive' ? 'Enter Owner Mode and continue' : 'Enter Owner Mode'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
