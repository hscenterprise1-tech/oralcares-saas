import { useForm, usePage } from '@inertiajs/react';
import { LogIn, MailQuestion, UserPlus } from 'lucide-react';
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
  /**
   * valid: new login, set a password. existing: the email already has a login;
   * confirm with its password to add this clinic. The rest explain why the
   * link can't be used as it stands.
   */
  status: 'valid' | 'existing' | 'invalid' | 'already_member' | 'wrong_account' | 'account_conflict';
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

/** Why an invitation link can't be used as it stands. */
const NOTICES = {
  invalid: {
    title: "This invitation link doesn't work anymore",
    body: 'It may have expired, already been used, or been replaced by a newer one. Ask the clinic admin who invited you to send a fresh link.',
  },
  already_member: {
    title: 'This account is already a member of this clinic',
    body: 'There is nothing to accept. Sign in as usual to open the clinic.',
  },
  wrong_account: {
    title: 'This invitation is for a different account',
    body: 'You are signed in as someone else. Sign out, then open the invitation link again.',
  },
  account_conflict: {
    title: "This email can't join a clinic",
    body: 'It belongs to an OralCares staff account. Ask the clinic admin to invite a different email.',
  },
} as const;

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

  const notice = NOTICES[status as keyof typeof NOTICES];

  if (notice) {
    return (
      <ClinicPreAuthShell title="Invitation link" headline="Your clinic invitation">
        <div className="flex flex-col items-center py-2 text-center">
          <div className="mb-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-ovs-crm-accent-coral-soft">
            <MailQuestion className="h-7 w-7 text-ovs-crm-accent-coral" aria-hidden="true" />
          </div>
          <h2 className="text-[20px] font-bold tracking-[-0.022em] text-ovs-crm-text-primary [text-wrap:balance]">
            {notice.title}
          </h2>
          <p className="mt-2 max-w-[34ch] text-[13px] leading-relaxed text-ovs-crm-text-secondary">{notice.body}</p>
        </div>
      </ClinicPreAuthShell>
    );
  }

  const invitedAs = roleName ? `${employeeName} · ${roleName}` : employeeName;

  if (status === 'existing') {
    return (
      <ClinicPreAuthShell
        title={`Join ${clinicName ?? 'your clinic'}`}
        clinicName={clinicName}
        clinicLogo={clinicLogo}
        heading="You're invited to join the team"
        subheading={
          <>
            You already have an OralCares account. Enter its password to add this clinic to it as{' '}
            <span className="font-semibold text-ovs-crm-text-primary">{invitedAs}</span>.
          </>
        }
      >
        <form onSubmit={submit} noValidate className="space-y-5">
          {seatLimitError && <AuthNotice tone="error">{seatLimitError}</AuthNotice>}

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

          <CrmFormField
            label="Your current password"
            hint={errors.password ? undefined : 'Your password stays as it is.'}
            error={errors.password}
          >
            <PasswordInput
              name="password"
              value={data.password}
              autoComplete="current-password"
              onChange={(e) => setData('password', e.target.value)}
              readOnly={processing}
              invalid={!!errors.password}
              autoFocus
            />
          </CrmFormField>

          <CrmButton type="submit" tone="primary" size="lg" icon={LogIn} loading={processing} className="w-full">
            Confirm &amp; join
          </CrmButton>
        </form>
      </ClinicPreAuthShell>
    );
  }

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
