import { useId } from 'react';
import { Calendar, UserRound } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Checkbox, Field, Input, Select } from '@/clinic';
import { ageFromDob } from '../../registration/types';
import { SectionCard } from './SectionCard';

export interface PersonalInfoData {
  first_name: string;
  middle_name: string;
  last_name: string;
  gender: string;
  date_of_birth: string;
  dob_unknown: boolean;
  age: string;
  blood_group: string;
}

interface PersonalInfoSectionProps {
  data: PersonalInfoData;
  onChange: <K extends keyof PersonalInfoData>(field: K, value: PersonalInfoData[K]) => void;
  errors: Partial<Record<keyof PersonalInfoData, string>>;
  lastNameRequired?: boolean;
  autoFocus?: boolean;
}

const GENDERS = [
  { value: 'female', label: 'Female' },
  { value: 'male', label: 'Male' },
  { value: 'other', label: 'Other' },
];

const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((group) => ({
  value: group,
  label: group,
}));

export function PersonalInfoSection({
  data,
  onChange,
  errors,
  lastNameRequired = false,
  autoFocus = false,
}: PersonalInfoSectionProps) {
  const baseId = useId();
  const calculatedAge = !data.dob_unknown && data.date_of_birth ? ageFromDob(data.date_of_birth) : null;

  return (
    <SectionCard
      id="section-personal-info"
      title="Personal Information"
      subtitle="Permanent demographic identity and patient identification."
      icon={UserRound}
      tone="blue"
      badge="Identity"
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-6">
        {/* First Name */}
        <div className="sm:col-span-2">
          <Field
            label="First Name"
            htmlFor={`${baseId}-first_name`}
            required
            error={errors.first_name}
          >
            <Input
              id={`${baseId}-first_name`}
              value={data.first_name}
              onChange={(e) => onChange('first_name', e.target.value)}
              placeholder="e.g. Rahul"
              autoFocus={autoFocus}
              autoComplete="given-name"
              invalid={!!errors.first_name}
              className="h-10 text-[14px]"
            />
          </Field>
        </div>

        {/* Middle Name */}
        <div className="sm:col-span-2">
          <Field
            label="Middle Name"
            htmlFor={`${baseId}-middle_name`}
            error={errors.middle_name}
          >
            <Input
              id={`${baseId}-middle_name`}
              value={data.middle_name}
              onChange={(e) => onChange('middle_name', e.target.value)}
              placeholder="Optional"
              autoComplete="additional-name"
              invalid={!!errors.middle_name}
              className="h-10 text-[14px]"
            />
          </Field>
        </div>

        {/* Last Name */}
        <div className="sm:col-span-2">
          <Field
            label="Last Name"
            htmlFor={`${baseId}-last_name`}
            required={lastNameRequired}
            error={errors.last_name}
          >
            <Input
              id={`${baseId}-last_name`}
              value={data.last_name}
              onChange={(e) => onChange('last_name', e.target.value)}
              placeholder="e.g. Sharma"
              autoComplete="family-name"
              invalid={!!errors.last_name}
              className="h-10 text-[14px]"
            />
          </Field>
        </div>

        {/* Gender Selection */}
        <div className="sm:col-span-3">
          <label className="mb-1.5 block text-[12px] font-semibold text-slate-700">
            Gender <span className="text-rose-500">*</span>
          </label>
          <div className="grid grid-cols-3 gap-2">
            {GENDERS.map((g) => {
              const selected = data.gender === g.value;
              return (
                <button
                  key={g.value}
                  type="button"
                  onClick={() => onChange('gender', g.value)}
                  className={cn(
                    'flex h-10 items-center justify-center rounded-xl border text-[13.5px] font-medium transition-all duration-150',
                    selected
                      ? 'border-blue-600 bg-blue-50/80 font-bold text-blue-700 shadow-sm ring-1 ring-blue-500'
                      : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50',
                  )}
                >
                  {g.label}
                </button>
              );
            })}
          </div>
          {errors.gender && (
            <p className="mt-1 text-[12px] font-medium text-rose-600">{errors.gender}</p>
          )}
        </div>

        {/* Blood Group */}
        <div className="sm:col-span-3">
          <Field
            label="Blood Group"
            htmlFor={`${baseId}-blood_group`}
            error={errors.blood_group}
            hint="Optional clinical reference"
          >
            <Select
              id={`${baseId}-blood_group`}
              value={data.blood_group}
              onChange={(e) => onChange('blood_group', e.target.value)}
              options={BLOOD_GROUPS}
              placeholder="Select blood group"
              invalid={!!errors.blood_group}
              className="h-10 text-[14px]"
            />
          </Field>
        </div>

        {/* Date of Birth & Age Row */}
        <div className="rounded-xl border border-slate-100 bg-slate-50/70 p-3.5 sm:col-span-6">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:items-end">
            {!data.dob_unknown ? (
              <Field
                label="Date of Birth"
                htmlFor={`${baseId}-date_of_birth`}
                error={errors.date_of_birth}
                hint={
                  calculatedAge !== null ? (
                    <span className="font-semibold text-blue-700">
                      Calculated Age: {calculatedAge} {calculatedAge === 1 ? 'year' : 'years'}
                    </span>
                  ) : (
                    'Enter birth date for auto-calculated age'
                  )
                }
              >
                <div className="relative">
                  <Input
                    id={`${baseId}-date_of_birth`}
                    type="date"
                    max={new Date().toISOString().slice(0, 10)}
                    value={data.date_of_birth}
                    onChange={(e) => onChange('date_of_birth', e.target.value)}
                    invalid={!!errors.date_of_birth}
                    className="h-10 text-[14px]"
                  />
                  <Calendar className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                </div>
              </Field>
            ) : (
              <Field
                label="Approximate Age (Years)"
                htmlFor={`${baseId}-age`}
                required
                error={errors.age}
                hint="Estimated age when birth date is unknown"
              >
                <Input
                  id={`${baseId}-age`}
                  inputMode="numeric"
                  placeholder="e.g. 35"
                  value={data.age}
                  onChange={(e) =>
                    onChange('age', e.target.value.replace(/\D/g, '').slice(0, 3))
                  }
                  invalid={!!errors.age}
                  className="h-10 text-[14px]"
                />
              </Field>
            )}

            <div className="flex h-10 items-center">
              <Checkbox
                id={`${baseId}-dob_unknown`}
                checked={data.dob_unknown}
                onCheckedChange={(checked: boolean) => {
                  onChange('dob_unknown', checked);
                  if (checked) {
                    onChange('date_of_birth', '');
                  } else {
                    onChange('age', '');
                  }
                }}
                label="Date of birth not known (approximate age)"
              />
            </div>
          </div>
        </div>
      </div>
    </SectionCard>
  );
}
