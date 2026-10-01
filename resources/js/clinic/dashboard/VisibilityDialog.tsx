import { useState } from 'react';
import { router } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import { LayoutGrid, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { DashboardVisibilitySettings } from '../types';
import { Button } from '../ui/Button';

/**
 * The owner's choice of what each staff role's dashboard shows (Owner Mode).
 *
 * Switching a widget off hides it for that role. Switching it on only lets it
 * appear for staff whose permissions already allow it; this never grants
 * access, and the server enforces that.
 */
export function VisibilityDialog({ settings }: { settings: DashboardVisibilitySettings }) {
  const [open, setOpen] = useState(false);
  const [role, setRole] = useState(settings.roles[0] ?? '');
  const [hidden, setHidden] = useState<Record<string, string[]>>(settings.hidden);
  const [saving, setSaving] = useState(false);

  const roleHidden = hidden[role] ?? [];
  const toggle = (key: string) =>
    setHidden((current) => {
      const list = current[role] ?? [];

      return { ...current, [role]: list.includes(key) ? list.filter((item) => item !== key) : [...list, key] };
    });

  const save = () => {
    setSaving(true);
    router.put(
      settings.action,
      { role, hidden: roleHidden },
      { preserveScroll: true, onSuccess: () => setOpen(false), onFinish: () => setSaving(false) },
    );
  };

  return (
    <Dialog.Root open={open} onOpenChange={setOpen}>
      <Dialog.Trigger className="inline-flex h-9 items-center gap-1.5 rounded-full border border-clinic-border bg-clinic-surface px-3.5 text-[12.5px] font-semibold text-clinic-ink hover:bg-clinic-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong [@media(pointer:coarse)]:h-11">
        <LayoutGrid className="h-3.5 w-3.5" aria-hidden="true" /> Staff dashboards
      </Dialog.Trigger>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-50 bg-clinic-ink/30 motion-safe:animate-in motion-safe:fade-in-0" />
        <Dialog.Content className="fixed left-1/2 top-1/2 z-50 flex max-h-[calc(100vh-48px)] w-[calc(100vw-32px)] max-w-[460px] -translate-x-1/2 -translate-y-1/2 flex-col rounded-3xl border border-clinic-border bg-clinic-surface shadow-[0_24px_64px_-16px_rgba(16,24,40,0.35)] focus:outline-none">
          <div className="flex items-start justify-between gap-3 px-6 pt-6">
            <div>
              <Dialog.Title className="text-[17px] font-bold tracking-tight text-clinic-ink">
                Staff dashboards
              </Dialog.Title>
              <Dialog.Description className="mt-1 text-[12.5px] leading-relaxed text-clinic-secondary">
                Choose what each role sees. A role still sees only what its permissions allow.
              </Dialog.Description>
            </div>
            <Dialog.Close
              aria-label="Close"
              className="-mr-2 -mt-2 flex h-10 w-10 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
            >
              <X className="h-4 w-4" aria-hidden="true" />
            </Dialog.Close>
          </div>

          <div
            role="radiogroup"
            aria-label="Role"
            className="mx-6 mt-4 flex flex-wrap gap-1 rounded-full bg-clinic-inset p-1"
          >
            {settings.roles.map((item) => (
              <button
                key={item}
                type="button"
                role="radio"
                aria-checked={item === role}
                onClick={() => setRole(item)}
                className={cn(
                  'h-8 rounded-full px-3 text-[12px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
                  item === role ? 'bg-clinic-ink text-white' : 'text-clinic-secondary hover:text-clinic-ink',
                )}
              >
                {item}
              </button>
            ))}
          </div>

          <ul className="mt-3 min-h-0 flex-1 overflow-y-auto px-6">
            {settings.widgets.map((widget) => {
              const shown = !roleHidden.includes(widget.key);

              return (
                <li key={widget.key}>
                  <label className="flex min-h-11 cursor-pointer items-center justify-between gap-3 border-b border-clinic-border py-2 text-[13px] text-clinic-ink">
                    {widget.title}
                    <input
                      type="checkbox"
                      checked={shown}
                      onChange={() => toggle(widget.key)}
                      className="h-4 w-4 rounded border-clinic-border-strong text-clinic-brand-strong focus:ring-clinic-brand-strong"
                    />
                  </label>
                </li>
              );
            })}
          </ul>

          <div className="flex justify-end gap-2 px-6 py-4">
            <Dialog.Close asChild>
              <Button size="sm">Cancel</Button>
            </Dialog.Close>
            <Button size="sm" tone="primary" loading={saving} loadingLabel="Saving…" onClick={save}>
              Save for {role}
            </Button>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
