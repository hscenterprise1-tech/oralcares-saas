import { Link, useForm } from '@inertiajs/react';
import { KeyRound, LinkIcon } from 'lucide-react';
import { CrmButton, CrmFormField, CrmInput } from '@/pages/Company/design';
import { ClinicPreAuthShell } from '../components/ClinicPreAuthShell';
import { PasswordInput } from '../components/PasswordInput';

/*
 * Choosing a password from a one-time link. Two flows share this page:
 *
 *   - `setup`: the primary owner's first password, from the email sent when
 *     Company provisions the clinic (`ClinicPasswordSetupController`).
 *   - `reset`: a forgotten password, from "Forgot your password?"
 *     (`ClinicPasswordResetController`).
 *
 * Either link can be dead: expired, already used, or never real. The page
 * then shows one state that explains nothing about the account, so it
 * cannot be used to probe which links or accounts exist.
 */

/** Mirrors the server's `Password::defaults()` — Laravel's own minimum, since
 * this app does not override it. The server stays the rule's authority; this
 * only tells the person the rule before they break it. */
const PASSWORD_HINT = 'At least 8 characters.';

interface SetupPasswordProps {
  status: 'valid' | 'invalid';
  mode: 'setup' | 'reset';
  email?: string;
  clinicName?: string | null;
  clinicLogo?: string | null;
}

const COPY = {
  setup: {
    title: 'Set your password',
    heading: 'Set up your account',
    subheading: "Choose the password you'll use to sign in to this clinic.",
    submit: 'Set password & continue',
    deadBody: 'Setup links work once and expire after 48 hours. Ask your OralCares contact to send a new one.',
  },
  reset: {
    title: 'Reset your password',
    heading: 'Choose a new password',
    subheading: 'Your new password replaces the old one straight away.',
    submit: 'Reset password',
    deadBody: 'Reset links work once and expire after an hour. You can ask for a new one.',
  },
} as const;

export default function SetupPassword({ status, mode, email, clinicName, clinicLogo }: SetupPasswordProps) {
  const copy = COPY[mode];
  const { data, setData, post, processing, errors, reset } = useForm({
    password: '',
    password_confirmation: '',
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post(window.location.pathname + window.location.search, {
      onFinish: () => reset('password', 'password_confirmation'),
    });
  };

  if (status === 'invalid') {
    return (
      <ClinicPreAuthShell
        title={copy.title}
        headline="Your clinic account"
        clinicName={clinicName}
        clinicLogo={clinicLogo}
      >
        <div className="flex flex-col items-center py-2 text-center">
          <div className="mb-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-ovs-crm-accent-coral-soft">
            <LinkIcon className="h-7 w-7 text-ovs-crm-accent-coral" aria-hidden="true" />
          </div>
          <h2 className="text-[20px] font-bold tracking-[-0.022em] text-ovs-crm-text-primary [text-wrap:balance]">
            This link doesn&apos;t work anymore
          </h2>
          <p className="mt-2 max-w-[34ch] text-[13px] leading-relaxed text-ovs-crm-text-secondary">{copy.deadBody}</p>

          {mode === 'reset' && (
            <Link
              href={route('clinic.password.request') + window.location.search}
              className="mt-6 flex min-h-[44px] items-center rounded text-[13px] font-semibold text-ovs-crm-brand-strong hover:text-ovs-crm-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/25"
            >
              Send me a new link
            </Link>
          )}
        </div>
      </ClinicPreAuthShell>
    );
  }

  return (
    <ClinicPreAuthShell
      title={copy.title}
      clinicName={clinicName}
      clinicLogo={clinicLogo}
      heading={copy.heading}
      subheading={copy.subheading}
    >
      <form onSubmit={submit} noValidate className="space-y-5">
        {/*
         * The account's email, shown read-only: it tells the person which
         * account this is, and it is what a password manager pairs the new
         * password with (`autocomplete="username"`), so the saved entry is
         * usable on the login page later.
         */}
        <CrmFormField label="Account">
          <CrmInput
            type="email"
            name="email"
            value={email ?? ''}
            autoComplete="username"
            readOnly
            className="bg-ovs-crm-surface-inset"
          />
        </CrmFormField>

        <CrmFormField label="New password" hint={errors.password ? undefined : PASSWORD_HINT} error={errors.password}>
          <PasswordInput
            name="password"
            value={data.password}
            autoComplete="new-password"
            onChange={(e) => setData('password', e.target.value)}
            readOnly={processing}
            invalid={!!errors.password}
            autoFocus
          />
        </CrmFormField>

        <CrmFormField label="Confirm password" error={errors.password_confirmation}>
          <PasswordInput
            name="password_confirmation"
            value={data.password_confirmation}
            autoComplete="new-password"
            onChange={(e) => setData('password_confirmation', e.target.value)}
            readOnly={processing}
            invalid={!!errors.password_confirmation}
          />
        </CrmFormField>

        <CrmButton type="submit" tone="primary" size="lg" icon={KeyRound} loading={processing} className="w-full">
          {copy.submit}
        </CrmButton>
      </form>
    </ClinicPreAuthShell>
  );
}
