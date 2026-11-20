import { useState, type ReactNode } from 'react';
import { ChevronLeft, ChevronRight, Loader2, MapPin, Search, SearchX, SlidersHorizontal, TriangleAlert, UserPlus, UserRound, Users, UsersRound, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useClinic } from '@/clinic/context';
import { DsButton, DsButtonLink, DsDialog, EmptyNote, FOCUS_RING, Pill, TONE, type DsTone } from '@/clinic/ds';
import { Button, ButtonLink } from '@/clinic/ui/Button';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { useIntake } from '@/clinic/shell/IntakeContext';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { Field, Input, Select, Switch } from '@/clinic/ui/controls';
import { deskMode } from '@/pages/ClinicPortal/FrontDesk/components/desk';
import { PatientTable, type RowActions } from './components/directory/PatientTable';
import { useDirectoryQuery } from './components/directory/useDirectoryQuery';
import type { DirectoryFilters, DirectoryPatient, DirectoryProps, DirectoryView } from './components/types';

/** The views, in the order the directory offers them; each shows only when the server offers it to this member. */
const VIEWS: { key: DirectoryView; label: string; tone: DsTone }[] = [
  { key: 'today', label: 'Today', tone: 'amber' },
  { key: 'all', label: 'All patients', tone: 'blue' },
  { key: 'mine', label: 'My patients', tone: 'violet' },
];

