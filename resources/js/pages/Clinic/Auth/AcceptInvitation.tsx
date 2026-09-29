import { useForm, usePage } from '@inertiajs/react';
import { MailQuestion, UserPlus } from 'lucide-react';
import { CrmButton, CrmFormField, CrmInput } from '@/pages/Company/design';
import { AuthNotice } from '../components/AuthNotice';
import { ClinicPreAuthShell } from '../components/ClinicPreAuthShell';
import { PasswordInput } from '../components/PasswordInput';

/*
 * Invitee-facing password setup — same pre-auth shell as ClinicLogin and
 * SetupPassword, because this is the same pre-auth visual context, not the
 * CRM shell an already-signed-in admin sees.
 */

interface AcceptInvitationProps {
  status: 'valid' | 'invalid';
  employeeName?: string;
  email?: string;
  roleName?: string | null;
  clinicName?: string;
  clinicLogo?: string | null;
}

interface FlashPageProps {
  flash?: { error?: string | null };
  [key: string]: unknown;
}

/** Mirrors the server's `Password::defaults()` — see SetupPassword. */
const PASSWORD_HINT = 'At least 8 characters.';

export default function AcceptInvitation({
  status,
  employeeName,
  email,
  roleName,
  clinicName,
  clinicLogo,
}: AcceptInvitationProps) {
  const { props } = usePage<FlashPageProps>();
  const seatLimitError = props.flash?.error;

  const { data, setData, post, processing, errors, reset } = useForm({
    password: '',
    password_confirmation: '',
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post(window.location.pathname + window.location.search, {
      onError: () => reset('password', 'password_confirmation'),
    });
  };

  if (status === 'invalid') {
    return (
      <ClinicPreAuthShell title="Invitation link" headline="Your clinic invitation">
        <div className="flex flex-col items-center py-2 text-center">
          <div className="mb-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-ovs-crm-accent-coral-soft">
            <MailQuestion className="h-7 w-7 text-ovs-crm-accent-coral" aria-hidden="true" />
          </div>
          <h2 className="text-[20px] font-bold tracking-[-0.022em] text-ovs-crm-text-primary [text-wrap:balance]">
            This invitation link doesn&apos;t work anymore
          </h2>
          <p className="mt-2 max-w-[34ch] text-[13px] leading-relaxed text-ovs-crm-text-secondary">
            It may have expired, already been used, or been replaced by a newer one. Ask the clinic admin who invited
            you to send a fresh link.
          </p>
        </div>
      </ClinicPreAuthShell>
    );
  }

  const invitedAs = roleName ? `${employeeName} · ${roleName}` : employeeName;

  return (
    <ClinicPreAuthShell
      title={`Join ${clinicName ?? 'your clinic'}`}
      clinicName={clinicName}
      clinicLogo={clinicLogo}
      heading="You're invited to join the team"
      subheading={
        <>
          Set a password to finish creating your account as{' '}
          <span className="font-semibold text-ovs-crm-text-primary">{invitedAs}</span>.
        </>
      }
    >
      <form onSubmit={submit} noValidate className="space-y-5">
        {seatLimitError && <AuthNotice tone="error">{seatLimitError}</AuthNotice>}

        {/*
         * The invited email, read-only — it is the sign-in the new password
         * belongs to, and what a password manager saves it against.
         */}
        {email && (
          <CrmFormField label="Your sign-in email">
            <CrmInput
              type="email"
              name="email"
              value={email}
              autoComplete="username"
              readOnly
              className="bg-ovs-crm-surface-inset"
            />
          </CrmFormField>
        )}

        <CrmFormField label="Password" hint={errors.password ? undefined : PASSWORD_HINT} error={errors.password}>
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

        <CrmButton type="submit" tone="primary" size="lg" icon={UserPlus} loading={processing} className="w-full">
          Set password &amp; join
        </CrmButton>
      </form>
    </ClinicPreAuthShell>
  );
}
