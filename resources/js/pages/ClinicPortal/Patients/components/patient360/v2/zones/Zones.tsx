import { useEffect, useState, type ReactNode } from 'react';
import { Activity, Clock, Smile, Stethoscope } from 'lucide-react';
import { cn } from '@/lib/utils';
import { requestJson } from '@/clinic/http';
import { Panel } from '@/pages/ClinicPortal/CommandCenter/sections';
import { shortDate } from '../../../format';
import { DentalSection } from '../../DentalSection';
import { Overview } from '../../Overview';
import { PhysioSection } from '../../PhysioSection';
import { AppointmentsSection, AuditSection, ClinicalSection, ConsentsSection, RequestsSection, TimelineSection } from '../../RecordSections';
import { BillingSection, DocumentsSection, FollowUpsSection, InvestigationsSection, MessagesSection, PrescriptionsSection } from '../../Sections';
import { VisitsHistory } from '../../VisitsHistory';
import type { Patient360Props } from '../../types';
import { MedicalHistoryCard } from '../MedicalHistory';

/*
 * The six zones other than Cases & Plans (spec §6). Each lays out the
 * server's sections for this member; a section the member may not open is
 * not in `sections` and is not drawn. These zones are patient-wide: the
 * selected case never filters them (their endpoints take no case).
 */

type ZoneProps = { props: Patient360Props; sections: string[] };

/** "All of this patient's records": the honest caption of every patient-wide zone. */
export function PatientWide({ children }: { children?: ReactNode }) {
  return (
    <p className="flex flex-wrap items-center gap-2 text-[12px] text-cx-text-3">
      <span className="rounded-full bg-white px-2.5 py-0.5 font-semibold text-cx-text-2 shadow-[inset_0_0_0_1px_rgba(213,219,230,0.9)]">All of this patient’s records</span>
      {children}
    </p>
  );
}

