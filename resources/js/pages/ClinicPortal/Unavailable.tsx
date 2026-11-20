import { usePage } from '@inertiajs/react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { ButtonLink } from '@/clinic/ui/Button';
import type { ClinicSharedProps } from '@/clinic/types';

/**
 * The one screen shown wherever the Clinic Portal has no frontend yet.
 *
 * The old Clinic frontend was removed for a rebuild (2026-10-11 reset). The
 * server still answers every route exactly as before — the same permission
 * checks, the same data — and `app.tsx` renders this for any Clinic page that
 * no longer exists, including the portal's error responses (`status` is set
 * then, and the HTTP status code is the server's).
 */
export default function Unavailable({ status, message, requestId }: { status?: number; message?: string; requestId?: string }) {
  const { portalLinks } = usePage<ClinicSharedProps>().props;
  const failed = typeof status === 'number';
  const title = failed ? `Error ${status}` : 'Not available yet';

  return (
    <ClinicPortalShell title={title}>
      <div className="mx-auto max-w-md py-16 text-center">
        <h1 className="text-[22px] font-semibold tracking-tight text-cx-nav-ink">
          {failed ? title : 'This part of the Clinic Portal is not available yet.'}
        </h1>
        <p className="mt-2 text-[14px] text-clinic-secondary">
          {failed ? (message ?? 'This request could not be completed.') : 'It is being rebuilt. Your data and permissions are unchanged.'}
        </p>
        {requestId && <p className="mt-2 text-[12px] text-clinic-secondary">Reference {requestId}</p>}
        {portalLinks && (
          <ButtonLink href={portalLinks.dashboard} tone="primary" size="lg" className="mt-6">
            Back to start
          </ButtonLink>
        )}
      </div>
    </ClinicPortalShell>
  );
}
