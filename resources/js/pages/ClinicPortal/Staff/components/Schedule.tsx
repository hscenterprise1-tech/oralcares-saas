import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Copy, MapPin, Plus, Star, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import type { ProfileProps } from '../lib/types';
import { Chip, Empty, Section } from '../lib/ui';

const DAYS = [
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
  { value: 0, label: 'Sunday' },
];

type Shift = { start: string; end: string };
type Week = Record<number, Shift[]>;

const timeInput =
  'h-11 w-[104px] rounded-xl border border-clinic-border bg-white px-2 text-[13px] tabular-nums text-clinic-ink focus:border-clinic-brand-strong focus:outline-none focus:ring-2 focus:ring-clinic-brand/25 sm:h-9';

/**
 * Where they work and when. Branch placements (primary, paused) and, for
 * practitioners, the weekly hours at each branch: each day a list of shifts,
 * the gaps between them being breaks. Appointment booking reads these hours.
 */
export function BranchesAndSchedule({ props }: { props: ProfileProps }) {
  const { employee, options, can, links } = props;
  const practitioner = props.practitioner !== null;
  const canEditBranches = can.edit;
  const [placements, setPlacements] = useState(employee.branches.map((branch) => ({ id: branch.id, name: branch.name, is_active: branch.is_active, editable: branch.editable })));
  const [primary, setPrimary] = useState<number | null>(employee.primary_branch?.id ?? null);
  const [addBranch, setAddBranch] = useState('');
  const [savingBranches, setSavingBranches] = useState(false);
  const [branchError, setBranchError] = useState<string | null>(null);
  const available = options.branches.filter((branch) => !placements.some((placement) => placement.id === branch.id));

  const saveBranches = () => {
    setSavingBranches(true);
    setBranchError(null);
    router.put(
      `${links.self}/branches`,
      { placements: placements.filter((placement) => placement.editable).map((placement) => ({ branch_id: placement.id, is_active: placement.is_active })), primary_branch_id: primary },
      { preserveScroll: true, onError: (errors) => setBranchError(Object.values(errors)[0] ?? 'Branches could not be saved.'), onFinish: () => setSavingBranches(false) },
    );
  };

  const scheduleBranches = placements.filter((placement) => placement.editable);

  return (
    <div className="grid gap-4">
      <Section
        title="Branches"
        aside={canEditBranches && <Button size="sm" tone="primary" loading={savingBranches} loadingLabel="Saving…" onClick={saveBranches}>Save branches</Button>}
      >
        {placements.length === 0 ? (
          <Empty>Not placed at a branch yet: they count as working at every branch.</Empty>
        ) : (
          <ul className="grid gap-2 sm:grid-cols-2">
            {placements.map((placement) => (
              <li key={placement.id} className={cn('flex min-h-[56px] items-center gap-3 rounded-2xl px-3.5 py-2.5 ring-1', placement.is_active ? 'bg-white ring-clinic-border' : 'bg-clinic-canvas ring-clinic-border')}>
                <MapPin className={cn('h-4 w-4 shrink-0', placement.is_active ? 'text-teal-700' : 'text-clinic-muted')} aria-hidden="true" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13.5px] font-bold text-clinic-ink">{placement.name}</span>
                  <span className="flex flex-wrap gap-1 pt-0.5">
                    {primary === placement.id && <Chip className="bg-teal-50 text-teal-800 ring-teal-200">Primary</Chip>}
                    {!placement.is_active && <Chip className="bg-slate-100 text-slate-700 ring-slate-200">Paused</Chip>}
                  </span>
                </span>
                {canEditBranches && placement.editable && (
                  <span className="flex shrink-0 items-center gap-1">
                    {primary !== placement.id && (
                      <button type="button" aria-label={`Make ${placement.name} primary`} onClick={() => setPrimary(placement.id)} className="flex h-11 w-11 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink sm:h-8 sm:w-8">
                        <Star className="h-4 w-4" aria-hidden="true" />
                      </button>
                    )}
                    <button
                      type="button"
                      onClick={() => setPlacements((current) => current.map((item) => (item.id === placement.id ? { ...item, is_active: !item.is_active } : item)))}
                      className="min-h-11 rounded-full px-2.5 text-[11.5px] font-semibold text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink sm:min-h-8"
                    >
                      {placement.is_active ? 'Pause' : 'Resume'}
                    </button>
                    <button
                      type="button"
                      aria-label={`Remove ${placement.name}`}
                      onClick={() => {
                        setPlacements((current) => current.filter((item) => item.id !== placement.id));
                        if (primary === placement.id) {
                          setPrimary(null);
                        }
                      }}
                      className="flex h-11 w-11 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-danger-soft hover:text-clinic-danger sm:h-8 sm:w-8"
                    >
                      <X className="h-4 w-4" aria-hidden="true" />
                    </button>
                  </span>
                )}
              </li>
            ))}
          </ul>
        )}
        {canEditBranches && available.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-2">
            <select aria-label="Add a branch" value={addBranch} onChange={(e) => setAddBranch(e.target.value)} className="h-11 min-w-0 flex-1 rounded-xl border border-clinic-border bg-white px-3 text-[13px] sm:max-w-[260px]">
              <option value="">Add a branch…</option>
              {available.map((branch) => (
                <option key={branch.id} value={branch.id}>{branch.name}</option>
              ))}
            </select>
            <Button
              icon={Plus}
              disabled={!addBranch}
              onClick={() => {
                const branch = options.branches.find((item) => item.id === Number(addBranch));
                if (branch) {
                  setPlacements((current) => [...current, { id: branch.id, name: branch.name, is_active: true, editable: true }]);
                  setPrimary((current) => current ?? branch.id);
                }
                setAddBranch('');
              }}
            >
              Add
            </Button>
          </div>
        )}
        {branchError && <p role="alert" className="mt-2 text-[12px] font-medium text-clinic-danger">{branchError}</p>}
      </Section>

      {practitioner && (
        scheduleBranches.length === 0 && options.branches.length === 0 ? null : (
          <WeeklySchedule props={props} branches={scheduleBranches.length > 0 ? scheduleBranches : options.branches.map((branch) => ({ ...branch, is_active: true, editable: true }))} />
        )
      )}
    </div>
  );
}

