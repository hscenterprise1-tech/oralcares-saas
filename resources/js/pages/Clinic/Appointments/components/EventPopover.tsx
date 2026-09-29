import React from 'react';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { addMinutes, format } from 'date-fns';
import { Clock, MapPin, Phone, Stethoscope } from 'lucide-react';
import { CrmAvatar, CrmButton, CrmStatusPill } from '@/pages/Company/design';
import { AppointmentRow } from '../types';
import { APPOINTMENT_STATUS_OPTIONS, APPOINTMENT_STATUS_PILL_TONE, appointmentStatusLabel } from '../statusTone';

/**
 * The shared appointment-detail surface — a Radix `Popover`, not `CrmModal`.
 * Every calendar surface (Day columns, Week grid, Month chips, the mobile
 * agenda) hands this the clicked element's bounding rect rather than a
 * fixed trigger — FullCalendar renders event cards imperatively, so there is
 * no single JSX node to wire a normal `Popover.Trigger` to. A zero-size,
 * pointer-events-none span positioned at that rect stands in as the
 * `Popover.Anchor`; Radix/Floating-UI does the rest (flip, shift, collision).
 */

export interface PopoverAnchorRect {
  top: number;
  left: number;
  width: number;
  height: number;
}

export function EventPopover({
  appointment,
  anchorRect,
  doctorName,
  processing,
  onClose,
  onStatusChange,
}: {
  appointment: AppointmentRow | null;
  anchorRect: PopoverAnchorRect | null;
  doctorName?: string;
  processing: boolean;
  onClose: () => void;
  onStatusChange: (status: string) => void;
}) {
  const open = Boolean(appointment && anchorRect);

  const start = appointment ? new Date(appointment.scheduled_at) : null;
  const end = start && appointment ? addMinutes(start, appointment.duration_minutes) : null;

  return (
    <PopoverPrimitive.Root open={open} onOpenChange={(next) => !next && onClose()}>
      <PopoverPrimitive.Anchor asChild>
        <span
          aria-hidden="true"
          style={{
            position: 'fixed',
            left: anchorRect?.left ?? 0,
            top: anchorRect?.top ?? 0,
            width: anchorRect?.width ?? 0,
            height: anchorRect?.height ?? 0,
            pointerEvents: 'none',
          }}
        />
      </PopoverPrimitive.Anchor>

      {open && appointment && start && end && (
        <PopoverPrimitive.Portal>
          <PopoverPrimitive.Content
            align="start"
            side="bottom"
            sideOffset={10}
            collisionPadding={16}
            onOpenAutoFocus={(e) => e.preventDefault()}
            style={{ maxHeight: 'var(--radix-popover-content-available-height)' }}
            className="z-50 w-[300px] max-w-[calc(100vw-32px)] overflow-y-auto rounded-[20px] border border-ovs-crm-border bg-white p-4 shadow-ovs-crmLift data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
          >
            <div className="flex items-start gap-3">
              <CrmAvatar name={appointment.patient_name} size="md" />
              <div className="min-w-0 flex-1">
                <p className="truncate text-[14px] font-semibold text-ovs-crm-text-primary">{appointment.patient_name}</p>
                <p className="truncate text-[11.5px] text-ovs-crm-text-muted">{appointment.patient_uhid}</p>
              </div>
              <CrmStatusPill tone={APPOINTMENT_STATUS_PILL_TONE[appointment.status]}>
                {appointmentStatusLabel(appointment.status)}
              </CrmStatusPill>
            </div>

            <div className="mt-3 space-y-2 border-t border-ovs-crm-border pt-3 text-[12.5px] text-ovs-crm-text-secondary">
              <div className="flex items-center gap-2">
                <Phone className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                {appointment.patient_phone}
              </div>
              {doctorName && (
                <div className="flex items-center gap-2">
                  <Stethoscope className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                  {doctorName}
                </div>
              )}
              <div className="flex items-center gap-2">
                <Clock className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                {format(start, 'EEE d MMM')} · {format(start, 'h:mm a')}–{format(end, 'h:mm a')}
              </div>
              {appointment.room_name && (
                <div className="flex items-center gap-2">
                  <MapPin className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                  {appointment.room_name}
                </div>
              )}
              <p className="capitalize text-ovs-crm-text-muted">{appointment.appointment_type.replace(/_/g, ' ')}</p>
            </div>

            {appointment.notes && (
              <p className="mt-2 rounded-[12px] bg-ovs-crm-surface-inset px-3 py-2 text-[12px] text-ovs-crm-text-secondary">
                {appointment.notes}
              </p>
            )}

            <div className="mt-3 border-t border-ovs-crm-border pt-3">
              <p className="mb-1.5 text-[10.5px] font-semibold uppercase tracking-[0.06em] text-ovs-crm-text-muted">Update status</p>
              <div className="flex flex-wrap gap-1.5">
                {APPOINTMENT_STATUS_OPTIONS.map((opt) => (
                  <button
                    key={opt.value}
                    type="button"
                    disabled={processing || appointment.status === opt.value}
                    onClick={() => onStatusChange(opt.value)}
                    className="min-h-11 rounded-full border border-ovs-crm-border bg-white px-2.5 text-[11.5px] font-medium text-ovs-crm-text-secondary transition-colors hover:border-ovs-crm-border-strong disabled:cursor-default disabled:opacity-40"
                  >
                    {opt.label}
                  </button>
                ))}
              </div>
            </div>

            <div className="mt-3 flex items-center justify-between border-t border-ovs-crm-border pt-3">
              <a
                href={route('clinic.patients.show', appointment.patient_id)}
                className="text-[12.5px] font-semibold text-ovs-crm-text-primary underline underline-offset-2"
              >
                View patient
              </a>
              <CrmButton size="sm" onClick={onClose}>
                Close
              </CrmButton>
            </div>
          </PopoverPrimitive.Content>
        </PopoverPrimitive.Portal>
      )}
    </PopoverPrimitive.Root>
  );
}

export default EventPopover;