/** The 9 operational filter keys supported server-side. */
const FILTER_KEYS = [
  'branch_id',
  'practitioner_id',
  'gender',
  'status',
  'treatment_status',
  'active_care',
  'patient_type',
  'age_min',
  'age_max',
  'registered_from',
  'registered_to',
  'last_visit_from',
  'last_visit_to',
  'specialty',
] as const;

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

  const views = options.views.map((key) => VIEWS.find((v) => v.key === key)).filter(Boolean) as { key: DirectoryView; label: string; tone: DsTone }[];
  const multiBranch = (clinic?.accessible_branch_count ?? 0) > 1;
  // Branches the member may open; a list cut short by the shared limit is not offered as a filter.
  const branches = clinic && multiBranch && clinic.accessible_branches.length >= clinic.accessible_branch_count ? clinic.accessible_branches : [];
  // In a doctor's own list the doctor is the filter.
  const doctors = filters.view === 'mine' ? [] : options.doctors;
  const activeFilters = [
    filters.branch_id,
    filters.practitioner_id,
    filters.gender,
    filters.status,
    filters.treatment_status || filters.active_care,
    filters.patient_type,
    filters.age_min || filters.age_max,
    filters.registered_from || filters.registered_to,
    filters.last_visit_from || filters.last_visit_to,
    filters.specialty,
  ].filter(Boolean).length;
  const searching = filters.q !== '';
  const branchName = branches.find((branch) => String(branch.id) === filters.branch_id)?.name;
  const canEdit = (clinic?.permissions ?? []).includes('patient.edit');
  const clearFilters = () =>
    directory.change({
      branch_id: '',
      practitioner_id: '',
      gender: '',
      status: '',
      treatment_status: '',
      active_care: '',
      patient_type: '',
      age_min: '',
      age_max: '',
      registered_from: '',
      registered_to: '',
      last_visit_from: '',
      last_visit_to: '',
      specialty: '',
    });

  const actions: RowActions = {
    profileHref: (patient) => (links.profile ? links.profile.replace('__ID__', String(patient.id)) : null),
    editHref: (patient: DirectoryPatient) => (canEdit && patient.status !== 'merged' ? route('clinic.patients.edit', patient.id) : null),
    checkIn: intake.available ? (patient) => intake.open('repeat', { id: patient.id, uhid: patient.uhid, name: patient.name }) : null,
  };

  // New patient: at the front desk the desk's own intake (register and check in in one step); anyone else the full form.
  // New patient: at the front desk the desk's own intake (register and check in in one step); anyone else the full form.
  const newPatient =
    intake.available && intake.canRegister ? (
      <Button
        tone="primary"
        size="md"
        icon={UserPlus}
        onClick={() => intake.open('new')}
        className="!h-10 rounded-full px-5 text-[13.5px] font-semibold bg-[#1769AA] text-white shadow-[0_2px_6px_rgba(23,105,170,0.25)] hover:bg-[#135A93]"
      >
        New Patient
      </Button>
    ) : links.create ? (
      <ButtonLink
        href={links.create}
        tone="primary"
        size="md"
        icon={UserPlus}
        className="!h-10 rounded-full px-5 text-[13.5px] font-semibold bg-[#1769AA] text-white shadow-[0_2px_6px_rgba(23,105,170,0.25)] hover:bg-[#135A93]"
      >
        New Patient
      </ButtonLink>
    ) : null;

  return (
    <div className="w-full space-y-4 pb-12 motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-300">
      <PageHeader
        icon={UsersRound}
        title={
          <span className="inline-flex flex-wrap items-center gap-2.5">
            Patient Directory
            {multiBranch && (
              <span className="inline-flex shrink-0 items-center gap-1 rounded-full border border-purple-200/90 bg-purple-50/90 px-2.5 py-0.5 text-[11px] font-bold text-purple-700">
                <MapPin className="h-3 w-3" />
                <span className="hidden sm:inline">{branchName ?? `All ${clinic?.accessible_branch_count} branches`}</span>
                <span className="sm:hidden">{clinic?.accessible_branch_count} br</span>
              </span>
            )}
          </span>
        }
        description="Permanent patient records and clinical history across all branches"
        actions={newPatient}
      />

      {/* Compact Search & Filter Toolbar: Owner Dashboard white surface card */}
      <section
        aria-label="Find and filter patients"
        className="rounded-2xl border border-cx-line/80 bg-white p-3.5 shadow-cx-card sm:p-4"
      >
        <div className="flex flex-col gap-2.5 md:flex-row md:items-center md:gap-3">
          {/* Row 1 on mobile: Segmented tabs on left, Filters on right. On md+: contents unwraps them into the main flex row */}
          <div className="flex items-center justify-between gap-2 md:contents">
            {/* Segmented Tabs: Today / All / Mine (exact Owner Dashboard PeriodSwitch design) */}
            {views.length > 1 && (
              <div
                role="tablist"
                aria-label="Which patients"
                className="inline-flex shrink-0 items-center rounded-full bg-white p-1 shadow-[inset_0_0_0_1px_rgba(213,219,230,0.9)] md:order-1"
              >
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
                        'inline-flex min-h-9 items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 sm:px-4 text-[12.5px] sm:text-[13px] font-medium transition-colors',
                        FOCUS_RING,
                        current
                          ? 'bg-cx-teal-bg text-cx-teal-ink font-semibold shadow-2xs'
                          : 'text-cx-text-2 hover:text-cx-nav-ink',
                      )}
                    >
                      <span
                        aria-hidden="true"
                        className={cn(
                          'h-1.5 w-1.5 rounded-full',
                          current ? 'bg-cx-teal-ink' : 'bg-slate-300',
                        )}
                      />
                      {view.label}
                    </button>
                  );
                })}
              </div>
            )}

            {/* Filters button with active filter count (on mobile: Row 1 right; min-h-11 for 44px touch target) */}
            <div className="flex shrink-0 items-center gap-1.5 md:order-5">
              <button
                type="button"
                onClick={() => setSheetOpen(true)}
                className={cn(
                  'inline-flex min-h-11 md:min-h-10 items-center gap-1.5 rounded-xl border px-3 text-[12.5px] font-semibold transition-all',
                  activeFilters > 0
                    ? 'border-[#1769AA]/50 bg-[#E7F0FD] text-[#1769AA] shadow-xs font-bold'
                    : 'border-[#D5DDE7] bg-white text-[#173B60] hover:border-[#1769AA]/50 hover:bg-[#F5F8FC]',
                )}
              >
                <SlidersHorizontal className="h-3.5 w-3.5" />
                <span>Filters</span>
                {activeFilters > 0 && (
                  <span className="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-[#1769AA] px-1 text-[10.5px] font-bold text-white">
                    {activeFilters}
                  </span>
                )}
              </button>
              {activeFilters > 0 && (
                <button
                  type="button"
                  onClick={clearFilters}
                  className="min-h-11 md:min-h-10 px-2 text-[12px] font-semibold text-rose-600 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition-colors"
                >
                  Clear
                </button>
              )}
            </div>
          </div>

          {/* Row 2 on mobile: Search Input (full width). On md+: order-2 flex-1 */}
          <div className="relative min-w-0 w-full md:flex-1 md:order-2">
            <label htmlFor="patients-search" className="sr-only">
              Search patients by name, UHID or phone
            </label>
            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
              <Search className="h-4 w-4 text-[#8C9BAE]" aria-hidden="true" />
            </div>
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
              placeholder="Search by name, UHID or phone…"
              autoComplete="off"
              className="h-10 w-full rounded-xl border border-[#D5DDE7] bg-[#F8FAFD] pl-10 pr-9 text-[13px] text-[#173B60] placeholder:text-[#8C9BAE] shadow-none transition-colors focus:border-[#1769AA] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#1769AA]/15"
            />
            {directory.loading ? (
              <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                <Loader2 className="h-4 w-4 animate-spin text-[#1769AA]" aria-hidden="true" />
              </div>
            ) : directory.query !== '' ? (
              <button
                type="button"
                onClick={() => directory.search('')}
                aria-label="Clear search"
                className="absolute inset-y-0 right-0 flex items-center pr-2.5 text-[#8C9BAE] hover:text-[#173B60]"
              >
                <span className="flex h-6 w-6 items-center justify-center rounded-md hover:bg-[#E8EEF5]">
                  <X className="h-3.5 w-3.5" aria-hidden="true" />
                </span>
              </button>
            ) : null}
          </div>

          {/* Doctor Quick Filter (where appropriate: when options.doctors > 0 and not 'mine' view) */}
          {doctors.length > 0 && (
            <div className="hidden md:block w-36 lg:w-44 shrink-0 md:order-3">
              <label htmlFor="toolbar-doctor-filter" className="sr-only">
                {mode.doctor}
              </label>
              <Select
                id="toolbar-doctor-filter"
                value={filters.practitioner_id}
                placeholder={`All ${mode.doctor.toLowerCase()}s`}
                options={doctors.map((d) => ({ value: String(d.id), label: d.name }))}
                onChange={(e) => directory.change({ practitioner_id: e.target.value })}
                className={cn(
                  '!h-10 rounded-xl border border-[#D5DDE7] bg-[#F8FAFD] text-[12.5px] text-[#173B60] transition-colors focus:border-[#1769AA] focus:bg-white',
                  filters.practitioner_id && 'border-[#1769AA]/50 bg-[#E7F0FD] font-bold text-[#1769AA]',
                )}
              />
            </div>
          )}

          {/* Branch Quick Filter (where multi-branch with accessible branches > 1) */}
          {branches.length > 1 && (
            <div className="hidden lg:block w-32 xl:w-40 shrink-0 md:order-4">
              <label htmlFor="toolbar-branch-filter" className="sr-only">
                Branch
              </label>
              <Select
                id="toolbar-branch-filter"
                value={filters.branch_id}
                placeholder="All branches"
                options={branches.map((b) => ({ value: String(b.id), label: b.name }))}
                onChange={(e) => directory.change({ branch_id: e.target.value })}
                className={cn(
                  '!h-10 rounded-xl border border-[#D5DDE7] bg-[#F8FAFD] text-[12.5px] text-[#173B60] transition-colors focus:border-[#1769AA] focus:bg-white',
                  filters.branch_id && 'border-[#1769AA]/50 bg-[#E7F0FD] font-bold text-[#1769AA]',
                )}
              />
            </div>
          )}
        </div>

        {/* Active filter badges / quick remove */}
        {(activeFilters > 0 || searching) && (
          <div className="mt-2.5 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-2 text-[12px]">
            <span className="font-semibold text-slate-400">Active:</span>
            {searching && (
              <FilterBadge label={`Search: “${directory.query}”`} onRemove={() => directory.search('')} />
            )}
            {filters.practitioner_id && (
              <FilterBadge
                label={`${mode.doctor}: ${filters.practitioner_id === 'none' ? 'Not assigned' : (doctors.find((d) => String(d.id) === filters.practitioner_id)?.name ?? filters.practitioner_id)}`}
                onRemove={() => directory.change({ practitioner_id: '' })}
              />
            )}
            {filters.branch_id && (
              <FilterBadge
                label={`Branch: ${branchName ?? filters.branch_id}`}
                onRemove={() => directory.change({ branch_id: '' })}
              />
            )}
            {filters.gender && (
              <FilterBadge
                label={`Gender: ${filters.gender.charAt(0).toUpperCase() + filters.gender.slice(1)}`}
                onRemove={() => directory.change({ gender: '' })}
              />
            )}
            {filters.status && (
              <FilterBadge
                label={`Status: ${filters.status.charAt(0).toUpperCase() + filters.status.slice(1)}`}
                onRemove={() => directory.change({ status: '' })}
              />
            )}
            {(filters.treatment_status || filters.active_care === '1') && (
              <FilterBadge
                label={`Treatment: ${
                  filters.treatment_status === 'completed'
                    ? 'Completed'
                    : filters.treatment_status === 'not_started'
                    ? 'Not started'
                    : 'Ongoing'
                }`}
                tone="amber"
                onRemove={() => directory.change({ treatment_status: '', active_care: '' })}
              />
            )}
            {filters.patient_type && (
              <FilterBadge
                label={`Type: ${filters.patient_type === 'new' ? 'New Patient' : 'Repeat Patient'}`}
                onRemove={() => directory.change({ patient_type: '' })}
              />
            )}
            {(filters.age_min || filters.age_max) && (
              <FilterBadge
                label={
                  filters.age_min && filters.age_max
                    ? `Age: ${filters.age_min}–${filters.age_max} yrs`
                    : filters.age_min
                    ? `Age: ≥ ${filters.age_min} yrs`
                    : `Age: ≤ ${filters.age_max} yrs`
                }
                onRemove={() => directory.change({ age_min: '', age_max: '' })}
              />
            )}
            {(filters.registered_from || filters.registered_to) && (
              <FilterBadge
                label={
                  filters.registered_from && filters.registered_to
                    ? `Registered: ${filters.registered_from} to ${filters.registered_to}`
                    : filters.registered_from
                    ? `Registered from ${filters.registered_from}`
                    : `Registered before ${filters.registered_to}`
                }
                onRemove={() => directory.change({ registered_from: '', registered_to: '' })}
              />
            )}
            {(filters.last_visit_from || filters.last_visit_to) && (
              <FilterBadge
                label={
                  filters.last_visit_from && filters.last_visit_to
                    ? `Last visit: ${filters.last_visit_from} to ${filters.last_visit_to}`
                    : filters.last_visit_from
                    ? `Last visit from ${filters.last_visit_from}`
                    : `Last visit before ${filters.last_visit_to}`
                }
                onRemove={() => directory.change({ last_visit_from: '', last_visit_to: '' })}
              />
            )}
            {filters.specialty && (
              <FilterBadge
                label={`Specialty: ${filters.specialty}`}
                onRemove={() => directory.change({ specialty: '' })}
              />
            )}
            <button
              type="button"
              onClick={clearFilters}
              className="ml-1 text-[12px] font-semibold text-[#1769AA] hover:underline"
            >
              Reset all
            </button>
          </div>
        )}
      </section>

      {/* Comprehensive Filter Dialog covering all 9 filter criteria */}
      <DsDialog
        open={sheetOpen}
        onOpenChange={setSheetOpen}
        title="Filter Patients"
        description="Narrow the patient list. Each choice applies straight away."
        footer={
          <>
            {activeFilters > 0 && (
              <DsButton variant="ghost" onClick={clearFilters}>
                Clear all filters
              </DsButton>
            )}
            <DsButton variant="primary" onClick={() => setSheetOpen(false)}>
              Done
            </DsButton>
          </>
        }
      >
        <div className="max-h-[70vh] space-y-6 overflow-y-auto pr-1">
          {/* Section 1: Demographics */}
          <div>
            <h3 className="mb-2 text-[12px] font-bold uppercase tracking-wider text-[#64748B]">Demographics</h3>
            <div className="grid gap-3 sm:grid-cols-3">
              <Field label="Gender">
                <Select
                  value={filters.gender}
                  placeholder="All Genders"
                  options={[
                    { value: 'male', label: 'Male' },
                    { value: 'female', label: 'Female' },
                    { value: 'other', label: 'Other' },
                  ]}
                  onChange={(e) => directory.change({ gender: e.target.value })}
                />
              </Field>
              <Field label="Min Age">
                <Input
                  type="number"
                  min="0"
                  max="130"
                  placeholder="e.g. 18"
                  value={filters.age_min}
                  onChange={(e) => directory.change({ age_min: e.target.value })}
                />
              </Field>
              <Field label="Max Age">
                <Input
                  type="number"
                  min="0"
                  max="130"
                  placeholder="e.g. 65"
                  value={filters.age_max}
                  onChange={(e) => directory.change({ age_max: e.target.value })}
                />
              </Field>
            </div>
            {filters.age_min && filters.age_max && Number(filters.age_min) > Number(filters.age_max) && (
              <p className="mt-1.5 text-[12px] font-medium text-[#B42318]">Min age cannot be greater than max age.</p>
            )}
          </div>

          {/* Section 2: Clinical & Care */}
          <div>
            <h3 className="mb-2 text-[12px] font-bold uppercase tracking-wider text-[#64748B]">Clinical & Care</h3>
            <div className="grid gap-3 sm:grid-cols-2">
              {doctors.length > 0 && (
                <Field label={mode.doctor}>
                  <Select
                    value={filters.practitioner_id}
                    placeholder={`All ${mode.doctor.toLowerCase()}s`}
                    options={[{ value: 'none', label: 'Not assigned' }, ...doctors.map((d) => ({ value: String(d.id), label: d.name }))]}
                    onChange={(e) => directory.change({ practitioner_id: e.target.value })}
                  />
                </Field>
              )}
              {branches.length > 1 && (
                <Field label="Branch">
                  <Select
                    value={filters.branch_id}
                    placeholder="All accessible branches"
                    options={branches.map((b) => ({ value: String(b.id), label: b.name }))}
                    onChange={(e) => directory.change({ branch_id: e.target.value })}
                  />
                </Field>
              )}
              <Field label="Treatment Status">
                <Select
                  value={filters.treatment_status || (filters.active_care === '1' ? 'ongoing' : '')}
                  placeholder="All Treatments"
                  options={[
                    { value: 'ongoing', label: 'Ongoing treatment' },
                    { value: 'completed', label: 'Completed treatment' },
                    { value: 'not_started', label: 'Not started' },
                  ]}
                  onChange={(e) => {
                    const val = e.target.value;
                    directory.change({
                      treatment_status: val,
                      active_care: val === 'ongoing' ? '1' : '',
                    });
                  }}
                />
              </Field>
              <Field label="Patient Type">
                <Select
                  value={filters.patient_type}
                  placeholder="All Types"
                  options={[
                    { value: 'new', label: 'New Patient' },
                    { value: 'repeat', label: 'Repeat / Old Patient' },
                  ]}
                  onChange={(e) => directory.change({ patient_type: e.target.value })}
                />
              </Field>
              <Field label="Patient Status">
                <Select
                  value={filters.status}
                  placeholder="All Statuses"
                  options={[
                    { value: 'active', label: 'Active' },
                    { value: 'inactive', label: 'Inactive' },
                  ]}
                  onChange={(e) => directory.change({ status: e.target.value })}
                />
              </Field>
            </div>
          </div>

          {/* Section 3: Date Ranges */}
          <div>
            <h3 className="mb-2 text-[12px] font-bold uppercase tracking-wider text-[#64748B]">Date Ranges</h3>
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="rounded-xl border border-[#E6EBF1] bg-[#F8FAFC] p-3 space-y-2">
                <h4 className="text-[12.5px] font-semibold text-[#173B60]">Registration Date Range</h4>
                <div className="grid grid-cols-2 gap-2">
                  <Field label="From">
                    <Input
                      type="date"
                      value={filters.registered_from}
                      onChange={(e) => directory.change({ registered_from: e.target.value })}
                    />
                  </Field>
                  <Field label="To">
                    <Input
                      type="date"
                      value={filters.registered_to}
                      onChange={(e) => directory.change({ registered_to: e.target.value })}
                    />
                  </Field>
                </div>
                {filters.registered_from && filters.registered_to && filters.registered_from > filters.registered_to && (
                  <p className="text-[11.5px] font-medium text-[#B42318]">From date cannot be after To date.</p>
                )}
              </div>

              <div className="rounded-xl border border-[#E6EBF1] bg-[#F8FAFC] p-3 space-y-2">
                <h4 className="text-[12.5px] font-semibold text-[#173B60]">Last Visit Date Range</h4>
                <div className="grid grid-cols-2 gap-2">
                  <Field label="From">
                    <Input
                      type="date"
                      value={filters.last_visit_from}
                      onChange={(e) => directory.change({ last_visit_from: e.target.value })}
                    />
                  </Field>
                  <Field label="To">
                    <Input
                      type="date"
                      value={filters.last_visit_to}
                      onChange={(e) => directory.change({ last_visit_to: e.target.value })}
                    />
                  </Field>
                </div>
                {filters.last_visit_from && filters.last_visit_to && filters.last_visit_from > filters.last_visit_to && (
                  <p className="text-[11.5px] font-medium text-[#B42318]">From date cannot be after To date.</p>
                )}
              </div>
            </div>
          </div>
        </div>
      </DsDialog>

      {/* The list */}
      <section
        id="patients-list"
        role={views.length > 1 ? 'tabpanel' : undefined}
        aria-labelledby={views.length > 1 ? `view-${filters.view}` : 'patients-list-title'}
        className="relative min-w-0 overflow-hidden rounded-2xl border border-cx-line/80 bg-white shadow-cx-card"
      >
        <header className="flex items-center justify-between border-b border-cx-line/80 bg-gradient-to-r from-[#F8FAFD] via-white to-[#F8FAFD] px-4 py-3 sm:px-5">
          <div className="flex items-center gap-2.5">
            <h2 id="patients-list-title" className="text-[14.5px] font-bold text-[#173B60]">
              Patients
            </h2>
            <span className="inline-flex items-center rounded-full border border-blue-200/80 bg-blue-50/80 px-2.5 py-0.5 text-[11px] font-bold text-[#1769AA] shadow-2xs">
              {patients.data.length} {patients.data.length === 1 ? 'patient' : 'patients'}
              {patients.next_cursor || patients.prev_cursor ? ' on this page' : ''}
            </span>
          </div>
          <div className="flex items-center gap-2 text-[12px] font-medium text-[#5F6B7E]">
            <span className="hidden sm:inline">Sorted by:</span>
            <span className="rounded-md border border-[#D5DDE7] bg-white px-2 py-0.5 text-[11px] font-semibold text-[#173B60] shadow-2xs">
              Newest first
            </span>
          </div>
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
            <p role="status" className="px-5 py-10 text-center text-[13px] text-[#5F6B7E]">
              Finding patients…
            </p>
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

