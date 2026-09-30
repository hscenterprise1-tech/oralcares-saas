import React from 'react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthButton } from '@/components/auth/AuthInput';
import { WifiOff, RefreshCw } from 'lucide-react';

export default function Offline() {
  return (
    <AuthShell
      pageTitle="No Internet Connection"
      formTitle="You Are Offline"
      formSubtitle="Network connection lost. Please check your internet or Wi-Fi connectivity."
    >
      <div className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-16 h-16 bg-slate-100 text-slate-600 rounded-full flex items-center justify-center mx-auto border border-slate-300 shadow-2xs">
          <WifiOff className="w-8 h-8" />
        </div>

        <p className="text-[13px] text-[#64748B] leading-relaxed">
          OralCares OS requires an active encrypted network connection for enterprise security validation.
        </p>

        <button onClick={() => window.location.reload()} className="w-full">
          <AuthButton type="button" icon={<RefreshCw className="w-4 h-4 text-emerald-300" />}>
            Retry Connection
          </AuthButton>
        </button>
      </div>
    </AuthShell>
  );
}
