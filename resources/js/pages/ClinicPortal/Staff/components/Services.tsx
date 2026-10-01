import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Info } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import type { ProfileProps } from '../lib/types';
import { Empty, Section } from '../lib/ui';

/**
 * Which services this practitioner provides. Reception's booking only
 * offers them for these services; with none chosen they are not restricted
 * yet and are offered for every service.
 */
export function Services({ props }: { props: ProfileProps }) {
  const services = props.services!;
  const editable = props.can.practitioner;
  const [selected, setSelected] = useState<number[]>(services.selected);
  const [saving, setSaving] = useState(false);
  const dirty = [...selected].sort().join(',') !== [...services.selected].sort().join(',');

  const groups = useMemo(() => {
    const map = new Map<string, typeof services.catalogue>();
    services.catalogue.forEach((service) => {
      const key = service.category || 'Other';
      map.set(key, [...(map.get(key) ?? []), service]);
    });

    return [...map.entries()];
  }, [services.catalogue]);

  const toggle = (id: number) => setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

  const save = () => {
    setSaving(true);
    router.put(`${props.links.self}/services`, { services: selected }, { preserveScroll: true, onFinish: () => setSaving(false) });
  };

  return (
    <Section
      title="Services provided"
      aside={
        editable && (
          <div className="flex gap-2">
            {selected.length > 0 && <Button size="sm" tone="ghost" onClick={() => setSelected([])}>Clear all</Button>}
            <Button size="sm" tone="primary" disabled={!dirty} loading={saving} loadingLabel="Saving…" onClick={save}>Save services</Button>
          </div>
        )
      }
    >
      <p className={cn('mb-4 flex items-start gap-2 rounded-2xl px-3.5 py-3 text-[12.5px]', selected.length === 0 ? 'bg-amber-50 text-amber-900' : 'bg-sky-50 text-sky-900')}>
        <Info className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        {selected.length === 0
          ? 'No services chosen: Reception can book them for any service. Choose the services they provide to restrict booking.'
          : `Reception can book them only for these ${selected.length} ${selected.length === 1 ? 'service' : 'services'}.`}
      </p>
      {services.catalogue.length === 0 ? (
        <Empty>The clinic has no services in its catalogue yet.</Empty>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {groups.map(([category, items]) => (
            <fieldset key={category}>
              <legend className="mb-2 text-[11.5px] font-bold uppercase tracking-wide text-clinic-secondary">{category}</legend>
              <ul className="space-y-1.5">
                {items.map((service) => {
                  const on = selected.includes(service.id);

                  return (
                    <li key={service.id}>
                      <button
                        type="button"
                        role="checkbox"
                        aria-checked={on}
                        disabled={!editable}
                        onClick={() => toggle(service.id)}
                        className={cn(
                          'flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2 text-left ring-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong disabled:cursor-default',
                          on ? 'bg-emerald-50 ring-emerald-200' : 'bg-white ring-clinic-border hover:ring-clinic-border-strong',
                        )}
                      >
                        <span className={cn('flex h-5 w-5 shrink-0 items-center justify-center rounded-md', on ? 'bg-emerald-600 text-white' : 'bg-clinic-inset')}>
                          {on && <Check className="h-3.5 w-3.5" aria-hidden="true" />}
                        </span>
                        <span className="min-w-0 flex-1 truncate text-[13px] font-semibold text-clinic-ink">{service.name}</span>
                        {service.duration_minutes && <span className="shrink-0 text-[11.5px] text-clinic-secondary">{service.duration_minutes} min</span>}
                      </button>
                    </li>
                  );
                })}
              </ul>
            </fieldset>
          ))}
        </div>
      )}
    </Section>
  );
}
