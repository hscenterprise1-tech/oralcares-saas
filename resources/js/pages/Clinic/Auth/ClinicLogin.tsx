import { useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { LogIn } from 'lucide-react';
import { CrmButton, CrmFormField, CrmInput } from '@/pages/Company/design';
import { AuthNotice } from '../components/AuthNotice';
import { ClinicPreAuthShell } from '../components/ClinicPreAuthShell';
import { PasswordInput } from '../components/PasswordInput';

interface FlashPageProps {
  flash?: {
    error?: string | null;
    message?: string | null;
    success?: string | null;
  };
  [key: string]: unknown;
}

export default function ClinicLogin({
  clinicName,
  clinicLogo,
}: {
  clinic: string;
  clinicName?: string | null;
  clinicLogo?: string | null;
}) {
  const { props } = usePage<FlashPageProps>();
  const { data, setData, post, processing, errors, reset } = useForm({
    email: '',
    password: '',
    remember: false,
  });
  /*
   * The server reports every sign-in refusal — wrong password, no clinic
   * access, inactive membership — against `email`. With an email filled in,
   * that is a verdict on the attempt, not on the field, so it belongs in a
   * form-level notice. Only "the email field is required" (an empty field) is
   * about the field itself. The flag is taken at submit time so typing into
   * the field afterwards does not move the message between the two.
   */
  const [submittedWithEmail, setSubmittedWithEmail] = useState(false);
  const attemptError = errors.email && submittedWithEmail ? errors.email : null;
  const emailFieldError = errors.email && !submittedWithEmail ? errors.email : undefined;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmittedWithEmail(data.email.trim() !== '');
    // The password is cleared after every attempt (the email is kept), so a
    // rejected password is never left sitting in the field to be re-sent.
    post(window.location.pathname + window.location.search, {
      onFinish: () => reset('password'),
    });
  };

  const flashNotice = props.flash?.error ?? props.flash?.message ?? null;
  // Set after a password reset, which sends the person here to sign in.
  const flashSuccess = props.flash?.success ?? null;

  return (
    <ClinicPreAuthShell
      title={clinicName ? `Sign in · ${clinicName}` : 'Sign in'}
      clinicName={clinicName}
      clinicLogo={clinicLogo}
      heading="Welcome back"
      subheading="Sign in with the email your clinic registered for you."
    >
      <form onSubmit={submit} noValidate className="space-y-5">
        {attemptError ? (
          <AuthNotice tone="error">{attemptError}</AuthNotice>
        ) : flashNotice ? (
          <AuthNotice tone="warning">{flashNotice}</AuthNotice>
        ) : (
          flashSuccess && <AuthNotice tone="success">{flashSuccess}</AuthNotice>
        )}

        <CrmFormField label="Email" error={emailFieldError}>
          <CrmInput
            type="email"
            name="email"
            inputMode="email"
            value={data.email}
            autoComplete="username"
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
            onChange={(e) => setData('email', e.target.value)}
            readOnly={processing}
            placeholder="name@clinic.com"
            invalid={!!emailFieldError || !!attemptError}
            autoFocus
          />
        </CrmFormField>

        <CrmFormField label="Password" error={errors.password}>
          <PasswordInput
            name="password"
            value={data.password}
            autoComplete="current-password"
            onChange={(e) => setData('password', e.target.value)}
            readOnly={processing}
            invalid={!!errors.password}
          />
        </CrmFormField>

        <div className="flex flex-wrap items-center justify-between gap-x-4">
          <label className="flex min-h-[44px] w-fit cursor-pointer items-center gap-2.5 text-[12.5px] font-medium text-ovs-crm-text-secondary">
            <input
              type="checkbox"
              name="remember"
              checked={data.remember}
              onChange={(e) => setData('remember', e.target.checked)}
              disabled={processing}
              className="h-4 w-4 rounded-[4px] accent-ovs-crm-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/25 focus-visible:ring-offset-2"
            />
            Keep me signed in
          </label>
          <Link
            href={route('clinic.password.request') + window.location.search}
            className="flex min-h-[44px] items-center rounded text-[12.5px] font-semibold text-ovs-crm-brand-strong hover:text-ovs-crm-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/25"
          >
            Forgot password?
          </Link>
        </div>

        <CrmButton type="submit" tone="primary" size="lg" icon={LogIn} loading={processing} className="w-full">
          Sign in
        </CrmButton>
      </form>
    </ClinicPreAuthShell>
  );
}
