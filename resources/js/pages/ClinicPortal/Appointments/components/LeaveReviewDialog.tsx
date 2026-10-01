import { AlertTriangle } from 'lucide-react';
import { Button } from '@/clinic/ui/Button';
import { shortDate, timeOf } from '../lib/dates';
import { personName, type AppointmentItem } from '../lib/types';
import { Modal } from '@/clinic/ui/Modal';

/**
 * Upcoming appointments whose provider has since gone on approved leave.
 * They are never moved or cancelled automatically; Reception decides each.
 */
export function LeaveReviewDialog({
  open,
  onOpenChange,
  appointments,
  canManage,
  onOpen,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  appointments: AppointmentItem[];
  canManage: boolean;
  onOpen: (appointment: AppointmentItem) => void;
}) {
  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title="Appointments affected by leave"
      description="The provider has approved leave on these days. Nothing was moved; reschedule or cancel each one."
      footer={
        <Button tone="ghost" onClick={() => onOpenChange(false)}>
          Close
        </Button>
      }
    >
      <ul className="divide-y divide-clinic-border rounded-2xl border border-clinic-border">
        {appointments.map((appointment) => (
          <li key={appointment.id} className="flex items-center gap-3 px-3 py-2.5">
            <AlertTriangle className="h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
            <span className="min-w-0 flex-1">
              <span className="block truncate text-[13px] font-bold text-clinic-ink">{personName(appointment)}</span>
              <span className="block truncate text-[11.5px] text-clinic-secondary">
                {shortDate(appointment.start.slice(0, 10))}, {timeOf(appointment.start)} · {appointment.provider_name} ·{' '}
                {appointment.service?.name ?? 'General appointment'}
              </span>
            </span>
            {canManage && (
              <Button size="sm" onClick={() => onOpen(appointment)}>
                Review
              </Button>
            )}
          </li>
        ))}
      </ul>
    </Modal>
  );
}
