import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  CalendarDays,
  CheckCircle2,
  Clock,
  LogIn,
  LogOut,
  UserX,
  Users,
} from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmOpenButton,
  CrmValue,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmDataState,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * People → Attendance
 *
 * HQ only. The clinic `leaves` and `shifts` tables belong to a different
 * workforce and are neither read nor written here.
 *
 * Two things this page will not do: mark somebody absent because nobody logged
 * their day, and pretend to know whether today is a working day before anyone
 * has said which days are. Both show as what they are.
 */

const HUE = {
  green: '#2FA96B',
  blue: '#2F6BE4',
  violet: '#7C6FE0',
  orange: '#F59245',
  coral: '#EF5B39',
  teal: '#3E93A3',
} as const;

interface AttendanceRow {
  id: number;
  employee_id: number;
  employee_name: string | null;
  employee_code: string | null;
  department: string | null;
  work_date: string;
  status: string;
  status_label: string;
  check_in_at: string | null;
  check_out_at: string | null;
  worked_minutes: number | null;
  timezone: string;
  source: string;
  source_label: string;
  is_open: boolean;
}

interface Props {
  summary: {
    date: string;
    label: string;
    headcount: number;
    recorded: number;
    unrecorded: number;
    counts: Record<string, number>;
    checked_in: number;
    holiday: string | null;
    is_working_day: boolean | null;
    calendar_configured: boolean;
  };
  records: AttendanceRow[];
  filters: { date: string };
  me: {
    employee_id: number;
    name: string;
    timezone: string;
    today: AttendanceRow | null;
  } | null;
  can: { viewAll: boolean; manage: boolean };
}

const STATUS_TONE: Record<string, CrmStatusTone> = {
  present: 'good',
  partial: 'warn',
  absent: 'bad',
  on_leave: 'info',
  holiday: 'neutral',
  weekly_off: 'neutral',
};

const hours = (minutes: number | null) => {
  if (!minutes) {
    return '—';
  }

  const h = Math.floor(minutes / 60);
  const m = minutes % 60;

  return m === 0 ? `${h}h` : `${h}h ${m}m`;
};

