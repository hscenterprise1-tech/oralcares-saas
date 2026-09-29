import React from 'react';
import { Head, router } from '@inertiajs/react';
import { CalendarDays, CalendarOff, Plus, Trash2 } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmDataState,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * People → Working calendar
 *
 * Which days this company works, and which it is closed. Both are
 * configuration: nothing defaults the week to Monday–Friday, and until somebody
 * sets it, attendance says the calendar is unconfigured rather than measuring
 * people against an office week nobody declared.
 *
 * Past holidays stay. Attendance was recorded against them.
 */

const HUE = { blue: '#2F6BE4', orange: '#F59245', teal: '#3E93A3' } as const;

interface PatternDay {
  day_of_week: number;
  name: string;
  is_working: boolean | null;
}

interface Holiday {
  id: number;
  date: string;
  label: string;
  name: string;
  description: string | null;
  is_optional: boolean;
  is_past: boolean;
  created_by: string | null;
}

interface Props {
  year: number;
  years: number[];
  pattern: PatternDay[];
  configured: boolean;
  holidays: Holiday[];
  can: { manage: boolean };
}

export default function WorkingCalendar({ year, years, pattern, configured, holidays, can }: Props) {
  /* Unset days start unticked rather than assumed true. */
  const [week, setWeek] = React.useState<Record<number, boolean>>(() =>
    Object.fromEntries(pattern.map((day) => [day.day_of_week, day.is_working === true])),
  );

  const [dirty, setDirty] = React.useState(false);
  const [holidayForm, setHolidayForm] = React.useState({ open: false, date: '', name: '', description: '', optional: '0' });

  const toggle = (day: number) => {
    setWeek((current) => ({ ...current, [day]: !current[day] }));
    setDirty(true);
  };

  const savePattern = () =>
    router.put(
      '/company/people/working-calendar/pattern',
      { pattern: week },
      { preserveScroll: true, onSuccess: () => setDirty(false) },
    );

  const submitHoliday = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      '/company/people/working-calendar/holidays',
      {
        holiday_date: holidayForm.date,
        name: holidayForm.name,
        description: holidayForm.description || null,
        is_optional: holidayForm.optional === '1',
      },
      { preserveScroll: true, onSuccess: () => setHolidayForm({ open: false, date: '', name: '', description: '', optional: '0' }) },
    );
  };

  const workingCount = Object.values(week).filter(Boolean).length;
  const upcoming = holidays.filter((holiday) => !holiday.is_past).length;

  return (
    <PeopleShell title="Working calendar">
      <Head title="Working calendar | People" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">People</p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Working calendar
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            The company&apos;s own week and closures — HQ, not clinic hours
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat
            icon={CalendarDays}
            value={configured ? `${workingCount} days` : 'Not set'}
            caption="Working week"
          />
          <CrmPageStat icon={CalendarOff} value={holidays.length} caption={`Holidays in ${year}`} />
          <CrmPageStat icon={CalendarOff} value={upcoming} caption="Still to come" />
        </div>
      </div>

      <CrmCard className="mb-4 p-6" step={1} frostHue={configured ? undefined : HUE.orange}>
        <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">The working week</h2>
            <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
              {configured
                ? 'Attendance reads this to tell a weekly off from an absence.'
                : /* Deliberately unset. Defaulting would decide company policy. */
                  'Not configured. Nothing assumes a Monday-to-Friday week on your behalf.'}
            </p>
          </div>
          <CrmCardIcon icon={CalendarDays} hue={configured ? HUE.blue : HUE.orange} />
        </div>

        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
          {pattern.map((day) => {
            const on = week[day.day_of_week];

            return (
              <button
                key={day.day_of_week}
                type="button"
                onClick={() => (can.manage ? toggle(day.day_of_week) : undefined)}
                disabled={!can.manage}
                aria-pressed={on}
                className={
                  on
                    ? 'rounded-[18px] border border-ovs-crm-ink bg-ovs-crm-ink px-3 py-4 text-left text-white transition disabled:cursor-default'
                    : 'rounded-[18px] border border-ovs-crm-border bg-ovs-crm-surface-inset px-3 py-4 text-left transition enabled:hover:border-ovs-crm-accent/50 disabled:cursor-default'
                }
              >
                <span className={on ? 'block text-[10px] font-bold uppercase tracking-[0.1em] text-white/70' : 'block text-[10px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted'}>
                  {day.name.slice(0, 3)}
                </span>
                <span className={on ? 'mt-1.5 block text-[13px] font-semibold' : 'mt-1.5 block text-[13px] font-semibold text-ovs-crm-text-primary'}>
                  {on ? 'Working' : day.is_working === null ? 'Not set' : 'Off'}
                </span>
              </button>
            );
          })}
        </div>

        {can.manage && (
          <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-ovs-crm-line pt-3">
            <p className="text-[11.5px] text-ovs-crm-text-muted">
              All seven days are saved together, so a half-set week cannot exist.
            </p>
            <CrmButton tone="primary" onClick={savePattern} disabled={!dirty}>
              {dirty ? 'Save the week' : 'Saved'}
            </CrmButton>
          </div>
        )}
      </CrmCard>

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <CrmSelect
          value={String(year)}
          onChange={(e) =>
            router.get('/company/people/working-calendar', { year: e.target.value }, { preserveState: true, replace: true })
          }
          aria-label="Year"
          className="w-auto text-[12px]"
        >
          {years.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </CrmSelect>

        {can.manage && (
          <CrmButton tone="primary" icon={Plus} onClick={() => setHolidayForm({ ...holidayForm, open: true })}>
            Add a holiday
          </CrmButton>
        )}
      </div>

      {holidays.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={CalendarOff}
          title={`No holidays in ${year}`}
          description="A holiday closes the company for the day, and attendance reads it as a non-working day."
        />
      ) : (
        <div className="space-y-2">
          {holidays.map((holiday) => (
            <CrmCard key={holiday.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
              <span className="min-w-[150px] text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                {holiday.label}
              </span>

              <span className="min-w-[160px] flex-1">
                <span className="flex flex-wrap items-center gap-2">
                  <span className="text-[13.5px] font-medium text-ovs-crm-text-primary">{holiday.name}</span>
                  {holiday.is_optional && <CrmStatusPill tone="neutral">Optional</CrmStatusPill>}
                  {holiday.is_past && <CrmStatusPill tone="neutral">Past</CrmStatusPill>}
                </span>
                {holiday.description && (
                  <span className="mt-0.5 block text-[11.5px] text-ovs-crm-text-muted">{holiday.description}</span>
                )}
              </span>

              {can.manage && !holiday.is_past && (
                <CrmButton
                  size="sm"
                  icon={Trash2}
                  onClick={() =>
                    router.delete(`/company/people/working-calendar/holidays/${holiday.id}`, { preserveScroll: true })
                  }
                >
                  Remove
                </CrmButton>
              )}

              {holiday.is_past && (
                /* Kept on purpose — attendance was recorded against this day. */
                <span className="text-[11.5px] text-ovs-crm-text-muted">Kept as history</span>
              )}
            </CrmCard>
          ))}
        </div>
      )}

      <CrmModal
        open={holidayForm.open}
        onClose={() => setHolidayForm({ ...holidayForm, open: false })}
        title="Add a holiday"
        description="Attendance reads this as a non-working day from the moment it is saved."
        footer={
          <>
            <CrmButton onClick={() => setHolidayForm({ ...holidayForm, open: false })}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="holiday-form">
              Add holiday
            </CrmButton>
          </>
        }
      >
        <form id="holiday-form" onSubmit={submitHoliday} className="flex flex-col gap-3.5">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="Date" required>
              <CrmInput
                type="date"
                required
                value={holidayForm.date}
                onChange={(e) => setHolidayForm({ ...holidayForm, date: e.target.value })}
              />
            </CrmFormField>

            <CrmFormField label="Kind" required>
              <CrmSelect value={holidayForm.optional} onChange={(e) => setHolidayForm({ ...holidayForm, optional: e.target.value })}>
                <option value="0">Company closed</option>
                <option value="1">Optional</option>
              </CrmSelect>
            </CrmFormField>
          </div>

          <CrmFormField label="Name" required>
            <CrmInput
              required
              value={holidayForm.name}
              onChange={(e) => setHolidayForm({ ...holidayForm, name: e.target.value })}
            />
          </CrmFormField>

          <CrmFormField label="Description">
            <CrmInput
              value={holidayForm.description}
              onChange={(e) => setHolidayForm({ ...holidayForm, description: e.target.value })}
            />
          </CrmFormField>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
