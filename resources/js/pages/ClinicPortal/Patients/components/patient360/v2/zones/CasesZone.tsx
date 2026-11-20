import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Activity, CalendarDays, ClipboardCheck, FolderHeart, ListChecks, Package, Smile, Stethoscope, UserRound, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CASE_STATUS, StepDialog, type CaseRow, type CasesPayload, type Step, type Treatment } from '../../CasesSection';
import { useEndpoint } from '../store';
import { BTN_PRIMARY, BTN_SM, Card, Empty, FOCUS, IconTile, Pager, Pending, Pill, day, humanize, money, type Paged, type Tone } from '../ui';

type ProcedureRow = { id: number; name: string; quantity: number; tooth: number | string | null; notes: string | null; date: string | null; practitioner: string | null; consultation: string | null; consultation_url: string | null; billed: boolean };
type TreatmentItem = { id: number; title: string; specialty: string | null; status: string; practitioner: { id: number; name: string } | null; progress: { done: number; total: number | null; unit: string }; next_stage: { id: number; name: string; status: string } | null; started_at: string | null };
type TreatmentPage = Paged<ProcedureRow> & { treatments: TreatmentItem[] };

const MODULE_ICON = { dental: Smile, physio: Activity, general: Stethoscope } as const;
const MODULE_TONE: Record<string, Tone> = { dental: 'blue', physio: 'violet', general: 'teal' };
const TREATMENT_TONE: Record<string, Tone> = { planned: 'slate', active: 'blue', in_progress: 'blue', paused: 'amber', completed: 'mint', cancelled: 'slate', declined: 'slate' };

function StatusPill({ status }: { status: CaseRow['status'] }) {
  const look = CASE_STATUS[status] ?? CASE_STATUS.active;

  return <span className={cn('rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', look.tone)}>{look.label}</span>;
}

function Progress({ done, total, tone = 'violet' }: { done: number; total: number; tone?: 'violet' | 'mint' }) {
  return (
    <span className="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-cx-slate-bg">
      <span className={cn('block h-full rounded-full bg-gradient-to-r', tone === 'mint' ? 'from-[#5FD9A6] to-[#12A877]' : 'from-[#9B7BF2] to-[#6B48DE]')} style={{ width: `${Math.min(100, Math.round((done / Math.max(total, 1)) * 100))}%` }} />
    </span>
  );
}

function PlanRow({ treatment }: { treatment: Treatment }) {
  const pack = (treatment.packages ?? []).find((item) => item.status !== 'cancelled');

  return (
    <li className="flex h-full flex-col justify-between gap-1 rounded-2xl border border-[#EDF1F7] bg-[#FBFCFE] p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <span className="min-w-0">
          <span className="block text-[13.5px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{treatment.title}</span>
          <span className="block text-[12px] text-cx-text-2">{[treatment.practitioner ? `by ${treatment.practitioner.name}` : null, treatment.progress.total ? `${treatment.progress.done} of ${treatment.progress.total} ${treatment.progress.unit}` : null].filter(Boolean).join(' · ')}</span>
        </span>
        <span className="flex flex-wrap items-center gap-1.5">
          {treatment.agreed_amount !== undefined && treatment.agreed_amount !== null && (
            <span className="text-[12.5px] font-semibold tabular-nums text-cx-nav-ink">
              {treatment.amount_scope === 'own_estimate' ? 'Your estimate ' : ''}
              {money(treatment.agreed_amount)}
            </span>
          )}
          <Pill tone={TREATMENT_TONE[treatment.status] ?? 'slate'}>{humanize(treatment.status)}</Pill>
        </span>
      </div>
      {treatment.progress.total ? <Progress done={treatment.progress.done} total={treatment.progress.total} /> : null}
      {pack && (
        <p className="mt-2 flex items-center gap-1.5 text-[12px] text-cx-violet-ink">
          <Package className="h-3.5 w-3.5" aria-hidden="true" />
          {pack.name}: {pack.remaining} of {pack.sessions_total} sessions left
        </p>
      )}
    </li>
  );
}

