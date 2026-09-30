import React from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { MailCheck, LogOut } from 'lucide-react';

export default function VerifyEmail({ status }: { status?: string }) {
  const { post, processing } = useForm({});

  const handleResend = (e: React.FormEvent) => {
    e.preventDefault();
    post('/email/verification-notification');
  };

  const handleLogout = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/logout');
  };

  return (
    <AuthShell
      pageTitle="Verify Email"
      formTitle="Verify Your Email"
      formSubtitle="Thanks for signing up! Please verify your corporate email address by clicking on the link we just emailed to you."
    >
      {status === 'verification-link-sent' && (
        <AuthAlert type="success" message="A new verification link has been sent to your email address." />
      )}

      <div className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-16 h-16 bg-[#EEF2FF] text-[#4F46E5] rounded-full flex items-center justify-center mx-auto shadow-2xs">
          <MailCheck className="w-8 h-8" />
        </div>

        <p className="text-[13px] text-[#64748B] leading-relaxed">
          If you didn't receive the email, we will gladly send you another activation link.
        </p>

        <form onSubmit={handleResend}>
          <AuthButton type="submit" loading={processing}>
            Resend Verification Email
          </AuthButton>
        </form>

        <form onSubmit={handleLogout} className="pt-2">
          <button
            type="submit"
            className="inline-flex items-center gap-1.5 text-[12px] font-bold text-[#64748B] hover:text-[#0F172A] hover:underline"
          >
            <LogOut className="w-3.5 h-3.5" /> Sign Out
          </button>
        </form>
      </div>
    </AuthShell>
  );
}
