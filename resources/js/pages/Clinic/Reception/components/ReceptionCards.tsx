import { Activity, AlarmClock, BellRing, CalendarCheck2, CheckCircle2, Clock, Siren, Stethoscope, UserX } from 'lucide-react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { CrmAvatar, CrmCard, CrmCardIcon, CrmRing, CrmStatusPill, type CrmStatusTone } from '@/pages/Company/design';
import { cn } from '@/lib/utils';
import { APPOINTMENT_STATUS_OPTIONS, APPOINTMENT_STATUS_TONE } from '../../Appointments/statusTone';
import type { AppointmentRow } from '../../Appointments/types';
import { CLINIC_HUE } from '../../components/nav';
import { formatClock, formatHour, formatMinutes } from '../queueUi';
import type { ActivityRow, AttentionEntry, DoctorRow, QueueBoardData } from '../types';

function CardHead({ icon, hue, title, hint, aside }: { icon: typeof Activity; hue: string; title: string; hint?: string; aside?: React.ReactNode }) {
  return (
    <div className="mb-4 flex items-start justify-between gap-3">
      <div className="flex items-center gap-3">
        <CrmCardIcon icon={icon} hue={hue} />
        <div>
          <h2 className="text-[15px] font-semibold text-ovs-crm-text-primary">{title}</h2>
          {hint && <p className="text-[12px] text-ovs-crm-text-secondary">{hint}</p>}
        </div>
      </div>
      {aside}
    </div>
  );
}

