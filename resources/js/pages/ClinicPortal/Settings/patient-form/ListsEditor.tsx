import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button, ChoiceChips, Input, Notice } from '@/clinic';
import { MultiChoiceChips } from '@/clinic/ui/ChoiceChips';
import { Switch } from '@/clinic/ui/controls';
import { ClinicHttpError, requestJson } from '@/clinic/http';
import type { ListItem, ListKind, PatientFormProps } from './types';

const KINDS: { kind: ListKind; label: string; hint: string }[] = [
  { kind: 'visit_reason', label: 'Visit reasons', hint: 'What reception can pick as the reason for today’s visit — the patient’s words, never a diagnosis.' },
  { kind: 'condition', label: 'Medical history', hint: 'Conditions offered as taps. High and critical ones raise a safety alert on the patient.' },
  { kind: 'allergy', label: 'Allergies', hint: 'Allergies offered as taps. Every allergy raises a safety alert.' },
  { kind: 'medication', label: 'Medications', hint: 'Common medications offered as taps; staff can always type another.' },
  { kind: 'surgery', label: 'Past surgery', hint: 'Operations offered as taps; staff can always type another.' },
  { kind: 'family_history', label: 'Family history', hint: 'Conditions in the patient’s family.' },
  { kind: 'alert', label: 'Team alerts', hint: 'Operational flags such as fall risk: for the team, never a diagnosis.' },
];

const ASK_STATES = [
  { value: 'required', label: 'Required' },
  { value: 'optional', label: 'Optional' },
  { value: 'hidden', label: 'Not asked' },
];

/**
 * The clinic's lists, one at a time: switch an item on or off, keep it for
 * some modules only, or add the clinic's own. Each change is saved at once
 * through the catalogue endpoints (audited); nothing is deleted. The visit
 * reason list also says whether a reason is asked at check-in.
 */
export function ListsEditor({ props }: { props: PatientFormProps }) {
  const [kind, setKind] = useState<ListKind>('visit_reason');
  const [lists, setLists] = useState(props.lists);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [name, setName] = useState('');
  const url = (key?: string) => props.links.catalogue.replace('__KIND__', kind) + (key ? `/${encodeURIComponent(key)}` : '');
  const items = (lists[kind] ?? []).filter((item) => !item.archived).sort((a, b) => a.position - b.position);
  const current = KINDS.find((entry) => entry.kind === kind)!;

  const save = async (key: string | null, body: Record<string, unknown>) => {
    setBusy(key ?? 'new');
    setError(null);
    try {
      const response = await requestJson<{ items: ListItem[] }>(key ? 'PUT' : 'POST', url(key ?? undefined), { body });
      setLists((all) => ({ ...all, [kind]: response.items }));
      if (!key) {
        setName('');
      }
    } catch (failure) {
      const message = failure instanceof ClinicHttpError ? ((failure.body as { message?: string } | null)?.message ?? null) : null;
      setError(message ?? 'That change was not saved. Check the connection and try again.');
    } finally {
      setBusy(null);
    }
  };

  const add = (event: FormEvent) => {
    event.preventDefault();
    if (name.trim() !== '') {
      void save(null, { name: name.trim() });
    }
  };

  return (
    <div className="space-y-4">
      <div role="tablist" aria-label="Lists" className="flex flex-wrap gap-1.5">
        {KINDS.map((entry) => (
          <button
            key={entry.kind}
            type="button"
            role="tab"
            aria-selected={kind === entry.kind}
            onClick={() => {
              setKind(entry.kind);
              setError(null);
            }}
            className={cn(
              'min-h-10 rounded-full border px-3.5 text-[12.5px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
              kind === entry.kind ? 'border-clinic-ink bg-clinic-ink text-white' : 'border-clinic-border text-clinic-ink hover:border-clinic-border-strong',
            )}
          >
            {entry.label}
          </button>
        ))}
      </div>

      <div role="tabpanel" aria-label={current.label} className="space-y-3">
        <p className="text-[12.5px] text-clinic-secondary">{current.hint}</p>

        {kind === 'visit_reason' && (
          <div>
            <p className="mb-1.5 text-[12px] font-semibold text-clinic-ink">Ask the reason at check-in</p>
            <ChoiceChips label="Ask the reason at check-in" options={ASK_STATES} value={props.visit_reason} onChange={(state) => router.put(props.links.check_in, { visit_reason: state }, { preserveScroll: true })} />
          </div>
        )}

        {error && <Notice tone="danger">{error}</Notice>}

        <ul className="divide-y divide-clinic-border rounded-2xl border border-clinic-border">
          {items.length === 0 && <li className="px-3.5 py-3 text-[12.5px] text-clinic-secondary">Nothing in this list yet. Add the first item below.</li>}
          {items.map((item) => (
            <li key={item.key} className={cn('space-y-2 px-3.5 py-2.5', !item.enabled && 'bg-clinic-inset/40')}>
              <div className="flex flex-wrap items-center gap-3">
                <p className={cn('min-w-0 flex-1 text-[13px] font-semibold', item.enabled ? 'text-clinic-ink' : 'text-clinic-secondary')}>
                  {item.name}
                  {item.custom && <span className="ml-1.5 text-[11.5px] font-medium text-clinic-secondary">· your own</span>}
                </p>
                <Switch checked={item.enabled} disabled={busy === item.key} onCheckedChange={(enabled) => void save(item.key, { enabled })} aria-label={`Offer ${item.name}`} />
              </div>
              {props.modules.length > 1 && item.enabled && (
                <MultiChoiceChips
                  label={`${item.name}: for which modules`}
                  options={props.modules.map((module) => ({ value: module.key, label: module.label }))}
                  value={item.specialty_keys}
                  onChange={(specialty_keys) => void save(item.key, { specialty_keys })}
                  noneLabel="All modules"
                  size="sm"
                />
              )}
            </li>
          ))}
        </ul>

        <form onSubmit={add} className="flex flex-wrap items-end gap-2">
          <div className="min-w-0 flex-1">
            <label htmlFor="pf-list-add" className="mb-1.5 block text-[12px] font-semibold text-clinic-ink">
              Add to {current.label.toLowerCase()}
            </label>
            <Input id="pf-list-add" value={name} maxLength={120} onChange={(event) => setName(event.target.value)} placeholder="Name" />
          </div>
          <Button type="submit" icon={Plus} loading={busy === 'new'} loadingLabel="Adding…" disabled={name.trim() === '' || busy !== null}>
            Add
          </Button>
        </form>
      </div>
    </div>
  );
}
