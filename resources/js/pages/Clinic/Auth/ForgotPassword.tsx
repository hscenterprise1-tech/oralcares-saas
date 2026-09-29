import { Link, useForm, usePage } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { CrmButton, CrmFormField, CrmInput } from '@/pages/Company/design';
import { AuthNotice } from '../components/AuthNotice';
import { ClinicPreAuthShell } from '../components/ClinicPreAuthShell';

/*
 * "Forgot your password?" — asks for an email and always answers the same
 * way, whether or not it belongs to anyone (`ClinicPasswordResetController`).
 * The confirmation is the server's own flash, not something this page
 * decides, so it cannot drift into saying more than the server does.
 */

interface FlashPageProps {
  flash?: { success?: string | null };
  [key: string]: unknown;
}

export default function ForgotPassword({
  clinicName,
  clinicLogo,
}: {
  clinicName?: string | null;
  clinicLogo?: string | null;
}) {
  const { props } = usePage<FlashPageProps>();
  const sent = props.flash?.success ?? null;
  const { data, setData, post, processing, errors } = useForm({ email: '' });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post(window.location.pathname + window.location.search, { preserveScroll: true });
  };

  const signInHref = route('clinic.login') + window.location.search;

  return (
    <ClinicPreAuthShell
      title="Forgot your password"
      clinicName={clinicName}
      clinicLogo={clinicLogo}
      heading="Forgot your password?"
      subheading="Enter the email you sign in with, and we'll send a link to choose a new password."
    >
      <form onSubmit={submit} noValidate className="space-y-5">
        {sent && <AuthNotice tone="success">{sent}</AuthNotice>}

        <CrmFormField label="Email" error={errors.email}>
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
            invalid={!!errors.email}
            autoFocus
          />
        </CrmFormField>

        <CrmButton type="submit" tone="primary" size="lg" icon={Send} loading={processing} className="w-full">
          Send reset link
        </CrmButton>

        <p className="text-center text-[12.5px] text-ovs-crm-text-secondary">
          Remembered it?{' '}
          <Link
            href={signInHref}
            className="rounded font-semibold text-ovs-crm-brand-strong hover:text-ovs-crm-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/25"
          >
            Back to sign in
          </Link>
        </p>
      </form>
    </ClinicPreAuthShell>
  );
}
