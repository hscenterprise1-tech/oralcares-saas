import React from 'react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthButton } from '@/components/auth/AuthInput';
import { FileQuestion, ArrowLeft } from 'lucide-react';

export default function Error404() {
  return (
    <AuthShell
      pageTitle="404 Page Not Found"
      formTitle="404 — Record Not Found"
      formSubtitle="The requested page or record could not be located in OralCares OS."
    >
      <div className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-16 h-16 bg-[#EEF2FF] text-[#4F46E5] rounded-full flex items-center justify-center mx-auto border border-[#6366F1]/20 shadow-2xs">
          <FileQuestion className="w-8 h-8" />
        </div>

        <p className="text-[13px] text-[#64748B] leading-relaxed">
          Please check the URL or return to your main executive workspace.
        </p>

        <a href="/company/dashboard" className="block">
          <AuthButton type="button" icon={<ArrowLeft className="w-4 h-4 text-emerald-300" />}>
            Back to Executive Dashboard
          </AuthButton>
        </a>
      </div>
    </AuthShell>
  );
}
