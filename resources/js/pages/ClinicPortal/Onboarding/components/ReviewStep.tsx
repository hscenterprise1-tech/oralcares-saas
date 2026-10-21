import { CircleCheck, CircleDashed, CircleMinus } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Checkbox } from '@/clinic/ui/controls';
import { asArray } from './fields';
import type { StepFormProps, StepStatus } from './types';

type ReviewSection = { key: string; title: string; status: StepStatus; required: boolean; lines: string[] };

const STATUS: Record<string, { label: string; icon: typeof CircleCheck; classes: string }> = {
  completed: { label: 'Done', icon: CircleCheck, classes: 'bg-cx-green-bg text-cx-green-ink' },
  skipped: { label: 'Skipped', icon: CircleMinus, classes: 'bg-cx-slate-bg text-cx-slate-ink' },
};
const OPEN = { label: 'Not done', icon: CircleDashed, classes: 'bg-cx-amber-bg text-cx-amber-ink' };

/**
 * The last step: every earlier step as the server summarises it, a way back
 * to each, and the confirmation the server requires (`confirmed: accepted`).
 * Finishing is refused on the server while a required step is open.
 */
export function ReviewStep({ form, onEdit }: { form: StepFormProps; onEdit: (key: string) => void }) {
  const sections = asArray<ReviewSection>(form.meta.sections);
  const missing = sections.filter((section) => section.required && section.status !== 'completed');

  return (
    <div className="space-y-4">
      <ul className="divide-y divide-cx-line rounded-xl border border-cx-line">
        {sections.map((section) => {
          const status = STATUS[section.status] ?? OPEN;
          const Icon = status.icon;

          return (
            <li key={section.key} className="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-[13.5px] font-semibold text-cx-nav-ink">{section.title}</p>
                  <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold', status.classes)}>
                    <Icon className="h-3 w-3" aria-hidden="true" />
                    {status.label}
                  </span>
                  {!section.required && <span className="text-[11.5px] text-cx-text-3">Optional</span>}
                </div>
                {section.lines.length > 0 && (
                  <ul className="mt-1 space-y-0.5 text-[12.5px] text-cx-text-2">
                    {section.lines.map((line) => (
                      <li key={line} className="break-words">
                        {line}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
              <button
                type="button"
                onClick={() => onEdit(section.key)}
                className="inline-flex h-9 shrink-0 items-center rounded-full px-3 text-[12.5px] font-semibold text-cx-nav-teal-strong hover:bg-cx-nav-active focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal [@media(pointer:coarse)]:min-h-11"
              >
                {section.status === 'completed' ? 'Edit' : 'Open'}
                <span className="sr-only"> {section.title}</span>
              </button>
            </li>
          );
        })}
      </ul>
      {missing.length > 0 && (
        <p role="status" className="rounded-xl bg-cx-amber-bg px-4 py-3 text-[12.5px] font-medium text-cx-amber-ink">
          Finish {missing.map((section) => section.title).join(', ')} before you can complete setup.
        </p>
      )}
      <div className="rounded-xl border border-cx-line px-4 py-3">
        <Checkbox
          checked={form.values.confirmed === true}
          onCheckedChange={(checked) => form.set('confirmed', checked)}
          disabled={form.disabled}
          label="I have checked these details"
          description="You can change any of them later from settings."
        />
        {form.errors.confirmed && (
          <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
            {form.errors.confirmed}
          </p>
        )}
      </div>
    </div>
  );
}
