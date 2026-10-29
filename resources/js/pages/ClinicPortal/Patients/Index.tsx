import { useState, type ReactNode } from 'react';
import { ChevronLeft, ChevronRight, MapPin, Search, SearchX, SlidersHorizontal, TriangleAlert, UserPlus, UserRound, Users, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useClinic } from '@/clinic/context';
import { DsButton, DsButtonLink, DsDialog, EmptyNote, FOCUS_RING, Pill, TONE, type DsTone } from '@/clinic/ds';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { useIntake } from '@/clinic/shell/IntakeContext';
import { Select, Switch } from '@/clinic/ui/controls';
import { deskMode } from '@/pages/ClinicPortal/FrontDesk/components/desk';
import { PatientTable, PatientTableSkeleton, type RowActions } from './components/directory/PatientTable';
import { useDirectoryQuery } from './components/directory/useDirectoryQuery';
import type { DirectoryFilters, DirectoryPatient, DirectoryProps, DirectoryView } from './components/types';

/** The views, in the order the directory offers them; each shows only when the server offers it to this member. */
const VIEWS: { key: DirectoryView; label: string; tone: DsTone }[] = [
  { key: 'today', label: 'Today', tone: 'amber' },
  { key: 'all', label: 'All patients', tone: 'blue' },
  { key: 'mine', label: 'My patients', tone: 'violet' },
];

/** The filters inside "Filters" (search and the view sit outside it). There is no patient-status filter. */
// `specialty` has no control here, but an old link can still carry it: it is counted so "Clear filters" shows and removes it.
const FILTER_KEYS = ['branch_id', 'practitioner_id', 'active_care', 'specialty'] as const;

/**
 * Patients: the clinic's patient directory.
 *
 * Search by name, patient ID or mobile; the views this member is offered
 * (Today, All, My patients — `PatientDirectoryAudience`); filters for
 * clinician, branch and active care; and the patients newest-first, a cursor
 * page at a time (`PatientDirectory`). Every row opens Patient 360; Edit and
 * Check in appear only for members and records they apply to, and the
 * server authorises each of them again. Nothing is counted or invented here.
 */
export default function PatientsIndex(props: DirectoryProps) {
  return (
    <ClinicPortalShell title="Patients">
      <Directory {...props} />
    </ClinicPortalShell>
  );
}

