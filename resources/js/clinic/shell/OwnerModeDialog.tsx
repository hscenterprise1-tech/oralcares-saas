import { useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { Field, Input } from '../ui/controls';

/**
 * Owner Mode: the owner confirms their password before owner-only figures and
 * decisions open. The server re-verifies the owner, audits every attempt, and
 * checks Owner Mode itself on every owner-only route.
 */
export function OwnerModeDialog({ open, onOpenChange, action, idleMinutes }: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  action: string;
  idleMinutes: number;
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
      title="Enter Owner Mode"
      description={`Confirm your password to see the clinic's money and owner decisions. It turns off after ${idleMinutes} minutes without activity.`}
    >
      <form
        onSubmit={(event) => {
          event.preventDefault();
          post(action, { preserveScroll: true, onSuccess: () => close(false) });
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
            Enter Owner Mode
          </Button>
        </div>
      </form>
    </Modal>
  );
}
