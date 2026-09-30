import React from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthInput, AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { CheckCircle2 } from 'lucide-react';

export default function AcceptInvitation({ email = 'employee@oralcares.in', token = '' }: { email?: string; token?: string }) {
  const { data, setData, post, processing, errors } = useForm({
    token: token || '',
    email: email || '',
    name: '',
    password: '',
    password_confirmation: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/accept-invitation');
  };

  return (
    <AuthShell
      pageTitle="Accept Invitation"
      formTitle="Complete Account Setup"
      formSubtitle={`Set up your employee credentials for ${email}.`}
    >
      <AuthAlert message={errors.password || errors.name || ''} />

      <form onSubmit={handleSubmit} className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm">
        <AuthInput
          id="name"
          label="Full Legal Name"
          value={data.name}
          onChange={e => setData('name', e.target.value)}
          disabled={processing}
          autoFocus
          required
          placeholder="e.g. Rahul Sharma"
          error={errors.name}
        />

        <AuthInput
          id="password"
          label="Create Account Password"
          isPassword
          value={data.password}
          onChange={e => setData('password', e.target.value)}
          disabled={processing}
          required
          placeholder="Min 12 characters"
          error={errors.password}
        />

        <AuthInput
          id="password_confirmation"
          label="Confirm Password"
          isPassword
          value={data.password_confirmation}
          onChange={e => setData('password_confirmation', e.target.value)}
          disabled={processing}
          required
          placeholder="Re-enter password"
        />

        <AuthButton
          type="submit"
          loading={processing}
          icon={<CheckCircle2 className="w-4 h-4 text-emerald-300" />}
        >
          Activate Corporate Account
        </AuthButton>
      </form>
    </AuthShell>
  );
}
