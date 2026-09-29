import React from 'react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthButton } from '@/components/auth/AuthInput';
import { Wrench, Clock } from 'lucide-react';

export default function Maintenance({ estimatedCompletion = '06:00 AM IST' }: { estimatedCompletion?: string }) {
  return (
    <AuthShell
      pageTitle="System Maintenance"
      formTitle="Scheduled Maintenance"
      formSubtitle="OralCares OS is currently undergoing scheduled platform upgrades."
    >
      <div className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-16 h-16 bg-indigo-50 text-[#6366F1] rounded-full flex items-center justify-center mx-auto border border-indigo-200 shadow-2xs">
          <Wrench className="w-8 h-8" />
        </div>

        <div className="p-4 bg-[#F8F9FB] border border-[#E4E7EE] rounded-xl flex items-center justify-between text-[12px] text-[#64748B]">
          <span className="flex items-center gap-1.5 font-medium">
            <Clock className="w-4 h-4 text-[#6366F1]" /> Estimated Resume
          </span>
          <span className="font-mono font-bold text-[#0F172A]">{estimatedCompletion}</span>
        </div>

        <button onClick={() => window.location.reload()} className="w-full">
          <AuthButton type="button">
            Check Status
          </AuthButton>
        </button>
      </div>
    </AuthShell>
  );
}
