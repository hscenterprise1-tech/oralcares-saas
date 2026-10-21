import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Button, Checkbox, Field, Modal, Select } from '@/clinic';
import type { DashboardVisibilitySettings } from '@/clinic/types';

/**
 * The owner choosing what a staff role's dashboard shows (Owner Mode only).
 * A choice can only hide: a widget appears for staff when their access
 * allows it and this does not hide it (`DashboardVisibility`), so ticking a
 * widget never gives a role something its permissions do not. The server
 * checks Owner Mode and the widget list again on save.
 */
export function VisibilityDialog({ open, onOpenChange, settings }: { open: boolean; onOpenChange: (open: boolean) => void; settings: DashboardVisibilitySettings }) {
  const [role, setRole] = useState(settings.roles[0] ?? '');
  const [hidden, setHidden] = useState<string[]>(settings.hidden[role] ?? []);
  const [saving, setSaving] = useState(false);

  // Each role starts from what is saved for it.
  useEffect(() => {
    setHidden(settings.hidden[role] ?? []);
  }, [role, settings.hidden, open]);

  const toggle = (key: string, shown: boolean) => setHidden((current) => (shown ? current.filter((item) => item !== key) : [...current, key]));
  const save = () => {
    setSaving(true);
    router.put(settings.action, { role, hidden }, {
      preserveScroll: true,
      onSuccess: () => onOpenChange(false),
      onFinish: () => setSaving(false),
    });
  };

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title="Staff dashboards"
      description="Choose what each staff role sees on their dashboard. Staff only ever see what their own access allows; this can hide, never add."
      size="md"
      footer={
        <>
          <Button tone="ghost" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button tone="primary" onClick={save} loading={saving} loadingLabel="Saving">Save for {role}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Role" htmlFor="visibility-role">
          <Select id="visibility-role" value={role} onChange={(event) => setRole(event.target.value)} options={settings.roles.map((value) => ({ value, label: value }))} />
        </Field>
        <fieldset>
          <legend className="mb-2 text-[13px] font-semibold text-cx-text">Shown to {role}</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {settings.widgets.map((widget) => (
              <Checkbox key={widget.key} checked={!hidden.includes(widget.key)} onCheckedChange={(shown) => toggle(widget.key, shown)} label={widget.title} />
            ))}
          </div>
        </fieldset>
      </div>
    </Modal>
  );
}
