import React from 'react';
import { CrmFormField, CrmSelect } from '@/pages/Company/design';
import { cn } from '@/lib/utils';

/*
 * Who the deal belongs to.
 *
 * A deal goes to a person or to a team, never both — the Lead model throws on
 * the combination. Two independent dropdowns made that rule invisible until
 * somebody filled in both and got a 500, so the choice is expressed as what it
 * actually is: one decision with two shapes.
 *
 * Shared by Create Deal and Deal Detail deliberately. The rule is the same in
 * both places, and a rule implemented twice is a rule that will eventually be
 * implemented differently.
 */

export type AssignmentMode = 'person' | 'team' | 'auto';

export interface AssignmentValue {
  assigned_user_id: string;
  assigned_team_id: string;
}

interface Props {
  value: AssignmentValue;
  onChange: (next: AssignmentValue) => void;
  users: { id: number; name: string }[];
  teams: { id: number; name: string }[];
  /** Whether leaving it unassigned is an option — it is on creation. */
  allowAuto?: boolean;
  errors?: { assigned_user_id?: string; assigned_team_id?: string };
  disabled?: boolean;
  className?: string;
}

/** Which shape the current value is in. */
export function assignmentModeOf(value: AssignmentValue): AssignmentMode {
  if (value.assigned_team_id) return 'team';
  if (value.assigned_user_id) return 'person';

  return 'auto';
}

export function DealAssignment({
  value,
  onChange,
  users,
  teams,
  allowAuto = true,
  errors,
  disabled = false,
  className,
}: Props) {
  const [mode, setMode] = React.useState<AssignmentMode>(assignmentModeOf(value));

  /* Follow the value when it changes underneath — after a save, say. */
  React.useEffect(() => {
    setMode(assignmentModeOf(value));
  }, [value.assigned_user_id, value.assigned_team_id]);

  /*
   * Switching shape clears the other side rather than hiding it. Hiding a set
   * value is how a form ends up submitting something the user cannot see.
   */
  const chooseMode = (next: AssignmentMode) => {
    setMode(next);
    onChange({ assigned_user_id: '', assigned_team_id: '' });
  };

  const options: { key: AssignmentMode; label: string; hint: string }[] = [
    ...(allowAuto
      ? [{ key: 'auto' as const, label: 'Automatically', hint: 'Routing picks an owner' }]
      : []),
    { key: 'person', label: 'A person', hint: 'One rep owns it' },
    { key: 'team', label: 'A team', hint: 'The team decides who works it' },
  ];

  const noTeams = teams.length === 0;

  return (
    <div className={cn('min-w-0', className)}>
      <p className="mb-1.5 text-[11.5px] font-medium text-ovs-crm-text-secondary">Assign to</p>

      <div className="mb-3 flex flex-wrap gap-1.5">
        {options.map((option) => {
          const active = mode === option.key;
          const unavailable = option.key === 'team' && noTeams;

          return (
            <button
              key={option.key}
              type="button"
              disabled={disabled || unavailable}
              onClick={() => chooseMode(option.key)}
              aria-pressed={active}
              title={unavailable ? 'No sales teams set up yet.' : option.hint}
              className={cn(
                'rounded-full border px-3.5 py-1.5 text-[12.5px] font-medium transition',
                active
                  ? 'border-transparent bg-ovs-crm-ink text-white shadow-ovs-crmInk'
                  : 'border-ovs-crm-border bg-white text-ovs-crm-text-secondary hover:border-ovs-crm-border-strong hover:text-ovs-crm-text-primary',
                (disabled || unavailable) && 'cursor-not-allowed opacity-55 hover:border-ovs-crm-border'
              )}
            >
              {option.label}
            </button>
          );
        })}
      </div>

      {mode === 'person' && (
        <CrmFormField label="Owner" error={errors?.assigned_user_id}>
          <CrmSelect
            disabled={disabled}
            value={value.assigned_user_id}
            onChange={(e) => onChange({ assigned_user_id: e.target.value, assigned_team_id: '' })}
          >
            <option value="">Pick a person</option>
            {users.map((user) => (
              <option key={user.id} value={user.id}>{user.name}</option>
            ))}
          </CrmSelect>
        </CrmFormField>
      )}

      {mode === 'team' && (
        <CrmFormField
          label="Team"
          error={errors?.assigned_team_id}
          hint={noTeams ? 'No sales teams set up yet.' : undefined}
        >
          <CrmSelect
            disabled={disabled || noTeams}
            value={value.assigned_team_id}
            onChange={(e) => onChange({ assigned_user_id: '', assigned_team_id: e.target.value })}
          >
            <option value="">Pick a team</option>
            {teams.map((team) => (
              <option key={team.id} value={team.id}>{team.name}</option>
            ))}
          </CrmSelect>
        </CrmFormField>
      )}

      {mode === 'auto' && (
        <p className="text-[12px] leading-relaxed text-ovs-crm-text-muted">
          Left unassigned, routing gives this deal an owner when it is created.
        </p>
      )}
    </div>
  );
}