function Directory({ patients, filters, options, links }: DirectoryProps) {
  const clinic = useClinic();
  const intake = useIntake();
  const mode = deskMode(clinic);
  const directory = useDirectoryQuery(filters, links.index);
  const [sheetOpen, setSheetOpen] = useState(false);

  const views = VIEWS.filter((view) => options.views.includes(view.key));
  const multiBranch = (clinic?.accessible_branch_count ?? 0) > 1;
  // Branches the member may open; a list cut short by the shared limit is not offered as a filter.
  const branches = clinic && multiBranch && clinic.accessible_branches.length >= clinic.accessible_branch_count ? clinic.accessible_branches : [];
  // In a doctor's own list the doctor is the filter.
  const doctors = filters.view === 'mine' ? [] : options.doctors;
  const activeFilters = FILTER_KEYS.filter((key) => filters[key]).length;
  const searching = filters.q !== '';
  const branchName = branches.find((branch) => String(branch.id) === filters.branch_id)?.name;
  const canEdit = (clinic?.permissions ?? []).includes('patient.edit');
  const clearFilters = () => directory.change({ branch_id: '', practitioner_id: '', active_care: '', specialty: '' });

  const actions: RowActions = {
    profileHref: (patient) => (links.profile ? links.profile.replace('__ID__', String(patient.id)) : null),
    editHref: (patient: DirectoryPatient) => (canEdit && patient.status !== 'merged' ? route('clinic.patients.edit', patient.id) : null),
    checkIn: intake.available ? (patient) => intake.open('repeat', { id: patient.id, uhid: patient.uhid, name: patient.name }) : null,
  };

  // New patient: at the front desk the desk's own intake (register and check in in one step); anyone else the full form.
  const newPatient =
    intake.available && intake.canRegister ? (
      <DsButton variant="primary" size="lg" icon={UserPlus} onClick={() => intake.open('new')}>
        New patient
      </DsButton>
    ) : links.create ? (
      <DsButtonLink href={links.create} variant="primary" size="lg" icon={UserPlus}>
        New patient
      </DsButtonLink>
    ) : null;

  const filterFields = (
    <FilterFields
      filters={filters}
      doctors={doctors}
      branches={branches}
      doctorLabel={mode.doctor}
      onChange={(patch) => directory.change(patch)}
    />
  );

  return (
    <div className="space-y-5 motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-300">
      {/* The header card in the front desk's treatment. */}
      <section aria-labelledby="patients-title" className="rounded-3xl border border-[#DCE6F3] bg-gradient-to-br from-[#EAF2FF] via-[#F5F1FF] to-[#E6F6F2] px-4 py-5 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_18px_40px_-28px_rgba(23,59,96,0.45)] sm:px-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="flex min-w-0 items-center gap-3">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#E3F6F2] to-[#E6F0FB] text-[#0F7C6E] ring-1 ring-inset ring-white/70">
              <Users className="h-5 w-5" strokeWidth={2} aria-hidden="true" />
            </span>
            <div className="min-w-0">
              <h1 id="patients-title" className="text-[22px] font-bold leading-8 tracking-tight text-cx-nav-ink sm:text-[24px]">
                Patients
              </h1>
              <p className="text-[14px] leading-[22px] text-[#475569]">Manage patient records and access patient history</p>
            </div>
          </div>
          {/* One way to add a patient: the registration flow holds both the quick fields and "More details". */}
          {newPatient && <div className="shrink-0">{newPatient}</div>}
        </div>
      </section>

      {/* Search, views and filters. */}
      <section aria-label="Find patients" className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative min-w-0 flex-1 basis-64">
            <label htmlFor="patients-search" className="sr-only">
              Search patients by name, patient ID or mobile
            </label>
            <Search className="pointer-events-none absolute left-4 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-[#64748B]" aria-hidden="true" />
            <input
              id="patients-search"
              type="search"
              value={directory.query}
              onChange={(event) => directory.search(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Escape' && directory.query !== '') {
                  event.preventDefault();
                  directory.search('');
                }
              }}
              placeholder="Name, patient ID or mobile"
              autoComplete="off"
              className="h-12 w-full rounded-xl border border-[#DCE6F3] bg-white pl-11 pr-12 text-[15px] text-[#173B60] shadow-[0_1px_2px_rgba(15,23,42,0.04)] placeholder:text-[#64748B] focus:border-[#1769AA] focus:outline-none focus:ring-2 focus:ring-[#1769AA]/25"
            />
            {directory.query !== '' && (
              <button type="button" onClick={() => directory.search('')} aria-label="Clear search" className={cn('absolute right-2 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-[#64748B] hover:bg-[#F1F5F9]', FOCUS_RING)}>
                <X className="h-4 w-4" aria-hidden="true" />
              </button>
            )}
          </div>
          {/* Phones: the filters live in a sheet. */}
          <DsButton size="lg" icon={SlidersHorizontal} onClick={() => setSheetOpen(true)} aria-haspopup="dialog" className="md:hidden">
            {activeFilters > 0 ? `Filters (${activeFilters})` : 'Filters'}
          </DsButton>
        </div>

        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          {views.length > 1 ? (
            <div role="tablist" aria-label="Which patients" className="flex w-fit max-w-full gap-1 overflow-x-auto rounded-xl bg-[#EEF2F7] p-1 [scrollbar-width:none]">
              {views.map((view) => {
                const current = view.key === filters.view;

                return (
                  <button
                    key={view.key}
                    type="button"
                    role="tab"
                    id={`view-${view.key}`}
                    aria-selected={current}
                    aria-controls="patients-list"
                    onClick={() => !current && directory.change({ view: view.key })}
                    className={cn(
                      'flex h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-[13px] font-semibold motion-safe:transition-colors [@media(pointer:coarse)]:h-11',
                      FOCUS_RING,
                      current ? 'bg-white text-[#173B60] shadow-[0_1px_3px_rgba(15,23,42,0.12)]' : 'text-[#64748B] hover:text-[#173B60]',
                    )}
                  >
                    <span aria-hidden="true" className={cn('h-2 w-2 rounded-full', TONE[view.tone].solid)} />
                    {view.label}
                  </button>
                );
              })}
            </div>
          ) : (
            <span />
          )}
          <div className="hidden flex-wrap items-center gap-2 md:flex">
            {filterFields}
            {activeFilters > 0 && (
              <button type="button" onClick={clearFilters} className={cn('h-9 rounded-lg px-2 text-[13px] font-semibold text-[#1769AA] hover:bg-[#EAF2FA]', FOCUS_RING)}>
                Clear filters ({activeFilters})
              </button>
            )}
          </div>
        </div>
      </section>

      <DsDialog
        open={sheetOpen}
        onOpenChange={setSheetOpen}
        title="Filters"
        description="Narrow the list. Search and the view stay as they are."
        footer={
          <>
            {activeFilters > 0 && (
              <DsButton variant="ghost" onClick={clearFilters}>
                Clear filters
              </DsButton>
            )}
            <DsButton variant="primary" onClick={() => setSheetOpen(false)}>
              Done
            </DsButton>
          </>
        }
      >
        <div className="grid gap-3">{filterFields}</div>
      </DsDialog>

      {/* The list. */}
      <section
        id="patients-list"
        role={views.length > 1 ? 'tabpanel' : undefined}
        aria-labelledby={views.length > 1 ? `view-${filters.view}` : 'patients-list-title'}
        className="relative min-w-0 overflow-hidden rounded-2xl border border-[#E6EBF1] bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04),0_10px_28px_-22px_rgba(23,59,96,0.35)]"
      >
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[#E6EBF1] bg-gradient-to-r from-[#F4F8FD] to-white px-4 py-3.5 sm:px-5">
          <div className="flex min-w-0 items-center gap-3">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#E3F6F2] to-[#E6F0FB] text-[#0F7C6E] ring-1 ring-inset ring-white/70">
              {searching ? <Search className="h-5 w-5" aria-hidden="true" /> : <Users className="h-5 w-5" aria-hidden="true" />}
            </span>
            <div className="min-w-0">
              <h2 id="patients-list-title" className="truncate text-[17px] font-semibold leading-6 text-[#173B60]">
                {listTitle(filters, searching)}
              </h2>
              <p className="truncate text-[12.5px] leading-5 text-[#64748B]">{searching ? `Matching “${filters.q}”` : listSubtitle(filters.view)}</p>
            </div>
          </div>
          {multiBranch && (
            <Pill tone="violet" icon={MapPin}>
              {branchName ?? `All ${clinic?.accessible_branch_count} branches`}
            </Pill>
          )}
        </header>
        {directory.loading && <span aria-hidden="true" className="absolute inset-x-0 top-0 z-10 h-0.5 bg-[#1769AA] motion-safe:animate-pulse" />}

        <div aria-live="polite" aria-busy={directory.loading || undefined}>
          {directory.failed ? (
            <EmptyNote
              icon={TriangleAlert}
              tone="rose"
              title="Patients could not be loaded"
              action={
                <DsButton variant="secondary" onClick={directory.retry}>
                  Try again
                </DsButton>
              }
            >
              Nothing was changed. Check the connection and try again.
            </EmptyNote>
          ) : patients.data.length === 0 && directory.loading ? (
            <PatientTableSkeleton />
          ) : patients.data.length === 0 ? (
            <Empty
              searchingOrFiltered={searching || activeFilters > 0 || filters.specialty !== ''}
              query={filters.q}
              view={filters.view}
              canSeeAll={options.views.includes('all') && filters.view !== 'all'}
              onClear={() => directory.clearAll()}
              onSeeAll={() => directory.change({ view: 'all' })}
              newPatient={newPatient}
            />
          ) : (
            <PatientTable patients={patients.data} mode={mode} actions={actions} busy={directory.loading} />
          )}
        </div>

        {!directory.failed && (patients.prev_cursor || patients.next_cursor) && (
          <nav aria-label="Pages of patients" className="flex items-center justify-between gap-3 border-t border-[#E6EBF1] px-4 py-3 sm:px-5">
            <DsButton size="sm" icon={ChevronLeft} disabled={!patients.prev_cursor || directory.loading} onClick={() => directory.page(patients.prev_cursor)}>
              Newer
            </DsButton>
            <span className="text-[12.5px] tabular-nums text-[#64748B]">
              {patients.data.length} {patients.data.length === 1 ? 'patient' : 'patients'} on this page
            </span>
            <DsButton size="sm" disabled={!patients.next_cursor || directory.loading} onClick={() => directory.page(patients.next_cursor)}>
              Older
              <ChevronRight className="h-4 w-4" aria-hidden="true" />
            </DsButton>
          </nav>
        )}
      </section>
    </div>
  );
}

