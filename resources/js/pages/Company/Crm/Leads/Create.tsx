import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import {
  CrmShell,
  CrmPageHead,
  CrmCard,
  CrmSection,
  CrmButton,
  CrmIconButton,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  CrmFormField,
  CrmStatusPill,
  CrmBadge,
  specialtyTone,
  priorityTone,
  sourceTone,
  toneHex,
} from '@/pages/Company/design';
import { AlertTriangle, ArrowLeft, Building2, Check, Contact2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { DealAssignment } from './components/DealAssignment';
import { DealPreviewCard } from './components/DealPreviewCard';

/*
 * Create Deal — intake, not discovery.
 *
 * This was a five-step wizard over thirty-six fields: branch addresses, required
 * modules, integrations, competitor lists, go-live dates, deployment
 * preferences, a plan and three commercial figures. All of it optional, and all
 * of it on screen while a rep is on a first call with somebody who has not yet
 * agreed to a second one. The form asked the questions of a discovery
 * conversation at the moment of a cold one, so it was either abandoned or filled
 * with guesses.
 *
 * Nine fields now, five of them required, all answerable in the first thirty
 * seconds of a call: who the doctor is, how to ring them back, what the practice
 * is called, which city, and what they practise. Everything else the pipeline
 * needs is asked later, by the Clinical Discovery form on the deal itself —
 * which is where the facility archetype and the per-site capacity are captured,
 * because those are answers a rep only has after talking to somebody.
 *
 * What survives from the old form, because it earned its place: the duplicate
 * check that runs while the phone number is typed, so a repeat lead is a warning
 * before it is a record rather than after.
 *
 * Laid out as two columns rather than one centred one: intake on the left, and
 * on the right a live preview of the exact card this deal becomes on the
 * pipeline the moment it saves, plus the routing that decides who owns it. A
 * rep can see what they are about to create instead of taking it on faith.
 */

interface Option {
  value: string;
  label: string;
}

interface SpecialtyOption extends Option {
  requires_more: boolean;
}

interface DuplicateMatch {
  id: number;
  company_name: string;
  contact_name: string | null;
  stage_label: string;
  owner: string | null;
  created_at: string | null;
  url: string;
}

interface CreateProps {
  leadSources: Record<string, string>;
  specialties: SpecialtyOption[];
  assignableUsers: { id: number; name: string; email: string }[];
  assignableTeams: { id: number; name: string }[];
}

const PRIORITIES: Option[] = [
  { value: 'low', label: 'Low' },
  { value: 'medium', label: 'Medium' },
  { value: 'high', label: 'High' },
];

/* The area hue tokens the rest of the CRM sections use. */
const AREA = {
  identity: toneHex('violet'),
  preview: toneHex('teal'),
  routing: toneHex('blue'),
} as const;

export default function CreateDeal({
  leadSources,
  specialties,
  assignableUsers,
  assignableTeams,
}: CreateProps) {
  const [duplicate, setDuplicate] = React.useState<DuplicateMatch | null>(null);
  const [duplicateDismissed, setDuplicateDismissed] = React.useState(false);

  const form = useForm({
    /* The five a rep can answer on a first call. */
    contact_name: '',
    phone: '',
    company_name: '',
    city: '',
    healthcare_specialties: [] as string[],

    /* Routing — optional, and defaulted server-side when left alone. */
    source: '',
    assigned_user_id: '',
    assigned_team_id: '',
    priority: 'medium',
    notes: '',
  });

  const { data, setData, post, processing, errors } = form;

  /*
   * The duplicate check runs while the phone or email is being typed, on a
   * pause rather than a keystroke. It is deliberately advisory: the form never
   * blocks, because a genuine second location of the same group is a real deal
   * and only the person on the call can tell the difference.
   */
  React.useEffect(() => {
    const phone = data.phone.trim();
    const company = data.company_name.trim();

    if (phone.length < 6 && company.length < 4) {
      setDuplicate(null);

      return;
    }

    const id = setTimeout(() => {
      const params = new URLSearchParams();
      if (phone) params.set('phone', phone);
      if (company) params.set('company_name', company);

      fetch(`${route('company.crm.deals.check-duplicate')}?${params}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })
        .then((r) => (r.ok ? r.json() : { duplicate: null }))
        .then((body) => {
          setDuplicate(body.duplicate ?? null);
          setDuplicateDismissed(false);
        })
        .catch(() => setDuplicate(null));
    }, 500);

    return () => clearTimeout(id);
  }, [data.phone, data.company_name]);

  const toggleSpecialty = (value: string) => {
    setData(
      'healthcare_specialties',
      data.healthcare_specialties.includes(value)
        ? data.healthcare_specialties.filter((s) => s !== value)
        : [...data.healthcare_specialties, value]
    );
  };

  const complete =
    data.contact_name.trim() !== '' &&
    data.phone.trim() !== '' &&
    data.company_name.trim() !== '' &&
    data.city.trim() !== '' &&
    data.healthcare_specialties.length > 0;

  const assignedUserName =
    data.assigned_user_id !== ''
      ? assignableUsers.find((u) => String(u.id) === data.assigned_user_id)?.name ?? null
      : null;

  const assignedTeamName =
    data.assigned_team_id !== ''
      ? assignableTeams.find((t) => String(t.id) === data.assigned_team_id)?.name ?? null
      : null;

  return (
    <CrmShell title="New deal">
      <div className="mx-auto min-w-0 max-w-[1480px] space-y-4 overflow-x-hidden pb-16">
        <CrmIconButton
          icon={ArrowLeft}
          label="Back to deals"
          tone="ghost"
          href={route('company.crm.deals.index')}
          className="mb-2"
        />

        <CrmPageHead
          className="mb-1"
          eyebrow="Acquisition"
          title="New deal"
          description="Five answers from the first call. The rest is captured on the deal, once you have spoken to them."
        />

        {/*
          * A possible duplicate, shown while typing rather than after saving.
          * Never blocks — a second location of the same group is a real deal.
          */}
        {duplicate && !duplicateDismissed && (
          <CrmCard className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
            <AlertTriangle
              className="h-4 w-4 shrink-0 text-ovs-crm-accent-yellow-text"
              aria-hidden="true"
            />

            <span className="min-w-0 flex-1 text-[13px] text-ovs-crm-text-primary">
              This may already be on the book:{' '}
              <Link href={duplicate.url} className="font-semibold underline underline-offset-2">
                {duplicate.company_name}
              </Link>
              {duplicate.contact_name && (
                <span className="text-ovs-crm-text-muted"> · {duplicate.contact_name}</span>
              )}
            </span>

            <CrmStatusPill tone="neutral">{duplicate.stage_label}</CrmStatusPill>

            {duplicate.owner && (
              <span className="text-[12px] text-ovs-crm-text-muted">{duplicate.owner}</span>
            )}

            <CrmButton size="sm" icon={X} onClick={() => setDuplicateDismissed(true)}>
              Not the same
            </CrmButton>
          </CrmCard>
        )}

        <form
          onSubmit={(e) => {
            e.preventDefault();
            post(route('company.crm.leads.store'));
          }}
          className="space-y-4"
        >
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-start">
            {/* Left column — the intake itself. ~65% on desktop. */}
            <div className="min-w-0 space-y-4 lg:col-span-8">
              <CrmSection
                title="Who you spoke to"
                description="Everything here is required — it is what makes the deal reachable and routable."
                icon={Contact2}
                hue={AREA.identity}
              >
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <CrmFormField label="Doctor's name" required error={errors.contact_name}>
                    <CrmInput
                      value={data.contact_name}
                      onChange={(e) => setData('contact_name', e.target.value)}
                      placeholder="Dr Harshil Mehta"
                      autoFocus
                    />
                  </CrmFormField>

                  <CrmFormField
                    label="Mobile"
                    required
                    error={errors.phone}
                    hint="Checked against the book as you type."
                  >
                    <CrmInput
                      value={data.phone}
                      onChange={(e) => setData('phone', e.target.value)}
                      placeholder="98765 43210"
                      inputMode="tel"
                    />
                  </CrmFormField>

                  <CrmFormField label="Clinic or hospital" required error={errors.company_name}>
                    <CrmInput
                      value={data.company_name}
                      onChange={(e) => setData('company_name', e.target.value)}
                      placeholder="Mehta Spine & Physio Rehab"
                    />
                  </CrmFormField>

                  <CrmFormField label="City" required error={errors.city}>
                    <CrmInput
                      value={data.city}
                      onChange={(e) => setData('city', e.target.value)}
                      placeholder="Vadodara"
                    />
                  </CrmFormField>
                </div>

                <div className="mt-4">
                  <CrmFormField
                    label="What they practise"
                    required
                    error={errors.healthcare_specialties}
                    hint="Pick every one that applies. Each specialty carries its own colour through the pipeline."
                  >
                    <div className="flex flex-wrap gap-1.5">
                      {specialties.map((specialty) => {
                        const on = data.healthcare_specialties.includes(specialty.value);
                        const tone = specialtyTone(specialty.value);

                        return (
                          <button
                            key={specialty.value}
                            type="button"
                            onClick={() => toggleSpecialty(specialty.value)}
                            aria-pressed={on}
                          >
                            <CrmBadge
                              tone={tone}
                              variant={on ? 'solid' : 'soft'}
                              size="sm"
                              icon={on ? <Check className="h-3 w-3 shrink-0" aria-hidden="true" /> : undefined}
                              className={cn(
                                'cursor-pointer transition',
                                !on && 'opacity-80 hover:opacity-100'
                              )}
                            >
                              {specialty.label}
                            </CrmBadge>
                          </button>
                        );
                      })}
                    </div>
                  </CrmFormField>
                </div>
              </CrmSection>

              <CrmCard className="hidden flex-wrap items-center gap-3 px-5 py-4 lg:flex">
                <p className="min-w-0 flex-1 text-[12.5px] text-ovs-crm-text-muted">
                  {complete
                    ? 'Ready. The deal opens at New with a main site recorded — capture the clinical detail from the deal once you have it.'
                    : 'Fill the five required answers to save.'}
                </p>

                <CrmButton href={route('company.crm.deals.index')}>Cancel</CrmButton>

                <CrmButton
                  tone="primary"
                  icon={Check}
                  loading={processing}
                  disabled={!complete}
                  type="submit"
                >
                  Create deal
                </CrmButton>
              </CrmCard>
            </div>

            {/* Right column — the live preview, and everything that routes it. ~35% on desktop. */}
            <div className="min-w-0 space-y-4 lg:col-span-4 lg:sticky lg:top-4">
              <DealPreviewCard
                contactName={data.contact_name}
                companyName={data.company_name}
                city={data.city}
                phone={data.phone}
                specialtyValues={data.healthcare_specialties}
                specialtyOptions={specialties}
                priority={data.priority}
                source={data.source}
                sourceLabel={data.source ? leadSources[data.source] ?? null : null}
                assignedUserName={assignedUserName}
                assignedTeamName={assignedTeamName}
              />

              <CrmSection
                title="Intake context & routing"
                description="Optional. Left alone, the deal auto-assigns to the least-loaded rep and opens at medium priority."
                icon={Building2}
                hue={AREA.routing}
              >
                <CrmFormField label="Priority" error={errors.priority}>
                  <div className="flex flex-wrap gap-1.5">
                    {PRIORITIES.map((p) => {
                      const on = data.priority === p.value;

                      return (
                        <button key={p.value} type="button" onClick={() => setData('priority', p.value)} aria-pressed={on}>
                          <CrmBadge tone={priorityTone(p.value)} variant={on ? 'solid' : 'soft'} className="cursor-pointer">
                            {p.label}
                          </CrmBadge>
                        </button>
                      );
                    })}
                  </div>
                </CrmFormField>

                <div className="mt-4">
                  <CrmFormField label="Where it came from" error={errors.source}>
                    <div className="flex flex-wrap gap-1.5">
                      {Object.entries(leadSources).map(([value, label]) => {
                        const on = data.source === value;

                        return (
                          <button
                            key={value}
                            type="button"
                            onClick={() => setData('source', on ? '' : value)}
                            aria-pressed={on}
                          >
                            <CrmBadge tone={sourceTone(value)} variant={on ? 'solid' : 'soft'} className="cursor-pointer">
                              {label}
                            </CrmBadge>
                          </button>
                        );
                      })}
                    </div>
                  </CrmFormField>
                </div>

                <div className="mt-4">
                  <DealAssignment
                    value={{
                      assigned_user_id: data.assigned_user_id,
                      assigned_team_id: data.assigned_team_id,
                    }}
                    onChange={(next) => {
                      setData('assigned_user_id', next.assigned_user_id);
                      setData('assigned_team_id', next.assigned_team_id);
                    }}
                    users={assignableUsers}
                    teams={assignableTeams}
                    errors={{
                      assigned_user_id: errors.assigned_user_id,
                      assigned_team_id: errors.assigned_team_id,
                    }}
                  />
                </div>

                <div className="mt-4">
                  <CrmFormField
                    label="Quick note"
                    error={errors.notes}
                    hint="What they said, in their words. It becomes the first entry on the deal's timeline."
                  >
                    <CrmTextarea
                      rows={3}
                      value={data.notes}
                      onChange={(e) => setData('notes', e.target.value)}
                      placeholder="Runs three sites, unhappy with current billing software, wants a demo next week."
                    />
                  </CrmFormField>
                </div>
              </CrmSection>
            </div>
          </div>

          <CrmCard className="flex flex-wrap items-center gap-3 px-5 py-4 lg:hidden">
            <p className="min-w-0 flex-1 text-[12.5px] text-ovs-crm-text-muted">
              {complete
                ? 'Ready. The deal opens at New with a main site recorded — capture the clinical detail from the deal once you have it.'
                : 'Fill the five required answers to save.'}
            </p>

            <CrmButton href={route('company.crm.deals.index')}>Cancel</CrmButton>

            <CrmButton tone="primary" icon={Check} loading={processing} disabled={!complete} type="submit">
              Create deal
            </CrmButton>
          </CrmCard>
        </form>
      </div>
    </CrmShell>
  );
}
