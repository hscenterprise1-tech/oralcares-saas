import React from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthInput, AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { Sparkles, ArrowLeft } from 'lucide-react';

export default function MagicLink({ status }: { status?: string }) {
  const { data, setData, post, processing, errors } = useForm({
    email: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/magic-link');
  };

  return (
    <AuthShell
      pageTitle="Magic Link Login"
      formTitle="Passwordless Sign In"
      formSubtitle="Enter your corporate email to receive a instant sign-in magic link."
    >
      {status && <AuthAlert type="success" message={status} />}
      <AuthAlert message={errors.email || ''} />

      <form onSubmit={handleSubmit} className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm">
        <AuthInput
          id="email"
          label="Corporate Email"
          type="email"
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
          icon={<Sparkles className="w-4 h-4 text-emerald-300" />}
        >
          Send Magic Link
        </AuthButton>

        <div className="text-center pt-2 border-t border-[#F1F5F9]">
          <a
            href="/company/login"
            className="inline-flex items-center gap-1.5 text-[12px] font-bold text-[#64748B] hover:text-[#0F172A] hover:underline py-1"
          >
            <ArrowLeft className="w-3.5 h-3.5" /> Sign in with Password instead
          </a>
        </div>
      </form>
    </AuthShell>
  );
}
