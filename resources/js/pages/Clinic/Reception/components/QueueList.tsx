import { MoreHorizontal, Siren, Users } from 'lucide-react';
import { CrmAvatar, CrmCard, CrmCardIcon, CrmStatusPill } from '@/pages/Company/design';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { CLINIC_HUE } from '../../components/nav';
import { ACTION_LABEL, QUEUE_ACTIONS, QUEUE_STATUS, formatClock, formatMinutes, minutesBetween } from '../queueUi';
import type { QueueAction, QueueRow } from '../types';

interface QueueListProps {
  rows: QueueRow[];
  now: Date;
  busyId: number | null;
  /** Briefly ringed after it is picked from "Needs attention". */
  highlightId: number | null;
  onAction: (row: QueueRow, action: QueueAction) => void;
  onToggleEmergency: (row: QueueRow) => void;
}

/** Minutes the row has been waiting, or with the doctor, as of `now`. */
function elapsed(row: QueueRow, now: Date): { label: string; minutes: number | null } {
  if (row.status === 'in_consultation') {
    return { label: 'With doctor', minutes: minutesBetween(row.started_at, now) };
  }
  if (row.status === 'called') {
    return { label: 'Called', minutes: minutesBetween(row.called_at, now) };
  }

  return { label: 'Waiting', minutes: minutesBetween(row.checked_in_at, now) };
}

function Identity({ row }: { row: QueueRow }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <CrmAvatar name={row.patient.short_name} size="md" />
      <div className="min-w-0">
        <p className="flex items-center gap-2 truncate text-[14px] font-semibold text-ovs-crm-text-primary">
          <span className="truncate">{row.patient.short_name}</span>
          {row.case_short && (
            <span className="shrink-0 rounded-md bg-ovs-crm-surface-inset px-1.5 py-0.5 font-mono text-[12px] font-semibold text-ovs-crm-text-secondary">
              {row.case_short}
            </span>
          )}
        </p>
        <p className="truncate text-[12px] text-ovs-crm-text-secondary">
          {row.booked_for ? `Booked ${formatClock(row.booked_for)}` : 'Walk-in'}
          {' · '}
          {row.doctor?.name ?? 'Any doctor'}
          {row.room && ` · ${row.room}`}
        </p>
      </div>
    </div>
  );
}

