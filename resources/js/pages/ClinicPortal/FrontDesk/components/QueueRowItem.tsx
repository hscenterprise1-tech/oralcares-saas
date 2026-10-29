import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { PersonAvatar } from '@/clinic/ui/PersonAvatar';
import { StatusBadge } from '@/clinic/ui/badges';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import type { BookingRow, QueueRow } from './types';

/** "14:05" from the server's clinic wall-clock string, with no time-zone arithmetic. */
export function clockTime(value: string | null | undefined): string | null {
  return value ? value.slice(11, 16) : null;
}

export function waitLabel(minutes: number | null): string | null {
  if (minutes === null) {
    return null;
  }

  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

const PRIORITY_TONE: Record<string, string> = {
  emergency: 'bg-cx-rose-bg text-cx-rose-ink',
  urgent: 'bg-cx-amber-bg text-cx-amber-ink',
};

const PILL = 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11.5px] font-semibold';

function PatientName({ row }: { row: { patient: { name: string } | null; links?: { patient: string | null } } }) {
  const name = row.patient?.name ?? 'Unknown patient';
  const className = 'truncate text-[13.5px] font-semibold text-cx-text';

  return row.links?.patient ? (
    <Link href={row.links.patient} className={cn(className, 'hover:text-cx-cyan-strong hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan')}>
      {name}
    </Link>
  ) : (
    <span className={className}>{name}</span>
  );
}

/**
 * One patient in today's queue: who, why, with whom, since when, and the
 * safety flags any front-office role may see. `actions` is the row's menu.
 */
export function QueueRowItem({ row, actions, showStatus = false }: { row: QueueRow; actions?: ReactNode; showStatus?: boolean }) {
  const visit = [row.visit?.specialty, row.visit?.type, row.visit?.reason].filter(Boolean).join(' · ');
  const since = clockTime(row.completed_at ?? row.started_at ?? row.called_at ?? row.checked_in_at);
  const wait = waitLabel(row.wait_minutes);

  return (
    <div className="flex min-w-0 items-start gap-3">
      <PersonAvatar name={row.patient?.name ?? '?'} size="sm" />
      <div className="min-w-0 flex-1">
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
          <PatientName row={row} />
          {row.case && <span className="text-[12px] font-semibold tabular-nums text-cx-text-3">{row.case}</span>}
          {PRIORITY_TONE[row.priority] && <span className={cn(PILL, PRIORITY_TONE[row.priority])}>{humanize(row.priority)}</span>}
          {row.priority !== 'normal' && !PRIORITY_TONE[row.priority] && <span className={cn(PILL, 'bg-cx-slate-bg text-cx-slate-ink')}>{humanize(row.priority)}</span>}
          {showStatus && <StatusBadge status={row.status} />}
        </div>
        <p className="mt-0.5 truncate text-[12.5px] text-cx-text-2">
          {[row.patient?.uhid, visit || null].filter(Boolean).join(' · ') || '—'}
        </p>
        <p className="mt-0.5 text-[12px] text-cx-text-3">
          {row.doctor ? row.doctor.name : <span className="font-semibold text-cx-amber-ink">No doctor yet</span>}
          {' · '}
          {row.source === 'appointment' ? 'Booked' : 'Walk-in'}
          {since && ` · ${since}`}
          {wait && <span className={cn('font-semibold', (row.wait_minutes ?? 0) >= 30 ? 'text-cx-rose-ink' : 'text-cx-text-2')}> · waiting {wait}</span>}
        </p>
        {row.safety.length > 0 && (
          <ul className="mt-1.5 flex flex-wrap gap-1" aria-label="Safety flags">
            {row.safety.map((flag, index) => (
              <li key={index} className={cn(PILL, 'bg-cx-rose-bg text-cx-rose-ink')}>
                <ShieldAlert className="h-3 w-3" aria-hidden="true" />
                {flag.label}
              </li>
            ))}
            {row.safety_more > 0 && <li className={cn(PILL, 'bg-cx-slate-bg text-cx-slate-ink')}>+{row.safety_more} more</li>}
          </ul>
        )}
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1.5">
        {row.payment === 'paid' && <span className={cn(PILL, 'bg-cx-green-bg text-cx-green-ink')}>Paid</span>}
        {row.payment === 'due' && <span className={cn(PILL, 'bg-cx-amber-bg text-cx-amber-ink')}>Balance due</span>}
        {row.payment === 'not_billed' && <span className={cn(PILL, 'bg-cx-slate-bg text-cx-slate-ink')}>Not billed</span>}
        {actions}
      </div>
    </div>
  );
}

/** A booking for today not yet checked in (a registered patient, or a prospect booked by name). */
export function BookingRowItem({ row }: { row: BookingRow }) {
  const name = row.patient?.name ?? row.prospect?.name ?? 'Unnamed booking';

  return (
    <div className="flex min-w-0 items-start gap-3">
      <PersonAvatar name={name} size="sm" />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <span className="truncate text-[13.5px] font-semibold text-cx-text">{name}</span>
          {!row.patient && <span className={cn(PILL, 'bg-cx-blue-bg text-cx-blue-ink')}>Not registered</span>}
        </div>
        <p className="mt-0.5 truncate text-[12.5px] text-cx-text-2">{[row.patient?.uhid, row.type, row.doctor].filter(Boolean).join(' · ') || '—'}</p>
      </div>
      <div className="shrink-0 text-right">
        <p className="text-[13px] font-semibold tabular-nums text-cx-text">{clockTime(row.scheduled_at) ?? '—'}</p>
        {row.late_minutes !== null && row.late_minutes > 0 && <p className="text-[11.5px] font-semibold text-cx-rose-ink">{waitLabel(row.late_minutes)} late</p>}
      </div>
    </div>
  );
}
