import React from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Clock, History, Pencil } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmValue,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * One person's month.
 *
 * Every day of the month is shown, including the ones with nothing on them —
 * "Not recorded" is a fact, and a calendar that quietly skipped those days
 * would read as though nothing was ever missed. Nobody is marked absent for a
 * day nobody logged.
 */

const HUE = {
  green: '#2FA96B',
  blue: '#2F6BE4',
  violet: '#7C6FE0',
  orange: '#F59245',
  coral: '#EF5B39',
  teal: '#3E93A3',
} as const;

interface Day {
  date: string;
  day_of_week: number;
  status: string | null;
  status_label: string;
  check_in_at: string | null;
  check_out_at: string | null;
  worked_minutes: number | null;
  source: string | null;
  notes: string | null;
  holiday: string | null;
  leave_portion: string | null;
  has_corrections: boolean;
  record_id: number | null;
}

interface Props {
  employee: {
    id: number;
    employee_code: string;
    name: string;
    designation: string | null;
    department: string | null;
    manager: string | null;
    timezone: string;
    timezone_is_fallback: boolean;
  };
  summary: {
    year: number;
    month: number;
    label: string;
    timezone: string;
    calendar_configured: boolean;
    days: Day[];
    counts: Record<string, number>;
    worked_minutes: number;
  };
  can: { manage: boolean };
}

const STATUS_TONE: Record<string, CrmStatusTone> = {
  present: 'good',
  partial: 'warn',
  absent: 'bad',
  on_leave: 'info',
  holiday: 'neutral',
  weekly_off: 'neutral',
};

const STATUS_HUE: Record<string, string> = {
  present: HUE.green,
  partial: HUE.orange,
  absent: HUE.coral,
  on_leave: HUE.violet,
  holiday: HUE.teal,
  weekly_off: HUE.blue,
};

const hours = (minutes: number) => {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;

  return m === 0 ? `${h}h` : `${h}h ${m}m`;
};

const WEEK = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

