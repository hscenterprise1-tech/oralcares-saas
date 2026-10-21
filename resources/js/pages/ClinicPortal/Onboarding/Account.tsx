import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Field, Input, useFieldId } from '@/clinic/ui/controls';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { OnboardingBar } from './components/OnboardingBar';
import type { OnboardingLinks } from './components/types';

type AccountProps = {
  clinicName: string | null;
  account: { name: string; email: string; phone: string };
  links: OnboardingLinks;
};

/**
 * The owner's first stop after accepting the invitation: confirm their own
 * name and mobile number (`OnboardingController::saveAccount`). The email is
 * the one the invitation was sent to and is not changed here.
 */
export default function Account({ clinicName, account, links }: AccountProps) {
  const { data, setData, post, processing, errors } = useForm({ name: account.name, phone: account.phone });
  const nameId = useFieldId();
  const phoneId = useFieldId();
  const emailId = useFieldId();

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (processing) {
      return;
    }
    post(links.account, { preserveScroll: true });
  };

  return (
    <ClinicPortalShell title="Your account" variant="setup" bar={<OnboardingBar clinicName={clinicName} logoutUrl={links.logout} />}>
      <div className="mx-auto max-w-xl">
        <p className="text-[12px] font-semibold uppercase tracking-wide text-cx-nav-teal-strong">Step 1 · Your account</p>
        <h1 className="mt-1 text-[24px] font-semibold leading-tight tracking-tight text-cx-nav-ink">Welcome{clinicName ? ` to ${clinicName}` : ''}</h1>
        <p className="mt-1 text-[13.5px] text-cx-nav-muted">Confirm how you are named in the clinic and the mobile number we can reach you on. Then set up the clinic.</p>

        <Card as="div" className="mt-6">
          <form onSubmit={submit} noValidate className="space-y-4 px-5 py-5 sm:px-6">
            <Field label="Email" htmlFor={emailId} hint="The address your invitation was sent to.">
              <Input id={emailId} type="email" value={account.email} readOnly disabled />
            </Field>
            <Field label="Full name" htmlFor={nameId} required error={errors.name}>
              <Input
                id={nameId}
                autoComplete="name"
                value={data.name}
                onChange={(event) => setData('name', event.target.value)}
                invalid={Boolean(errors.name)}
                aria-describedby={errors.name ? `${nameId}-error` : undefined}
                maxLength={120}
                disabled={processing}
                autoFocus
              />
            </Field>
            <Field label="Mobile number" htmlFor={phoneId} required error={errors.phone} hint="Digits, spaces, + and -, 7 to 20 characters.">
              <Input
                id={phoneId}
                type="tel"
                inputMode="tel"
                autoComplete="tel"
                value={data.phone}
                onChange={(event) => setData('phone', event.target.value)}
                invalid={Boolean(errors.phone)}
                aria-describedby={errors.phone ? `${phoneId}-error` : `${phoneId}-hint`}
                maxLength={20}
                disabled={processing}
              />
            </Field>
            <div className="flex justify-end border-t border-cx-line pt-4">
              <Button type="submit" tone="primary" loading={processing} loadingLabel="Saving…" icon={ArrowRight}>
                Continue to clinic setup
              </Button>
            </div>
          </form>
        </Card>
      </div>
    </ClinicPortalShell>
  );
}