function listTitle(filters: DirectoryFilters, searching: boolean): string {
  if (searching) {
    return 'Search results';
  }

  return filters.view === 'today' ? 'Today’s patients' : filters.view === 'mine' ? 'My patients' : 'All patients';
}

function listSubtitle(view: DirectoryView): string {
  return view === 'today' ? 'Everyone who attended today, new and repeat' : view === 'mine' ? 'Patients assigned to you' : 'Newest registrations first';
}

/** The filters, the same fields beside the views from `md` and in the phone's sheet. */
function FilterFields({
  filters,
  doctors,
  branches,
  doctorLabel,
  onChange,
}: {
  filters: DirectoryFilters;
  doctors: { id: number; name: string }[];
  branches: { id: number; name: string }[];
  doctorLabel: string;
  onChange: (patch: Partial<DirectoryFilters>) => void;
}) {
  return (
    <>
      {doctors.length > 0 && (
        <FilterSelect
          label={doctorLabel}
          value={filters.practitioner_id}
          anyLabel={`Any ${doctorLabel.toLowerCase()}`}
          options={[{ value: 'none', label: 'Not assigned' }, ...doctors.map((doctor) => ({ value: String(doctor.id), label: doctor.name }))]}
          onChange={(value) => onChange({ practitioner_id: value })}
        />
      )}
      {branches.length > 1 && (
        <FilterSelect label="Branch" value={filters.branch_id} anyLabel="All my branches" options={branches.map((branch) => ({ value: String(branch.id), label: branch.name }))} onChange={(value) => onChange({ branch_id: value })} />
      )}
      <div className="flex h-11 items-center rounded-xl border border-[#D5DDE7] bg-white px-3.5 md:h-10">
        <Switch checked={filters.active_care === '1'} onCheckedChange={(on) => onChange({ active_care: on ? '1' : '' })} label="Ongoing treatment" />
      </div>
    </>
  );
}

