import { Lock, ShieldAlert, UserCheck } from 'lucide-react';
import { SectionCard } from './SectionCard';

interface DoctorProtectionCardProps {
  doctorName?: string | null;
  registeredOn?: string | null;
  uhid?: string | null;
}

export function DoctorProtectionCard({
  doctorName,
  registeredOn,
  uhid,
}: DoctorProtectionCardProps) {
  return (
    <SectionCard
      id="section-governed-assignment"
      title="Permanent Doctor Assignment & UHID"
      subtitle="Governed medical identity and default practitioner record."
      icon={Lock}
      tone="blue"
      badge="Governed"
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        {/* Assigned Doctor Display */}
        <div className="rounded-xl border border-blue-100 bg-blue-50/50 p-4">
          <div className="flex items-center gap-2 text-blue-900">
            <UserCheck className="h-4 w-4 text-blue-700" />
            <span className="text-[12px] font-bold uppercase tracking-wider">Default Doctor</span>
          </div>
          <p className="mt-1 text-[15px] font-bold text-slate-900">
            {doctorName ? `Dr. ${doctorName}` : 'Unassigned'}
          </p>
          <p className="mt-1 flex items-center gap-1 text-[11.5px] text-slate-500">
            <Lock className="h-3 w-3 text-slate-400" />
            Protected from direct modification
          </p>
        </div>

        {/* Permanent UHID */}
        <div className="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4">
          <span className="text-[12px] font-bold uppercase tracking-wider text-slate-600">
            Permanent UHID
          </span>
          <p className="mt-1 text-[15px] font-bold tracking-tight text-slate-900 font-mono">
            {uhid || 'Assigned on registration'}
          </p>
          <p className="mt-1 text-[11.5px] text-slate-500">
            Universal health record identifier
          </p>
        </div>

        {/* Registration Date */}
        <div className="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4">
          <span className="text-[12px] font-bold uppercase tracking-wider text-slate-600">
            Initial Registration
          </span>
          <p className="mt-1 text-[15px] font-bold text-slate-900">
            {registeredOn || 'Recorded'}
          </p>
          <p className="mt-1 text-[11.5px] text-slate-500">
            Immutable registration timestamp
          </p>
        </div>
      </div>

      <div className="mt-4 flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50/80 p-3 text-[12.5px] text-slate-600">
        <ShieldAlert className="h-4 w-4 shrink-0 text-blue-700 mt-0.5" />
        <p>
          Permanent default doctor assignment is protected and governed by Owner/Admin transfer approval.
          Treating doctor assignments for individual visits remain managed through Reception and New/Repeat Case workflows.
        </p>
      </div>
    </SectionCard>
  );
}
