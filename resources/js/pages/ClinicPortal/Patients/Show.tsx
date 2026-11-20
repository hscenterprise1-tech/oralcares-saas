import { useMemo, useState } from 'react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import type { Patient360Props, Patient360Zone } from './components/patient360/types';
import { ActiveCases } from './components/patient360/v2/ActiveCases';
import { MedicalHistoryEditor } from './components/patient360/v2/MedicalHistory';
import { PatientHeaderCard } from './components/patient360/v2/PatientHeaderCard';
import { Patient360DataProvider } from './components/patient360/v2/store';
import { ZoneBar } from './components/patient360/v2/ZoneBar';
import { BillingZone } from './components/patient360/v2/zones/BillingZone';
import { CasesZone } from './components/patient360/v2/zones/CasesZone';
import { ClinicalZone } from './components/patient360/v2/zones/ClinicalZone';
import { DocsZone } from './components/patient360/v2/zones/DocsZone';
import { OverviewZone } from './components/patient360/v2/zones/OverviewZone';
import { TimelineZone } from './components/patient360/v2/zones/TimelineZone';
import { VisitsZone } from './components/patient360/v2/zones/VisitsZone';

/**
 * Patient 360 (redesign, `docs/patient-management/16-patient-360-frontend-redesign-spec.md`):
 * the persistent patient header and Active Cases, then the seven zones
 * (`Patient360SectionPolicy::ZONES`). The server decides every section a
 * member may open; a zone is only the server's sections laid out together.
 * Every section endpoint is fetched once, together, on arrival, so a zone
 * opens at once with its data already there. The selected case is
 * client-side context: it picks the clinical workspace and opens Cases &
 * Plans; it filters nothing else.
 */
export default function PatientShow(props: Patient360Props) {
  return (
    <ClinicPortalShell title={props.patient.name}>
      <Patient360 {...props} />
    </ClinicPortalShell>
  );
}

type Place = { zone: Patient360Zone['key']; case: number | null };

/** Where the URL asks to open: `?zone=` and `?case=`, or an older `?section=` link's zone. */
function initialPlace(zones: Patient360Zone[]): Place {
  const fallback: Place = { zone: zones[0]?.key ?? 'overview', case: null };
  if (typeof window === 'undefined') {
    return fallback;
  }
  const query = new URLSearchParams(window.location.search);
  const caseId = Number(query.get('case')) || null;
  const section = query.get('section');
  const zone = zones.find((item) => item.key === query.get('zone')) ?? (section ? zones.find((item) => item.sections.includes(section)) : undefined);

  return { zone: zone?.key ?? fallback.zone, case: caseId };
}

function Patient360(props: Patient360Props) {
  const { patient, state, context, zones, active_cases: activeCases, actions, endpoints, links, lens } = props;
  const [place, setPlace] = useState<Place>(() => initialPlace(zones));
  const [historyOpen, setHistoryOpen] = useState(false);
  const update = (next: Place) => {
    setPlace(next);
    const url = new URL(window.location.href);
    url.searchParams.delete('section');
    url.searchParams.set('zone', next.zone);
    if (next.case) {
      url.searchParams.set('case', String(next.case));
    } else {
      url.searchParams.delete('case');
    }
    window.history.replaceState(window.history.state, '', url);
  };
  const has = (key: Patient360Zone['key']) => zones.some((item) => item.key === key);
  const go = (key: Patient360Zone['key']) => {
    if (has(key)) {
      update({ ...place, zone: key });
      window.scrollTo({ top: Math.min(window.scrollY, 260), behavior: 'smooth' });
    }
  };
  const zone = zones.find((item) => item.key === place.zone) ?? zones[0];
  const selected = activeCases.find((item) => item.id === place.case) ?? null;
  const clinicalSections = zones.find((item) => item.key === 'clinical')?.sections ?? [];
  const modules = (['dental', 'physio'] as const).filter((key) => clinicalSections.includes(key));
  const governance = endpoints.governance_request && endpoints.governance_direct ? { request: endpoints.governance_request, direct: endpoints.governance_direct } : null;
  const sections = zone?.sections ?? [];
  const prefetch = useMemo(() => {
    const keys = new Set(zones.flatMap((item) => item.sections));
    if (has('cases')) {
      keys.add('cases');
    }

    return Array.from(keys)
      .map((key) => endpoints[key])
      .filter((url): url is string => Boolean(url));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [zones, endpoints]);

  const initial = useMemo(
    () => Object.fromEntries(Object.entries(props.preloaded ?? {}).flatMap(([key, data]) => (endpoints[key] ? [[endpoints[key] as string, data]] : []))),
    [props.preloaded, endpoints],
  );

  return (
    <Patient360DataProvider prefetch={prefetch} initial={initial}>
      <div className="mx-auto w-full max-w-[1320px] space-y-5">
        <PatientHeaderCard
          patient={patient}
          state={state}
          context={context}
          actions={actions}
          modules={modules}
          backHref={links.index}
          governance={lens.governance}
          openRequests={lens.open_requests}
          governanceEndpoints={governance}
          practitioners={props.practitioners}
          onOpenMedicalHistory={patient.status !== 'merged' && (props.condition_editor || props.safety_editor) ? () => setHistoryOpen(true) : undefined}
        />
        {historyOpen && <MedicalHistoryEditor context={context} conditionEditor={props.condition_editor} safetyEditor={props.safety_editor} onClose={() => setHistoryOpen(false)} />}

        <ActiveCases
          cases={activeCases}
          selected={selected?.id ?? null}
          onSelect={(id) => update({ ...place, case: id })}
          onOpen={(id) => update({ zone: has('cases') ? 'cases' : place.zone, case: id })}
        />

        {zone && <ZoneBar zones={zones} active={zone.key} onSelect={go} selectedCase={selected ? { title: selected.title } : null} onClearCase={() => update({ ...place, case: null })} />}

        <div aria-live="polite">
          {zone?.key === 'overview' && <OverviewZone props={props} has={has} go={go} />}
          {zone?.key === 'clinical' && <ClinicalZone props={props} sections={sections} preferred={selected && selected.module !== 'general' ? selected.module : null} />}
          {zone?.key === 'cases' && endpoints.cases && (
            <CasesZone endpoint={endpoints.cases} treatmentEndpoint={sections.includes('treatment') ? endpoints.treatment : null} selected={place.case} onSelect={(id) => update({ ...place, case: id })} />
          )}
          {zone?.key === 'visits' && <VisitsZone props={props} sections={sections} />}
          {zone?.key === 'docs' && <DocsZone props={props} sections={sections} />}
          {zone?.key === 'billing' && <BillingZone props={props} />}
          {zone?.key === 'timeline' && <TimelineZone props={props} sections={sections} />}
        </div>
      </div>
    </Patient360DataProvider>
  );
}
