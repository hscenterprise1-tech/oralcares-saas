import React from 'react';
import { APPOINTMENT_STATUS_OPTIONS, APPOINTMENT_STATUS_TONE } from '../statusTone';

/** Status colour key — sits below the calendar header, above the grid. */
export function StatusLegend() {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-1">
      {APPOINTMENT_STATUS_OPTIONS.map((opt) => (
        <span key={opt.value} className="inline-flex items-center gap-1.5 text-[11.5px] text-ovs-crm-text-muted">
          <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: APPOINTMENT_STATUS_TONE[opt.value].dot }} aria-hidden="true" />
          {opt.label}
        </span>
      ))}
    </div>
  );
}

export default StatusLegend;