export default function AttendanceOverview({ summary, records, filters, me, can }: Props) {
  const openShift = me?.today?.is_open ?? false;
  const hasCheckedIn = Boolean(me?.today?.check_in_at);
  const hasCheckedOut = Boolean(me?.today?.check_out_at);

  const clock = (action: 'in' | 'out') =>
    router.post('/company/people/attendance/clock', { action }, { preserveScroll: true });

  return (
    <PeopleShell title="Attendance">
      <Head title="Attendance | People" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">People</p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Attendance
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            {summary.label}
            {summary.holiday && <> · {summary.holiday}</>}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={Users} value={summary.headcount} caption="People" />
          <CrmPageStat
            icon={CheckCircle2}
            value={summary.counts.present ?? 0}
            chip={summary.checked_in > 0 ? `${summary.checked_in} in` : undefined}
            chipHue={HUE.green}
            caption="Present"
          />
          <CrmPageStat icon={UserX} value={summary.counts.absent ?? 0} caption="Absent" />
          <CrmPageStat icon={CalendarDays} value={summary.counts.on_leave ?? 0} caption="On leave" />
        </div>
      </div>

      {!summary.calendar_configured && (
        <CrmCard className="mb-4 flex flex-wrap items-center justify-between gap-4 px-5 py-4" step={0}>
          <div className="flex items-start gap-3">
            <CrmCardIcon icon={CalendarDays} hue={HUE.orange} />
            <div>
              <p className="text-[13px] font-semibold text-ovs-crm-text-primary">The working week is not set</p>
              {/*
                Deliberately not defaulted to Monday–Friday. Nothing in this
                system says which days this company works, so holidays and
                weekly offs stay blank until somebody says.
              */}
              <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
                Until it is, days are not classed as weekly offs and nobody is counted absent for a day nobody logged.
              </p>
            </div>
          </div>
          {can.manage && <CrmOpenButton href="/company/people/working-calendar" label="Set the working week" />}
        </CrmCard>
      )}

      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        {me && (
          <CrmCard className="flex min-h-[168px] flex-col lg:col-span-4" step={1} frostHue={openShift ? HUE.green : undefined}>
            <div className="mb-4 flex items-start justify-between gap-2">
              <CrmCardIcon icon={Clock} hue={openShift ? HUE.green : HUE.blue} />
              <CrmStatusPill tone={openShift ? 'good' : 'neutral'}>
                {openShift ? 'Checked in' : hasCheckedOut ? 'Day closed' : 'Not started'}
              </CrmStatusPill>
            </div>

            <p className="text-[13px] text-ovs-crm-text-secondary">Your day</p>

            <div className="mt-auto flex items-end justify-between gap-3 pt-3">
              <div className="min-w-0">
                <CrmValue value={me.today?.check_in_at ?? '—'} size="major" />
                <p className="mt-1 text-[11.5px] text-ovs-crm-text-muted">
                  {me.today?.check_out_at ? `Out at ${me.today.check_out_at}` : 'Not checked out'} · {me.timezone}
                </p>
              </div>

              <div className="flex shrink-0 gap-2">
                {!hasCheckedIn && (
                  <CrmButton tone="primary" size="sm" icon={LogIn} onClick={() => clock('in')}>
                    Check in
                  </CrmButton>
                )}
                {openShift && (
                  <CrmButton size="sm" icon={LogOut} onClick={() => clock('out')}>
                    Check out
                  </CrmButton>
                )}
              </div>
            </div>
          </CrmCard>
        )}

        <CrmCard className={me ? 'p-6 lg:col-span-8' : 'p-6 lg:col-span-12'} step={2}>
          <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">Today</h2>
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                {summary.recorded} of {summary.headcount} recorded · {summary.unrecorded} not yet logged
              </p>
            </div>

            <CrmInput
              type="date"
              value={filters.date}
              onChange={(e) =>
                router.get('/company/people/attendance', { date: e.target.value }, { preserveState: true, replace: true })
              }
              aria-label="Date"
              className="w-auto text-[12px]"
            />
          </div>

          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            {[
              { key: 'present', label: 'Present', hue: HUE.green },
              { key: 'partial', label: 'Partial', hue: HUE.orange },
              { key: 'absent', label: 'Absent', hue: HUE.coral },
              { key: 'on_leave', label: 'On leave', hue: HUE.violet },
              { key: 'holiday', label: 'Holiday', hue: HUE.teal },
              { key: 'weekly_off', label: 'Weekly off', hue: HUE.blue },
            ].map((cell) => (
              <div key={cell.key} className="rounded-[18px] bg-ovs-crm-surface-inset px-3.5 py-3">
                <span className="block h-1.5 w-8 rounded-full" style={{ background: cell.hue }} aria-hidden />
                <p className="mt-2 text-[20px] font-bold tabular-nums leading-none text-ovs-crm-text-primary">
                  {summary.counts[cell.key] ?? 0}
                </p>
                <p className="mt-1 text-[11px] text-ovs-crm-text-muted">{cell.label}</p>
              </div>
            ))}
          </div>
        </CrmCard>
      </div>

      {records.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={Clock}
          title="Nothing logged for this day"
          description="Attendance appears here as people check in, or when a manager records a day on their behalf."
        />
      ) : (
        <div className="space-y-2">
          {records.map((row) => (
            <Link key={row.id} href={`/company/people/attendance/employees/${row.employee_id}`} className="block">
              <CrmCard className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 transition hover:border-ovs-crm-accent/40">
                <span className="min-w-[180px] flex-1">
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="text-[13.5px] font-medium text-ovs-crm-text-primary">{row.employee_name}</span>
                    <CrmStatusPill tone={STATUS_TONE[row.status] ?? 'neutral'}>{row.status_label}</CrmStatusPill>
                    {row.is_open && <CrmStatusPill tone="good">In now</CrmStatusPill>}
                  </span>
                  <span className="mt-0.5 block text-[11.5px] text-ovs-crm-text-muted">
                    {row.employee_code}
                    {row.department && <> · {row.department}</>}
                    {/* The timezone the day was measured in, snapshotted on the record. */}
                    <> · {row.timezone}</>
                  </span>
                </span>

                <span className="min-w-[120px] text-[12.5px] tabular-nums text-ovs-crm-text-secondary">
                  {row.check_in_at ?? '—'} → {row.check_out_at ?? '—'}
                </span>

                <span className="min-w-[70px] text-right text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                  {hours(row.worked_minutes)}
                </span>

                <CrmStatusPill tone="neutral">{row.source_label}</CrmStatusPill>
              </CrmCard>
            </Link>
          ))}
        </div>
      )}
    </PeopleShell>
  );
}
