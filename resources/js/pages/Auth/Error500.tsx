import React from 'react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthButton } from '@/components/auth/AuthInput';
import { ServerCrash, RefreshCw } from 'lucide-react';

export default function Error500({ incidentId = 'INC-849204' }: { incidentId?: string }) {
  return (
    <AuthShell
      pageTitle="500 Internal Server Error"
      formTitle="500 System Error"
      formSubtitle="An unexpected server error occurred. Our engineering team has been notified automatically."
    >
      <div className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-16 h-16 bg-red-50 text-red-600 rounded-full flex items-center justify-center mx-auto border border-red-200 shadow-2xs">
          <ServerCrash className="w-8 h-8" />
        </div>

        <div className="p-3 bg-[#F8F9FB] border border-[#E4E7EE] rounded-xl font-mono text-[11px] text-[#64748B]">
          Incident Ticket ID: <span className="font-bold text-[#0F172A]">{incidentId}</span>
        </div>

        <button onClick={() => window.location.reload()} className="w-full">
          <AuthButton type="button" icon={<RefreshCw className="w-4 h-4 text-emerald-300" />}>
            Retry Request
          </AuthButton>
        </button>
      </div>
    </AuthShell>
  );
}