function RowActions({ row, busy, onAction, onToggleEmergency }: { row: QueueRow; busy: boolean } & Pick<QueueListProps, 'onAction' | 'onToggleEmergency'>) {
  const { primary, more } = QUEUE_ACTIONS[row.status];

  return (
    <div className="flex items-center justify-end gap-1.5">
      <button
        type="button"
        disabled={busy}
        onClick={() => onAction(row, primary)}
        className="min-h-[44px] rounded-full bg-ovs-crm-ink px-4 text-[13px] font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
      >
        {ACTION_LABEL[primary]}
      </button>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button
            type="button"
            aria-label={`More actions for ${row.patient.short_name}`}
            className="flex h-11 w-11 items-center justify-center rounded-full text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset"
          >
            <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-60">
          {more.map((action) => (
            <DropdownMenuItem key={action} className="min-h-[44px]" onSelect={() => onAction(row, action)}>
              {ACTION_LABEL[action]}
            </DropdownMenuItem>
          ))}
          {more.length > 0 && <DropdownMenuSeparator />}
          <DropdownMenuItem className="min-h-[44px]" onSelect={() => onToggleEmergency(row)}>
            <Siren className="mr-2 h-4 w-4" aria-hidden="true" />
            {row.priority === 'emergency' ? 'Remove emergency' : 'Mark as emergency'}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

function StatusCell({ row, now }: { row: QueueRow; now: Date }) {
  const time = elapsed(row, now);

  return (
    <div className="flex flex-col items-start gap-1">
      <div className="flex flex-wrap items-center gap-1.5">
        {row.priority === 'emergency' && (
          <span className="inline-flex items-center gap-1 rounded-full bg-ovs-crm-ink px-2.5 py-1 text-[11.5px] font-semibold text-white">
            <Siren className="h-3 w-3" aria-hidden="true" />
            Emergency
          </span>
        )}
        <CrmStatusPill tone={QUEUE_STATUS[row.status].pill}>{QUEUE_STATUS[row.status].label}</CrmStatusPill>
      </div>
      {row.status !== 'skipped' && (
        <span className="text-[12px] tabular-nums text-ovs-crm-text-secondary">
          {time.label} {formatMinutes(time.minutes)}
        </span>
      )}
    </div>
  );
}

/**
 * Today's queue in the order the server decided — emergencies, then booked
 * patients who arrived on time at their slot, then everyone by arrival. No
 * drag-and-drop: order is a rule, not a choice.
 */
export function QueueList({ rows, now, busyId, highlightId, onAction, onToggleEmergency }: QueueListProps) {
  return (
    <CrmCard step={3} className="p-0" aria-label="Queue">
      <div className="flex items-center justify-between gap-3 px-5 pt-5">
        <div className="flex items-center gap-3">
          <CrmCardIcon icon={Users} hue={CLINIC_HUE.staff} />
          <div>
            <h2 className="text-[15px] font-semibold text-ovs-crm-text-primary">Queue</h2>
            <p className="text-[12px] text-ovs-crm-text-secondary">In the order patients will be seen</p>
          </div>
        </div>
        <span className="text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">{rows.length} in queue</span>
      </div>

      {rows.length === 0 ? (
        <div className="px-5 pb-8 pt-10 text-center">
          <p className="text-[14px] font-semibold text-ovs-crm-text-primary">No one is in the queue</p>
          <p className="mt-1 text-[13px] text-ovs-crm-text-secondary">Checked-in patients appear here in the order they will be seen.</p>
        </div>
      ) : (
        <>
          {/* Desktop and tablet: one row per patient. */}
          <table className="mt-3 hidden w-full md:table">
            <thead>
              <tr className="border-b border-ovs-crm-line text-left text-[11.5px] font-medium uppercase tracking-wide text-ovs-crm-text-secondary">
                <th className="w-10 py-2 pl-5 pr-2">#</th>
                <th className="py-2 pr-3">Patient</th>
                <th className="py-2 pr-3">Status</th>
                <th className="py-2 pr-3">Checked in</th>
                <th className="py-2 pr-5 text-right">
                  <span className="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, index) => (
                <tr
                  key={row.id}
                  data-queue-row={row.id}
                  className={cn(
                    'border-b border-ovs-crm-line transition-shadow last:border-0',
                    row.status === 'in_consultation' && 'bg-ovs-crm-surface-inset/60',
                    highlightId === row.id && 'shadow-[inset_0_0_0_2px_#0B0B0F]'
                  )}
                >
                  <td className="py-3 pl-5 pr-2 align-middle text-[13px] font-semibold tabular-nums text-ovs-crm-text-secondary">{index + 1}</td>
                  <td className="max-w-0 py-3 pr-3 align-middle">
                    <Identity row={row} />
                  </td>
                  <td className="py-3 pr-3 align-middle">
                    <StatusCell row={row} now={now} />
                  </td>
                  <td className="py-3 pr-3 align-middle text-[13px] tabular-nums text-ovs-crm-text-secondary">{formatClock(row.checked_in_at)}</td>
                  <td className="py-3 pr-5 align-middle">
                    <RowActions row={row} busy={busyId === row.id} onAction={onAction} onToggleEmergency={onToggleEmergency} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {/* Phone: one card per patient, action on its own line. */}
          <ol className="mt-3 divide-y divide-ovs-crm-line md:hidden">
            {rows.map((row, index) => (
              <li
                key={row.id}
                data-queue-row={row.id}
                className={cn('space-y-3 px-4 py-4 transition-shadow', highlightId === row.id && 'shadow-[inset_0_0_0_2px_#0B0B0F]')}
              >
                <div className="flex items-start gap-3">
                  <span className="w-5 pt-2.5 text-[13px] font-semibold tabular-nums text-ovs-crm-text-secondary">{index + 1}</span>
                  <div className="min-w-0 flex-1 space-y-2">
                    <Identity row={row} />
                    <StatusCell row={row} now={now} />
                  </div>
                </div>
                <RowActions row={row} busy={busyId === row.id} onAction={onAction} onToggleEmergency={onToggleEmergency} />
              </li>
            ))}
          </ol>
        </>
      )}

    </CrmCard>
  );
}
