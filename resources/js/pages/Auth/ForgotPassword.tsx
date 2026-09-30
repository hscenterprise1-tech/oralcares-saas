import React from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthInput, AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { ArrowLeft, Send } from 'lucide-react';

export default function ForgotPassword({ status }: { status?: string }) {
  const { data, setData, post, processing, errors } = useForm({
    email: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/forgot-password');
  };

  return (
    <AuthShell
      pageTitle="Forgot Password"
      formTitle="Reset Password"
      formSubtitle="Enter your corporate email to receive password recovery instructions."
    >
      {status && <AuthAlert type="success" message={status} />}
      <AuthAlert message={errors.email || ''} />

      <form onSubmit={handleSubmit} className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm">
        <AuthInput
          id="email"
          label="Corporate Email Address"
          type="email"
          name="email"
          value={data.email}
          onChange={e => setData('email', e.target.value)}
          disabled={processing}
          autoFocus
          required
          placeholder="you@oralcares.in"
          error={errors.email}
        />

        <AuthButton
          type="submit"
          loading={processing}
          icon={<Send className="w-4 h-4 text-emerald-300" />}
        >
          Send Recovery Link
        </AuthButton>

        <div className="text-center pt-2 border-t border-[#F1F5F9]">
          <a
            href="/company/login"
            className="inline-flex items-center gap-1.5 text-[12px] font-bold text-[#64748B] hover:text-[#0F172A] hover:underline py-1"
          >
            <ArrowLeft className="w-3.5 h-3.5" /> Back to Company Sign In
          </a>
        </div>
      </form>
    </AuthShell>
  );
}
