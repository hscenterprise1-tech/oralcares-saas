import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthInput, AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { OtpDigitInput } from '@/components/auth/OtpDigitInput';
import { ShieldCheck, Key } from 'lucide-react';

export default function TwoFactorChallenge() {
  const [useRecovery, setUseRecovery] = useState(false);
  const { data, setData, post, processing, errors } = useForm({
    code: '',
    recovery_code: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/two-factor-challenge');
  };

  return (
    <AuthShell
      pageTitle="Two-Factor Authentication"
      formTitle="2FA Verification"
      formSubtitle={useRecovery ? "Enter one of your emergency recovery codes." : "Open your authenticator app (Google Authenticator / 1Password) and enter the 6-digit code."}
    >
      <AuthAlert message={errors.code || errors.recovery_code || ''} />

      <form onSubmit={handleSubmit} className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        {!useRecovery ? (
          <div className="space-y-2">
            <label className="block text-[12.5px] font-bold text-[#0F172A]">
              Authenticator Code
            </label>
            <OtpDigitInput
              value={data.code}
              onChange={val => setData('code', val)}
              disabled={processing}
            />
          </div>
        ) : (
          <AuthInput
            id="recovery_code"
            label="Emergency Recovery Code"
            value={data.recovery_code}
            onChange={e => setData('recovery_code', e.target.value)}
            disabled={processing}
            autoFocus
            required
            placeholder="XXXXX-XXXXX"
          />
        )}

        <AuthButton
          type="submit"
          loading={processing}
          icon={<ShieldCheck className="w-4 h-4 text-emerald-300" />}
        >
          Verify 2FA Code
        </AuthButton>

        <div className="pt-2 border-t border-[#F1F5F9] text-center">
          <button
            type="button"
            onClick={() => setUseRecovery(!useRecovery)}
            className="text-[12px] font-bold text-[#6366F1] hover:underline inline-flex items-center gap-1"
          >
            <Key className="w-3.5 h-3.5" />
            {useRecovery ? "Use Authenticator App Code" : "Use Emergency Recovery Code"}
          </button>
        </div>
      </form>
    </AuthShell>
  );
}
