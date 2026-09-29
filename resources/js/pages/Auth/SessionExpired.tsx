import React from 'react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthButton } from '@/components/auth/AuthInput';
import { ShieldAlert, ArrowRight } from 'lucide-react';

export default function SessionExpired() {
  return (
    <AuthShell
      pageTitle="Session Expired"
      formTitle="Session Expired"
      formSubtitle="Your session has expired for security reasons. Please sign in again to access OralCares OS."
    >
      <div className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-16 h-16 bg-red-50 text-red-600 rounded-full flex items-center justify-center mx-auto border border-red-200 shadow-2xs">
          <ShieldAlert className="w-8 h-8" />
        </div>

        <p className="text-[13px] text-[#64748B] leading-relaxed">
          Sessions automatically expire after 8 hours or upon inactivity to protect enterprise data.
        </p>

        <a href="/company/login" className="block">
          <AuthButton type="button" icon={<ArrowRight className="w-4 h-4 text-emerald-300" />}>
            Re-authenticate Session
          </AuthButton>
        </a>
      </div>
    </AuthShell>
  );
}
