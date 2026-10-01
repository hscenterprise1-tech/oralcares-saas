import { useState, type FormEvent, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Stethoscope } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PageHeader } from '@/clinic/page/PageHeader';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button, buttonClasses } from '@/clinic/ui/Button';
import { Field, inputClass, selectClass, textareaClass } from '@/clinic/ui/Modal';
import type { StaffTypeOption } from './lib/types';

interface FormEmployee {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  status: string;
  staff_type: string | null;
  date_of_birth: string | null;
  gender: string | null;
  joining_date: string | null;
  notes: string | null;
  primary_branch_id: number | null;
  department_id: number | null;
  branches: number[];
  roles: { id: number; name: string }[];
  has_login: boolean;
}

/**
 * Add or edit a staff member: who they are, what they do, where they work.
 * Clinical staff types get a practitioner profile, completed on the profile
 * page (qualifications, specialty, services, schedule).
 */
export default function StaffForm({
  employee,
  branches,
  departments,
  roles,
  staffTypes,
  statuses,
  canAssignRoles,
  links,
}: {
  employee: FormEmployee | null;
  branches: { id: number; name: string; is_main: boolean }[];
  departments: { id: number; name: string }[];
  roles: { id: number; name: string }[];
  staffTypes: StaffTypeOption[];
  statuses: { value: string; label: string }[];
  canAssignRoles: boolean;
  links: { submit: string; back: string };
}) {
  const [form, setForm] = useState({
    name: employee?.name ?? '',
    email: employee?.email ?? '',
    phone: employee?.phone ?? '',
    status: employee?.status ?? 'active',
    staff_type: employee?.staff_type ?? '',
    date_of_birth: employee?.date_of_birth ?? '',
    gender: employee?.gender ?? '',
    joining_date: employee?.joining_date ?? '',
    notes: employee?.notes ?? '',
    primary_branch_id: employee?.primary_branch_id ?? (branches.length === 1 ? branches[0].id : null),
    department_id: employee?.department_id ?? null,
    branches: employee?.branches ?? (branches.length === 1 ? [branches[0].id] : []),
    roles: (employee?.roles ?? []).map((role) => role.id),
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((current) => ({ ...current, [key]: value }));
  const clinical = staffTypes.find((type) => type.value === form.staff_type)?.clinical ?? false;

  const toggleBranch = (id: number) => {
    const next = form.branches.includes(id) ? form.branches.filter((value) => value !== id) : [...form.branches, id];
    setForm((current) => ({
      ...current,
      branches: next,
      primary_branch_id: next.includes(current.primary_branch_id ?? -1) ? current.primary_branch_id : (next[0] ?? null),
    }));
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    const payload = {
      ...form,
      email: form.email || null,
      phone: form.phone || null,
      staff_type: form.staff_type || null,
      date_of_birth: form.date_of_birth || null,
      gender: form.gender || null,
      joining_date: form.joining_date || null,
      notes: form.notes || null,
      ...(canAssignRoles ? {} : { roles: undefined }),
    };
    const options = {
      preserveScroll: true,
      onError: (received: Record<string, string>) => setErrors(received),
      onFinish: () => setSaving(false),
    };
    if (employee) {
      router.put(links.submit, payload, options);
    } else {
      router.post(links.submit, payload, options);
    }
  };

  const error = (key: string) => errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

  return (
    <ClinicPortalShell title={employee ? `Edit ${employee.name}` : 'Add staff'}>
      <Link href={links.back} className="mb-3 inline-flex min-h-11 items-center gap-1.5 rounded-full text-[12.5px] font-semibold text-clinic-secondary hover:text-clinic-ink">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" /> {employee ? 'Back to profile' : 'Back to staff'}
      </Link>
      <PageHeader eyebrow="Staff" title={employee ? `Edit ${employee.name}` : 'Add staff'} description="Only the name is required. Fill in what applies to this person's work." />

      <form onSubmit={submit} className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]" noValidate>
        <div className="grid gap-4">
          <Card title="Who they are">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Full name" htmlFor="sf-name" error={error('name')} className="sm:col-span-2">
                <input id="sf-name" className={inputClass} value={form.name} onChange={(e) => set('name', e.target.value)} autoComplete="off" />
              </Field>
              <Field label="Mobile" htmlFor="sf-phone" error={error('phone')}>
                <input id="sf-phone" inputMode="tel" className={inputClass} value={form.phone} onChange={(e) => set('phone', e.target.value)} />
              </Field>
              <Field label="Email" htmlFor="sf-email" error={error('email')} hint="Needed to invite them to sign in.">
                <input id="sf-email" type="email" className={inputClass} value={form.email} onChange={(e) => set('email', e.target.value)} />
              </Field>
              <Field label="Date of birth (optional)" htmlFor="sf-dob" error={error('date_of_birth')}>
                <input id="sf-dob" type="date" className={inputClass} value={form.date_of_birth} onChange={(e) => set('date_of_birth', e.target.value)} />
              </Field>
              <Field label="Gender (optional)" htmlFor="sf-gender" error={error('gender')}>
                <select id="sf-gender" className={selectClass} value={form.gender} onChange={(e) => set('gender', e.target.value)}>
                  <option value="">Not recorded</option>
                  <option value="female">Female</option>
                  <option value="male">Male</option>
                  <option value="other">Other</option>
                </select>
              </Field>
            </div>
          </Card>

          <Card title="Their work">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Staff type" htmlFor="sf-type" error={error('staff_type')}>
                <select id="sf-type" className={selectClass} value={form.staff_type} onChange={(e) => set('staff_type', e.target.value)}>
                  <option value="">Choose a type</option>
                  {staffTypes.map((type) => (
                    <option key={type.value} value={type.value}>
                      {type.label}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Department (optional)" htmlFor="sf-department" error={error('department_id')}>
                <select id="sf-department" className={selectClass} value={form.department_id ?? ''} onChange={(e) => set('department_id', e.target.value ? Number(e.target.value) : null)}>
                  <option value="">None</option>
                  {departments.map((department) => (
                    <option key={department.id} value={department.id}>
                      {department.name}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Joining date (optional)" htmlFor="sf-joining" error={error('joining_date')}>
                <input id="sf-joining" type="date" className={inputClass} value={form.joining_date} onChange={(e) => set('joining_date', e.target.value)} />
              </Field>
              <Field label="Employment status" htmlFor="sf-status" error={error('status')}>
                <select id="sf-status" className={selectClass} value={form.status} onChange={(e) => set('status', e.target.value)}>
                  {statuses.map((status) => (
                    <option key={status.value} value={status.value}>
                      {status.value === 'resigned' ? 'Archived' : status.label}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            {clinical && (
              <p className="mt-4 flex items-start gap-2 rounded-2xl bg-violet-50 px-3.5 py-3 text-[12.5px] text-violet-900">
                <Stethoscope className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                A clinical profile is created for them. Add qualifications, specialty, registration, services and working hours on their profile.
              </p>
            )}
            <Field label="Notes (optional)" htmlFor="sf-notes" error={error('notes')} className="mt-4">
              <textarea id="sf-notes" rows={3} maxLength={2000} className={textareaClass} value={form.notes} onChange={(e) => set('notes', e.target.value)} />
            </Field>
          </Card>
        </div>

        <div className="grid content-start gap-4">
          <Card title="Branches">
            {branches.length === 0 ? (
              <p className="text-[12.5px] text-clinic-secondary">You do not have access to any branch.</p>
            ) : (
              <ul className="space-y-1.5">
                {branches.map((branch) => {
                  const on = form.branches.includes(branch.id);

                  return (
                    <li key={branch.id} className={cn('flex min-h-11 items-center gap-2.5 rounded-xl px-2.5 py-1.5', on ? 'bg-clinic-brand-soft/60' : 'bg-clinic-canvas')}>
                      <input
                        id={`sf-branch-${branch.id}`}
                        type="checkbox"
                        checked={on}
                        onChange={() => toggleBranch(branch.id)}
                        className="h-4 w-4 rounded border-clinic-border-strong text-clinic-brand-strong focus:ring-clinic-brand-strong"
                      />
                      <label htmlFor={`sf-branch-${branch.id}`} className="min-w-0 flex-1 cursor-pointer truncate text-[13px] font-semibold text-clinic-ink">
                        {branch.name}
                      </label>
                      {on && (
                        <label className="flex min-h-11 cursor-pointer items-center gap-1.5 text-[11.5px] font-semibold text-clinic-secondary sm:min-h-0">
                          <input
                            type="radio"
                            name="primary"
                            checked={form.primary_branch_id === branch.id}
                            onChange={() => set('primary_branch_id', branch.id)}
                            className="h-4 w-4 border-clinic-border-strong text-clinic-brand-strong focus:ring-clinic-brand-strong"
                          />
                          Primary
                        </label>
                      )}
                    </li>
                  );
                })}
              </ul>
            )}
            {(error('branches') || error('primary_branch_id')) && (
              <p role="alert" className="mt-2 text-[12px] font-medium text-clinic-danger">
                {error('branches') ?? error('primary_branch_id')}
              </p>
            )}
          </Card>

          {canAssignRoles && (
            <Card title="Role">
              <p className="mb-2 text-[11.5px] text-clinic-secondary">What they may do in the portal. {employee?.has_login ? '' : 'Applied when they accept their invitation.'}</p>
              <div className="flex flex-wrap gap-1.5">
                {roles.map((role) => {
                  const on = form.roles.includes(role.id);

                  return (
                    <button
                      key={role.id}
                      type="button"
                      aria-pressed={on}
                      onClick={() => set('roles', on ? form.roles.filter((id) => id !== role.id) : [...form.roles, role.id])}
                      className={cn(
                        'min-h-11 rounded-full px-3.5 text-[12.5px] font-semibold ring-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:min-h-9',
                        on ? 'bg-clinic-ink text-white ring-clinic-ink' : 'bg-white text-clinic-ink ring-clinic-border',
                      )}
                    >
                      {role.name}
                    </button>
                  );
                })}
              </div>
              {error('roles') && <p className="mt-2 text-[12px] font-medium text-clinic-danger">{error('roles')}</p>}
            </Card>
          )}

          <div className="flex flex-wrap justify-end gap-2">
            <Link href={links.back} className={buttonClasses('ghost', 'lg')}>
              Cancel
            </Link>
            <Button tone="primary" size="lg" type="submit" loading={saving} loadingLabel="Saving…">
              {employee ? 'Save changes' : 'Add staff'}
            </Button>
          </div>
        </div>
      </form>
    </ClinicPortalShell>
  );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section aria-label={title} className="rounded-3xl border border-white/70 bg-white/[0.93] p-4 backdrop-blur-sm sm:p-5">
      <h2 className="mb-3 text-[15px] font-bold tracking-tight text-clinic-ink">{title}</h2>
      {children}
    </section>
  );
}
