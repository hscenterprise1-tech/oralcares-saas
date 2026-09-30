import { Head, usePage } from '@inertiajs/react';
import { useClinicShared } from '@/clinic/context';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { buttonClasses, ButtonLink } from '@/clinic/ui/Button';
import { StatePanel, type StateKind } from '@/clinic/ui/StatePanel';

interface ErrorPageProps {
  status: 403 | 404 | 429 | 500 | 503;
  reason: 'plan_unavailable' | 'no_clinic_context' | null;
  /** The sentence a deliberate 403 was raised with. Never set for a 5xx. */
  message: string | null;
  requestId: string | null;
}

const COPY: Record<ErrorPageProps['status'], { kind: StateKind; title: string; description: string }> = {
  403: {
    kind: 'forbidden',
    title: 'You do not have access to this page',
    description: 'Your role at this branch does not include it. If you need it, ask your clinic administrator.',
  },
  404: {
    kind: 'not_found',
    title: 'Page not found',
    description: 'The page may have moved, or the link may be wrong.',
  },
  429: { kind: 'error', title: 'Too many requests', description: 'Please wait a moment, then try again.' },
  500: {
    kind: 'error',
    title: 'Something went wrong',
    description: 'We could not complete this. Please try again in a moment.',
  },
  503: {
    kind: 'error',
    title: 'Briefly unavailable',
    description: 'The portal is being updated. Please try again in a few minutes.',
  },
};

/**
 * The Clinic Portal's error page, rendered by `ClinicPortalErrors` for 403,
 * 404, 429, 500 and 503. Inside a clinic session it keeps the portal's frame
 * so the user can navigate on; without one it stands alone.
 */
export default function ClinicPortalError({ status, reason, message, requestId }: ErrorPageProps) {
  const shared = useClinicShared();
  const { url } = usePage();
  const copy = COPY[status] ?? COPY[500];

  let kind = copy.kind;
  let title = copy.title;
  let description = copy.description;

  if (reason === 'plan_unavailable') {
    kind = 'plan_unavailable';
    title = 'Not included in your clinic’s plan';
    description =
      'This module is not part of your clinic’s current plan. Your clinic owner can ask your account manager to add it.';
  } else if (reason === 'no_clinic_context') {
    title = 'No clinic is open in this session';
    description = 'Sign in again to choose the clinic you work in.';
  } else if (status === 403 && message) {
    description = message;
  }

  const home = shared?.portalLinks?.dashboard ?? null;
  const inSession = Boolean(shared?.clinic && shared.auth?.user);

  const panel = (
    <div className="mx-auto max-w-xl rounded-3xl border border-clinic-border bg-clinic-surface">
      <StatePanel
        kind={kind}
        title={title}
        description={
          <>
            {description}
            {status >= 500 && requestId && (
              <span className="mt-3 block text-[12px]">
                Reference:{' '}
                <code className="rounded bg-clinic-inset px-1.5 py-0.5 font-mono text-clinic-ink">{requestId}</code>
              </span>
            )}
          </>
        }
        action={
          <>
            {status === 429 || status >= 500 ? (
              <button
                type="button"
                onClick={() => window.location.assign(url)}
                className={buttonClasses('primary', 'md')}
              >
                Try again
              </button>
            ) : null}
            {home && (
              <ButtonLink href={home} tone={status === 429 || status >= 500 ? 'secondary' : 'primary'}>
                Go to dashboard
              </ButtonLink>
            )}
          </>
        }
      />
      <p className="pb-6 text-center text-[11.5px] font-semibold uppercase tracking-[0.08em] text-clinic-secondary">
        Error {status}
      </p>
    </div>
  );

  if (inSession) {
    return <ClinicPortalShell title={title}>{panel}</ClinicPortalShell>;
  }

  return (
    <>
      <Head title={title} />
      <main id="main" className="flex min-h-screen items-center bg-clinic-canvas px-4 py-16">
        <div className="w-full">{panel}</div>
      </main>
    </>
  );
}