/** The latest few timeline events, read from the Timeline's own endpoint. */
function RecentActivity({ endpoint }: { endpoint: string }) {
  const [rows, setRows] = useState<{ id: number; title: string; description: string; at: string | null; actor: string | null }[] | null>(null);
  useEffect(() => {
    requestJson<{ data: { id: number; title: string; description: string; at: string | null; actor: string | null }[] }>('GET', endpoint, { query: { page: 1 } })
      .then((result) => setRows(result.data.slice(0, 5)))
      .catch(() => setRows([]));
  }, [endpoint]);

  return (
    <Panel id="recent-activity" title="Recent activity" icon={Clock} tone="violet">
      {rows === null ? null : rows.length === 0 ? (
        <p className="text-[13px] text-cx-text-2">Nothing recorded yet.</p>
      ) : (
        <ol className="space-y-3">
          {rows.map((row) => (
            <li key={row.id} className="flex gap-3">
              <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-[#6B48DE]" aria-hidden="true" />
              <span className="min-w-0">
                <span className="block text-[13px] font-semibold text-cx-nav-ink">{row.title}</span>
                <span className="block text-[12.5px] text-cx-text-2 [overflow-wrap:anywhere]">{row.description}</span>
                <span className="block text-[11.5px] text-cx-text-3">{[shortDate(row.at), row.actor].filter(Boolean).join(' · ')}</span>
              </span>
            </li>
          ))}
        </ol>
      )}
    </Panel>
  );
}

export function OverviewZone({ props, onOpenSection }: ZoneProps & { onOpenSection: (key: string) => void }) {
  const openable = props.sections.filter((section) => section.built).map((section) => section.key);

  return (
    <div className="grid gap-5 xl:grid-cols-12">
      <div className="min-w-0 xl:col-span-8">
        {/* The overview comes with the page (`Patient360Controller`), so there is nothing to wait for. */}
        {props.overview && (
          <Overview
            overview={props.overview}
            state={props.state}
            context={props.context}
            profile={props.profile}
            billsUrl={props.links.bills}
            openable={openable}
            onOpenSection={onOpenSection}
            visitsBuilt={openable.includes('visits')}
          />
        )}
      </div>
      <div className="min-w-0 space-y-5 xl:col-span-4">{props.endpoints.timeline && openable.includes('timeline') && <RecentActivity endpoint={props.endpoints.timeline} />}</div>
    </div>
  );
}

const WORKSPACE_LOOK = {
  dental: { label: 'Dental workspace', icon: Smile },
  physio: { label: 'Physio workspace', icon: Activity },
  clinical: { label: 'Consultations', icon: Stethoscope },
} as const;

export function ClinicalZone({ props, sections, preferred }: ZoneProps & { preferred: 'dental' | 'physio' | null }) {
  const options = (['dental', 'physio', 'clinical'] as const).filter((key) => sections.includes(key));
  // A combined clinic opens the selected case's module, else the member's own practice.
  const persona = props.lens.persona === 'physio' ? 'physio' : props.lens.persona === 'dental' ? 'dental' : null;
  const first = (preferred && options.includes(preferred) ? preferred : null) ?? (persona && options.includes(persona) ? persona : null) ?? options[0] ?? null;
  const [view, setView] = useState<(typeof options)[number] | null>(first);
  useEffect(() => {
    if (preferred && options.includes(preferred)) {
      setView(preferred);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [preferred]);
  const showHistory = props.context.clinical;

  return (
    <div className="space-y-5">
      <PatientWide />
      {showHistory && <MedicalHistoryCard context={props.context} conditionEditor={props.condition_editor} safetyEditor={props.safety_editor} readOnly={props.patient.status === 'merged'} />}
      {options.length > 1 && (
        <div role="tablist" aria-label="Clinical views" className="inline-flex flex-wrap gap-1 rounded-full bg-white p-1 shadow-[inset_0_0_0_1px_rgba(213,219,230,0.9)]">
          {options.map((key) => {
            const look = WORKSPACE_LOOK[key];
            const Icon = look.icon;

            return (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={view === key}
                onClick={() => setView(key)}
                className={cn('inline-flex h-9 items-center gap-2 rounded-full px-4 text-[13px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35', view === key ? 'bg-cx-blue-bg text-cx-blue-ink' : 'text-cx-text-2 hover:text-cx-nav-ink')}
              >
                <Icon className="h-4 w-4" strokeWidth={1.75} aria-hidden="true" />
                {look.label}
              </button>
            );
          })}
        </div>
      )}
      {view === 'dental' && props.endpoints.dental && <DentalSection endpoint={props.endpoints.dental} />}
      {view === 'physio' && props.endpoints.physio && <PhysioSection endpoint={props.endpoints.physio} />}
      {view === 'clinical' && props.endpoints.clinical && <ClinicalSection endpoint={props.endpoints.clinical} />}
    </div>
  );
}

export function VisitsZone({ props, sections }: ZoneProps) {
  const side = sections.includes('appointments') || sections.includes('follow-ups');

  return (
    <div className="space-y-4">
      <PatientWide />
      <div className={cn('grid gap-5', side && 'xl:grid-cols-12')}>
        {sections.includes('visits') && props.endpoints.visits && (
          <div className={cn('min-w-0', side && 'xl:col-span-8')}>
            <VisitsHistory endpoint={props.endpoints.visits} />
          </div>
        )}
        {side && (
          <div className="min-w-0 space-y-5 xl:col-span-4">
            {sections.includes('appointments') && props.endpoints.appointments && <AppointmentsSection endpoint={props.endpoints.appointments} />}
            {sections.includes('follow-ups') && props.endpoints['follow-ups'] && <FollowUpsSection endpoint={props.endpoints['follow-ups'] as string} practitioners={props.practitioners} />}
          </div>
        )}
      </div>
    </div>
  );
}

export function DocsZone({ props, sections }: ZoneProps) {
  const governance = props.endpoints.governance_request && props.endpoints.governance_direct ? { request: props.endpoints.governance_request, direct: props.endpoints.governance_direct } : null;
  const pair = ['prescriptions', 'investigations'].filter((key) => sections.includes(key));

  return (
    <div className="space-y-5">
      <PatientWide />
      {sections.includes('documents') && props.endpoints.documents && <DocumentsSection endpoint={props.endpoints.documents} governance={governance} />}
      {pair.length > 0 && (
        <div className={cn('grid gap-5', pair.length > 1 && 'xl:grid-cols-2')}>
          {sections.includes('prescriptions') && props.endpoints.prescriptions && (
            <div className="min-w-0">
              <PrescriptionsSection endpoint={props.endpoints.prescriptions} />
            </div>
          )}
          {sections.includes('investigations') && props.endpoints.investigations && (
            <div className="min-w-0">
              <InvestigationsSection endpoint={props.endpoints.investigations} />
            </div>
          )}
        </div>
      )}
      {sections.includes('consents') && props.endpoints.consents && <ConsentsSection endpoint={props.endpoints.consents} />}
    </div>
  );
}

export function BillingZone({ props }: ZoneProps) {
  return (
    <div className="space-y-4">
      <PatientWide>{!props.lens.fields.financial && <span>Only your own fees are shown.</span>}</PatientWide>
      {props.endpoints.billing && <BillingSection endpoint={props.endpoints.billing} billsUrl={props.links.bills} />}
    </div>
  );
}

export function TimelineZone({ props, sections }: ZoneProps) {
  const side = ['communication', 'requests', 'audit'].filter((key) => sections.includes(key));

  return (
    <div className="space-y-4">
      <PatientWide />
      <div className={cn('grid gap-5', side.length > 0 && 'xl:grid-cols-12')}>
        {sections.includes('timeline') && props.endpoints.timeline && (
          <div className={cn('min-w-0', side.length > 0 && 'xl:col-span-7')}>
            <TimelineSection endpoint={props.endpoints.timeline} />
          </div>
        )}
        {side.length > 0 && (
          <div className="min-w-0 space-y-5 xl:col-span-5">
            {sections.includes('communication') && props.endpoints.communication && <MessagesSection endpoint={props.endpoints.communication} />}
            {sections.includes('requests') && props.endpoints.requests && <RequestsSection endpoint={props.endpoints.requests} />}
            {sections.includes('audit') && props.endpoints.audit && <AuditSection endpoint={props.endpoints.audit} />}
          </div>
        )}
      </div>
    </div>
  );
}
