import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowRight, CircleCheck, Users } from 'lucide-react';
import { Button, ButtonLink } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { OnboardingBar } from './components/OnboardingBar';

type DoneProps = {
  kind: 'clinic' | 'staff';
  clinicName: string | null;
  completedAt: string | null;
  links: { dashboard: string; staff: string | null };
};

/**
 * The confirmation after the last step. The server shows it only once the
 * session is recorded as completed (`OnboardingController::done`), so this
 * page never claims a setup that did not persist.
 */
export default function Done({ kind, clinicName, completedAt, links }: DoneProps) {
  const [opening, setOpening] = useState(false);
  const finishedAt = completedAt ? new Date(completedAt) : null;
  const when =
    finishedAt && !Number.isNaN(finishedAt.getTime())
      ? finishedAt.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })
      : null;
  const isClinic = kind === 'clinic';

  return (
    <ClinicPortalShell title="Setup complete" variant="setup" bar={<OnboardingBar clinicName={clinicName} logoutUrl={null} />}>
      <Card className="mx-auto max-w-xl px-6 py-8 text-center sm:px-10 sm:py-10">
        <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-cx-green-bg text-cx-green-ink">
          <CircleCheck className="h-7 w-7" aria-hidden="true" />
        </span>
        <h1 className="mt-4 text-[22px] font-semibold tracking-tight text-cx-nav-ink">{isClinic ? `${clinicName ?? 'Your clinic'} is ready` : 'Your setup is complete'}</h1>
        <p className="mx-auto mt-2 max-w-md text-[13.5px] text-cx-nav-muted">
          {isClinic
            ? 'Setup is finished and the workspace is open to your team. Anyone you invited will get an email to set their password.'
            : 'Your profile is saved. The clinic workspace is now open to you.'}
        </p>
        {when && <p className="mt-2 text-[12px] text-cx-text-3">Completed {when}</p>}
        {isClinic && links.staff && (
          <p className="mx-auto mt-4 max-w-md rounded-xl bg-cx-blue-bg px-4 py-3 text-left text-[12.5px] text-cx-blue-ink">
            <span className="font-semibold">Do you also treat patients?</span> Add yourself under Staff as a Doctor or Dentist, with your own email, and you will appear in the check-in list and the calendar.
          </p>
        )}
        <div className="mt-7 flex flex-col-reverse items-stretch justify-center gap-2 sm:flex-row sm:items-center">
          {isClinic && links.staff && (
            <ButtonLink href={links.staff} icon={Users}>
              Manage staff
            </ButtonLink>
          )}
          <Button
            tone="primary"
            size="lg"
            icon={ArrowRight}
            loading={opening}
            loadingLabel="Opening…"
            onClick={() => {
              setOpening(true);
              router.visit(links.dashboard, { onFinish: () => setOpening(false) });
            }}
          >
            Open the dashboard
          </Button>
        </div>
      </Card>
    </ClinicPortalShell>
  );
}
