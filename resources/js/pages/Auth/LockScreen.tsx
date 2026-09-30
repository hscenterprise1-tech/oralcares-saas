import React from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthInput, AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { Lock, LogOut } from 'lucide-react';

export default function LockScreen({ user = { name: 'Executive Administrator', email: 'admin@oralcares.in' } }) {
  const { data, setData, post, processing, errors } = useForm({
    password: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/unlock');
  };

  const handleLogout = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/logout');
  };

  return (
    <AuthShell
      pageTitle="Session Locked"
      formTitle="Session Locked"
      formSubtitle={`Workspace locked due to inactivity. Enter password for ${user.email} to resume.`}
    >
      <AuthAlert message={errors.password || ''} />

      <form onSubmit={handleSubmit} className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-16 h-16 bg-[#EEF2FF] text-[#0A2540] rounded-full flex items-center justify-center mx-auto shadow-2xs">
          <Lock className="w-8 h-8 text-[#6366F1]" />
        </div>

        <AuthInput
          id="password"
          label="Account Password"
          isPassword
          value={data.password}
          onChange={e => setData('password', e.target.value)}
          disabled={processing}
          autoFocus
          required
          placeholder="Enter password"
          error={errors.password}
        />

        <AuthButton
          type="submit"
          loading={processing}
          icon={<Lock className="w-4 h-4 text-emerald-300" />}
        >
          Unlock Workspace
        </AuthButton>

        <div className="pt-2 border-t border-[#F1F5F9] text-center">
          <button
            type="button"
            onClick={handleLogout}
            className="inline-flex items-center gap-1.5 text-[12px] font-bold text-[#64748B] hover:text-[#0F172A] hover:underline"
          >
            <LogOut className="w-3.5 h-3.5" /> Sign in as different user
          </button>
        </div>
      </form>
    </AuthShell>
  );
}
