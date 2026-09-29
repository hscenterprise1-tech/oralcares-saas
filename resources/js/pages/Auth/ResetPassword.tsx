import React from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthInput, AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { KeyRound } from 'lucide-react';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
  const { data, setData, post, processing, errors } = useForm({
    token: token || '',
    email: email || '',
    password: '',
    password_confirmation: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/reset-password');
  };

  return (
    <AuthShell
      pageTitle="Reset Password"
      formTitle="Set New Password"
      formSubtitle="Ensure your password meets enterprise complexity rules."
    >
      <AuthAlert message={errors.email || errors.password || ''} />

      <form onSubmit={handleSubmit} className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm">
        <AuthInput
          id="email"
          label="Corporate Email"
          type="email"
          value={data.email}
          onChange={e => setData('email', e.target.value)}
          disabled
          required
        />

        <AuthInput
          id="password"
          label="New Password"
          isPassword
          value={data.password}
          onChange={e => setData('password', e.target.value)}
          disabled={processing}
          autoFocus
          required
          placeholder="Min 12 characters"
          error={errors.password}
        />

        <AuthInput
          id="password_confirmation"
          label="Confirm New Password"
          isPassword
          value={data.password_confirmation}
          onChange={e => setData('password_confirmation', e.target.value)}
          disabled={processing}
          required
          placeholder="Re-enter new password"
          error={errors.password_confirmation}
        />

        <AuthButton
          type="submit"
          loading={processing}
          icon={<KeyRound className="w-4 h-4 text-emerald-300" />}
        >
          Update Password & Sign In
        </AuthButton>
      </form>
    </AuthShell>
  );
}
