import { useState } from 'react';
import { Deferred } from '@inertiajs/react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Overview, OverviewSkeleton } from './components/patient360/Overview';
import { PatientHeader } from './components/patient360/PatientHeader';
import { SectionNav } from './components/patient360/SectionNav';
import { BillingSection, DocumentsSection, FollowUpsSection, InvestigationsSection, MessagesSection, PrescriptionsSection, TreatmentSection } from './components/patient360/Sections';
import { DentalSection } from './components/patient360/DentalSection';
import { PhysioSection } from './components/patient360/PhysioSection';
import { AppointmentsSection, AuditSection, ClinicalSection, ConsentsSection, RequestsSection, TimelineSection } from './components/patient360/RecordSections';
import { VisitsHistory } from './components/patient360/VisitsHistory';
import type { Patient360Props } from './components/patient360/types';

/**
 * Patient 360: one patient's workspace.
 *
 * The first response carries the header — identity, the facts the desk asks
 * first, allergies and alerts — and the sections this member may open, in
 * their role's order (`Patient360SectionPolicy`). The Overview arrives just
 * after (a deferred prop); every other section will load from its own
 * endpoint as its screen is built (`ClinicFrontend::PATIENT_360_SECTIONS`).
 */
export default function PatientShow(props: Patient360Props) {
  return (
    <ClinicPortalShell title={props.patient.name}>
      <Patient360 {...props} />
    </ClinicPortalShell>
  );
}

function Patient360({
  patient,
  state,
  context,
  profile,
  practitioners,
  overview,
  lens,
  sections,
  open_section: openSection,
  actions,
  endpoints,
  links,
  condition_editor: conditionEditor,
  safety_editor: safetyEditor,
}: Patient360Props) {
  // `?section=` opens a built section this member may see; anything else opens the role's own section.
  const [active, setActive] = useState(() => {
    const requested = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('section');

    return sections.some((section) => section.built && section.key === requested) ? (requested as string) : openSection;
  });
  const select = (key: string) => {
    setActive(key);
    const url = new URL(window.location.href);
    url.searchParams.set('section', key);
    window.history.replaceState(window.history.state, '', url);
  };

  return (
    <div className="mx-auto w-full max-w-[1280px]">
      <PatientHeader
        patient={patient}
        context={context}
        actions={actions}
        practitioners={practitioners}
        backHref={links.index}
        governance={lens.governance}
        openRequests={lens.open_requests}
        governanceEndpoints={
          endpoints.governance_request && endpoints.governance_direct
            ? {
                request: endpoints.governance_request,
                direct: endpoints.governance_direct,
              }
            : null
        }
        conditionEditor={conditionEditor}
        safetyEditor={safetyEditor}
      />

      <div className="mt-4">
        <SectionNav sections={sections} active={active} onSelect={select} />
      </div>

      <div className="mt-4" aria-live="polite">
        {active === 'overview' && (
          <Deferred data="overview" fallback={<OverviewSkeleton />}>
            {overview ? (
              <Overview
                overview={overview}
                state={state}
                context={context}
                profile={profile}
                billsUrl={links.bills}
                openable={sections.filter((section) => section.built).map((section) => section.key)}
                onOpenSection={(key) => (sections.some((section) => section.built && section.key === key) ? select(key) : undefined)}
                visitsBuilt={sections.some((section) => section.built && section.key === 'visits')}
              />
            ) : (
              <OverviewSkeleton />
            )}
          </Deferred>
        )}
        {active === 'visits' && endpoints.visits && <VisitsHistory endpoint={endpoints.visits} />}
        {active === 'prescriptions' && endpoints.prescriptions && <PrescriptionsSection endpoint={endpoints.prescriptions} />}
        {active === 'investigations' && endpoints.investigations && <InvestigationsSection endpoint={endpoints.investigations} />}
        {active === 'billing' && endpoints.billing && <BillingSection endpoint={endpoints.billing} billsUrl={links.bills} />}
        {active === 'documents' && endpoints.documents && (
          <DocumentsSection
            endpoint={endpoints.documents}
            governance={
              endpoints.governance_request && endpoints.governance_direct
                ? {
                    request: endpoints.governance_request,
                    direct: endpoints.governance_direct,
                  }
                : null
            }
          />
        )}
        {active === 'communication' && endpoints.communication && <MessagesSection endpoint={endpoints.communication} />}
        {active === 'follow-ups' && endpoints['follow-ups'] && <FollowUpsSection endpoint={endpoints['follow-ups'] as string} practitioners={practitioners} />}
        {active === 'treatment' && endpoints.treatment && <TreatmentSection endpoint={endpoints.treatment} />}
        {active === 'dental' && endpoints.dental && <DentalSection endpoint={endpoints.dental} />}
        {active === 'physio' && endpoints.physio && <PhysioSection endpoint={endpoints.physio} />}
        {active === 'appointments' && endpoints.appointments && <AppointmentsSection endpoint={endpoints.appointments} />}
        {active === 'consents' && endpoints.consents && <ConsentsSection endpoint={endpoints.consents} />}
        {active === 'timeline' && endpoints.timeline && <TimelineSection endpoint={endpoints.timeline} />}
        {active === 'audit' && endpoints.audit && <AuditSection endpoint={endpoints.audit} />}
        {active === 'clinical' && endpoints.clinical && <ClinicalSection endpoint={endpoints.clinical} />}
        {active === 'requests' && endpoints.requests && <RequestsSection endpoint={endpoints.requests} />}
      </div>
    </div>
  );
}
