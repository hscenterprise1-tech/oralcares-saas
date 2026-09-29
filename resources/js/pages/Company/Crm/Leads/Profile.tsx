import React from 'react';
import { router, useForm } from '@inertiajs/react';
import {
  ArrowLeft,
  Building2,
  IndianRupee,
  MapPin,
  Megaphone,
  Plus,
  Stethoscope,
  Trash2,
  Users,
} from 'lucide-react';
import {
  CrmButton,
  CrmCard,
  CrmFormField,
  CrmIconButton,
  CrmInput,
  CrmSection,
  CrmSelect,
  CrmShell,
  CrmStatusPill,
  CrmTextarea,
  inr,
} from '@/pages/Company/design';
import { cn } from '@/lib/utils';
import { DealAssignment } from './components/DealAssignment';
import { DealPeople } from './components/DealPeople';
import type { DealBranch, DealProfileProps } from './deal';

/**
 * Z6 — the organisation profile.
 *
 * Everything the create form captures, on one page, in create-form order:
 * organisation, location, branches, contacts, qualification, commercial and
 * attribution. One form, one save, one validation pass.
 *
 * This replaces six inline editors on the deal page. Each of those owned a
 * slice of the same record, each resent the company name to satisfy a rule
 * none of them needed, and any two could be open at once with no warning and
 * no idea which draft was real. Reference data is edited rarely and
 * completely; that is a page, not six drawers.
 *
 * Attribution is shown and not edited — a record somebody can rewrite is not
 * a record.
 */

const DEPLOYMENT: Record<string, string> = {
  cloud: 'Cloud',
  on_premise: 'On premise',
  hybrid: 'Hybrid',
};

const PRIORITIES: Record<string, string> = {
  high: 'Chase it',
  medium: 'Normal',
  low: 'Low priority',
};

const asList = (raw: string) => raw.split(',').map((s) => s.trim()).filter(Boolean);

const blankBranch = (isFirst: boolean): DealBranch => ({
  name: '',
  is_main: isFirst,
  address: '',
  area: '',
  city: '',
  state: '',
  country: 'India',
  postal_code: '',
  contact_name: '',
  phone: '',
  email: '',
});

/**
 * The editor, remounted whenever the deal's main contact changes.
 *
 * `useForm` captures its defaults once. Promoting somebody else to main
 * contact rewrites the deal's own name, email and phone on the server, and
 * without a remount those three fields kept showing the previous person — so
 * the next save would have written the old name back over the promotion. The
 * key ties the form's lifetime to the identity it is editing.
 */
export default function DealProfile(props: DealProfileProps) {
  const primaryId = props.profile.contacts.find((c) => c.is_primary)?.id ?? 0;

  return <ProfileEditor key={`profile-${props.deal.id}-${primaryId}`} {...props} />;
}

