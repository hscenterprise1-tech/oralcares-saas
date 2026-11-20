import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { UserPlus, UserCog } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Input, Select, Textarea } from '@/clinic/ui/controls';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';

/** `EmployeeController::formProps`: the server is the contract. */
interface Props {
  employee: {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    status: string | null;
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
  } | null;
  branches: { id: number; name: string; is_main: boolean }[];
  departments: { id: number; name: string }[];
  roles: { id: number; name: string }[];
  staffTypes: { value: string; label: string; clinical: boolean }[];
  statuses: { value: string; label: string }[];
  canAssignRoles: boolean;
  links: { submit: string; back: string };
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white p-5 shadow-cx-card';

export default function StaffForm(props: Props) {
  return (
    <ClinicPortalShell title={props.employee ? 'Edit staff' : 'Add staff'}>
      <StaffFormPage {...props} />
    </ClinicPortalShell>
  );
}

/** Add a staff member, or change their details, branches and roles. A new person has no login until invited from their profile. */
function StaffFormPage({ employee, branches, departments, roles, staffTypes, statuses, canAssignRoles, links }: Props) {
  const form = useForm({
    name: employee?.name ?? '',
    email: employee?.email ?? '',
    phone: employee?.phone ?? '',
    status: employee?.status ?? 'active',
    staff_type: employee?.staff_type ?? '',
    date_of_birth: employee?.date_of_birth ?? '',
    gender: employee?.gender ?? '',
    joining_date: employee?.joining_date ?? '',
    notes: employee?.notes ?? '',
    department_id: employee?.department_id ? String(employee.department_id) : '',
    primary_branch_id: employee?.primary_branch_id ? String(employee.primary_branch_id) : '',
    branches: (employee?.branches ?? (branches.length === 1 ? [branches[0].id] : [])) as number[],
    roles: (employee?.roles.map((role) => role.id) ?? []) as number[],
  });
  const errors = form.errors as Record<string, string | undefined>;
  const flip = (key: 'branches' | 'roles', id: number, on: boolean) =>
    form.setData(key, on ? [...form.data[key], id] : form.data[key].filter((value) => value !== id));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const options = { preserveScroll: true };
    const payload = (data: typeof form.data) => ({
      ...data,
      department_id: data.department_id || null,
      primary_branch_id: data.primary_branch_id || null,
      staff_type: data.staff_type || null,
      gender: data.gender || null,
      date_of_birth: data.date_of_birth || null,
      joining_date: data.joining_date || null,
      // A person who may not hand out roles sends none, so the server keeps what they have.
      ...(canAssignRoles ? {} : { roles: undefined }),
    });
    form.transform(payload as never);
    if (employee) {
      form.put(links.submit, options);
    } else {
      form.post(links.submit, options);
    }
  };

