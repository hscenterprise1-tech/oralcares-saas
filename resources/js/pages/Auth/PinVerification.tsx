import React from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { PinPadInput } from '@/components/auth/OtpDigitInput';
import { AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { Lock } from 'lucide-react';

export default function PinVerification({ userName = 'Enterprise Administrator' }: { userName?: string }) {
  const { data, setData, post, processing, errors } = useForm({
    pin: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/pin-verify');
  };

  return (
    <AuthShell
      pageTitle="PIN Verification"
      formTitle="Quick PIN Unlock"
      formSubtitle={`Welcome back, ${userName}. Enter your 4-digit security PIN to unlock workspace.`}
    >
      <AuthAlert message={errors.pin || ''} />

      <form onSubmit={handleSubmit} className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <PinPadInput
          value={data.pin}
          onChange={val => setData('pin', val)}
          disabled={processing}
        />

        <AuthButton
          type="submit"
          loading={processing}
          disabled={data.pin.length < 4}
          icon={<Lock className="w-4 h-4 text-emerald-300" />}
        >
          Unlock Session
        </AuthButton>

        <div className="pt-2 border-t border-[#F1F5F9] text-center">
          <a
            href="/company/login"
            className="text-[12px] font-bold text-[#6366F1] hover:underline"
          >
            Sign in with Password instead
          </a>
        </div>
      </form>
    </AuthShell>
  );
}
