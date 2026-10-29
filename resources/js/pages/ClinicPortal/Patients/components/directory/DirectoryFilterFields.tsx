import { useId } from 'react';
import { Select, Switch } from '@/clinic/ui/controls';
import type { DirectoryFilters } from '../types';

export type DirectoryFilterOptions = {
  specialties: { key: string; label: string }[];
  /** Empty when there is only one branch to choose from (no filter is shown). */
  branches: { id: number; name: string }[];
  /** Empty when the doctor filter is not offered (a doctor's own list). */
  doctors: { id: number; name: string }[];
};

/** A filter's label: shown above the control in the phone's Filters sheet, read out only beside the search from `md`. */
function FilterLabel({ htmlFor, children }: { htmlFor: string; children: string }) {
  return (
    <label htmlFor={htmlFor} className="mb-1.5 block text-[12px] font-semibold text-[#334155] md:sr-only">
      {children}
    </label>
  );
}

/**
 * The directory's filters, each one a parameter `PatientDirectory::query`
 * already supports: assigned doctor (or nobody), branch, specialty, and
 * whether the patient is in active care. There is deliberately no
 * lifecycle (active / inactive) filter. `doctorLabel` follows the clinic:
 * Dentist, Therapist or Doctor.
 */
export function DirectoryFilterFields({
  filters,
  options,
  doctorLabel,
  onChange,
}: {
  filters: DirectoryFilters;
  options: DirectoryFilterOptions;
  doctorLabel: string;
  onChange: (patch: Partial<DirectoryFilters>) => void;
}) {
  const id = useId();

  return (
    <>
      {options.doctors.length > 0 && (
        <div className="min-w-0 md:w-48">
          <FilterLabel htmlFor={`${id}-doctor`}>{doctorLabel}</FilterLabel>
          <Select
            id={`${id}-doctor`}
            value={filters.practitioner_id}
            placeholder={`Any ${doctorLabel.toLowerCase()}`}
            options={[{ value: 'none', label: 'Not assigned' }, ...options.doctors.map((doctor) => ({ value: String(doctor.id), label: doctor.name }))]}
            onChange={(event) => onChange({ practitioner_id: event.target.value })}
          />
        </div>
      )}
      {options.branches.length > 1 && (
        <div className="min-w-0 md:w-44">
          <FilterLabel htmlFor={`${id}-branch`}>Branch</FilterLabel>
          <Select
            id={`${id}-branch`}
            value={filters.branch_id}
            placeholder="All my branches"
            options={options.branches.map((branch) => ({ value: String(branch.id), label: branch.name }))}
            onChange={(event) => onChange({ branch_id: event.target.value })}
          />
        </div>
      )}
      {options.specialties.length > 1 && (
        <div className="min-w-0 md:w-44">
          <FilterLabel htmlFor={`${id}-specialty`}>Specialty</FilterLabel>
          <Select
            id={`${id}-specialty`}
            value={filters.specialty}
            placeholder="All specialties"
            options={options.specialties.map((specialty) => ({ value: specialty.key, label: specialty.label }))}
            onChange={(event) => onChange({ specialty: event.target.value })}
          />
        </div>
      )}
      <div className="flex h-11 items-center rounded-full md:border md:border-[#E2E8F0] md:bg-white md:px-3.5">
        <Switch checked={filters.active_care === '1'} onCheckedChange={(on) => onChange({ active_care: on ? '1' : '' })} label="In active care" />
      </div>
    </>
  );
}