  return (
    <form onSubmit={submit} className="w-full pb-12" noValidate>
      <PageHeader
        icon={employee ? UserCog : UserPlus}
        title={employee ? `Edit ${employee.name}` : 'Add staff'}
        description={employee ? 'Change their details, where they work and what they may do.' : 'Add the person first. You can invite them to sign in from their profile.'}
        actions={
          <>
            <Link href={links.back} className="inline-flex h-10 items-center rounded-xl px-4 text-[13.5px] font-semibold text-cx-text-2 hover:text-cx-nav-ink">
              Cancel
            </Link>
            <Button type="submit" tone="primary" loading={form.processing}>
              {employee ? 'Save changes' : 'Add staff'}
            </Button>
          </>
        }
      />

      {Object.keys(errors).length > 0 && (
        <Notice tone="danger" className="mb-4">
          {errors.status ?? errors.roles ?? errors.branches ?? 'Some details need attention.'}
        </Notice>
      )}

      <div className="grid gap-4 xl:grid-cols-3">
        <section className={cn(SURFACE, 'space-y-4 xl:col-span-2')} aria-label="Details">
          <h2 className="text-[14px] font-semibold text-cx-nav-ink">Details</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Full name" htmlFor="st-name" required error={errors.name}>
              <Input id="st-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={255} invalid={!!errors.name} />
            </Field>
            <Field label="Role at the clinic" htmlFor="st-type" error={errors.staff_type}>
              <Select id="st-type" value={form.data.staff_type} onChange={(e) => form.setData('staff_type', e.target.value)} options={[{ value: '', label: 'Choose' }, ...staffTypes.map((t) => ({ value: t.value, label: t.label }))]} />
            </Field>
            <Field label="Email" htmlFor="st-email" error={errors.email} hint="Needed to invite them to sign in.">
              <Input id="st-email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} invalid={!!errors.email} />
            </Field>
            <Field label="Phone" htmlFor="st-phone" error={errors.phone}>
              <Input id="st-phone" type="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} maxLength={50} />
            </Field>
            <Field label="Status" htmlFor="st-status" error={errors.status}>
              <Select id="st-status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} options={statuses.map((s) => ({ value: s.value, label: s.label }))} />
            </Field>
            <Field label="Joining date" htmlFor="st-join" error={errors.joining_date}>
              <Input id="st-join" type="date" value={form.data.joining_date} onChange={(e) => form.setData('joining_date', e.target.value)} />
            </Field>
            <Field label="Date of birth" htmlFor="st-dob" error={errors.date_of_birth}>
              <Input id="st-dob" type="date" value={form.data.date_of_birth} onChange={(e) => form.setData('date_of_birth', e.target.value)} />
            </Field>
            <Field label="Gender" htmlFor="st-gender">
              <Select
                id="st-gender"
                value={form.data.gender}
                onChange={(e) => form.setData('gender', e.target.value)}
                options={[{ value: '', label: 'Not set' }, { value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }, { value: 'other', label: 'Other' }]}
              />
            </Field>
          </div>
          <Field label="Notes" htmlFor="st-notes" error={errors.notes}>
            <Textarea id="st-notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={2000} />
          </Field>
        </section>

        <div className="space-y-4">
          <section className={SURFACE} aria-label="Where they work">
            <h2 className="mb-3 text-[14px] font-semibold text-cx-nav-ink">Where they work</h2>
            {branches.length === 0 ? (
              <p className="text-[13px] text-cx-text-2">No branch is open to you.</p>
            ) : (
              <div className="space-y-2.5">
                {branches.map((branch) => (
                  <Checkbox key={branch.id} checked={form.data.branches.includes(branch.id)} onCheckedChange={(on) => flip('branches', branch.id, on)} label={`${branch.name}${branch.is_main ? ' (main)' : ''}`} />
                ))}
              </div>
            )}
            {errors.branches && <p role="alert" className="mt-2 text-[12px] font-medium text-[#B42318]">{errors.branches}</p>}
            <div className="mt-4 grid gap-4">
              <Field label="Main branch" htmlFor="st-primary" error={errors.primary_branch_id}>
                <Select id="st-primary" value={form.data.primary_branch_id} onChange={(e) => form.setData('primary_branch_id', e.target.value)} options={[{ value: '', label: 'Not set' }, ...branches.filter((b) => form.data.branches.includes(b.id)).map((b) => ({ value: String(b.id), label: b.name }))]} />
              </Field>
              {departments.length > 0 && (
                <Field label="Department" htmlFor="st-dept">
                  <Select id="st-dept" value={form.data.department_id} onChange={(e) => form.setData('department_id', e.target.value)} options={[{ value: '', label: 'None' }, ...departments.map((d) => ({ value: String(d.id), label: d.name }))]} />
                </Field>
              )}
            </div>
          </section>

          <section className={SURFACE} aria-label="What they may do">
            <h2 className="mb-1 text-[14px] font-semibold text-cx-nav-ink">What they may do</h2>
            <p className="mb-3 text-[12.5px] text-cx-text-3">Roles decide what each person sees and can change. The owner sets these.</p>
            {canAssignRoles ? (
              <div className="space-y-2.5">
                {roles.map((role) => (
                  <Checkbox key={role.id} checked={form.data.roles.includes(role.id)} onCheckedChange={(on) => flip('roles', role.id, on)} label={humanize(role.name)} />
                ))}
                {errors.roles && <p role="alert" className="text-[12px] font-medium text-[#B42318]">{errors.roles}</p>}
              </div>
            ) : (
              <p className="text-[13px] text-cx-text-2">
                {employee && employee.roles.length > 0 ? employee.roles.map((role) => humanize(role.name)).join(', ') : 'You may not change roles.'}
              </p>
            )}
          </section>
        </div>
      </div>
    </form>
  );
}