function WeeklySchedule({ props, branches }: { props: ProfileProps; branches: { id: number; name: string; is_active: boolean }[] }) {
  const { schedule, can, links } = props;
  const editable = can.practitioner;
  const [branchId, setBranchId] = useState(branches[0]?.id ?? 0);
  const load = (id: number): Week => {
    const stored = schedule?.weekly[String(id)] ?? {};
    const week: Week = {};
    DAYS.forEach((day) => {
      week[day.value] = (stored[String(day.value)] ?? []).map((shift) => ({ ...shift }));
    });

    return week;
  };
  const [week, setWeek] = useState<Week>(() => load(branchId));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setWeek(load(branchId));
    setErrors({});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branchId, schedule]);

  const update = (day: number, shifts: Shift[]) => setWeek((current) => ({ ...current, [day]: shifts }));
  const copyToWeekdays = (day: number) => setWeek((current) => {
    const next = { ...current };
    [1, 2, 3, 4, 5, 6].forEach((other) => {
      if (other !== day) {
        next[other] = current[day].map((shift) => ({ ...shift }));
      }
    });

    return next;
  });

  const save = () => {
    setSaving(true);
    router.put(
      `${links.self}/schedule`,
      { branch_id: branchId, days: Object.fromEntries(Object.entries(week).map(([day, shifts]) => [day, shifts])) },
      { preserveScroll: true, onError: (received) => setErrors(received as Record<string, string>), onFinish: () => setSaving(false) },
    );
  };

  const hours = (shifts: Shift[]) => {
    const minutes = shifts.reduce((sum, shift) => {
      const [sh, sm] = shift.start.split(':').map(Number);
      const [eh, em] = shift.end.split(':').map(Number);

      return sum + Math.max(0, eh * 60 + em - (sh * 60 + sm));
    }, 0);

    return minutes ? `${Math.floor(minutes / 60)}h${minutes % 60 ? ` ${minutes % 60}m` : ''}` : '';
  };

  return (
    <Section
      title="Working hours"
      aside={
        <div className="flex flex-wrap items-center gap-2">
          {branches.length > 1 && (
            <select aria-label="Branch" value={branchId} onChange={(e) => setBranchId(Number(e.target.value))} className="h-11 rounded-full border border-clinic-border bg-white pl-3.5 pr-9 text-[12.5px] font-semibold sm:h-9">
              {branches.map((branch) => (
                <option key={branch.id} value={branch.id}>{branch.name}</option>
              ))}
            </select>
          )}
          {editable && <Button size="sm" tone="primary" loading={saving} loadingLabel="Saving…" onClick={save}>Save hours</Button>}
        </div>
      }
    >
      <p className="mb-3 text-[12px] text-clinic-secondary">
        {branches.length === 1 ? `At ${branches[0].name}. ` : ''}Add a second shift for a split day; the gap between shifts is their break. A day with no shift is a day off.
      </p>
      {errors.branch_id && <p role="alert" className="mb-2 text-[12px] font-medium text-clinic-danger">{errors.branch_id}</p>}
      <ul className="divide-y divide-clinic-border rounded-2xl ring-1 ring-clinic-border">
        {DAYS.map((day) => {
          const shifts = week[day.value] ?? [];
          const dayError = Object.entries(errors).find(([key]) => key.startsWith(`days.${day.value}`))?.[1];

          return (
            <li key={day.value} className="flex flex-col gap-2 px-3.5 py-2.5 sm:flex-row sm:items-center">
              <span className="flex w-36 shrink-0 items-center gap-2">
                <span className={cn('h-2 w-2 rounded-full', shifts.length ? 'bg-emerald-500' : 'bg-slate-300')} aria-hidden="true" />
                <span className="text-[13px] font-bold text-clinic-ink">{day.label}</span>
                <span className="text-[11.5px] text-clinic-secondary">{hours(shifts)}</span>
              </span>
              <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                {shifts.length === 0 && <span className="text-[12.5px] text-clinic-secondary">Day off</span>}
                {shifts.map((shift, index) => (
                  <span key={index} className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-50/70 p-1 ring-1 ring-emerald-200">
                    <input aria-label={`${day.label} shift ${index + 1} start`} type="time" disabled={!editable} value={shift.start} onChange={(e) => update(day.value, shifts.map((item, i) => (i === index ? { ...item, start: e.target.value } : item)))} className={timeInput} />
                    <span className="text-[12px] text-clinic-secondary">to</span>
                    <input aria-label={`${day.label} shift ${index + 1} end`} type="time" disabled={!editable} value={shift.end} onChange={(e) => update(day.value, shifts.map((item, i) => (i === index ? { ...item, end: e.target.value } : item)))} className={timeInput} />
                    {editable && (
                      <button type="button" aria-label={`Remove ${day.label} shift ${index + 1}`} onClick={() => update(day.value, shifts.filter((_, i) => i !== index))} className="flex h-11 w-11 items-center justify-center rounded-full text-clinic-secondary hover:bg-white hover:text-clinic-danger sm:h-8 sm:w-8">
                        <X className="h-3.5 w-3.5" aria-hidden="true" />
                      </button>
                    )}
                  </span>
                ))}
                {editable && shifts.length < 4 && (
                  <button
                    type="button"
                    onClick={() => update(day.value, [...shifts, shifts.length === 0 ? { start: '09:00', end: '13:00' } : { start: '15:00', end: '19:00' }])}
                    className="inline-flex min-h-11 items-center gap-1 rounded-full px-2.5 text-[12px] font-semibold text-clinic-brand-strong hover:bg-clinic-brand-soft sm:min-h-8"
                  >
                    <Plus className="h-3.5 w-3.5" aria-hidden="true" /> {shifts.length === 0 ? 'Add hours' : 'Add shift'}
                  </button>
                )}
                {editable && shifts.length > 0 && day.value >= 1 && day.value <= 5 && (
                  <button type="button" onClick={() => copyToWeekdays(day.value)} className="inline-flex min-h-11 items-center gap-1 rounded-full px-2.5 text-[12px] font-semibold text-clinic-secondary hover:bg-clinic-inset sm:min-h-8">
                    <Copy className="h-3.5 w-3.5" aria-hidden="true" /> Copy to Mon–Sat
                  </button>
                )}
              </div>
              {dayError && <p role="alert" className="text-[12px] font-medium text-clinic-danger sm:w-48">{dayError}</p>}
            </li>
          );
        })}
      </ul>
    </Section>
  );
}
