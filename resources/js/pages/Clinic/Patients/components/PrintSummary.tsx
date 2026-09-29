import React from 'react';
import { usePage } from '@inertiajs/react';
import { PatientDetail, TimelineEvent } from '../types';

/**
 * The print-only view of a patient's summary — `hidden print:block`, so it
 * costs nothing on screen and only exists when someone actually prints.
 * `ClinicShell` hides its own chrome (header, rail, flash banner) under the
 * same `print:` media query, so this is the only thing that ends up on the
 * page: no separate print route, no PDF service, browser print is enough
 * for a front-desk handout.
 */

interface ClinicPageProps {
  clinic?: { name?: string } | null;
  [key: string]: unknown;
}

export function PrintSummary({ patient, timeline }: { patient: PatientDetail; timeline: TimelineEvent[] }) {
  const { props } = usePage<ClinicPageProps>();
  const clinicName = props.clinic?.name ?? 'Clinic';

  return (
    <div className="hidden print:block">
      <div className="mb-6 flex items-baseline justify-between border-b border-black pb-3">
        <div>
          <p className="text-[18px] font-bold">{clinicName}</p>
          <p className="text-[11px] text-gray-600">Patient Summary</p>
        </div>
        <p className="text-[11px] text-gray-600">Generated {new Date().toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}</p>
      </div>

      <div className="mb-6 grid grid-cols-2 gap-x-8 gap-y-1.5 text-[12px]">
        <p><span className="font-semibold">Name:</span> {patient.name}</p>
        <p><span className="font-semibold">UHID:</span> {patient.uhid}</p>
        <p><span className="font-semibold">Age / Gender:</span> {patient.age ?? '—'} yrs · {patient.gender ?? '—'}</p>
        <p><span className="font-semibold">Blood Group:</span> {patient.blood_group ?? '—'}</p>
        <p><span className="font-semibold">Phone:</span> {patient.phone ?? '—'}</p>
        <p><span className="font-semibold">Email:</span> {patient.email ?? '—'}</p>
        {patient.profile?.address && (
          <p className="col-span-2"><span className="font-semibold">Address:</span> {patient.profile.address}, {patient.profile.city} {patient.profile.state} {patient.profile.pincode}</p>
        )}
        {patient.profile?.emergency_contact_name && (
          <p className="col-span-2"><span className="font-semibold">Emergency Contact:</span> {patient.profile.emergency_contact_name} ({patient.profile.emergency_contact_phone})</p>
        )}
        {patient.allergies.length > 0 && (
          <p className="col-span-2"><span className="font-semibold">Allergies:</span> {patient.allergies.join(', ')}</p>
        )}
      </div>

      <p className="mb-2 text-[13px] font-semibold">Recent History</p>
      <table className="w-full border-collapse text-[11.5px]">
        <thead>
          <tr className="border-b border-black text-left">
            <th className="py-1 pr-3 font-semibold">Date</th>
            <th className="py-1 pr-3 font-semibold">Event</th>
            <th className="py-1 font-semibold">Details</th>
          </tr>
        </thead>
        <tbody>
          {timeline.slice(0, 10).map((event) => (
            <tr key={event.id} className="border-b border-gray-300">
              <td className="py-1 pr-3 align-top whitespace-nowrap">{event.formatted_time}</td>
              <td className="py-1 pr-3 align-top whitespace-nowrap">{event.title}</td>
              <td className="py-1 align-top">{event.description}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <p className="mt-8 text-[10px] text-gray-500">
        This summary is generated for reference only and does not replace the patient&apos;s full clinical record.
      </p>
    </div>
  );
}

export default PrintSummary;