function CaseDetail({ row, onStep }: { row: CaseRow; onStep: (step: Step) => void }) {
  const tone = MODULE_TONE[row.module] ?? 'teal';

  return (
    <section aria-labelledby={`case-${row.id}-title`} className="overflow-hidden rounded-[24px] bg-white shadow-cx-card">
      <div className="relative overflow-hidden bg-gradient-to-br from-[#EEF5FF] via-[#F4F0FF] to-[#ECFBF7] px-5 py-5 sm:px-6">
        <span aria-hidden="true" className="pointer-events-none absolute -right-10 -top-12 h-40 w-40 rounded-full bg-[#9B7BF2]/15 blur-2xl" />
        <div className="relative flex flex-wrap items-start justify-between gap-4">
          <div className="flex min-w-0 items-start gap-3.5">
            <IconTile icon={MODULE_ICON[row.module] ?? Stethoscope} tone={tone} size="lg" />
            <div className="min-w-0">
              <h3 id={`case-${row.id}-title`} className="text-[18px] font-bold leading-tight text-cx-nav-ink [overflow-wrap:anywhere]">
                {row.title}
              </h3>
              <p className="mt-1 flex flex-wrap items-center gap-2 text-[12.5px] text-cx-text-2">
                <StatusPill status={row.status} />
                {row.branch}
              </p>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {row.actions.recommend && (
              <button type="button" className={BTN_SM} onClick={() => onStep({ kind: 'recommend', url: row.actions.recommend as string, title: 'Recommend completing this case' })}>
                Recommend completion
              </button>
            )}
            {row.actions.close && (
              <button type="button" className={BTN_PRIMARY} onClick={() => onStep({ kind: 'close', url: row.actions.close as string, title: 'Close this case' })}>
                Close case
              </button>
            )}
            {row.actions.reopen && (
              <button type="button" className={BTN_SM} onClick={() => onStep({ kind: 'reopen', url: row.actions.reopen as string, title: 'Reopen this case' })}>
                Reopen
              </button>
            )}
          </div>
        </div>
        <dl className="relative mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
          {(
            [
              [CalendarDays, 'Started', day(row.opened_at) ?? '—'],
              [ClipboardCheck, 'Linked visits', `${row.visits.count}`],
              [CalendarDays, row.closed_at ? 'Closed' : 'Last visit', day(row.closed_at ?? row.visits.last) ?? '—'],
              [UserRound, 'Lead', row.lead ?? '—'],
            ] as const
          ).map(([Icon, label, value]) => (
            <div key={label} className="rounded-2xl bg-white/80 px-3 py-2.5 backdrop-blur">
              <dt className="flex items-center gap-1.5 text-[11.5px] font-medium text-cx-text-3">
                <Icon className="h-3.5 w-3.5" aria-hidden="true" />
                {label}
              </dt>
              <dd className="mt-0.5 truncate text-[13.5px] font-semibold text-cx-nav-ink">{value}</dd>
            </div>
          ))}
        </dl>
      </div>

      <div className="space-y-5 px-5 py-5 sm:px-6">
        {row.complaint && row.complaint !== row.title && (
          <p className="rounded-2xl bg-cx-blue-bg/40 px-4 py-3 text-[13px] text-cx-text [overflow-wrap:anywhere]">
            <b className="text-cx-blue-ink">Complaint:</b> {row.complaint}
          </p>
        )}
        <div>
          <p className="flex items-center gap-1.5 text-[12px] font-semibold uppercase tracking-wide text-cx-text-3">
            <Users className="h-3.5 w-3.5" aria-hidden="true" /> Treated by on this case
          </p>
          <p className="mt-1.5 flex flex-wrap gap-1.5">
            {row.visits.treated_by.length === 0 ? <span className="text-[13px] text-cx-text-2">No one yet</span> : row.visits.treated_by.map((name) => <Pill key={name}>{name}</Pill>)}
          </p>
        </div>
        {row.treatments !== null && (
          <div>
            <p className="text-[12px] font-semibold uppercase tracking-wide text-cx-text-3">Treatment plans</p>
            {row.treatments.length === 0 ? (
              <Empty>No treatment started in this case’s consultations yet.</Empty>
            ) : (
              <ul className="mt-2 grid auto-rows-fr gap-2 md:grid-cols-2">
                {row.treatments.map((treatment) => (
                  <PlanRow key={treatment.id} treatment={treatment} />
                ))}
              </ul>
            )}
          </div>
        )}
        {row.status === 'completion_recommended' && (
          <p className="rounded-2xl bg-cx-amber-bg/50 px-4 py-3 text-[12.5px] text-cx-amber-ink">Completion has been recommended. The case closes once an authorised member closes it.</p>
        )}
        {(row.closed_at || row.discharge_summary) && (
          <div className="rounded-2xl bg-cx-green-bg/40 px-4 py-3 text-[12.5px] text-cx-text">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-cx-green-ink">{row.closed_at ? `Closed ${day(row.closed_at)}` : 'Closure summary'}</p>
            {row.discharge_summary && <p className="mt-1 whitespace-pre-line [overflow-wrap:anywhere]">{row.discharge_summary}</p>}
          </div>
        )}
      </div>
    </section>
  );
}

