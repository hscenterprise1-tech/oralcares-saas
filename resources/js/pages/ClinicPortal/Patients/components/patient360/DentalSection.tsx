import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { CheckCircle2, Printer } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Skeleton } from '@/clinic/ui/Spinner';
import { ErrorState, PermissionState } from '@/clinic/ui/states';
import { formatMoney, humanize } from '@/clinic/ui/visuals';
import { ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '@/clinic/http';
import { shortDate } from '../format';

/*
 * Dental (only where Dental is part of the clinic's plan — the server
 * refuses this section otherwise): the FDI chart with each tooth's state,
 * treatment plans with their real transitions, procedures done, imaging,
 * laboratory work, gum charting and recalls. Charting itself happens in the
 * consultation (`/consultations/{id}/dental/*`); completing a planned item
 * needs an open consultation, which the server names.
 *
 * Three views come from the server by role: clinical (everything), the
 * front desk (what is under way and lab status) and billing (plans and
 * estimates). This file draws whichever arrives.
 */

type StateStyle = { label: string; fill: string; stroke: string };
export type PlanItem = {
  id: number;
  description: string;
  tooth: string | number | null;
  surfaces: string[];
  status: string;
  price: number | null;
  completed_at: string | null;
  billed: boolean | null;
  complete_url: string | null;
};
type Plan = {
  id: number;
  title: string;
  status: string;
  author: string | null;
  created_at: string | null;
  accepted_at: string | null;
  done: number;
  total: number;
  estimate?: { total: number; done: number; priced: boolean } | null;
  actions?: {
    estimate_url?: string | null;
    accept_url: string | null;
    pause_url: string | null;
    resume_url: string | null;
    decline_url: string | null;
    cancel_url: string | null;
  };
  items: PlanItem[];
};
export type DentalPayload = {
  view: 'clinical' | 'operational' | 'financial';
  states: Record<string, string>;
  state_styles: Record<string, StateStyle>;
  ongoing?: {
    key: string;
    tooth: number | null;
    procedure: string;
    status: string;
    state: string;
    next_step?: string;
    source: string;
    price?: number | null;
  }[];
  summary?: {
    charted: number;
    dmft: {
      decayed: number;
      missing: number;
      filled: number;
      total: number;
    };
    open_plans: number;
    items_to_do: number;
    estimate_to_do: number | null;
  };
  teeth?: {
    tooth: number;
    status: string;
    surfaces: string[];
    notes: string | null;
    updated_at: string | null;
  }[];
  conditions?: Record<string, { label: string; surface: boolean }>;
  plans?: Plan[];
  procedures?: {
    name: string;
    tooth: number | string | null;
    surfaces: string[];
    date: string | null;
    consultation: string | null;
    billed: boolean;
  }[];
  imaging?: {
    name: string;
    status: string;
    summary: string | null;
    is_abnormal: boolean;
    date: string | null;
    teeth: number[];
  }[];
  images?: {
    id: number;
    name: string;
    teeth: number[];
    date: string | null;
    preview_url: string | null;
  }[];
  lab?: {
    id?: number;
    restoration?: string;
    laboratory?: string | null;
    status?: string;
    due?: string | null;
    teeth?: number[];
  }[];
  periodontal?: {
    latest: {
      recorded_on?: string;
      stage?: string | null;
      grade?: string | null;
      extent?: string | null;
    } | null;
  };
  recall?: {
    open: {
      id: number;
      due_on: string;
      reason: string | null;
      practitioner: string | null;
      status: string;
    }[];
  };
  follow_ups?: {
    id: number;
    due_on: string;
    reason: string | null;
    status: string;
  }[];
  open_consultation?: {
    id: number;
    number: string | null;
    url: string;
  } | null;
  outstanding?: number | null;
};

/**
 * The billing view's plans carry less (each item's `procedure`, no surfaces,
 * no counts or actions): fill the same shape so one screen draws all views.
 */
export function normalize(payload: DentalPayload): DentalPayload {
  const raw = payload.plans as unknown as (Omit<Partial<Plan>, 'items'> & { items: (Partial<PlanItem> & { procedure?: string })[] })[] | undefined;

  return {
    ...payload,
    plans: raw?.map((plan) => {
      const items = plan.items.map((item) => ({
        id: item.id as number,
        description: item.description ?? item.procedure ?? '',
        tooth: item.tooth ?? null,
        surfaces: item.surfaces ?? [],
        status: item.status ?? 'planned',
        price: item.price ?? null,
        completed_at: item.completed_at ?? null,
        billed: item.billed ?? null,
        complete_url: item.complete_url ?? null,
      }));

      return {
        id: plan.id as number,
        title: plan.title ?? '',
        status: plan.status ?? 'proposed',
        author: plan.author ?? null,
        created_at: plan.created_at ?? null,
        accepted_at: plan.accepted_at ?? null,
        done: plan.done ?? items.filter((item) => item.status === 'completed').length,
        total: plan.total ?? items.length,
        estimate: plan.estimate ?? null,
        actions: plan.actions,
        items,
      };
    }),
  };
}

const UPPER = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
const LOWER = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];

