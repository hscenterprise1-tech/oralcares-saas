import React from 'react';
import { useForm } from '@inertiajs/react';
import { CalendarClock, Hash, History, Save } from 'lucide-react';
import { CrmButton, CrmFormField, CrmInput, CrmSection, toneHex } from '@/pages/Company/design';
import { Switch } from '@/pages/Company/design/CrmFormPrimitives';
import { cn } from '@/lib/utils';
import {
  type CaseNumberFormat,
  type YearStyle,
  SEPARATORS,
  YEAR_STYLES,
  formatCaseNumber,
  shortLabel,
} from './caseNumberFormat';

export interface CaseNumberSettings {
  active: CaseNumberFormat & { start_number: number };
  pending: { show_year: boolean; year_style: YearStyle; reset_yearly: boolean; effective_from: string } | null;
  today: string;
  next_period_start: string;
  highest_issued: number;
  recent_changes: {
    id: number;
    changed_by: string;
    changed_at: string;
    old_values: { active: CaseNumberFormat & { start_number: number } } | null;
    new_values: { active: CaseNumberFormat & { start_number: number }; pending: CaseNumberSettings['pending'] };
  }[];
}

type FormData = CaseNumberFormat & { start_number: number };

const YEAR_STYLE_LABELS: Record<YearStyle, string> = {
  YYYY: '2026',
  YY: '26',
  FY: '2026-27',
  FY_SHORT: '26-27',
};

const SEPARATOR_LABELS: Record<string, string> = { '-': 'Dash  -', '/': 'Slash  /', '.': 'Dot  .', '': 'None' };

const PERIOD_FIELDS = ['show_year', 'year_style', 'reset_yearly'] as const;

const FIELD_NAMES: Record<keyof FormData, string> = {
  prefix: 'Prefix',
  show_year: 'Show year',
  year_style: 'Year style',
  separator: 'Separator',
  digits: 'Digits',
  start_number: 'Start number',
  reset_yearly: 'Restart every year',
};