function ProfileEditor({
  deal,
  can,
  profile,
  lead_sources,
  organization_types,
  specialties,
  assignable_users,
  assignable_teams,
  packages,
  decision_roles,
}: DealProfileProps) {
  const primary = profile.contacts.find((c) => c.is_primary);

  const [areaOptions, setAreaOptions] = React.useState<string[]>([]);
  const [resolving, setResolving] = React.useState(false);

  const form = useForm({
    company_name: profile.organisation.company_name,
    organization_type: profile.organisation.organization_type ?? '',
    healthcare_specialties: profile.organisation.healthcare_specialties,
    organization_size: profile.organisation.organization_size ?? '',

    address: profile.location.address ?? '',
    area: profile.location.area ?? '',
    city: profile.location.city ?? '',
    state: profile.location.state ?? '',
    country: profile.location.country ?? 'India',
    postal_code: profile.location.postal_code ?? '',

    contact_name: deal.contact_name,
    email: deal.email ?? '',
    phone: deal.phone ?? '',
    contact: {
      whatsapp: primary?.whatsapp ?? '',
      job_title: primary?.job_title ?? '',
      decision_role: primary?.decision_role ?? '',
    },

    current_software: profile.qualification.current_software ?? '',
    migration_required: profile.qualification.migration_required,
    competitors: profile.qualification.competitors ?? '',
    deployment_preference: profile.qualification.deployment_preference ?? '',
    expected_go_live_date: profile.qualification.expected_go_live_date ?? '',
    expected_users: profile.qualification.expected_users?.toString() ?? '',
    required_modules: profile.qualification.required_modules,
    required_integrations: profile.qualification.required_integrations,
    product_interest: profile.qualification.product_interest,

    assigned_user_id: profile.commercial.assigned_user_id?.toString() ?? '',
    assigned_team_id: profile.commercial.assigned_team_id?.toString() ?? '',
    source: profile.commercial.source ?? '',
    selected_plan_id: profile.commercial.selected_plan_id?.toString() ?? '',
    expected_mrr: profile.commercial.expected_mrr?.toString() ?? '',
    expected_arr: profile.commercial.expected_arr?.toString() ?? '',
    probability_to_close: profile.commercial.probability_to_close?.toString() ?? '',
    priority: profile.commercial.priority ?? '',
    notes: profile.commercial.notes ?? '',

    branches: profile.branches,
  });

  const { data, setData, errors, processing, isDirty } = form;

  /*
   * A guard on leaving with unsaved work. The old inline editors had none, so
   * navigating away — or another section saving — discarded a draft silently.
   */
  React.useEffect(() => {
    if (! isDirty) return;

    const warn = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };

    window.addEventListener('beforeunload', warn);

    return () => window.removeEventListener('beforeunload', warn);
  }, [isDirty]);

  const resolvePincode = async (pincode: string) => {
    if (! /^\d{6}$/.test(pincode)) return;

    setResolving(true);

    try {
      const response = await fetch(`/api/v1/geo/pincode/${pincode}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });

      if (! response.ok) {
        setAreaOptions([]);

        return;
      }

      const { data: geo } = await response.json();

      setData((current) => ({
        ...current,
        country: geo.country?.name ?? current.country,
        state: geo.state?.name ?? current.state,
        city: geo.city?.name ?? current.city,
      }));
      setAreaOptions((geo.areas ?? []).map((a: { name: string }) => a.name));
    } finally {
      setResolving(false);
    }
  };

  const updateBranch = (index: number, changes: Partial<DealBranch>) =>
    setData('branches', data.branches.map((b, i) => (i === index ? { ...b, ...changes } : b)));

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    form.put(route('company.crm.leads.update', deal.id), { preserveScroll: true });
  };

  const readOnly = ! can.update;

  return (
    <CrmShell title={`${deal.company_name} — profile`}>
      <form onSubmit={submit}>
        <header className="sticky top-0 z-30 -mx-4 mb-4 border-b border-ovs-crm-border bg-ovs-crm-surface/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
          <div className="mx-auto flex max-w-[1100px] flex-wrap items-center gap-x-4 gap-y-2">
            <CrmIconButton
              icon={ArrowLeft}
              label="Back to the deal"
              tone="ghost"
              href={route('company.crm.leads.show', deal.id)}
            />

            <div className="min-w-0 flex-1">
              <h1 className="truncate text-[17px] font-semibold text-ovs-crm-text-primary">
                {readOnly ? 'Organisation profile' : 'Edit organisation profile'}
              </h1>
              <p className="truncate text-[12px] text-ovs-crm-text-muted">{deal.company_name}</p>
            </div>

            {isDirty && <CrmStatusPill tone="warn">Unsaved changes</CrmStatusPill>}

            {! readOnly && (
              <div className="flex items-center gap-2">
                <CrmButton
                  onClick={() => router.get(route('company.crm.leads.show', deal.id))}
                  disabled={processing}
                >
                  Cancel
                </CrmButton>
                <CrmButton tone="primary" type="submit" loading={processing} disabled={! isDirty}>
                  Save profile
                </CrmButton>
              </div>
            )}
          </div>
        </header>

        <div className="mx-auto max-w-[1100px] space-y-3 pb-24">
          <CrmSection
            title="Organisation"
            description="Who they are, and what they practise."
            icon={Building2}
            hue="#8B7FE8"
            step={1}
          >
            <div className="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
              <CrmFormField label="Clinic, hospital or group name" required error={errors.company_name}>
                <CrmInput
                  value={data.company_name}
                  onChange={(e) => setData('company_name', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Organisation type" error={errors.organization_type}>
                <CrmSelect
                  value={data.organization_type}
                  onChange={(e) => setData('organization_type', e.target.value)}
                  disabled={readOnly}
                >
                  <option value="">Not recorded</option>
                  {Object.entries(organization_types).map(([v, label]) => (
                    <option key={v} value={v}>{label}</option>
                  ))}
                </CrmSelect>
              </CrmFormField>

              {/*
                * How big they are. A real column that feeds the lead score and
                * that no form has ever asked for, so the score has been made
                * without it on every deal.
                */}
              <CrmFormField label="How big they are" hint="Counts toward the lead score" error={errors.organization_size}>
                <CrmSelect
                  value={data.organization_size}
                  onChange={(e) => setData('organization_size', e.target.value)}
                  disabled={readOnly}
                >
                  <option value="">Not recorded</option>
                  <option value="1-10">1–10 people</option>
                  <option value="11-50">11–50 people</option>
                  <option value="51-200">51–200 people</option>
                  <option value="200+">More than 200</option>
                </CrmSelect>
              </CrmFormField>
            </div>

            <div className="mt-6">
              <p className="mb-2 text-[11.5px] font-medium text-ovs-crm-text-secondary">What they practise</p>
              <div className="flex flex-wrap gap-2">
                {specialties.map((specialty) => {
                  const on = data.healthcare_specialties.includes(specialty.value);

                  return (
                    <button
                      key={specialty.value}
                      type="button"
                      aria-pressed={on}
                      disabled={readOnly}
                      onClick={() =>
                        setData(
                          'healthcare_specialties',
                          on
                            ? data.healthcare_specialties.filter((s) => s !== specialty.value)
                            : [...data.healthcare_specialties, specialty.value]
                        )
                      }
                      className={cn(
                        'rounded-full border px-3.5 py-2 text-[12.5px] font-medium transition disabled:cursor-not-allowed disabled:opacity-60',
                        on
                          ? 'border-transparent bg-ovs-crm-ink text-white'
                          : 'border-ovs-crm-border bg-white text-ovs-crm-text-secondary hover:border-ovs-crm-border-strong hover:text-ovs-crm-text-primary'
                      )}
                    >
                      {specialty.label}
                    </button>
                  );
                })}
              </div>
              {errors.healthcare_specialties && (
                <p className="mt-2.5 text-[11.5px]" style={{ color: '#E8593C' }}>
                  {errors.healthcare_specialties}
                </p>
              )}
            </div>
          </CrmSection>

          <CrmSection
            title="Location"
            description="Where the practice operates."
            icon={MapPin}
            hue="#4A9DAB"
            step={2}
          >
            <div className="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
              <CrmFormField
                label="Pincode"
                hint={resolving ? 'Looking it up…' : 'Six digits fills the rest'}
                error={errors.postal_code}
              >
                <CrmInput
                  inputMode="numeric"
                  maxLength={6}
                  disabled={readOnly}
                  value={data.postal_code}
                  onChange={(e) => {
                    const v = e.target.value.replace(/\D/g, '').slice(0, 6);
                    setData('postal_code', v);
                    if (v.length === 6) resolvePincode(v);
                  }}
                />
              </CrmFormField>

              <CrmFormField label="Locality / area" error={errors.area}>
                {areaOptions.length > 0 ? (
                  <CrmSelect
                    value={data.area}
                    onChange={(e) => setData('area', e.target.value)}
                    disabled={readOnly}
                  >
                    <option value="">Pick a locality</option>
                    {areaOptions.map((a) => (
                      <option key={a} value={a}>{a}</option>
                    ))}
                  </CrmSelect>
                ) : (
                  <CrmInput
                    value={data.area}
                    onChange={(e) => setData('area', e.target.value)}
                    disabled={readOnly}
                  />
                )}
              </CrmFormField>

              <CrmFormField label="City" error={errors.city}>
                <CrmInput value={data.city} onChange={(e) => setData('city', e.target.value)} disabled={readOnly} />
              </CrmFormField>

              <CrmFormField label="State" error={errors.state}>
                <CrmInput value={data.state} onChange={(e) => setData('state', e.target.value)} disabled={readOnly} />
              </CrmFormField>

              <CrmFormField label="Country" error={errors.country}>
                <CrmInput
                  value={data.country}
                  onChange={(e) => setData('country', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Street address" error={errors.address}>
                <CrmInput
                  value={data.address}
                  onChange={(e) => setData('address', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>
            </div>
          </CrmSection>

          <CrmSection
            title="Branches"
            description="Sites this organisation already operates."
            icon={Building2}
            hue="#8B7FE8"
            step={3}
            actions={
              ! readOnly ? (
                <CrmButton
                  size="sm"
                  icon={Plus}
                  onClick={() => setData('branches', [...data.branches, blankBranch(data.branches.length === 0)])}
                >
                  Add a branch
                </CrmButton>
              ) : undefined
            }
          >
            {data.branches.length === 0 ? (
              <p className="text-[13px] text-ovs-crm-text-muted">
                No branches recorded. A single-site clinic needs none — the address above is the whole story.
              </p>
            ) : (
              <div className="space-y-3">
                {data.branches.map((branch, index) => (
                  <div
                    key={branch.id ?? `new-${index}`}
                    className="rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset p-4"
                  >
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                      <span className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                        Branch {index + 1}
                      </span>

                      {! readOnly && (
                        <span className="flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() =>
                              setData('branches', data.branches.map((b, i) => ({ ...b, is_main: i === index })))
                            }
                            className={cn(
                              'rounded-full px-2.5 py-1 text-[11px] font-medium transition',
                              branch.is_main
                                ? 'bg-ovs-crm-ink text-white'
                                : 'bg-white text-ovs-crm-text-muted hover:text-ovs-crm-text-primary'
                            )}
                          >
                            {branch.is_main ? 'Main site' : 'Make main'}
                          </button>
                          <button
                            type="button"
                            aria-label={`Remove branch ${index + 1}`}
                            onClick={() => setData('branches', data.branches.filter((_, i) => i !== index))}
                            className="rounded-full p-1.5 text-ovs-crm-text-muted transition hover:bg-white hover:text-ovs-crm-text-primary"
                          >
                            <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                          </button>
                        </span>
                      )}
                    </div>

                    <div className="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
                      <CrmFormField label="Branch name">
                        <CrmInput
                          value={branch.name ?? ''}
                          onChange={(e) => updateBranch(index, { name: e.target.value })}
                          disabled={readOnly}
                        />
                      </CrmFormField>
                      <CrmFormField label="City">
                        <CrmInput
                          value={branch.city ?? ''}
                          onChange={(e) => updateBranch(index, { city: e.target.value })}
                          disabled={readOnly}
                        />
                      </CrmFormField>
                      <CrmFormField label="Pincode">
                        <CrmInput
                          inputMode="numeric"
                          maxLength={6}
                          value={branch.postal_code ?? ''}
                          onChange={(e) =>
                            updateBranch(index, { postal_code: e.target.value.replace(/\D/g, '').slice(0, 6) })
                          }
                          disabled={readOnly}
                        />
                      </CrmFormField>
                      <CrmFormField label="Phone">
                        <CrmInput
                          value={branch.phone ?? ''}
                          onChange={(e) => updateBranch(index, { phone: e.target.value })}
                          disabled={readOnly}
                        />
                      </CrmFormField>
                      <CrmFormField label="Address" className="sm:col-span-2">
                        <CrmInput
                          value={branch.address ?? ''}
                          onChange={(e) => updateBranch(index, { address: e.target.value })}
                          disabled={readOnly}
                        />
                      </CrmFormField>
                      <CrmFormField label="Person on site">
                        <CrmInput
                          value={branch.contact_name ?? ''}
                          onChange={(e) => updateBranch(index, { contact_name: e.target.value })}
                          disabled={readOnly}
                        />
                      </CrmFormField>
                      <CrmFormField label="Email">
                        <CrmInput
                          type="email"
                          value={branch.email ?? ''}
                          onChange={(e) => updateBranch(index, { email: e.target.value })}
                          disabled={readOnly}
                        />
                      </CrmFormField>
                    </div>
                  </div>
                ))}
              </div>
            )}

            {errors.branches && (
              <p className="mt-3 text-[11.5px]" style={{ color: '#E8593C' }}>{errors.branches}</p>
            )}
          </CrmSection>

          <CrmSection
            title="Contacts"
            description="Who you are dealing with."
            icon={Users}
            hue="#8B7FE8"
            step={4}
          >
            <div className="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
              <CrmFormField label="Name" required error={errors.contact_name}>
                <CrmInput
                  value={data.contact_name}
                  onChange={(e) => setData('contact_name', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Designation" error={errors['contact.job_title']}>
                <CrmInput
                  value={data.contact.job_title}
                  onChange={(e) => setData('contact', { ...data.contact, job_title: e.target.value })}
                  placeholder="Head orthodontist"
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Say in the decision" error={errors['contact.decision_role']}>
                <CrmSelect
                  value={data.contact.decision_role}
                  onChange={(e) => setData('contact', { ...data.contact, decision_role: e.target.value })}
                  disabled={readOnly}
                >
                  <option value="">Not established</option>
                  {Object.entries(decision_roles).map(([v, label]) => (
                    <option key={v} value={v}>{label}</option>
                  ))}
                </CrmSelect>
              </CrmFormField>

              <CrmFormField label="Email" error={errors.email}>
                <CrmInput
                  type="email"
                  value={data.email}
                  onChange={(e) => setData('email', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Phone" error={errors.phone}>
                <CrmInput
                  type="tel"
                  value={data.phone}
                  onChange={(e) => setData('phone', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField
                label="WhatsApp"
                hint="Leave blank if it is the same number"
                error={errors['contact.whatsapp']}
              >
                <CrmInput
                  type="tel"
                  value={data.contact.whatsapp}
                  onChange={(e) => setData('contact', { ...data.contact, whatsapp: e.target.value })}
                  disabled={readOnly}
                />
              </CrmFormField>
            </div>

            <DealPeople
              dealId={deal.id}
              contacts={profile.contacts}
              decisionRoles={decision_roles}
              readOnly={readOnly}
              hasUnsavedChanges={isDirty}
            />
          </CrmSection>

          <CrmSection
            title="Qualification"
            description="What they run today, what they need, and when."
            icon={Stethoscope}
            hue="#F5A623"
            step={5}
          >
            <div className="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
              <CrmFormField
                label="What they run today"
                hint="The software you would be replacing"
                error={errors.current_software}
              >
                <CrmInput
                  value={data.current_software}
                  onChange={(e) => setData('current_software', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Their data has to come across" error={errors.migration_required}>
                <label className="flex items-center gap-2.5 py-2.5 text-[13px] text-ovs-crm-text-primary">
                  <input
                    type="checkbox"
                    checked={data.migration_required}
                    onChange={(e) => setData('migration_required', e.target.checked)}
                    disabled={readOnly}
                    className="h-4 w-4 rounded border-ovs-crm-border text-ovs-crm-ink focus:ring-ovs-crm-ink/20"
                  />
                  Migration needed from their current system
                </label>
              </CrmFormField>

              <CrmFormField label="Who else they are looking at" error={errors.competitors}>
                <CrmInput
                  value={data.competitors}
                  onChange={(e) => setData('competitors', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Deployment preference" error={errors.deployment_preference}>
                <CrmSelect
                  value={data.deployment_preference}
                  onChange={(e) => setData('deployment_preference', e.target.value)}
                  disabled={readOnly}
                >
                  <option value="">No preference stated</option>
                  {Object.entries(DEPLOYMENT).map(([v, label]) => (
                    <option key={v} value={v}>{label}</option>
                  ))}
                </CrmSelect>
              </CrmFormField>

              <CrmFormField label="Hoping to go live" error={errors.expected_go_live_date}>
                <CrmInput
                  type="date"
                  value={data.expected_go_live_date}
                  onChange={(e) => setData('expected_go_live_date', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Users they expect" error={errors.expected_users}>
                <CrmInput
                  type="number"
                  min={0}
                  value={data.expected_users}
                  onChange={(e) => setData('expected_users', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Modules they asked for" hint="Comma separated" error={errors.required_modules}>
                <CrmInput
                  value={data.required_modules.join(', ')}
                  onChange={(e) => setData('required_modules', asList(e.target.value))}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField
                label="Integrations they need"
                hint="Comma separated"
                error={errors.required_integrations}
              >
                <CrmInput
                  value={data.required_integrations.join(', ')}
                  onChange={(e) => setData('required_integrations', asList(e.target.value))}
                  disabled={readOnly}
                />
              </CrmFormField>

              {/*
                * `product_interest` has been a validated column with no input
                * on either page since it was added. It has one now.
                */}
              <CrmFormField
                label="What they are interested in"
                hint="Comma separated"
                error={errors.product_interest}
              >
                <CrmInput
                  value={data.product_interest.join(', ')}
                  onChange={(e) => setData('product_interest', asList(e.target.value))}
                  disabled={readOnly}
                />
              </CrmFormField>
            </div>
          </CrmSection>

          <CrmSection
            title="Commercial"
            description="Who owns it, where it came from, and what it is worth."
            icon={IndianRupee}
            hue="#2FA96B"
            step={6}
          >
            <DealAssignment
              className="mb-6"
              allowAuto={false}
              value={{ assigned_user_id: data.assigned_user_id, assigned_team_id: data.assigned_team_id }}
              onChange={(next) => setData((current) => ({ ...current, ...next }))}
              users={assignable_users}
              teams={assignable_teams}
              errors={{ assigned_user_id: errors.assigned_user_id, assigned_team_id: errors.assigned_team_id }}
            />

            <div className="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
              <CrmFormField label="Where it came from" error={errors.source}>
                <CrmSelect
                  value={data.source}
                  onChange={(e) => setData('source', e.target.value)}
                  disabled={readOnly}
                >
                  <option value="">Not recorded</option>
                  {Object.entries(lead_sources).map(([v, label]) => (
                    <option key={v} value={v}>{label}</option>
                  ))}
                </CrmSelect>
              </CrmFormField>

              <CrmFormField label="Package" error={errors.selected_plan_id}>
                <CrmSelect
                  value={data.selected_plan_id}
                  onChange={(e) => setData('selected_plan_id', e.target.value)}
                  disabled={readOnly}
                >
                  <option value="">Not decided</option>
                  {packages.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name} — {inr(p.price)}/{p.billing_period}
                    </option>
                  ))}
                </CrmSelect>
              </CrmFormField>

              <CrmFormField label="How hard to push" error={errors.priority}>
                <CrmSelect
                  value={data.priority}
                  onChange={(e) => setData('priority', e.target.value)}
                  disabled={readOnly}
                >
                  {Object.entries(PRIORITIES).map(([v, label]) => (
                    <option key={v} value={v}>{label}</option>
                  ))}
                </CrmSelect>
              </CrmFormField>

              <CrmFormField label="Expected monthly value" error={errors.expected_mrr}>
                <CrmInput
                  type="number"
                  min={0}
                  value={data.expected_mrr}
                  onChange={(e) => setData('expected_mrr', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField
                label="Expected annual value"
                hint="Left blank, twelve months of the monthly figure is used"
                error={errors.expected_arr}
              >
                <CrmInput
                  type="number"
                  min={0}
                  value={data.expected_arr}
                  onChange={(e) => setData('expected_arr', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Chance of closing" error={errors.probability_to_close}>
                <CrmInput
                  type="number"
                  min={0}
                  max={100}
                  value={data.probability_to_close}
                  onChange={(e) => setData('probability_to_close', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>

              <CrmFormField label="Notes" error={errors.notes} className="sm:col-span-2 lg:col-span-3">
                <CrmTextarea
                  rows={3}
                  value={data.notes}
                  onChange={(e) => setData('notes', e.target.value)}
                  disabled={readOnly}
                />
              </CrmFormField>
            </div>
          </CrmSection>

          <CrmCard className="px-5 py-4">
            <h2 className="mb-3 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
              <Megaphone className="h-3.5 w-3.5" aria-hidden="true" />
              Where this came from
            </h2>

            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <dt className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                  Campaign
                </dt>
                <dd className="mt-1 text-[13px] text-ovs-crm-text-primary">
                  {profile.attribution.marketing_campaign?.name ?? 'Not from a campaign'}
                </dd>
              </div>
              <div>
                <dt className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                  Marketing contact
                </dt>
                <dd className="mt-1 text-[13px] text-ovs-crm-text-primary">
                  {profile.attribution.marketing_contact?.name ?? '—'}
                </dd>
              </div>
              <div>
                <dt className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                  Created
                </dt>
                <dd className="mt-1 text-[13px] text-ovs-crm-text-primary">
                  {profile.attribution.created_at ?? '—'}
                </dd>
              </div>
              <div>
                <dt className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                  Qualified
                </dt>
                <dd className="mt-1 text-[13px] text-ovs-crm-text-primary">
                  {profile.attribution.qualified_at ?? 'Not yet'}
                </dd>
              </div>
            </dl>

            <p className="mt-3 border-t border-ovs-crm-line pt-3 text-[11.5px] text-ovs-crm-text-muted">
              Attribution is a record of what happened, so it is shown here rather than edited.
            </p>
          </CrmCard>
        </div>
      </form>
    </CrmShell>
  );
}
