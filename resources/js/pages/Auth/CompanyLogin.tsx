import React from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthInput, AuthButton, AuthAlert } from '@/components/auth/AuthInput';
import { ArrowRight } from 'lucide-react';

export default function CompanyLogin() {
  const { data, setData, post, processing, errors } = useForm({
    email: '',
    password: '',
    remember: true,
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/login');
  };

  return (
    <AuthShell
      pageTitle="Company Login"
      formTitle="Welcome Back"
      formSubtitle="Sign in to continue to OralCares internal operating system."
    >
      {/* Validation Errors */}
      <AuthAlert message={errors.email || errors.password || ''} />

      {/* Form Card */}
      <form onSubmit={handleSubmit} className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm">
        {/* Email Field */}
        <AuthInput
          id="email"
          label="Company Email"
          type="email"
          name="email"
          value={data.email}
          onChange={e => setData('email', e.target.value)}
          disabled={processing}
          autoFocus
          autoComplete="username"
          required
          placeholder="you@oralcares.in"
          error={errors.email}
        />

        {/* Password Field */}
        <AuthInput
          id="password"
          label="Password"
          isPassword
          name="password"
          value={data.password}
          onChange={e => setData('password', e.target.value)}
          disabled={processing}
          autoComplete="current-password"
          required
          placeholder="Enter your password"
          error={errors.password}
        />

        {/* Remember Me Checkbox */}
        <div className="flex items-center justify-between">
          <label className="flex items-center gap-2.5 text-[12.5px] text-[#475569] font-medium cursor-pointer select-none">
            <input
              type="checkbox"
              checked={data.remember}
              onChange={e => setData('remember', e.target.checked)}
              disabled={processing}
              className="w-4 h-4 rounded border-[#CBD5E1] text-[#6366F1] focus:ring-[#6366F1]"
            />
            Remember this device for 30 days
          </label>
        </div>

        {/* Submit Button */}
        <AuthButton
          type="submit"
          loading={processing}
          icon={<ArrowRight className="w-4 h-4 text-emerald-300" />}
        >
          Sign In to Workspace
        </AuthButton>

        {/* Forgot Password Link */}
        <div className="text-center pt-2 border-t border-[#F1F5F9]">
          <a
            href="/forgot-password"
            className="text-[12px] font-bold text-[#6366F1] hover:underline inline-block py-1"
          >
            Forgot password?
          </a>
        </div>
      </form>
    </AuthShell>
  );
}