export default function AttendanceDetail({ employee, summary, can }: Props) {
  const [correcting, setCorrecting] = React.useState<Day | null>(null);
  const [form, setForm] = React.useState({ status: '', notes: '', reason: '' });

  const month = `${summary.year}-${String(summary.month).padStart(2, '0')}`;

  const openCorrection = (day: Day) => {
    setForm({ status: day.status ?? '', notes: day.notes ?? '', reason: '' });
    setCorrecting(day);
  };

  const submitCorrection = (e: React.FormEvent) => {
    e.preventDefault();

    if (!correcting?.record_id) {
      return;
    }

    router.post(
      `/company/people/attendance/${correcting.record_id}/correct`,
      { status: form.status || null, notes: form.notes || null, reason: form.reason },
      { preserveScroll: true, onSuccess: () => setCorrecting(null) },
    );
  };

  /* Blank cells so the first row starts on the right weekday. */
  const lead = summary.days.length > 0 ? summary.days[0].day_of_week : 0;

  return (
    <PeopleShell title={`Attendance · ${employee.name}`}>
      <Head title={`${employee.name} · Attendance`} />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Attendance · {employee.employee_code}
          </p>
          <h1 className="text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            {employee.name}
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            {employee.designation}
            {employee.department && <> · {employee.department}</>}
            {employee.manager && <> · reports to {employee.manager}</>}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={Clock} value={summary.counts.present ?? 0} caption="Present" />
          <CrmPageStat icon={CalendarDays} value={summary.counts.on_leave ?? 0} caption="On leave" />
          <CrmPageStat icon={History} value={hours(summary.worked_minutes)} caption="Time logged" />
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <CrmButton size="sm" icon={ArrowLeft} href="/company/people/attendance">
          All attendance
        </CrmButton>

        <div className="flex flex-wrap items-center gap-3">
          <span className="text-[12px] text-ovs-crm-text-muted">
            Measured in {summary.timezone}
            {/* Three of nine people carry no timezone of their own. */}
            {employee.timezone_is_fallback && <> · from the application default</>}
          </span>
          <CrmInput
            type="month"
            value={month}
            onChange={(e) =>
              router.get(`/company/people/attendance/employees/${employee.id}`, { month: e.target.value }, { preserveState: true })
            }
            aria-label="Month"
            className="w-auto text-[12px]"
          />
        </div>
      </div>

      <CrmCard className="mb-4 p-6" step={1}>
        <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">{summary.label}</h2>
            <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
              {summary.counts.unrecorded ?? 0} day{(summary.counts.unrecorded ?? 0) === 1 ? '' : 's'} with nothing
              recorded
              {!summary.calendar_configured && <> · working week not configured</>}
            </p>
          </div>

          <div className="flex flex-wrap gap-x-4 gap-y-1">
            {Object.entries(STATUS_HUE).map(([key, hue]) => (
              <span key={key} className="inline-flex items-center gap-1.5 text-[11px] text-ovs-crm-text-muted">
                <span className="h-2 w-2 rounded-full" style={{ background: hue }} aria-hidden />
                {key.replace('_', ' ')}
              </span>
            ))}
          </div>
        </div>

        <div className="grid grid-cols-7 gap-1.5">
          {WEEK.map((letter, index) => (
            <div key={index} className="pb-1 text-center text-[10px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
              {letter}
            </div>
          ))}

          {Array.from({ length: lead }).map((_, index) => (
            <div key={`lead-${index}`} aria-hidden />
          ))}

          {summary.days.map((day) => (
            <button
              key={day.date}
              type="button"
              onClick={() => (can.manage && day.record_id ? openCorrection(day) : undefined)}
              disabled={!can.manage || !day.record_id}
              title={`${day.date} — ${day.status_label}${day.holiday ? ` (${day.holiday})` : ''}`}
              className="group relative aspect-square rounded-[14px] border border-ovs-crm-border bg-ovs-crm-surface p-1.5 text-left transition enabled:hover:border-ovs-crm-accent/50 disabled:cursor-default"
            >
              <span className="text-[11px] font-medium tabular-nums text-ovs-crm-text-secondary">
                {Number(day.date.slice(-2))}
              </span>

              {day.status ? (
                <span
                  className="absolute bottom-1.5 left-1.5 right-1.5 h-1.5 rounded-full"
                  style={{ background: STATUS_HUE[day.status] ?? '#CBD5E1' }}
                  aria-hidden
                />
              ) : (
                /* Not recorded reads as empty, never as a colour meaning absent. */
                <span className="absolute bottom-1.5 left-1.5 right-1.5 h-1.5 rounded-full border border-dashed border-ovs-crm-border" aria-hidden />
              )}

              {day.has_corrections && (
                <Pencil className="absolute right-1.5 top-1.5 h-2.5 w-2.5 text-ovs-crm-text-muted" aria-hidden />
              )}
            </button>
          ))}
        </div>
      </CrmCard>

      <CrmCard className="p-6" step={2}>
        <h2 className="mb-4 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">Days logged</h2>

        {summary.days.filter((day) => day.status).length === 0 ? (
          <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">Nothing recorded this month.</p>
        ) : (
          <ul className="space-y-2">
            {summary.days
              .filter((day) => day.status)
              .map((day) => (
                <li
                  key={day.date}
                  className="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-[16px] border border-ovs-crm-border px-4 py-2.5"
                >
                  <span className="min-w-[96px] text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                    {day.date}
                  </span>
                  <CrmStatusPill tone={STATUS_TONE[day.status ?? ''] ?? 'neutral'}>{day.status_label}</CrmStatusPill>
                  <span className="min-w-[120px] flex-1 text-[12px] tabular-nums text-ovs-crm-text-secondary">
                    {day.check_in_at ?? '—'} → {day.check_out_at ?? '—'}
                    {day.worked_minutes ? <> · {hours(day.worked_minutes)}</> : null}
                  </span>
                  {day.holiday && <CrmStatusPill tone="neutral">{day.holiday}</CrmStatusPill>}
                  {day.has_corrections && <CrmStatusPill tone="warn">Corrected</CrmStatusPill>}
                  {can.manage && day.record_id && (
                    <CrmButton size="sm" onClick={() => openCorrection(day)}>
                      Correct
                    </CrmButton>
                  )}
                </li>
              ))}
          </ul>
        )}
      </CrmCard>

      <CrmModal
        open={correcting !== null}
        onClose={() => setCorrecting(null)}
        title={`Correct ${correcting?.date ?? ''}`}
        description="Every field you change is recorded with your name and this reason."
        footer={
          <>
            <CrmButton onClick={() => setCorrecting(null)}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="attendance-correction">
              Save correction
            </CrmButton>
          </>
        }
      >
        <form id="attendance-correction" onSubmit={submitCorrection} className="flex flex-col gap-3.5">
          <CrmFormField label="Status">
            <CrmSelect value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
              <option value="">Leave unchanged</option>
              <option value="present">Present</option>
              <option value="partial">Partial</option>
              <option value="absent">Absent</option>
              <option value="on_leave">On leave</option>
              <option value="holiday">Holiday</option>
              <option value="weekly_off">Weekly off</option>
            </CrmSelect>
          </CrmFormField>

          <CrmFormField label="Notes">
            <CrmTextarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </CrmFormField>

          <CrmFormField label="Reason" required hint="Kept on the record. A correction without one is refused.">
            <CrmTextarea
              rows={2}
              required
              value={form.reason}
              onChange={(e) => setForm({ ...form, reason: e.target.value })}
            />
          </CrmFormField>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
