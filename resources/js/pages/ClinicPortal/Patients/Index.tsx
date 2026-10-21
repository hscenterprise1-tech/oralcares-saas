import { useCallback, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, UserPlus } from 'lucide-react';
import { useClinic } from '@/clinic/context';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { useIntake } from '@/clinic/shell/IntakeContext';
import { Button, ButtonLink } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState, ErrorState } from '@/clinic/ui/states';
import { DirectoryToolbar, type FilterOptions } from './components/directory/DirectoryToolbar';
import { PatientList, PatientListSkeleton } from './components/directory/PatientList';
import type { DirectoryFilters, DirectoryProps } from './components/types';

/** The page-wide props a directory visit reloads; the rest stay as they are. */
const RELOADED = ['patients', 'filters'];

/**
 * The patient directory: everyone this member may see at the clinic, newest
 * first, one cursor page at a time (`PatientDirectory`). Search and filters
 * run on the server; the page never filters, counts or invents rows.
 */
export default function PatientsIndex(props: DirectoryProps) {
  return (
    <ClinicPortalShell title="Patients">
      <Directory {...props} />
    </ClinicPortalShell>
  );
}

function Directory({ patients, filters, options, isPractitioner, links }: DirectoryProps) {
  const clinic = useClinic();
  const intake = useIntake();
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const lastVisit = useRef<Record<string, string>>({});

  // Branches the member may open; a list cut short by the shared limit is not offered as a filter.
  const branches = useMemo<FilterOptions['branches']>(() => {
    if (!clinic || clinic.accessible_branch_count <= 1 || clinic.accessible_branches.length < clinic.accessible_branch_count) {
      return [];
    }

    return clinic.accessible_branches.map((b) => ({ id: b.id, name: b.name }));
  }, [clinic]);

  const visit = useCallback(
    (params: Record<string, string>) => {
      lastVisit.current = params;
      setFailed(false);
      router.get(links.index, params, {
        only: RELOADED,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
        onHttpException: () => {
          setFailed(true);

          return false;
        },
        onNetworkError: () => {
          setFailed(true);

          return false;
        },
      });
    },
    [links.index],
  );

  /** The query a set of filters is: empty values left out, the tab only for practitioners. */
  const paramsFor = useCallback(
    (next: DirectoryFilters, cursor?: string | null) => {
      const params: Record<string, string> = {};
      (['q', 'status', 'specialty', 'branch_id', 'active_care'] as const).forEach((key) => {
        if (next[key]) {
          params[key] = next[key];
        }
      });
      if (isPractitioner) {
        params.tab = next.tab;
      }
      if (cursor) {
        params.cursor = cursor;
      }

      return params;
    },
    [isPractitioner],
  );

  const change = (patch: Partial<DirectoryFilters>) => visit(paramsFor({ ...filters, ...patch }));
  const page = (cursor: string | null) => {
    if (cursor) {
      visit(paramsFor(filters, cursor));
    }
  };

  const filtered = Boolean(filters.q || filters.status || filters.specialty || filters.branch_id || filters.active_care);
  const profileHref = (id: number) => (links.profile ? links.profile.replace('__ID__', String(id)) : null);

  // Add a patient: at the front desk (where New Case is offered) the desk's own popup, so
  // registering and checking in stay one step; anyone else gets the full registration page.
  const addPatient = intake.available && intake.canRegister ? (
    <>
      {links.create && (
        <ButtonLink href={links.create} tone="ghost">
          Full form
        </ButtonLink>
      )}
      <Button tone="primary" icon={UserPlus} onClick={() => intake.open('new')}>
        Add patient
      </Button>
    </>
  ) : links.create ? (
    <ButtonLink href={links.create} tone="primary" icon={UserPlus}>
      Add patient
    </ButtonLink>
  ) : null;

  const title = isPractitioner && filters.tab === 'mine' ? 'My patients' : 'Patient directory';
  const description =
    isPractitioner && filters.tab === 'mine'
      ? 'Patients assigned to you. Switch to All patients to search the whole clinic.'
      : 'Search the clinic’s patients by name, mobile number or UHID.';

  return (
    <>
      <PageHeader eyebrow={<span className="text-[11px] font-bold uppercase tracking-[0.08em] text-cx-nav-teal-strong">Patients</span>} title={title} description={description} actions={addPatient} />

      <Card as="div" className="overflow-hidden">
        <DirectoryToolbar filters={filters} options={{ statuses: options.statuses, specialties: options.specialties, branches }} isPractitioner={isPractitioner} loading={loading} onChange={change} />

        <div aria-live="polite" aria-busy={loading || undefined}>
          {failed ? (
            <ErrorState title="Patients could not be loaded" onRetry={() => visit(lastVisit.current)} />
          ) : loading && patients.data.length === 0 ? (
            <PatientListSkeleton />
          ) : patients.data.length === 0 ? (
            filtered ? (
              <EmptyState
                filtered
                title="No patients match"
                description={filters.q ? `Nothing starts with “${filters.q}”. Try a mobile number, a UHID or the first letters of a name.` : 'Nothing matches these filters.'}
                onClearFilters={() => visit(paramsFor({ q: '', status: '', specialty: '', branch_id: '', active_care: '', tab: filters.tab }))}
              />
            ) : isPractitioner && filters.tab === 'mine' ? (
              <EmptyState title="No patients assigned to you yet" description="Patients appear here once they are assigned to you at check-in or by transfer." action={<Button onClick={() => change({ tab: 'all' })}>See all patients</Button>} />
            ) : (
              <EmptyState title="No patients yet" description="Registered patients appear here, newest first." action={addPatient} />
            )
          ) : (
            <div className={loading ? 'pointer-events-none opacity-60 motion-safe:transition-opacity' : undefined}>
              <PatientList patients={patients.data} profileHref={profileHref} />
            </div>
          )}
        </div>

        {!failed && (patients.prev_cursor || patients.next_cursor) && (
          <nav aria-label="Pages of patients" className="flex items-center justify-between gap-3 border-t border-cx-line px-4 py-3 sm:px-5">
            <p className="text-[12.5px] text-cx-text-2">
              {patients.data.length} {patients.data.length === 1 ? 'patient' : 'patients'} on this page
            </p>
            <div className="flex gap-2">
              <Button size="sm" icon={ChevronLeft} disabled={!patients.prev_cursor || loading} onClick={() => page(patients.prev_cursor)}>
                Newer
              </Button>
              <Button size="sm" disabled={!patients.next_cursor || loading} onClick={() => page(patients.next_cursor)}>
                <span className="inline-flex items-center gap-1.5">
                  Older <ChevronRight className="h-4 w-4" aria-hidden="true" />
                </span>
              </Button>
            </div>
          </nav>
        )}
      </Card>
    </>
  );
}