export function DentalSection({ endpoint }: { endpoint: string }) {
  const [data, setData] = useState<DentalPayload | null>(null);
  const [error, setError] = useState<ClinicHttpErrorKind | null>(null);
  const load = useCallback(() => {
    requestJson<DentalPayload>('GET', endpoint)
      .then((payload) => setData(normalize(payload)))
      .catch((failure) => errorKind(failure) !== 'aborted' && setError(errorKind(failure)));
  }, [endpoint]);
  useEffect(load, [load]);

  if (error === 'forbidden') {
    return <PermissionState />;
  }
  if (error) {
    return <ErrorState title="Dental records could not be loaded" description={ERROR_COPY[error]} onRetry={load} />;
  }
  if (!data) {
    return (
      <div role="status" aria-label="Loading dental records" className="grid gap-3">
        <Skeleton className="h-40 w-full rounded-2xl" />
        <Skeleton className="h-24 w-full rounded-2xl" />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {data.summary && <Summary summary={data.summary} />}
      <Chart data={data} />
      {data.plans && <Plans plans={data.plans} conditions={data.conditions ?? {}} openConsultation={data.open_consultation ?? null} onChanged={load} />}
      {data.ongoing && data.view !== 'clinical' && <Ongoing rows={data.ongoing} />}
      {data.procedures && data.procedures.length > 0 && (
        <Panel title="Procedures done">
          <ul className="divide-y divide-cx-line">
            {data.procedures.map((row, index) => (
              <li key={index} className="flex flex-wrap items-center justify-between gap-2 py-2 text-[13.5px]">
                <span className="min-w-0 [overflow-wrap:anywhere]">
                  <span className="font-semibold text-cx-text">{row.name}</span>
                  <span className="text-cx-text-2">
                    {' '}
                    · {[row.tooth ? `Tooth ${row.tooth}${row.surfaces.length ? ` (${row.surfaces.join('')})` : ''}` : null, shortDate(row.date), row.consultation].filter(Boolean).join(' · ')}
                  </span>
                </span>
                <Pill tone={row.billed ? 'green' : 'slate'}>{row.billed ? 'Billed' : 'Not billed'}</Pill>
              </li>
            ))}
          </ul>
        </Panel>
      )}
      {((data.imaging?.length ?? 0) > 0 || (data.images?.length ?? 0) > 0) && (
        <Panel title="X-rays and imaging">
          <ul className="divide-y divide-cx-line text-[13.5px]">
            {data.imaging?.map((row, index) => (
              <li key={`o-${index}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span className="min-w-0 [overflow-wrap:anywhere]">
                  <span className="font-semibold text-cx-text">{row.name}</span>
                  <span className="text-cx-text-2"> · {[row.teeth.length ? `Teeth ${row.teeth.join(', ')}` : null, shortDate(row.date), row.summary].filter(Boolean).join(' · ')}</span>
                </span>
                <span className="flex gap-1.5">
                  <Pill tone={row.status === 'reviewed' || row.status === 'resulted' ? 'green' : 'slate'}>{humanize(row.status)}</Pill>
                  {row.is_abnormal && <Pill tone="rose">Abnormal</Pill>}
                </span>
              </li>
            ))}
            {data.images?.map((image) => (
              <li key={`i-${image.id}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span className="min-w-0 [overflow-wrap:anywhere]">
                  <span className="font-semibold text-cx-text">{image.name}</span>
                  <span className="text-cx-text-2"> · {[image.teeth.length ? `Teeth ${image.teeth.join(', ')}` : null, shortDate(image.date)].filter(Boolean).join(' · ')}</span>
                </span>
                {image.preview_url && (
                  <a href={image.preview_url} target="_blank" rel="noopener" className="text-[13px] font-semibold text-cx-blue-ink hover:underline">
                    Open
                  </a>
                )}
              </li>
            ))}
          </ul>
        </Panel>
      )}
      {data.lab && data.lab.length > 0 && (
        <Panel title="Laboratory work">
          <ul className="divide-y divide-cx-line text-[13.5px]">
            {data.lab.map((order, index) => (
              <li key={order.id ?? index} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span className="min-w-0 [overflow-wrap:anywhere]">
                  <span className="font-semibold text-cx-text">{order.restoration ?? 'Lab case'}</span>
                  <span className="text-cx-text-2">
                    {' '}
                    · {[order.teeth?.length ? `Teeth ${order.teeth.join(', ')}` : null, order.laboratory, order.due ? `due ${shortDate(order.due)}` : null].filter(Boolean).join(' · ')}
                  </span>
                </span>
                {order.status && <Pill tone="blue">{humanize(order.status)}</Pill>}
              </li>
            ))}
          </ul>
        </Panel>
      )}
      {data.periodontal?.latest && (
        <Panel title="Gum health">
          <p className="text-[13.5px] text-cx-text">
            {[
              data.periodontal.latest.stage ? `Stage ${humanize(data.periodontal.latest.stage)}` : null,
              data.periodontal.latest.grade ? `grade ${humanize(data.periodontal.latest.grade)}` : null,
              data.periodontal.latest.extent ? humanize(data.periodontal.latest.extent) : null,
            ]
              .filter(Boolean)
              .join(' · ') || 'Charted'}
            {data.periodontal.latest.recorded_on && <span className="text-cx-text-2"> · {shortDate(data.periodontal.latest.recorded_on)}</span>}
          </p>
        </Panel>
      )}
      {(data.recall?.open.length ?? 0) > 0 && (
        <Panel title="Recalls">
          <ul className="divide-y divide-cx-line text-[13.5px]">
            {data.recall!.open.map((row) => (
              <li key={row.id} className="py-2">
                <span className="font-semibold text-cx-text">Due {shortDate(row.due_on)}</span>
                <span className="text-cx-text-2"> · {[row.reason, row.practitioner].filter(Boolean).join(' · ')}</span>
              </li>
            ))}
          </ul>
        </Panel>
      )}
    </div>
  );
}

type Tone = 'green' | 'slate' | 'rose' | 'blue' | 'amber';
const PILL: Record<Tone, string> = {
  green: 'bg-[#F0FDF4] text-[#166534] border border-[#BBF7D0]',
  slate: 'bg-slate-100 text-slate-700 border border-slate-200',
  rose: 'bg-[#FFF1F2] text-[#9F1239] border border-[#FECDD3]',
  blue: 'bg-[#EFF6FF] text-[#1E40AF] border border-[#BFDBFE]',
  amber: 'bg-[#FEFCE8] text-[#B45309] border border-[#FDE68A]',
};

function Pill({ tone, children }: { tone: Tone; children: ReactNode }) {
  return <span className={cn('inline-flex h-6 shrink-0 items-center whitespace-nowrap rounded-full px-2.5 text-[11.5px] font-bold border', PILL[tone])}>{children}</span>;
}

function Panel({ title, children, aside }: { title: string; children: ReactNode; aside?: ReactNode }) {
  const id = `dental-${title.toLowerCase().replace(/\W+/g, '-')}`;

  return (
    <section aria-labelledby={id} className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_2px_12px_rgba(15,23,42,0.04)]">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 id={id} className="text-[16px] font-bold tracking-tight text-slate-900">
          {title}
        </h2>
        {aside}
      </div>
      {children}
    </section>
  );
}

function Summary({ summary }: { summary: NonNullable<DentalPayload['summary']> }) {
  const items: [string, ReactNode][] = [
    ['Teeth charted', summary.charted],
    ['Open plans', summary.open_plans],
    ['Items to do', summary.items_to_do],
    ['DMFT', `${summary.dmft.total} (D${summary.dmft.decayed} M${summary.dmft.missing} F${summary.dmft.filled})`],
  ];
  if (summary.estimate_to_do !== null) {
    items.push(['Estimate to do', formatMoney(summary.estimate_to_do)]);
  }

  return (
    <dl className="grid grid-cols-2 gap-3 md:grid-cols-5">
      {items.map(([label, value]) => (
        <div key={label} className="rounded-xl border border-blue-100 bg-gradient-to-br from-blue-50/60 to-white p-3.5 shadow-xs">
          <dt className="text-[11.5px] font-bold uppercase tracking-wider text-slate-500">{label}</dt>
          <dd className="mt-1 whitespace-nowrap text-[18px] font-black tabular-nums text-slate-900">{value}</dd>
        </div>
      ))}
    </dl>
  );
}

/** The FDI chart: each tooth coloured by its state, with a legend; a tooth opens what is recorded for it. */
function Chart({ data }: { data: DentalPayload }) {
  const [tooth, setTooth] = useState<number | null>(null);
  const styleOf = (number: number) => {
    const state = data.states[String(number)];

    return state ? data.state_styles[state] : undefined;
  };
  const record = data.teeth?.find((row) => row.tooth === tooth);
  const ongoing = data.ongoing?.filter((row) => row.tooth === tooth) ?? [];
  const used = Array.from(new Set(Object.values(data.states)));

  const row = (numbers: number[]) => (
    <div className="grid grid-cols-8 gap-1 sm:grid-cols-[repeat(16,minmax(0,1fr))]">
      {numbers.map((number) => {
        const style = styleOf(number);

        return (
          <button
            key={number}
            type="button"
            onClick={() => setTooth(number)}
            aria-label={`Tooth ${number}${style ? `: ${style.label}` : ''}`}
            aria-pressed={tooth === number}
            className={cn(
              'grid h-11 place-items-center rounded-[9px] border-2 text-[12.5px] font-bold tabular-nums focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan',
              tooth === number && 'ring-2 ring-cx-cyan',
            )}
            style={{
              background: style?.fill ?? '#FFFFFF',
              borderColor: style?.stroke ?? '#E3E8EF',
              color: '#1E293B',
            }}
          >
            {number}
          </button>
        );
      })}
    </div>
  );

  return (
    <Panel title="Dental chart (FDI)">
      <div className="space-y-1.5">
        <p className="text-[12px] font-semibold text-cx-text-2">Upper</p>
        {row(UPPER)}
        {row(LOWER)}
        <p className="text-[12px] font-semibold text-cx-text-2">Lower</p>
      </div>
      {used.length > 0 && (
        <ul className="mt-3 flex flex-wrap gap-x-3 gap-y-1.5 text-[12.5px] text-cx-text-2" aria-label="Legend">
          {used.map((state) => (
            <li key={state} className="flex items-center gap-1.5">
              <span
                aria-hidden="true"
                className="h-3 w-3 rounded-[3px] border-2"
                style={{
                  background: data.state_styles[state]?.fill,
                  borderColor: data.state_styles[state]?.stroke,
                }}
              />
              {data.state_styles[state]?.label ?? humanize(state)}
            </li>
          ))}
        </ul>
      )}
      {tooth !== null && (
        <div className="mt-3 rounded-[12px] border border-cx-line p-3 text-[13.5px]" aria-live="polite">
          <p className="font-semibold text-cx-text">Tooth {tooth}</p>
          {record ? (
            <p className="text-cx-text-2">
              {[humanize(record.status), record.surfaces.length ? `surfaces ${record.surfaces.join('')}` : null, record.notes, record.updated_at ? `updated ${shortDate(record.updated_at)}` : null]
                .filter(Boolean)
                .join(' · ')}
            </p>
          ) : (
            !ongoing.length && <p className="text-cx-text-2">Nothing recorded for this tooth.</p>
          )}
          {ongoing.map((item) => (
            <p key={item.key} className="text-cx-text">
              {item.procedure} — <span className="text-cx-text-2">{item.next_step ?? humanize(item.status)}</span>
            </p>
          ))}
        </div>
      )}
    </Panel>
  );
}

function Ongoing({ rows }: { rows: NonNullable<DentalPayload['ongoing']> }) {
  return (
    <Panel title="Under way">
      {rows.length === 0 ? (
        <p className="text-[13.5px] text-cx-text-2">Nothing under way.</p>
      ) : (
        <ul className="divide-y divide-cx-line text-[13.5px]">
          {rows.map((row) => (
            <li key={row.key} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span className="min-w-0 [overflow-wrap:anywhere]">
                <span className="font-semibold text-cx-text">{row.procedure}</span>
                {row.tooth && <span className="text-cx-text-2"> · Tooth {row.tooth}</span>}
              </span>
              <Pill tone="blue">{humanize(row.status)}</Pill>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

const PLAN_STATUS: Record<string, Tone> = {
  proposed: 'amber',
  accepted: 'blue',
  in_progress: 'blue',
  paused: 'amber',
  completed: 'green',
  declined: 'slate',
  cancelled: 'slate',
};

/** Treatment plans with the transitions the server offers (accept, pause, resume, decline, cancel — with a reason where required). */
function Plans({
  plans,
  conditions,
  openConsultation,
  onChanged,
}: {
  plans: Plan[];
  conditions: Record<string, { label: string; surface: boolean }>;
  openConsultation: DentalPayload['open_consultation'] | null;
  onChanged: () => void;
}) {
  const [reasonFor, setReasonFor] = useState<{
    url: string;
    label: string;
  } | null>(null);
  const [completing, setCompleting] = useState<PlanItem | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const act = (url: string, data: Record<string, unknown> = {}) =>
    router.post(url, data as never, {
      preserveScroll: true,
      onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'Not done.'),
      onSuccess: () => {
        setProblem(null);
        setReasonFor(null);
        setCompleting(null);
        onChanged();
      },
    });

  return (
    <Panel
      title="Treatment plans"
      aside={
        openConsultation ? (
          <Link href={openConsultation.url} className="text-[13px] font-semibold text-cx-blue-ink hover:underline">
            Open consultation {openConsultation.number}
          </Link>
        ) : undefined
      }
    >
      {problem && (
        <p role="alert" className="mb-2 rounded-[10px] bg-cx-rose-bg px-3 py-2 text-[13px] font-semibold text-cx-rose-ink">
          {problem}
        </p>
      )}
      {plans.length === 0 ? (
        <p className="text-[13.5px] text-cx-text-2">No treatment plan yet. Plans are proposed in a consultation.</p>
      ) : (
        <div className="space-y-3">
          {plans.map((plan) => (
            <div key={plan.id} className="rounded-[12px] border border-cx-line p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="flex min-w-0 flex-wrap items-center gap-2 text-[14px] font-semibold text-cx-text [overflow-wrap:anywhere]">
                  {plan.title}
                  <Pill tone={PLAN_STATUS[plan.status] ?? 'slate'}>{humanize(plan.status)}</Pill>
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {plan.actions?.estimate_url && (
                    <a href={plan.actions.estimate_url} target="_blank" rel="noreferrer" className="inline-flex h-8 items-center gap-1.5 rounded-full border border-[#DCE6F3] bg-white px-3 text-[12.5px] font-semibold text-cx-nav-ink hover:bg-[#F8FAFC]">
                      <Printer className="h-3.5 w-3.5" aria-hidden="true" /> Estimate
                    </a>
                  )}
                  {plan.actions?.accept_url && (
                    <Button size="sm" onClick={() => act(plan.actions!.accept_url!)}>
                      Accept
                    </Button>
                  )}
                  {plan.actions?.resume_url && (
                    <Button size="sm" onClick={() => act(plan.actions!.resume_url!)}>
                      Resume
                    </Button>
                  )}
                  {plan.actions?.pause_url && (
                    <Button
                      size="sm"
                      tone="ghost"
                      onClick={() =>
                        setReasonFor({
                          url: plan.actions!.pause_url!,
                          label: 'Pause plan',
                        })
                      }
                    >
                      Pause
                    </Button>
                  )}
                  {plan.actions?.decline_url && (
                    <Button
                      size="sm"
                      tone="ghost"
                      onClick={() =>
                        setReasonFor({
                          url: plan.actions!.decline_url!,
                          label: 'Patient declined',
                        })
                      }
                    >
                      Declined
                    </Button>
                  )}
                  {plan.actions?.cancel_url && (
                    <Button
                      size="sm"
                      tone="ghost"
                      onClick={() =>
                        setReasonFor({
                          url: plan.actions!.cancel_url!,
                          label: 'Cancel plan',
                        })
                      }
                    >
                      Cancel
                    </Button>
                  )}
                </div>
              </div>
              <p className="text-[12.5px] text-cx-text-2">
                {[`${plan.done} of ${plan.total} done`, plan.estimate?.priced ? `estimate ${formatMoney(plan.estimate.total)}` : null, plan.author, shortDate(plan.accepted_at ?? plan.created_at)]
                  .filter(Boolean)
                  .join(' · ')}
              </p>
              <ul className="mt-2 divide-y divide-cx-line text-[13.5px]">
                {plan.items.map((item) => (
                  <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                    <span className="min-w-0 [overflow-wrap:anywhere]">
                      <span className="font-semibold text-cx-text">{item.description}</span>
                      <span className="text-cx-text-2">
                        {' '}
                        ·{' '}
                        {[
                          item.tooth ? `Tooth ${item.tooth}${item.surfaces.length ? ` (${item.surfaces.join('')})` : ''}` : null,
                          item.price !== null ? formatMoney(item.price) : null,
                          item.completed_at ? `done ${shortDate(item.completed_at)}` : null,
                        ]
                          .filter(Boolean)
                          .join(' · ')}
                      </span>
                    </span>
                    <span className="flex items-center gap-1.5">
                      {item.status === 'completed' ? (
                        <Pill tone="green">
                          <CheckCircle2 className="mr-1 h-3.5 w-3.5" aria-hidden="true" />
                          Done
                        </Pill>
                      ) : (
                        <Pill tone="slate">{humanize(item.status)}</Pill>
                      )}
                      {item.complete_url && item.status !== 'completed' && (
                        <Button size="sm" onClick={() => setCompleting(item)}>
                          Mark done
                        </Button>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}
      {reasonFor && <ReasonDialog title={reasonFor.label} onClose={() => setReasonFor(null)} onConfirm={(reason) => act(reasonFor.url, { reason })} />}
      {completing?.complete_url && <CompleteDialog item={completing} conditions={conditions} onClose={() => setCompleting(null)} onConfirm={(data) => act(completing.complete_url!, data)} />}
    </Panel>
  );
}

export function ReasonDialog({ title, onClose, onConfirm }: { title: string; onClose: () => void; onConfirm: (reason: string) => void }) {
  const [reason, setReason] = useState('');

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title={title}
      description="The reason is kept with the plan."
      size="sm"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Back
          </Button>
          <Button tone="primary" disabled={reason.trim().length < 3} onClick={() => onConfirm(reason.trim())}>
            Confirm
          </Button>
        </>
      }
    >
      <Field label="Reason" htmlFor="plan-reason" required>
        <Input id="plan-reason" value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} />
      </Field>
    </Modal>
  );
}

/** Record a planned item as done in the open consultation, with the tooth's resulting condition (required for surface work). */
export function CompleteDialog({
  item,
  conditions,
  onClose,
  onConfirm,
}: {
  item: PlanItem;
  conditions: Record<string, { label: string; surface: boolean }>;
  onClose: () => void;
  onConfirm: (data: Record<string, unknown>) => void;
}) {
  const choices = Object.entries(conditions).filter(([, condition]) => item.surfaces.length === 0 || condition.surface);
  const [condition, setCondition] = useState(item.surfaces.length > 0 ? (choices.find(([key]) => key === 'filled')?.[0] ?? '') : '');
  const [note, setNote] = useState('');

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title={`Mark done: ${item.description}`}
      description={item.tooth ? `Tooth ${item.tooth}${item.surfaces.length ? ` (${item.surfaces.join('')})` : ''}, in the open consultation.` : 'In the open consultation.'}
      size="sm"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Back
          </Button>
          <Button
            tone="primary"
            disabled={item.surfaces.length > 0 && condition === ''}
            onClick={() =>
              onConfirm({
                resulting_condition: condition || null,
                note: note.trim() || null,
              })
            }
          >
            Mark done
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Field label="Tooth afterwards" htmlFor="complete-condition" hint={item.surfaces.length ? 'Required for work on surfaces.' : 'Leave empty to keep the chart as it is.'}>
          <Select
            id="complete-condition"
            value={condition}
            onChange={(event) => setCondition(event.target.value)}
            placeholder={item.surfaces.length ? 'Choose' : 'No change'}
            options={choices.map(([value, entry]) => ({
              value,
              label: entry.label,
            }))}
          />
        </Field>
        <Field label="Note" htmlFor="complete-note">
          <Input id="complete-note" value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} />
        </Field>
      </div>
    </Modal>
  );
}