function Treatments({ endpoint }: { endpoint: string }) {
  const [page, setPage] = useState(1);
  const state = useEndpoint<TreatmentPage>(endpoint, page > 1 ? { page } : undefined);
  const data = state.data;

  return (
    <div className="grid gap-5 xl:grid-cols-12">
      <Card title="All treatments" caption="Every treatment for this patient, across cases" icon={ListChecks} tone="violet" className="xl:col-span-5">
        {!data ? (
          <Pending error={state.error} onRetry={state.reload} />
        ) : data.treatments.length === 0 ? (
          <Empty>No treatment started for this patient.</Empty>
        ) : (
          <ul className="space-y-3">
            {data.treatments.map((item) => (
              <li key={item.id}>
                <p className="flex flex-wrap items-center justify-between gap-2 text-[13px]">
                  <span className="font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{item.title}</span>
                  <Pill tone={TREATMENT_TONE[item.status] ?? 'slate'}>{humanize(item.status)}</Pill>
                </p>
                <p className="text-[12px] text-cx-text-2">
                  {[item.practitioner?.name, item.started_at ? `started ${day(item.started_at)}` : null, item.progress.total ? `${item.progress.done} of ${item.progress.total} ${item.progress.unit}` : null, item.next_stage ? `next: ${item.next_stage.name}` : null].filter(Boolean).join(' · ')}
                </p>
                {item.progress.total ? <Progress done={item.progress.done} total={item.progress.total} tone="mint" /> : null}
              </li>
            ))}
          </ul>
        )}
      </Card>
      <Card title="Procedures done" caption="Newest first" icon={ClipboardCheck} tone="mint" className="xl:col-span-7">
        {!data ? (
          <Pending error={state.error} onRetry={state.reload} />
        ) : data.data.length === 0 ? (
          <Empty>No procedures recorded yet.</Empty>
        ) : (
          <ul className="divide-y divide-cx-line">
            {data.data.map((row) => (
              <li key={row.id} className="flex flex-wrap items-start justify-between gap-2 py-2.5 text-[12.5px]">
                <span className="min-w-0">
                  <span className="block text-[13px] font-semibold text-cx-nav-ink">
                    {row.name}
                    {row.quantity > 1 && <span className="text-cx-text-3"> × {row.quantity}</span>}
                    {row.tooth && <span className="font-normal text-cx-text-2"> · tooth {row.tooth}</span>}
                  </span>
                  <span className="block text-cx-text-2">{[day(row.date), row.practitioner].filter(Boolean).join(' · ')}</span>
                  {row.notes && <span className="block text-cx-text-3 [overflow-wrap:anywhere]">{row.notes}</span>}
                </span>
                <span className="flex items-center gap-1.5">
                  {row.billed && <Pill tone="mint">Billed</Pill>}
                  {row.consultation_url && (
                    <Link href={row.consultation_url} className={BTN_SM}>
                      {row.consultation ?? 'Open'}
                    </Link>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}
        {data && <Pager page={data.meta.page} last={data.meta.last_page} onPage={setPage} busy={state.loading} />}
      </Card>
    </div>
  );
}

/**
 * Cases & Plans (spec §6): the patient's cases as a rail, the chosen one in
 * full — lead apart from who treated, plans, packages and the lifecycle steps
 * the server allows — then every treatment and procedure. The only zone with
 * case-specific data; the selected case opens here.
 */
export function CasesZone({ endpoint, treatmentEndpoint, selected, onSelect }: { endpoint: string; treatmentEndpoint: string | null; selected: number | null; onSelect: (id: number) => void }) {
  const state = useEndpoint<CasesPayload>(endpoint);
  const [step, setStep] = useState<Step | null>(null);
  const data = state.data;
  const current = data ? (data.data.find((row) => row.id === selected) ?? data.data[0] ?? null) : null;

  return (
    <div className="space-y-5">
      {!data ? (
        <Card title="Cases" icon={FolderHeart} tone="violet">
          <Pending error={state.error} onRetry={state.reload} />
        </Card>
      ) : data.data.length === 0 ? (
        <Card title="Cases" icon={FolderHeart} tone="violet">
          <Empty>No cases yet. A case opens when the patient is checked in for a doctor who may lead it.</Empty>
        </Card>
      ) : (
        <div className="grid gap-5 lg:grid-cols-12">
          <nav aria-label="Cases" className="min-w-0 lg:col-span-4">
            <ul className="flex gap-2.5 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible lg:pb-0">
              {data.data.map((row) => {
                const Icon = MODULE_ICON[row.module] ?? Stethoscope;
                const isCurrent = current?.id === row.id;

                return (
                  <li key={row.id} className="w-[260px] shrink-0 lg:w-auto">
                    <button
                      type="button"
                      aria-current={isCurrent ? 'true' : undefined}
                      onClick={() => onSelect(row.id)}
                      className={cn('flex h-full w-full items-start gap-3 rounded-[20px] bg-white p-3.5 text-left shadow-cx-card transition-all', FOCUS, isCurrent ? 'ring-2 ring-[#6B48DE]/40' : 'hover:-translate-y-0.5')}
                    >
                      <IconTile icon={Icon} tone={MODULE_TONE[row.module] ?? 'teal'} size="sm" />
                      <span className="min-w-0 flex-1">
                        <span className="block text-[13.5px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{row.title}</span>
                        <span className="mt-1 flex flex-wrap items-center gap-1.5 text-[12px] text-cx-text-2">
                          <StatusPill status={row.status} />
                        </span>
                        <span className="mt-1 block text-[12px] text-cx-text-2">{row.lead ? `Lead: ${row.lead}` : 'No lead yet'}</span>
                        <span className="block text-[12px] text-cx-text-3">{[`Started ${day(row.opened_at) ?? '—'}`, row.visits.last ? `last activity ${day(row.visits.last)}` : null].filter(Boolean).join(' · ')}</span>
                        {!row.module_offered && <span className="block text-[11.5px] text-cx-text-3">No longer offered here</span>}
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          </nav>
          <div className="min-w-0 lg:col-span-8">{current && <CaseDetail row={current} onStep={setStep} />}</div>
        </div>
      )}

      {data?.other_treatments && data.other_treatments.length > 0 && (
        <Card title="Plans not tied to a case" caption="Started outside a case’s consultations" icon={ListChecks} tone="slate">
          <ul className="grid auto-rows-fr gap-2 md:grid-cols-2 xl:grid-cols-3">
            {data.other_treatments.map((treatment) => (
              <PlanRow key={treatment.id} treatment={treatment} />
            ))}
          </ul>
        </Card>
      )}

      {treatmentEndpoint && <Treatments endpoint={treatmentEndpoint} />}

      {step && (
        <StepDialog
          step={step}
          onClose={() => setStep(null)}
          onDone={() => {
            setStep(null);
            void state.reload();
            router.reload({ only: ['active_cases'] });
          }}
        />
      )}
    </div>
  );
}