function FilterSelect({ label, value, anyLabel, options, onChange }: { label: string; value: string; anyLabel: string; options: { value: string; label: string }[]; onChange: (value: string) => void }) {
  const id = `filter-${label.toLowerCase().replace(/\W+/g, '-')}`;

  return (
    <div className="min-w-0 md:w-44">
      <label htmlFor={id} className="mb-1.5 block text-[12px] font-semibold text-[#334155] md:sr-only">
        {label}
      </label>
      <Select id={id} value={value} placeholder={anyLabel} options={options} onChange={(event) => onChange(event.target.value)} className={cn('md:h-10', value && 'border-[#1769AA]/50 bg-[#F5F9FF] font-semibold text-[#1769AA]')} />
    </div>
  );
}

function Empty({
  searchingOrFiltered,
  query,
  view,
  canSeeAll,
  onClear,
  onSeeAll,
  newPatient,
}: {
  searchingOrFiltered: boolean;
  query: string;
  view: DirectoryView;
  canSeeAll: boolean;
  onClear: () => void;
  onSeeAll: () => void;
  newPatient: ReactNode;
}) {
  if (searchingOrFiltered) {
    return (
      <EmptyNote
        icon={SearchX}
        tone="slate"
        title={query ? `No patient matches “${query}”` : 'No patient matches these filters'}
        action={
          <DsButton variant="secondary" onClick={onClear}>
            Clear search and filters
          </DsButton>
        }
      >
        {query ? 'Search matches the start of a name, a patient ID or a mobile number. Try fewer letters, or the mobile number from its first digit.' : 'Loosen a filter to see more patients.'}
      </EmptyNote>
    );
  }
  if (view === 'today') {
    return (
      <EmptyNote icon={Users} tone="amber" title="Nobody has attended yet today" action={canSeeAll ? <DsButton variant="secondary" onClick={onSeeAll}>See all patients</DsButton> : undefined}>
        New and returning patients appear here once they are checked in today.
      </EmptyNote>
    );
  }
  if (view === 'mine') {
    return (
      <EmptyNote icon={UserRound} tone="violet" title="No patients assigned to you yet" action={canSeeAll ? <DsButton variant="secondary" onClick={onSeeAll}>See all patients</DsButton> : undefined}>
        Patients appear here once they are assigned to you.
      </EmptyNote>
    );
  }

  return (
    <EmptyNote icon={Users} tone="blue" title="No patients registered yet" action={newPatient}>
      Register the first patient to start their record.
    </EmptyNote>
  );
}