function EmptyNote({ title, body }: { title: string; body: string }) {
  return (
    <div className="rounded-[16px] bg-ovs-crm-surface-inset px-4 py-6 text-center">
      <p className="text-[13px] font-semibold text-ovs-crm-text-primary">{title}</p>
      <p className="mt-1 text-[12.5px] text-ovs-crm-text-secondary">{body}</p>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────── summary */

export function SummaryStrip({ summary }: { summary: QueueBoardData['summary'] }) {
  const cells = [
    { label: 'Booked today', value: summary.booked, icon: CalendarCheck2, hue: CLINIC_HUE.appointments },
    { label: 'Checked in', value: summary.checked_in, icon: BellRing, hue: CLINIC_HUE.dashboard },
    { label: 'Waiting', value: summary.waiting, icon: Clock, hue: CLINIC_HUE.staff },
    { label: 'With a doctor', value: summary.in_consultation, icon: Stethoscope, hue: CLINIC_HUE.patients },
    { label: 'Seen', value: summary.completed, icon: CheckCircle2, hue: CLINIC_HUE.billing },
  ];

  return (
    <CrmCard step={1} className="p-0" aria-label="Today at a glance">
      <dl className="grid grid-cols-2 divide-ovs-crm-line sm:grid-cols-3 lg:grid-cols-5 lg:divide-x">
        {cells.map((cell, index) => (
          <div key={cell.label} className={cn('flex items-center gap-3 px-5 py-4', index === 4 && 'col-span-2 sm:col-span-1')}>
            <CrmCardIcon icon={cell.icon} hue={cell.hue} />
            <div>
              <dt className="text-[12px] text-ovs-crm-text-secondary">{cell.label}</dt>
              <dd className="text-[22px] font-semibold leading-tight tabular-nums text-ovs-crm-text-primary">{cell.value}</dd>
            </div>
          </div>
        ))}
      </dl>
    </CrmCard>
  );
}

/* ─────────────────────────────────────────────────── needs attention */

const ATTENTION: Record<AttentionEntry['kind'], { label: string; icon: typeof Siren }> = {
  emergency: { label: 'Emergency', icon: Siren },
  called_not_seen: { label: 'Called, not seen', icon: BellRing },
  long_wait: { label: 'Long wait', icon: AlarmClock },
  doctor_on_leave: { label: 'Doctor on leave', icon: UserX },
  late: { label: 'Late', icon: Clock },
};

/**
 * First thing on the page: only what someone has to act on. Emergencies are
 * set apart in ink rather than red — red is kept for things that went wrong.
 */
export function AttentionPanel({ entries, onOpenItem }: { entries: AttentionEntry[]; onOpenItem: (itemId: number) => void }) {
  if (entries.length === 0) {
    return (
      <CrmCard step={2} className="flex items-center gap-3 py-4" aria-label="Needs attention">
        <CrmCardIcon icon={CheckCircle2} hue={CLINIC_HUE.billing} />
        <div>
          <p className="text-[14px] font-semibold text-ovs-crm-text-primary">Nothing needs attention</p>
          <p className="text-[12.5px] text-ovs-crm-text-secondary">No emergencies, long waits or late bookings right now.</p>
        </div>
      </CrmCard>
    );
  }

  return (
    <CrmCard step={2} aria-label="Needs attention">
      <CardHead icon={AlarmClock} hue={CLINIC_HUE.staff} title="Needs attention" hint={`${entries.length} to look at`} />
      <ul className="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
        {entries.map((entry) => {
          const { label, icon: Icon } = ATTENTION[entry.kind];
          const emergency = entry.kind === 'emergency';
          const content = (
            <>
              <span className={cn('mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full', emergency ? 'bg-white/15' : 'bg-white')}>
                <Icon className="h-4 w-4" aria-hidden="true" />
              </span>
              <span className="min-w-0 text-left">
                <span className={cn('block text-[11.5px] font-semibold uppercase tracking-wide', emergency ? 'text-white/80' : 'text-ovs-crm-accent-yellow-text')}>
                  {label}
                </span>
                <span className="block truncate text-[14px] font-semibold">{entry.title}</span>
                <span className={cn('block text-[12.5px]', emergency ? 'text-white/85' : 'text-ovs-crm-text-secondary')}>{entry.detail}</span>
              </span>
            </>
          );
          const classes = cn(
            'flex min-h-[44px] w-full items-start gap-3 rounded-[16px] px-3 py-3',
            emergency ? 'bg-ovs-crm-ink text-white' : 'bg-ovs-crm-accent-yellow-soft text-ovs-crm-text-primary'
          );

          return (
            <li key={entry.id}>
              {entry.item_id ? (
                <button type="button" className={cn(classes, 'transition-opacity hover:opacity-90')} onClick={() => onOpenItem(entry.item_id!)}>
                  {content}
                </button>
              ) : (
                <div className={classes}>{content}</div>
              )}
            </li>
          );
        })}
      </ul>
    </CrmCard>
  );
}

/* ─────────────────────────────────────────────────────────── doctors */

const DOCTOR_STATE: Record<DoctorRow['state'], { label: string; tone: CrmStatusTone }> = {
  available: { label: 'Free', tone: 'good' },
  busy: { label: 'With a patient', tone: 'warn' },
  off_shift: { label: 'Off shift', tone: 'neutral' },
  on_leave: { label: 'On leave', tone: 'neutral' },
};

export function DoctorPanel({ doctors, roomsEnabled }: { doctors: DoctorRow[]; roomsEnabled: boolean }) {
  return (
    <CrmCard step={4} aria-label="Doctors">
      <CardHead icon={Stethoscope} hue={CLINIC_HUE.patients} title="Doctors" hint="Who can see patients now" />

      {doctors.length === 0 ? (
        <EmptyNote title="No doctors at this branch" body="Add a doctor under Staff to start seeing patients here." />
      ) : (
        <ul className="divide-y divide-ovs-crm-line">
          {doctors.map((doctor) => (
            <li key={doctor.id} className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
              <CrmAvatar name={doctor.name} size="md" />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="truncate text-[14px] font-semibold text-ovs-crm-text-primary">{doctor.name}</p>
                  <CrmStatusPill tone={DOCTOR_STATE[doctor.state].tone}>{DOCTOR_STATE[doctor.state].label}</CrmStatusPill>
                </div>
                <p className="mt-0.5 text-[12.5px] text-ovs-crm-text-secondary">
                  {doctor.state === 'busy' && doctor.with_patient ? `With ${doctor.with_patient} · ` : ''}
                  {doctor.waiting} waiting · {doctor.seen_today} seen · {doctor.shift_label}
                </p>
                {roomsEnabled && doctor.room && <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">Assigned room: {doctor.room}</p>}
              </div>
            </li>
          ))}
        </ul>
      )}
    </CrmCard>
  );
}

/* ──────────────────────────────────────────────────── booking status */

export function BookingStatusCard({ bookingStatus }: { bookingStatus: QueueBoardData['booking_status'] }) {
  const labels = Object.fromEntries(APPOINTMENT_STATUS_OPTIONS.map((option) => [option.value, option.label]));
  const segments = bookingStatus.segments.map((segment) => ({
    key: segment.status,
    value: segment.count,
    label: labels[segment.status] ?? segment.status,
    color: APPOINTMENT_STATUS_TONE[segment.status as AppointmentRow['status']]?.dot ?? '#9AA1B0',
  }));

  return (
    <CrmCard step={5} aria-label="Today's bookings">
      <CardHead icon={CalendarCheck2} hue={CLINIC_HUE.appointments} title="Today's bookings" hint="Every appointment at this branch today" />

      {bookingStatus.total === 0 ? (
        <EmptyNote title="No bookings today" body="Appointments booked for today will show here by status." />
      ) : (
        <div className="flex flex-col items-center gap-5 sm:flex-row">
          <CrmRing
            value={bookingStatus.total}
            max={bookingStatus.total}
            color={CLINIC_HUE.appointments}
            size={148}
            thickness={10}
            segments={segments}
            label="Total"
            caption={<span className="text-[26px] font-semibold tabular-nums text-ovs-crm-text-primary">{bookingStatus.total}</span>}
          />
          <ul className="w-full space-y-2">
            {segments.map((segment) => (
              <li key={segment.key} className="flex items-center justify-between gap-3 text-[13px]">
                <span className="flex items-center gap-2 text-ovs-crm-text-primary">
                  <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: segment.color }} aria-hidden="true" />
                  {segment.label}
                </span>
                <span className="tabular-nums text-ovs-crm-text-secondary">
                  {segment.value} <span className="text-ovs-crm-text-muted">({Math.round((segment.value / bookingStatus.total) * 100)}%)</span>
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </CrmCard>
  );
}

/* ───────────────────────────────────────────────────────── wait times */

export function WaitTimeCard({ waitTimes }: { waitTimes: QueueBoardData['wait_times'] }) {
  const data = waitTimes.by_hour.map((point) => ({ ...point, label: formatHour(point.hour) }));

  return (
    <CrmCard step={6} aria-label="Waiting time">
      <CardHead icon={Clock} hue={CLINIC_HUE.staff} title="Waiting time" hint="Check-in to being called, by hour of arrival" />

      {waitTimes.seen === 0 ? (
        <EmptyNote title="No waits to show yet" body="Waiting times appear once the first patient has been called." />
      ) : (
        <>
          <div className="h-[180px] w-full">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={data} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#E8ECF3" />
                <XAxis dataKey="label" tick={{ fontSize: 11, fill: '#565F73' }} tickLine={false} axisLine={false} />
                <YAxis tick={{ fontSize: 11, fill: '#565F73' }} tickLine={false} axisLine={false} allowDecimals={false} unit="m" width={44} />
                <Tooltip
                  formatter={(value) => [`${value} min`, 'Average wait']}
                  labelFormatter={(label, payload) => `${label} · ${payload?.[0]?.payload?.count ?? 0} patients`}
                  contentStyle={{ borderRadius: 14, border: '1px solid #E8ECF3', fontSize: 12 }}
                />
                <Line type="monotone" dataKey="average" stroke={CLINIC_HUE.staff} strokeWidth={2.5} dot={{ r: 3 }} activeDot={{ r: 5 }} />
              </LineChart>
            </ResponsiveContainer>
          </div>
          <dl className="mt-3 grid grid-cols-2 gap-3 border-t border-ovs-crm-line pt-3">
            <div>
              <dt className="text-[12px] text-ovs-crm-text-secondary">Average wait</dt>
              <dd className="text-[18px] font-semibold tabular-nums text-ovs-crm-text-primary">{formatMinutes(waitTimes.average)}</dd>
            </div>
            <div>
              <dt className="text-[12px] text-ovs-crm-text-secondary">Longest wait</dt>
              <dd className="text-[18px] font-semibold tabular-nums text-ovs-crm-text-primary">{formatMinutes(waitTimes.longest)}</dd>
            </div>
          </dl>
        </>
      )}
    </CrmCard>
  );
}

/* ─────────────────────────────────────────────────────────── activity */

const ACTIVITY_VERB: Record<string, string> = {
  checked_in: 'checked in',
  call: 'called in',
  recall: 'called again',
  start: 'went in',
  complete: 'done',
  skip: 'marked not present',
  requeue: 'back in the queue',
  cancel: 'left the queue',
  priority_changed: 'priority changed',
};

function describeActivity(row: ActivityRow): string {
  if (row.action === 'priority_changed') {
    return row.notes?.endsWith('emergency') ? 'marked as an emergency' : 'no longer an emergency';
  }

  return ACTIVITY_VERB[row.action] ?? row.action.replace(/_/g, ' ');
}

export function ActivityCard({ activity }: { activity: ActivityRow[] }) {
  return (
    <CrmCard step={7} aria-label="Recent activity">
      <CardHead icon={Activity} hue={CLINIC_HUE.dashboard} title="Recent activity" hint="Everything that moved in today's queue" />

      {activity.length === 0 ? (
        <EmptyNote title="Nothing yet today" body="Check-ins, calls and completed visits will be listed here." />
      ) : (
        <ol className="space-y-3">
          {activity.map((row) => (
            <li key={row.id} className="flex items-start gap-3">
              <span className="w-[62px] shrink-0 pt-0.5 text-[12px] tabular-nums text-ovs-crm-text-secondary">{formatClock(row.at)}</span>
              <p className="min-w-0 text-[13px] text-ovs-crm-text-primary">
                <span className="font-semibold">{row.patient}</span>
                {row.case_short && <span className="font-mono text-[12px] text-ovs-crm-text-secondary"> · {row.case_short}</span>}{' '}
                {describeActivity(row)}
                {row.by && <span className="text-ovs-crm-text-secondary"> by {row.by}</span>}
                {row.notes && row.action !== 'priority_changed' && <span className="block text-[12px] text-ovs-crm-text-secondary">{row.notes}</span>}
              </p>
            </li>
          ))}
        </ol>
      )}
    </CrmCard>
  );
}
