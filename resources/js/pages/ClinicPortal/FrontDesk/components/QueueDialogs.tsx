import { useEffect, useState } from 'react';
import { useClinic } from '@/clinic/context';
import { Avatar, DsButton, DsDialog, FOCUS_RING, InlineNotice } from '@/clinic/ds';
import { formatNumber } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { useIntakeConfig } from '@/pages/Clinic/Reception/intake/useIntakeConfig';
import type { DoctorRow, QueueRow } from './types';
import type { useQueueAction } from './useQueueAction';

type Action = ReturnType<typeof useQueueAction>;

/** Priorities come from the clinic's own check-in settings (the intake configuration), never a list kept here. */
export function PriorityDialog({ row, action, onClose }: { row: QueueRow; action: Action; onClose: () => void }) {
  const clinic = useClinic();
  const { config, failed, retry } = useIntakeConfig(true, String(clinic?.active_branch?.id ?? ''));
  const [choice, setChoice] = useState(row.priority);
  const busy = action.busy === `${row.id}:priority`;
  const options = config && config.check_in.priority ? config.priorities : [];

  return (
    <DsDialog
      open
      onOpenChange={(open) => !open && !busy && onClose()}
      title="Change priority"
      description={`For ${row.patient?.name ?? 'this patient'}. A higher priority moves them up today's queue.`}
      footer={
        <>
          <DsButton disabled={busy} onClick={onClose}>
            Close
          </DsButton>
          <DsButton variant="primary" loading={busy} disabled={!config || options.length === 0 || choice === row.priority} onClick={() => action.run(row.id, 'priority', row.links.priority, { priority: choice }, (ok) => ok && onClose())}>
            Save priority
          </DsButton>
        </>
      }
    >
      {failed ? (
        <InlineNotice action={<DsButton size="sm" onClick={retry}>Try again</DsButton>}>The priority list did not load.</InlineNotice>
      ) : !config ? (
        <p className="py-4 text-[13px] text-[#64748B]" role="status">
          Loading this clinic&apos;s priorities…
        </p>
      ) : options.length === 0 ? (
        <InlineNotice tone="slate">This clinic does not use visit priorities. An administrator can switch them on in the check-in settings.</InlineNotice>
      ) : (
        <div role="radiogroup" aria-label="Priority" className="grid gap-2 sm:grid-cols-2">
          {options.map((option) => (
            <button
              key={option.value}
              type="button"
              role="radio"
              aria-checked={choice === option.value}
              onClick={() => setChoice(option.value)}
              className={cn(
                'flex min-h-11 items-center justify-between rounded-xl border px-3.5 text-left text-[14px] font-semibold',
                FOCUS_RING,
                choice === option.value ? 'border-[#1769AA] bg-[#EAF2FA] text-cx-text' : 'border-cx-line text-[#64748B] hover:border-cx-line-strong',
              )}
            >
              {option.label}
              {option.value === row.priority && <span className="text-[12px] font-medium text-[#64748B]">Current</span>}
            </button>
          ))}
        </div>
      )}
      {action.error?.rowId === row.id && (
        <div className="mt-3">
          <InlineNotice>{action.error.message}</InlineNotice>
        </div>
      )}
    </DsDialog>
  );
}

/** Another doctor on shift at this branch today (`desk.doctors`); the server checks the move again. */
export function DoctorDialog({ row, doctors, action, onClose }: { row: QueueRow; doctors: DoctorRow[]; action: Action; onClose: () => void }) {
  const options = doctors.filter((doctor) => doctor.id !== row.doctor?.id);
  const [choice, setChoice] = useState<number | null>(null);
  const busy = action.busy === `${row.id}:doctor`;

  useEffect(() => setChoice(null), [row.id]);

  return (
    <DsDialog
      open
      onOpenChange={(open) => !open && !busy && onClose()}
      title="Change doctor"
      description={`${row.patient?.name ?? 'This patient'} is with ${row.doctor?.name ?? 'no doctor yet'}. Choose who will see them instead.`}
      footer={
        <>
          <DsButton disabled={busy} onClick={onClose}>
            Close
          </DsButton>
          <DsButton variant="primary" loading={busy} disabled={choice === null} onClick={() => choice !== null && action.run(row.id, 'doctor', row.links.doctor, { practitioner_id: choice }, (ok) => ok && onClose())}>
            Change doctor
          </DsButton>
        </>
      }
    >
      {options.length === 0 ? (
        <InlineNotice tone="slate">No other doctor is on shift at this branch today.</InlineNotice>
      ) : (
        <div role="radiogroup" aria-label="Doctor" className="space-y-2">
          {options.map((doctor) => (
            <button
              key={doctor.id}
              type="button"
              role="radio"
              aria-checked={choice === doctor.id}
              onClick={() => setChoice(doctor.id)}
              className={cn('flex w-full min-w-0 items-center gap-3 rounded-xl border px-3.5 py-2.5 text-left', FOCUS_RING, choice === doctor.id ? 'border-[#1769AA] bg-[#EAF2FA]' : 'border-cx-line hover:border-cx-line-strong')}
            >
              <Avatar name={doctor.name} size="sm" />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[14px] font-semibold text-cx-text">{doctor.name}</span>
                <span className="block truncate text-[12px] text-[#64748B]">{[doctor.practice, doctor.room].filter(Boolean).join(' · ') || doctor.shift_label}</span>
              </span>
              <span className="shrink-0 text-[12px] tabular-nums text-[#64748B]">{formatNumber(doctor.waiting)} waiting</span>
            </button>
          ))}
        </div>
      )}
      {action.error?.rowId === row.id && (
        <div className="mt-3">
          <InlineNotice>{action.error.message}</InlineNotice>
        </div>
      )}
    </DsDialog>
  );
}
