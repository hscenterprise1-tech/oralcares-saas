import { useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Activity, FolderHeart, Smile, Stethoscope } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Field, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Skeleton } from '@/clinic/ui/Spinner';
import { EmptyState, ErrorState, PermissionState } from '@/clinic/ui/states';
import { formatMoney } from '@/clinic/ui/visuals';
import { ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '@/clinic/http';
import { shortDate } from '../format';
import type { ActiveCase, CaseStatus } from './types';

/*
 * Cases & Plans: the patient's episodes of care (`patient_cases`), open ones
 * first. Each says who leads the case and, separately, who treated the
 * patient on its visits; for clinical members, the treatments started in its
 * consultations with their progress, agreed estimates and session packages.
 * The lifecycle (recommend completion → close → reopen) is offered only as
 * the server allows it. The internal case number is never shown.
 */

export type Treatment = {
  id: number;
  title: string;
  status: string;
  practitioner: { id: number; name: string } | null;
  progress: { done: number; total: number | null; unit: string };
  agreed_amount?: number | null;
  amount_scope?: 'own_estimate';
  packages?: { name: string; sessions_total: number; remaining: number; status: string }[];
};
export type CaseRow = {
  id: number;
  module: ActiveCase['module'];
  module_offered: boolean;
  specialty: string;
  title: string;
  complaint: string | null;
  status: CaseStatus;
  lead: string | null;
  branch: string | null;
  opened_at: string | null;
  closed_at: string | null;
  discharge_summary: string | null;
  visits: { count: number; last: string | null; treated_by: string[] };
  treatments: Treatment[] | null;
  actions: { recommend: string | null; close: string | null; reopen: string | null };
};
export type CasesPayload = { data: CaseRow[]; other_treatments: Treatment[] | null; statuses: Record<CaseStatus, string> };

export const CASE_STATUS: Record<CaseStatus, { label: string; tone: string }> = {
  active: { label: 'Active', tone: 'bg-cx-teal-bg text-cx-teal-ink' },
  completion_recommended: { label: 'Completion recommended', tone: 'bg-cx-amber-bg text-cx-amber-ink' },
  closed: { label: 'Closed', tone: 'bg-cx-slate-bg text-cx-slate-ink' },
};
const MODULE_ICON = { dental: Smile, physio: Activity, general: Stethoscope } as const;
const MODULE_TONE = { dental: 'bg-cx-blue-bg text-cx-blue-ink', physio: 'bg-cx-violet-bg text-cx-violet-ink', general: 'bg-cx-slate-bg text-cx-slate-ink' } as const;

function StatusPill({ status }: { status: CaseStatus }) {
  const look = CASE_STATUS[status] ?? CASE_STATUS.active;

  return <span className={cn('inline-flex rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', look.tone)}>{look.label}</span>;
}

/** The persistent bar above the zones: each open case, and a way into it. */
export function ActiveCasesBar({ cases, onOpen }: { cases: ActiveCase[]; onOpen: (id: number) => void }) {
  if (cases.length === 0) {
    return null;
  }

  return (
    <section aria-label="Active cases" className="flex gap-2.5 overflow-x-auto pb-1 [scrollbar-width:thin]">
      {cases.map((item) => {
        const Icon = MODULE_ICON[item.module] ?? Stethoscope;

        return (
          <button
            key={item.id}
            type="button"
            onClick={() => onOpen(item.id)}
            className="flex min-w-[240px] max-w-[340px] shrink-0 items-start gap-3 rounded-2xl bg-white p-3.5 text-left shadow-cx-card ring-1 ring-inset ring-transparent transition hover:ring-[#CFE3F7] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/40"
          >
            <span className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-[10px]', MODULE_TONE[item.module] ?? MODULE_TONE.general)} aria-hidden="true">
              <Icon className="h-[18px] w-[18px]" strokeWidth={1.75} />
            </span>
            <span className="min-w-0 flex-1">
              <span className="flex flex-wrap items-center gap-1.5">
                <span className="text-[12px] font-semibold text-cx-text-2">{item.specialty}</span>
                <StatusPill status={item.status} />
              </span>
              <span className="mt-0.5 block text-[13.5px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{item.title}</span>
              <span className="block text-[12px] text-cx-text-2">
                {[item.lead ? `Lead: ${item.lead}` : null, item.opened_at ? `since ${shortDate(item.opened_at)}` : null].filter(Boolean).join(' · ')}
              </span>
              {item.summary.length > 0 && <span className="mt-0.5 block text-[12px] text-cx-text-3 [overflow-wrap:anywhere]">{item.summary.join(' · ')}</span>}
            </span>
          </button>
        );
      })}
    </section>
  );
}

export function TreatmentLine({ treatment }: { treatment: Treatment }) {
  const pack = (treatment.packages ?? []).find((item) => item.status !== 'cancelled');

  return (
    <li className="flex flex-wrap items-center justify-between gap-2 py-2">
      <span className="min-w-0">
        <span className="block text-[13px] font-semibold text-cx-nav-ink">{treatment.title}</span>
        <span className="block text-[12px] text-cx-text-2">
          {[
            treatment.status.replace(/_/g, ' '),
            treatment.progress.total ? `${treatment.progress.done} of ${treatment.progress.total} ${treatment.progress.unit}` : null,
            treatment.practitioner ? `by ${treatment.practitioner.name}` : null,
            pack ? `${pack.remaining} of ${pack.sessions_total} sessions left (${pack.name})` : null,
          ]
            .filter(Boolean)
            .join(' · ')}
        </span>
      </span>
      {treatment.agreed_amount !== undefined && treatment.agreed_amount !== null && (
        <span className="text-[12.5px] font-semibold tabular-nums text-cx-text">
          {treatment.amount_scope === 'own_estimate' ? 'Your agreed estimate ' : 'Agreed '}
          {formatMoney(treatment.agreed_amount)}
        </span>
      )}
    </li>
  );
}

export type Step = { kind: 'recommend' | 'close' | 'reopen'; url: string; title: string };

function CaseCard({ row, focused, onStep }: { row: CaseRow; focused: boolean; onStep: (step: Step) => void }) {
  const Icon = MODULE_ICON[row.module] ?? Stethoscope;

  return (
    <Card as="article" id={`case-${row.id}`} className={cn('p-4', focused && 'ring-2 ring-[#1769AA]/30')}>
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <span className={cn('grid h-10 w-10 shrink-0 place-items-center rounded-[12px]', MODULE_TONE[row.module] ?? MODULE_TONE.general)} aria-hidden="true">
            <Icon className="h-5 w-5" strokeWidth={1.75} />
          </span>
          <div className="min-w-0">
            <p className="flex flex-wrap items-center gap-1.5 text-[12px] font-semibold text-cx-text-2">
              {row.specialty}
              <StatusPill status={row.status} />
              {!row.module_offered && <span className="rounded-full bg-cx-slate-bg px-2 py-0.5 text-[11px] text-cx-slate-ink">No longer offered here</span>}
            </p>
            <h3 className="mt-0.5 text-[15px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{row.title}</h3>
            {row.complaint && row.complaint !== row.title && <p className="text-[12.5px] text-cx-text-2 [overflow-wrap:anywhere]">{row.complaint}</p>}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {row.actions.recommend && (
            <Button onClick={() => onStep({ kind: 'recommend', url: row.actions.recommend as string, title: 'Recommend completing this case' })}>Recommend completion</Button>
          )}
          {row.actions.close && (
            <Button tone="primary" onClick={() => onStep({ kind: 'close', url: row.actions.close as string, title: 'Close this case' })}>
              Close case
            </Button>
          )}
          {row.actions.reopen && <Button onClick={() => onStep({ kind: 'reopen', url: row.actions.reopen as string, title: 'Reopen this case' })}>Reopen</Button>}
        </div>
      </header>

      <dl className="mt-3 grid gap-x-6 gap-y-2 text-[12.5px] sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <dt className="text-cx-text-3">Lead practitioner</dt>
          <dd className="font-semibold text-cx-nav-ink">{row.lead ?? '—'}</dd>
        </div>
        <div>
          <dt className="text-cx-text-3">Treated by</dt>
          <dd className="font-semibold text-cx-nav-ink">{row.visits.treated_by.length > 0 ? row.visits.treated_by.join(', ') : '—'}</dd>
        </div>
        <div>
          <dt className="text-cx-text-3">Visits</dt>
          <dd className="font-semibold text-cx-nav-ink">
            {row.visits.count}
            {row.visits.last ? ` · last ${shortDate(row.visits.last)}` : ''}
          </dd>
        </div>
        <div>
          <dt className="text-cx-text-3">{row.closed_at ? 'Opened – closed' : 'Opened'}</dt>
          <dd className="font-semibold text-cx-nav-ink">
            {[shortDate(row.opened_at), row.closed_at ? shortDate(row.closed_at) : null].filter(Boolean).join(' – ')}
            {row.branch ? ` · ${row.branch}` : ''}
          </dd>
        </div>
      </dl>

      {row.treatments !== null && (
        <div className="mt-3 border-t border-cx-line pt-2">
          <p className="text-[12px] font-semibold uppercase tracking-wide text-cx-teal-ink">Plans</p>
          {row.treatments.length === 0 ? (
            <p className="py-2 text-[12.5px] text-cx-text-2">No treatment started in this case’s consultations yet.</p>
          ) : (
            <ul className="divide-y divide-cx-line">
              {row.treatments.map((treatment) => (
                <TreatmentLine key={treatment.id} treatment={treatment} />
              ))}
            </ul>
          )}
        </div>
      )}
      {row.discharge_summary && <p className="mt-2 rounded-xl bg-cx-slate-bg/70 px-3 py-2 text-[12.5px] text-cx-text-2">{row.discharge_summary}</p>}
    </Card>
  );
}

export function StepDialog({ step, onClose, onDone }: { step: Step; onClose: () => void; onDone: () => void }) {
  const [note, setNote] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const required = step.kind === 'reopen';
  const submit = () => {
    if (required && note.trim().length < 3) {
      setProblem('Say why the case is reopened.');

      return;
    }
    setBusy(true);
    router.post(step.url, { note: note.trim() || null }, {
      preserveScroll: true,
      preserveState: true,
      onError: (errors) => setProblem(Object.values(errors)[0] ?? 'That was not done.'),
      onSuccess: () => onDone(),
      onFinish: () => setBusy(false),
    });
  };
  const label = { recommend: 'Note (optional)', close: 'Discharge summary (optional)', reopen: 'Why is it reopened?' }[step.kind];
  const help = {
    recommend: 'The case stays open until it is approved and closed.',
    close: 'The case’s visits, treatments, packages and history are kept.',
    reopen: 'The case becomes active again; its earlier closure stays in the timeline.',
  }[step.kind];

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      title={step.title}
      description={help}
      size="sm"
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button tone="primary" loading={busy} onClick={submit}>
            {{ recommend: 'Recommend', close: 'Close case', reopen: 'Reopen case' }[step.kind]}
          </Button>
        </>
      }
    >
      <Field label={label} htmlFor="case-step-note" required={required} error={problem}>
        <Textarea id="case-step-note" value={note} maxLength={2000} onChange={(event) => setNote(event.target.value)} invalid={!!problem} />
      </Field>
    </Modal>
  );
}

