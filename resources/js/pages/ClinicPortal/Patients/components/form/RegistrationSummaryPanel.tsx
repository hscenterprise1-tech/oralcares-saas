import { AlertTriangle, Check, CheckCircle2, ChevronRight, Lock, ShieldCheck, UserCheck, UserPlus } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button, ButtonLink } from '@/clinic/ui/Button';
import { Checkbox, Textarea } from '@/clinic';
import type { DuplicateMatch } from '../../registration/types';

interface SummaryProps {
  firstName: string;
  middleName?: string;
  lastName?: string;
  phone: string;
  gender?: string;
  dateOfBirth?: string;
  age?: string;
  dobUnknown?: boolean;
  conditionsCount: number;
  allergiesCount: number;
  documentsCount: number;
  duplicateMatches: DuplicateMatch[];
  isCheckingDuplicates?: boolean;
  overrideConfirmed: boolean;
  overrideReason: string;
  onOverrideConfirmChange: (confirmed: boolean) => void;
  onOverrideReasonChange: (reason: string) => void;
  overrideReasonError?: string;
  onInspectMatch?: (match: DuplicateMatch) => void;
  saving: boolean;
  isEditMode?: boolean;
  cancelHref: string;
  onSubmit: () => void;
  submitLabel?: string;
}

export function RegistrationSummaryPanel({
  firstName,
  middleName,
  lastName,
  phone,
  gender,
  dateOfBirth,
  age,
  dobUnknown,
  conditionsCount,
  allergiesCount,
  documentsCount,
  duplicateMatches,
  isCheckingDuplicates = false,
  overrideConfirmed,
  overrideReason,
  onOverrideConfirmChange,
  onOverrideReasonChange,
  overrideReasonError,
  onInspectMatch,
  saving,
  isEditMode = false,
  cancelHref,
  onSubmit,
  submitLabel,
}: SummaryProps) {
  const fullName = [firstName, middleName, lastName].filter(Boolean).join(' ').trim();
  const initials = fullName
    ? fullName
        .split(' ')
        .map((n) => n[0])
        .slice(0, 2)
        .join('')
        .toUpperCase()
    : 'NP';

  const hasIdentity = firstName.trim().length > 0 && !!gender;
  const hasContact = phone.trim().length >= 10;
  const hasMedical = conditionsCount > 0 || allergiesCount > 0;
  const hasDocs = documentsCount > 0;

  const steps = [
    { label: 'Personal Information', completed: hasIdentity },
    { label: 'Contact Details', completed: hasContact },
    { label: 'Medical History', completed: hasMedical, optional: true },
    { label: 'Attached Records', completed: hasDocs, optional: true, count: documentsCount },
  ];

  const hasDuplicates = duplicateMatches.length > 0;

  return (
    <div className="sticky top-6 space-y-4">
      {/* Live Preview Card */}
      <div className="rounded-2xl border border-cx-line/80 bg-white p-5 shadow-cx-card">
        <div className="flex items-center gap-3.5">
          <div className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 text-[16px] font-extrabold text-white shadow-md shadow-blue-500/20">
            {initials}
          </div>
          <div className="min-w-0 flex-1">
            <h3 className="truncate text-[16px] font-semibold text-[#173B60]">
              {fullName || (isEditMode ? 'Patient Profile' : 'New Patient Registration')}
            </h3>
            <p className="truncate text-[12.5px] text-slate-500">
              {phone || 'No mobile provided yet'}
            </p>
          </div>
        </div>

        {/* Quick Demographic Badges */}
        <div className="mt-3.5 flex flex-wrap gap-1.5 border-t border-slate-100 pt-3">
          {gender && (
            <span className="rounded-full bg-blue-50 px-2.5 py-0.5 text-[11.5px] font-bold capitalize text-blue-700">
              {gender}
            </span>
          )}
          {dateOfBirth && (
            <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11.5px] font-medium text-slate-700">
              DOB {dateOfBirth}
            </span>
          )}
          {dobUnknown && age && (
            <span className="rounded-full bg-amber-50 px-2.5 py-0.5 text-[11.5px] font-bold text-amber-700">
              Approx. {age} yrs
            </span>
          )}
          {conditionsCount > 0 && (
            <span className="rounded-full bg-purple-50 px-2.5 py-0.5 text-[11.5px] font-bold text-purple-700">
              {conditionsCount} {conditionsCount === 1 ? 'condition' : 'conditions'}
            </span>
          )}
          {documentsCount > 0 && (
            <span className="rounded-full bg-amber-50 px-2.5 py-0.5 text-[11.5px] font-bold text-amber-800">
              {documentsCount} {documentsCount === 1 ? 'record' : 'records'}
            </span>
          )}
        </div>

        {/* Section Completion Progress */}
        <div className="mt-4 border-t border-slate-100 pt-3.5">
          <p className="mb-2.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">
            Profile Completion
          </p>
          <ul className="space-y-2 text-[12.5px]">
            {steps.map((s, idx) => (
              <li key={idx} className="flex items-center justify-between">
                <span
                  className={cn(
                    'flex items-center gap-2 font-medium',
                    s.completed ? 'text-slate-800' : 'text-slate-500',
                  )}
                >
                  <span
                    className={cn(
                      'grid h-4 w-4 place-items-center rounded-full text-[10px]',
                      s.completed ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-500',
                    )}
                  >
                    {s.completed ? <Check className="h-2.5 w-2.5 stroke-[3]" /> : idx + 1}
                  </span>
                  {s.label}
                </span>
                <span className="text-[11px] font-semibold text-slate-400">
                  {s.completed ? 'Complete' : s.optional ? 'Optional' : 'Required'}
                </span>
              </li>
            ))}
          </ul>
        </div>
      </div>

      {/* Duplicate Patient Alert Status (Real-time telemetry) */}
      {!isEditMode && (
        <div
          className={cn(
            'rounded-2xl border p-4 transition-all duration-200',
            hasDuplicates
              ? 'border-amber-300 bg-amber-50/70 shadow-sm'
              : 'border-cx-line/80 bg-white shadow-cx-card',
          )}
        >
          <div className="flex items-center justify-between">
            <h4
              className={cn(
                'flex items-center gap-2 text-[13.5px] font-bold',
                hasDuplicates ? 'text-amber-950' : 'text-slate-800',
              )}
            >
              {hasDuplicates ? (
                <AlertTriangle className="h-4 w-4 text-amber-600" />
              ) : isCheckingDuplicates ? (
                <div className="h-4 w-4 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" />
              ) : (
                <ShieldCheck className="h-4 w-4 text-emerald-600" />
              )}
              {hasDuplicates
                ? `${duplicateMatches.length} Possible Duplicate Found`
                : isCheckingDuplicates
                  ? 'Checking duplicates…'
                  : 'Duplicate Check Active'}
            </h4>
            <span
              className={cn(
                'rounded-md px-2 py-0.5 text-[11px] font-bold uppercase tracking-wider',
                hasDuplicates
                  ? 'bg-amber-100 text-amber-900 border border-amber-300'
                  : 'bg-emerald-50 text-emerald-800 border border-emerald-200',
              )}
            >
              {hasDuplicates ? 'Review Required' : 'Clean Record'}
            </span>
          </div>

          {hasDuplicates ? (
            <div className="mt-3 space-y-3">
              <p className="text-[12px] text-amber-900">
                Patients with matching phone or identity already exist in the system.
              </p>

              <ul className="space-y-1.5 max-h-48 overflow-y-auto pr-1">
                {duplicateMatches.map((match) => (
                  <li
                    key={match.id}
                    className="flex items-center justify-between gap-2 rounded-xl border border-amber-200 bg-white p-2.5 text-[12px]"
                  >
                    <div className="min-w-0">
                      <p className="font-bold text-slate-900 truncate">
                        {match.name} {match.uhid && <span className="font-normal text-slate-500">· {match.uhid}</span>}
                      </p>
                      <p className="text-slate-500 text-[11px] truncate">
                        {match.phone_hint || 'Phone matched'} · {match.gender ? match.gender : ''}
                      </p>
                    </div>
                    {match.restricted ? (
                      <span className="flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600">
                        <Lock className="h-3 w-3" /> Branch Restricted
                      </span>
                    ) : onInspectMatch ? (
                      <button
                        type="button"
                        onClick={() => onInspectMatch(match)}
                        className="rounded px-2 py-1 text-[11px] font-bold text-blue-700 hover:bg-blue-50"
                      >
                        Inspect
                      </button>
                    ) : null}
                  </li>
                ))}
              </ul>

              {/* Override Checkbox & Reason */}
              <div className="rounded-xl border border-amber-300/80 bg-amber-100/40 p-3 space-y-2">
                <Checkbox
                  checked={overrideConfirmed}
                  onCheckedChange={onOverrideConfirmChange}
                  label="I confirm this is a different person (override duplicate protection)"
                />
                {overrideConfirmed && (
                  <div className="pt-1">
                    <label className="block text-[11px] font-semibold text-amber-950 mb-1">
                      Reason for registration override <span className="text-rose-600">*</span>
                    </label>
                    <Textarea
                      rows={2}
                      value={overrideReason}
                      onChange={(e) => onOverrideReasonChange(e.target.value)}
                      placeholder="e.g. Family member (spouse/child) sharing the same primary mobile number."
                      className="text-[12.5px] bg-white"
                    />
                    {overrideReasonError && (
                      <p className="text-[11px] font-semibold text-rose-600 mt-1">
                        {overrideReasonError}
                      </p>
                    )}
                  </div>
                )}
              </div>
            </div>
          ) : (
            <p className="mt-2 text-[12px] text-slate-500">
              Live cross-database check verifies phone and demographic identity to prevent duplicate
              patient creation.
            </p>
          )}
        </div>
      )}

      {/* Primary Action Buttons */}
      <div className="rounded-2xl border border-cx-line/80 bg-white p-4 shadow-cx-card space-y-2.5">
        <Button
          type="button"
          tone="primary"
          onClick={onSubmit}
          loading={saving}
          loadingLabel={isEditMode ? 'Saving Changes…' : 'Registering Patient…'}
          disabled={saving}
          className="w-full justify-center h-11 text-[14.5px] font-bold shadow-md shadow-blue-500/15"
          icon={isEditMode ? undefined : UserPlus}
        >
          {submitLabel || (isEditMode ? 'Save Changes' : 'Register Patient')}
        </Button>

        <ButtonLink
          href={cancelHref}
          tone="ghost"
          className="w-full justify-center text-[13.5px] text-slate-600 hover:text-slate-900"
        >
          Cancel
        </ButtonLink>
      </div>
    </div>
  );
}
