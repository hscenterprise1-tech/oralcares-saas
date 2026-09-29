import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { OtpDigitInput } from '@/components/auth/OtpDigitInput';
import { AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { ShieldCheck, RefreshCw } from 'lucide-react';

export default function OtpVerification({ email = 'you@oralcares.in' }: { email?: string }) {
  const { data, setData, post, processing, errors } = useForm({
    code: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/otp-verify');
  };

  return (
    <AuthShell
      pageTitle="OTP Verification"
      formTitle="Enter Security Code"
      formSubtitle={`We sent a 6-digit security code to ${email}`}
    >
      <AuthAlert message={errors.code || ''} />

      <form onSubmit={handleSubmit} className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="space-y-2">
          <label className="block text-[12.5px] font-bold text-[#0F172A]">
            6-Digit Verification Code
          </label>
          <OtpDigitInput
            value={data.code}
            onChange={val => setData('code', val)}
            disabled={processing}
          />
        </div>

        <AuthButton
          type="submit"
          loading={processing}
          disabled={data.code.length < 6}
          icon={<ShieldCheck className="w-4 h-4 text-emerald-300" />}
        >
          Verify & Continue
        </AuthButton>

        <div className="pt-2 border-t border-[#F1F5F9] flex items-center justify-between text-[12px] text-[#64748B]">
          <span>Didn't receive the code?</span>
          <button
            type="button"
            onClick={() => alert('A new OTP code has been sent to your email.')}
            className="font-bold text-[#6366F1] hover:underline inline-flex items-center gap-1"
          >
            <RefreshCw className="w-3 h-3" /> Resend Code
          </button>
        </div>
      </form>
    </AuthShell>
  );
}
