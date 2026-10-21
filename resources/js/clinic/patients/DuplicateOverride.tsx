import { ShieldAlert } from 'lucide-react';
import { Checkbox, Field, Textarea } from '../ui/controls';

/** The shortest reason accepted for registering a possible duplicate (the server asks the same). */
export const DUPLICATE_REASON_MIN = 3;

/** Why the override cannot go yet, in words; null when it can. */
export function duplicateOverrideProblem(confirmed: boolean, reason: string): string | null {
  if (!confirmed || reason.trim().length < DUPLICATE_REASON_MIN) {
    return 'Tick the confirmation and say why this is a different person.';
  }

  return null;
}

/**
 * Registering someone who may already be a patient: every match checked,
 * and a reason, both recorded in the audit log. The one override used
 * wherever a duplicate can be registered (New Case, the full registration
 * page); what each surface shows about the matches is its own.
 */
export function DuplicateOverride({
  count,
  confirmed,
  reason,
  onChange,
  error,
  idPrefix,
  onReasonBlur,
}: {
  /** How many possible matches were found. */
  count: number;
  confirmed: boolean;
  reason: string;
  onChange: (patch: { confirmed?: boolean; reason?: string }) => void;
  error?: string;
  idPrefix: string;
  onReasonBlur?: () => void;
}) {
  return (
    <div className="space-y-3 rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
      <p className="flex items-start gap-2 text-[13px] font-semibold text-amber-900">
        <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        Registering anyway is recorded in the audit log with your reason.
      </p>
      <Checkbox
        checked={confirmed}
        onCheckedChange={(next) => onChange({ confirmed: next })}
        label={count === 1 ? 'I have checked: this is a different person' : `I have checked all ${count}: this is a different person`}
      />
      <Field label="Reason" htmlFor={`${idPrefix}-duplicate-reason`} error={error} required hint="For example: twin sharing the family phone.">
        <Textarea
          id={`${idPrefix}-duplicate-reason`}
          value={reason}
          onChange={(event) => onChange({ reason: event.target.value })}
          onBlur={onReasonBlur}
          invalid={!!error}
          maxLength={500}
          rows={2}
        />
      </Field>
    </div>
  );
}
