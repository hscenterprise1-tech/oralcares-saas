import React from 'react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthButton } from '@/components/auth/AuthInput';
import { Lock, ArrowLeft } from 'lucide-react';

export default function Unauthorized() {
  return (
    <AuthShell
      pageTitle="Access Denied"
      formTitle="Unauthorized Access"
      formSubtitle="You do not have permission to view or manage this corporate resource."
    >
      <div className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-16 h-16 bg-amber-50 text-amber-600 rounded-full flex items-center justify-center mx-auto border border-amber-200 shadow-2xs">
          <Lock className="w-8 h-8" />
        </div>

        <p className="text-[13px] text-[#64748B] leading-relaxed">
          Contact your System Administrator or Super Admin if you believe this is an error.
        </p>

        <a href="/company/dashboard" className="block">
          <AuthButton type="button" icon={<ArrowLeft className="w-4 h-4 text-emerald-300" />}>
            Return to Dashboard
          </AuthButton>
        </a>
      </div>
    </AuthShell>
  );
}
