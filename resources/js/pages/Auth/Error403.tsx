import React from 'react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthButton } from '@/components/auth/AuthInput';
import { ShieldX, Home } from 'lucide-react';

export default function Error403() {
  return (
    <AuthShell
      pageTitle="403 Forbidden"
      formTitle="403 Forbidden"
      formSubtitle="Access to this system endpoint is restricted by security policy."
    >
      <div className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-16 h-16 bg-red-50 text-red-600 rounded-full flex items-center justify-center mx-auto border border-red-200 shadow-2xs">
          <ShieldX className="w-8 h-8" />
        </div>

        <p className="text-[13px] text-[#64748B] leading-relaxed">
          Your role ACL policy does not permit access to this module.
        </p>

        <a href="/company/dashboard" className="block">
          <AuthButton type="button" icon={<Home className="w-4 h-4 text-emerald-300" />}>
            Back to Mission Control
          </AuthButton>
        </a>
      </div>
    </AuthShell>
  );
}