function FilterBadge({ label, tone = 'blue', onRemove }: { label: string; tone?: 'blue' | 'amber' | 'teal' | 'purple' | 'rose'; onRemove: () => void }) {
  const styles = {
    blue: 'border-blue-200 bg-blue-50 text-blue-700',
    amber: 'border-amber-200 bg-amber-50 text-amber-800',
    teal: 'border-teal-200 bg-teal-50 text-teal-700',
    purple: 'border-purple-200 bg-purple-50 text-purple-700',
    rose: 'border-rose-200 bg-rose-50 text-rose-700',
  }[tone];

  return (
    <span className={cn('inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11.5px] font-bold shadow-xs', styles)}>
      {label}
      <button
        type="button"
        onClick={onRemove}
        className="rounded p-0.5 opacity-70 transition-opacity hover:opacity-100"
        aria-label={`Remove filter ${label}`}
      >
        <X className="h-3 w-3" />
      </button>
    </span>
  );
}

function FilterSelect({ label, value, anyLabel, options, onChange }: { label: string; value: string; anyLabel: string; options: { value: string; label: string }[]; onChange: (value: string) => void }) {
  const id = `filter-${label.toLowerCase().replace(/\W+/g, '-')}`;

  return (
    <div className="min-w-0 md:w-44">
      <label htmlFor={id} className="sr-only">
        {label}
      </label>
      <Select
        id={id}
        value={value}
        placeholder={anyLabel}
        options={options}
        onChange={(event) => onChange(event.target.value)}
        className={cn('h-9 rounded-xl border border-slate-200 bg-slate-50/50 text-[12.5px] text-slate-700 transition-colors focus:border-blue-500 focus:bg-white', value && 'border-blue-300 bg-blue-50/60 font-bold text-blue-800')}
      />
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
      <div className="flex flex-col items-center justify-center px-4 py-8 sm:py-10 text-center">
        <span className="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-[#94A3B8] to-[#64748B] text-white shadow-[0_4px_10px_-6px_rgba(30,50,110,0.35)]">
          <SearchX className="h-5 w-5" strokeWidth={2} />
        </span>
        <h3 className="mt-3 text-[14px] font-bold text-[#173B60]">
          {query ? `No patient matches “${query}”` : 'No patient matches these filters'}
        </h3>
        <p className="mt-1 max-w-[42ch] text-[12.5px] leading-relaxed text-[#5F6B7E]">
          {query
            ? 'Search matches name, patient UHID or mobile number. Try fewer letters or verify the digits.'
            : 'Loosen or reset the active filters to see more patient records.'}
        </p>
        <div className="mt-3.5">
          <DsButton variant="secondary" size="sm" onClick={onClear}>
            Clear search & filters
          </DsButton>
        </div>
      </div>
    );
  }

  if (view === 'today') {
    return (
      <div className="flex flex-col items-center justify-center px-4 py-8 sm:py-10 text-center">
        <span className="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-[#2CC6B4] to-[#0E9F87] text-white shadow-[0_4px_10px_-6px_rgba(14,159,135,0.4)]">
          <UsersRound className="h-5 w-5" strokeWidth={2} />
        </span>
        <h3 className="mt-3 text-[14.5px] font-bold text-[#173B60]">
          Nobody has attended yet today
        </h3>
        <p className="mt-1 max-w-[40ch] text-[12.5px] leading-relaxed text-[#5F6B7E]">
          New and returning patients appear here automatically once checked in at Reception today.
        </p>
        {canSeeAll && (
          <div className="mt-3.5">
            <DsButton variant="secondary" size="sm" onClick={onSeeAll}>
              See all patients
            </DsButton>
          </div>
        )}
      </div>
    );
  }

  if (view === 'mine') {
    return (
      <div className="flex flex-col items-center justify-center px-4 py-8 sm:py-10 text-center">
        <span className="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-[#9B7BF2] to-[#6B48DE] text-white shadow-[0_4px_10px_-6px_rgba(107,72,222,0.4)]">
          <UserRound className="h-5 w-5" strokeWidth={2} />
        </span>
        <h3 className="mt-3 text-[14.5px] font-bold text-[#173B60]">
          No patients assigned to you yet
        </h3>
        <p className="mt-1 max-w-[40ch] text-[12.5px] leading-relaxed text-[#5F6B7E]">
          Patients assigned to you for consultation or treatment will appear here.
        </p>
        {canSeeAll && (
          <div className="mt-3.5">
            <DsButton variant="secondary" size="sm" onClick={onSeeAll}>
              See all patients
            </DsButton>
          </div>
        )}
      </div>
    );
  }

  return (
    <div className="flex flex-col items-center justify-center px-4 py-8 sm:py-10 text-center">
      <span className="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-[#4F9BF0] to-[#2B6FD8] text-white shadow-[0_4px_10px_-6px_rgba(43,111,216,0.4)]">
        <Users className="h-5 w-5" strokeWidth={2} />
      </span>
      <h3 className="mt-3 text-[14.5px] font-bold text-[#173B60]">
        No patients registered yet
      </h3>
      <p className="mt-1 max-w-[40ch] text-[12.5px] leading-relaxed text-[#5F6B7E]">
        Register the first patient to create their permanent clinical record.
      </p>
      {newPatient && <div className="mt-3.5">{newPatient}</div>}
    </div>
  );
}