export function CasesSection({ endpoint, focusCase }: { endpoint: string; focusCase: number | null }) {
  const [data, setData] = useState<CasesPayload | null>(null);
  const [error, setError] = useState<ClinicHttpErrorKind | null>(null);
  const [step, setStep] = useState<Step | null>(null);
  const load = useCallback(() => {
    setError(null);
    requestJson<CasesPayload>('GET', endpoint)
      .then(setData)
      .catch((failure) => setError(errorKind(failure)));
  }, [endpoint]);
  useEffect(() => load(), [load]);
  useEffect(() => {
    if (data && focusCase !== null) {
      document.getElementById(`case-${focusCase}`)?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }
  }, [data, focusCase]);

  if (error === 'forbidden') {
    return <PermissionState />;
  }
  if (error) {
    return <ErrorState title="Cases could not be loaded" description={ERROR_COPY[error]} onRetry={load} />;
  }
  if (!data) {
    return (
      <div role="status" aria-label="Loading cases" className="space-y-2">
        <Skeleton className="h-28 w-full rounded-2xl" />
        <Skeleton className="h-28 w-full rounded-2xl" />
      </div>
    );
  }

  return (
    <section aria-labelledby="cases-heading" className="space-y-3">
      <h2 id="cases-heading" className="flex items-center gap-2 text-[16px] font-bold text-cx-nav-ink">
        <FolderHeart className="h-[18px] w-[18px] text-cx-teal-ink" strokeWidth={1.75} aria-hidden="true" />
        Cases
      </h2>
      {data.data.length === 0 ? (
        <EmptyState title="No cases yet" description="A case opens when the patient is checked in for a doctor who may lead it." />
      ) : (
        data.data.map((row) => <CaseCard key={row.id} row={row} focused={row.id === focusCase} onStep={setStep} />)
      )}
      {data.other_treatments && data.other_treatments.length > 0 && (
        <Card as="section" className="p-4">
          <h3 className="text-[14px] font-semibold text-cx-nav-ink">Plans not tied to a case</h3>
          <p className="text-[12px] text-cx-text-2">Started outside a case’s consultations; they are not guessed into one.</p>
          <ul className="mt-1 divide-y divide-cx-line">
            {data.other_treatments.map((treatment) => (
              <TreatmentLine key={treatment.id} treatment={treatment} />
            ))}
          </ul>
        </Card>
      )}
      {step && (
        <StepDialog
          step={step}
          onClose={() => setStep(null)}
          onDone={() => {
            setStep(null);
            load();
            router.reload({ only: ['active_cases'] });
          }}
        />
      )}
    </section>
  );
}