const formatDate = (isoDate: string) =>
  new Date(`${isoDate}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });

const formatDateTime = (isoDateTime: string) =>
  new Date(isoDateTime).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });

/** A row of 44px-tall pill buttons for picking one of a few values. */
function Segmented<T extends string | number>({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: T;
  options: { value: T; label: string }[];
  onChange: (next: T) => void;
}) {
  return (
    <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-1.5">
      {options.map((option) => {
        const active = option.value === value;

        return (
          <button
            key={String(option.value)}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => onChange(option.value)}
            className={cn(
              'min-h-[44px] min-w-[44px] whitespace-pre rounded-full px-4 text-[13px] font-medium transition-colors',
              active
                ? 'bg-ovs-crm-ink text-white'
                : 'bg-ovs-crm-surface-inset text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary'
            )}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

/** A switch whose whole row is the 44px touch target. */
function SwitchRow({
  title,
  hint,
  checked,
  onChange,
  error,
}: {
  title: string;
  hint: string;
  checked: boolean;
  onChange: (next: boolean) => void;
  error?: string;
}) {
  return (
    <div>
      <label className="flex min-h-[44px] cursor-pointer items-center justify-between gap-4 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset px-4 py-3">
        <span>
          <span className="block text-[13px] font-semibold text-ovs-crm-text-primary">{title}</span>
          <span className="block text-[12px] text-ovs-crm-text-secondary">{hint}</span>
        </span>
        <Switch checked={checked} onCheckedChange={onChange} />
      </label>
      {error && <p className="mt-1.5 text-[12px] text-ovs-crm-accent-coral-text">{error}</p>}
    </div>
  );
}

/** Which fields a saved change touched, in plain words. */
function describeChange(change: CaseNumberSettings['recent_changes'][number]): string {
  const before = change.old_values?.active;
  const after = change.new_values.active;
  if (!before) return 'Set the case number format';

  const pending = change.new_values.pending;
  const changed = (Object.keys(FIELD_NAMES) as (keyof FormData)[]).filter((field) => before[field] !== after[field]);
  const scheduled = pending ? PERIOD_FIELDS.filter((field) => pending[field] !== after[field]) : [];
  const parts = [
    changed.length > 0 ? `changed ${changed.map((field) => FIELD_NAMES[field].toLowerCase()).join(', ')}` : null,
    scheduled.length > 0 && pending
      ? `scheduled ${scheduled.map((field) => FIELD_NAMES[field].toLowerCase()).join(', ')} from ${formatDate(pending.effective_from)}`
      : null,
  ].filter(Boolean);

  if (parts.length === 0) return 'Cancelled a scheduled change';
  const text = parts.join('; ');

  return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * Settings > Case Number. The clinic's own numbering format with a live
 * preview computed by `caseNumberFormat.ts` — the same rules the server uses
 * (see `CaseNumberFormatParityTest`).
 */
export function CaseNumberSection({ settings }: { settings: CaseNumberSettings }) {
  const initial: FormData = settings.pending ? { ...settings.active, ...settings.pending } : settings.active;
  const form = useForm<FormData>({
    prefix: initial.prefix,
    show_year: initial.show_year,
    year_style: initial.year_style,
    separator: initial.separator,
    digits: initial.digits,
    start_number: initial.start_number,
    reset_yearly: initial.reset_yearly,
  });
  const { data, setData, errors, processing } = form;

  const periodChanged = PERIOD_FIELDS.some((field) => data[field] !== settings.active[field]);
  const nextSequence = Math.max(settings.highest_issued + 1, Number(data.start_number) || 1);
  const currentFormat: FormData = {
    ...data,
    prefix: data.prefix.toUpperCase() || '—',
    show_year: settings.active.show_year,
    year_style: settings.active.year_style,
    reset_yearly: settings.active.reset_yearly,
  };
  const nextNumber = formatCaseNumber(currentFormat, settings.today, nextSequence);
  const fromNextPeriod = periodChanged
    ? formatCaseNumber(
        { ...data, prefix: data.prefix.toUpperCase() || '—' },
        settings.next_period_start,
        data.reset_yearly ? Number(data.start_number) || 1 : nextSequence
      )
    : null;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    form.put(route('admin.settings.case-number'), { preserveScroll: true });
  };

  return (
    <form onSubmit={submit} className="max-w-[640px] space-y-8 pb-12">
      <CrmSection
        title="Case Number"
        description="How patient case numbers look. A patient keeps one case number for the whole year; numbers already given out never change."
        icon={Hash}
        hue={toneHex('teal')}
      >
        <div className="space-y-6">
          <div className="rounded-[18px] border border-ovs-crm-border bg-ovs-crm-surface px-5 py-4" aria-live="polite">
            <p className="text-[12px] font-medium text-ovs-crm-text-secondary">Next case number</p>
            <p className="mt-1 break-all font-mono text-[24px] font-semibold tracking-tight text-ovs-crm-text-primary">
              {nextNumber}
            </p>
            <p className="mt-1 text-[12px] text-ovs-crm-text-secondary">
              Queue and TV screens show the last four digits: <span className="font-mono font-semibold text-ovs-crm-text-primary">{shortLabel(nextSequence)}</span>
            </p>
            {fromNextPeriod && (
              <p className="mt-3 border-t border-ovs-crm-line pt-3 text-[12px] text-ovs-crm-text-secondary">
                From {formatDate(settings.next_period_start)}:{' '}
                <span className="break-all font-mono font-semibold text-ovs-crm-text-primary">{fromNextPeriod}</span>
              </p>
            )}
          </div>

          <CrmFormField label="Prefix" hint="1 to 10 letters or numbers, e.g. OPD or DC." error={errors.prefix}>
            <CrmInput
              value={data.prefix}
              maxLength={10}
              autoCapitalize="characters"
              onChange={(e) => setData('prefix', e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase())}
              invalid={!!errors.prefix}
            />
          </CrmFormField>

          <CrmFormField label="Separator" error={errors.separator}>
            <Segmented
              label="Separator"
              value={data.separator}
              options={SEPARATORS.map((separator) => ({ value: separator, label: SEPARATOR_LABELS[separator] }))}
              onChange={(next) => setData('separator', next)}
            />
          </CrmFormField>

          <SwitchRow
            title="Show the year"
            hint="Include the year in every number."
            checked={data.show_year}
            onChange={(next) => setData('show_year', next)}
          />

          <CrmFormField label="Year style" hint="2026-27 and 26-27 follow the April to March financial year." error={errors.year_style}>
            <Segmented
              label="Year style"
              value={data.year_style}
              options={YEAR_STYLES.map((style) => ({ value: style, label: YEAR_STYLE_LABELS[style] }))}
              onChange={(next) => setData('year_style', next)}
            />
          </CrmFormField>

          <CrmFormField label="Digits in the number" error={errors.digits}>
            <Segmented
              label="Digits in the number"
              value={data.digits}
              options={[4, 5, 6, 7, 8].map((digits) => ({ value: digits, label: String(digits) }))}
              onChange={(next) => setData('digits', next)}
            />
          </CrmFormField>

          <SwitchRow
            title="Restart numbering every year"
            hint={data.show_year ? 'Numbers start again from the start number each year.' : 'Needs the year shown, or numbers would repeat.'}
            checked={data.reset_yearly}
            onChange={(next) => setData('reset_yearly', next)}
            error={errors.reset_yearly}
          />

          <CrmFormField
            label="Start number"
            hint={settings.highest_issued > 0 ? `Must be higher than ${settings.highest_issued}, the highest number already issued.` : 'The first number to issue.'}
            error={errors.start_number}
          >
            <CrmInput
              type="number"
              inputMode="numeric"
              min={1}
              value={String(data.start_number)}
              onChange={(e) => setData('start_number', Number(e.target.value))}
              invalid={!!errors.start_number}
            />
          </CrmFormField>

          {(periodChanged || settings.pending) && (
            <div className="flex gap-3 rounded-[16px] bg-ovs-crm-accent-yellow-soft px-4 py-3">
              <CalendarClock className="mt-0.5 h-4 w-4 shrink-0 text-ovs-crm-accent-yellow-text" aria-hidden="true" />
              <p className="text-[12.5px] text-ovs-crm-text-primary">
                Changes to the year, year style or yearly restart take effect from{' '}
                <strong>{formatDate(settings.pending?.effective_from ?? settings.next_period_start)}</strong>, the start of the next period.
                Prefix, separator, digits and start number apply to the next number issued.
              </p>
            </div>
          )}
        </div>
      </CrmSection>

      <div className="flex justify-end">
        <CrmButton type="submit" tone="primary" size="lg" icon={Save} loading={processing}>
          Save case number format
        </CrmButton>
      </div>

      <CrmSection title="Recent changes" description="Every change to the case number format is recorded." icon={History} hue={toneHex('slate')}>
        {settings.recent_changes.length === 0 ? (
          <p className="text-[13px] text-ovs-crm-text-secondary">No changes yet. The format above is the default.</p>
        ) : (
          <ul className="divide-y divide-ovs-crm-line">
            {settings.recent_changes.map((change) => (
              <li key={change.id} className="flex flex-wrap items-baseline justify-between gap-2 py-3 first:pt-0 last:pb-0">
                <span className="text-[13px] text-ovs-crm-text-primary">
                  <span className="font-semibold">{change.changed_by}</span> · {describeChange(change)}
                </span>
                <span className="text-[12px] text-ovs-crm-text-secondary">{formatDateTime(change.changed_at)}</span>
              </li>
            ))}
          </ul>
        )}
      </CrmSection>
    </form>
  );
}
