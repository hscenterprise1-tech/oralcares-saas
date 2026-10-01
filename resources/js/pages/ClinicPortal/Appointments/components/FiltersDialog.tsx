import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { statusStyle } from '../lib/status';
import type { CalendarProps, Filters, StatusValue } from '../lib/types';
import { Field, Modal, selectClass } from './Modal';

/** Narrow the calendar by status and service. Provider has its own control. */
export function FiltersDialog({
  open,
  onOpenChange,
  props,
  onApply,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  props: CalendarProps;
  onApply: (filters: Filters) => void;
}) {
  const [draft, setDraft] = useState<Filters>(props.filters);

  useEffect(() => {
    if (open) {
      setDraft(props.filters);
    }
  }, [open, props.filters]);

  const toggleStatus = (status: StatusValue) =>
    setDraft((current) => ({
      ...current,
      statuses: current.statuses.includes(status) ? current.statuses.filter((value) => value !== status) : [...current.statuses, status],
    }));

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      size="sm"
      title="Filter appointments"
      footer={
        <>
          <Button tone="ghost" onClick={() => onApply({ ...draft, service_id: null, statuses: [] })}>
            Clear
          </Button>
          <Button tone="primary" onClick={() => onApply(draft)}>
            Show
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        <fieldset>
          <legend className="mb-2 text-[12px] font-semibold text-clinic-ink">Status</legend>
          <div className="flex flex-wrap gap-1.5">
            {props.options.statuses.map((option) => {
              const on = draft.statuses.includes(option.value as StatusValue);

              return (
                <button
                  key={option.value}
                  type="button"
                  aria-pressed={on}
                  onClick={() => toggleStatus(option.value as StatusValue)}
                  className={cn(
                    'inline-flex min-h-11 items-center gap-1.5 rounded-full px-3 text-[12.5px] font-semibold ring-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:min-h-9',
                    on ? 'bg-clinic-ink text-white ring-clinic-ink' : 'bg-white text-clinic-ink ring-clinic-border',
                  )}
                >
                  <span className={cn('h-2 w-2 rounded-full', statusStyle(option.value).dot)} aria-hidden="true" />
                  {option.label}
                </button>
              );
            })}
          </div>
        </fieldset>
        <Field label="Service" htmlFor="fl-service">
          <select
            id="fl-service"
            className={selectClass}
            value={draft.service_id ?? ''}
            onChange={(e) => setDraft({ ...draft, service_id: e.target.value ? Number(e.target.value) : null })}
          >
            <option value="">Any service</option>
            {props.services.map((service) => (
              <option key={service.id} value={service.id}>
                {service.name}
              </option>
            ))}
          </select>
        </Field>
      </div>
    </Modal>
  );
}

export function activeFilterCount(filters: Filters): number {
  return (filters.service_id ? 1 : 0) + (filters.statuses.length > 0 ? 1 : 0);
}
