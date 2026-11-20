import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Field, Input } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Skeleton } from '@/clinic/ui/Spinner';
import { ErrorState, PermissionState } from '@/clinic/ui/states';
import { humanize } from '@/clinic/ui/visuals';
import { ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '@/clinic/http';
import { shortDate } from '../format';

/*
 * Physiotherapy (only where Physio is part of the clinic's plan — the server
 * refuses this section otherwise): the plan of care with its goals and
 * home exercises, each session (SOAP, pain before and after, exercises done),
 * session packages with what is left, outcome and pain trends from the
 * recorded scores, and assessments. Sessions and assessments are written in
 * the consultation; this section reads them. No tooth chart, no dental terms.
 */

export type Physio = {
  assessments: {
    id: number;
    kind: string;
    date: string | null;
    by: string | null;
    consultation: string | null;
    presenting_problem: string | null;
    diagnosis: string | null;
    pain_at_rest: number | null;
    pain_on_activity: number | null;
    goals: string | null;
    clinical_impression: string | null;
    functional_measures: {
      measure: string | null;
      label: string;
      max: number | null;
      score: number | null;
    }[];
  }[];
  plan: {
    id: number;
    title: string;
    goals: string | null;
    frequency: string | null;
    planned: number | null;
    done: number;
    starts_on: string | null;
    exercises: {
      id: number;
      name: string;
      sets: number | null;
      reps: number | null;
      hold_seconds: number | null;
      frequency: string | null;
      instructions: string | null;
      is_home: boolean;
    }[];
  } | null;
  discharges: {
    id: number;
    title: string;
    status: string;
    closed_at: string | null;
    note: string | null;
    done: number;
    planned: number | null;
  }[];
  sessions: {
    id: number;
    number: number | null;
    date: string | null;
    therapist: string | null;
    subjective: string | null;
    objective: string | null;
    assessment: string | null;
    plan: string | null;
    treatment_performed: string | null;
    exercises_performed: { name?: string }[];
    pain_before: number | null;
    pain_after: number | null;
    package: string | null;
    consultation: string | null;
    signed: boolean;
    consultation_url: string;
  }[];
  packages: {
    id: number;
    name: string;
    status: string;
    total: number;
    used: number;
    remaining: number;
    valid_until: string | null;
    expired: boolean;
  }[];
  progress: {
    pain: {
      session_number: number | null;
      pain_before: number | null;
      pain_after: number | null;
      date: string | null;
    }[];
    outcomes: {
      measure: string;
      label: string;
      max: number | null;
      points: { date: string | null; score: number | null }[];
    }[];
  };
  open_consultation: {
    id: number;
    number: string | null;
    url: string;
  } | null;
  /** `sell_package` only for billing staff, and only while a plan of care is active (a package pays for that treatment). */
  links: { sell_package: string | null; sell_treatment_id: number | null };
};

export function PhysioSection({ endpoint }: { endpoint: string }) {
  const [data, setData] = useState<Physio | null>(null);
  const [error, setError] = useState<ClinicHttpErrorKind | null>(null);
  const load = useCallback(() => {
    requestJson<Physio>('GET', endpoint)
      .then(setData)
      .catch((failure) => errorKind(failure) !== 'aborted' && setError(errorKind(failure)));
  }, [endpoint]);
  useEffect(load, [load]);

  if (error === 'forbidden') {
    return <PermissionState />;
  }
  if (error) {
    return <ErrorState title="Physiotherapy records could not be loaded" description={ERROR_COPY[error]} onRetry={load} />;
  }
  if (!data) {
    return <Skeleton className="h-40 w-full rounded-2xl" />;
  }

  return (
    <div className="space-y-4">
      <Panel
        title="Plan of care"
        aside={
          data.open_consultation ? (
            <Link href={data.open_consultation.url} className="text-[13px] font-semibold text-cx-blue-ink hover:underline">
              Open session {data.open_consultation.number}
            </Link>
          ) : undefined
        }
      >
        {data.plan ? (
          <div className="space-y-2 text-[13.5px]">
            <p className="font-semibold text-cx-text">{data.plan.title}</p>
            <p className="text-cx-text-2">
              {[data.plan.planned ? `${data.plan.done} of ${data.plan.planned} sessions` : `${data.plan.done} sessions`, data.plan.frequency, data.plan.starts_on ? `from ${shortDate(data.plan.starts_on)}` : null]
                .filter(Boolean)
                .join(' · ')}
            </p>
            {data.plan.goals && <p className="whitespace-pre-line text-cx-text [overflow-wrap:anywhere]">Goals: {data.plan.goals}</p>}
            {data.plan.exercises.length > 0 && (
              <div>
                <p className="text-[12.5px] font-semibold text-cx-text-2">Exercises</p>
                <ul className="mt-1 divide-y divide-cx-line">
                  {data.plan.exercises.map((exercise) => (
                    <li key={exercise.id} className="py-1.5">
                      <span className="font-semibold text-cx-text">{exercise.name}</span>
                      <span className="text-cx-text-2">
                        {' '}
                        ·{' '}
                        {[
                          exercise.sets && exercise.reps ? `${exercise.sets} × ${exercise.reps}` : null,
                          exercise.hold_seconds ? `hold ${exercise.hold_seconds}s` : null,
                          exercise.frequency,
                          exercise.is_home ? 'home programme' : null,
                        ]
                          .filter(Boolean)
                          .join(' · ')}
                      </span>
                      {exercise.instructions && <span className="block text-[12.5px] text-cx-text-2">{exercise.instructions}</span>}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        ) : (
          <p className="text-[13.5px] text-cx-text-2">No active plan of care. Plans are started in a session.</p>
        )}
      </Panel>

      {(data.packages.length > 0 || data.links.sell_package) && (
        <Panel
          title="Session packages"
          aside={
            data.links.sell_package && data.links.sell_treatment_id ? (
              <SellPackage url={data.links.sell_package} treatmentId={data.links.sell_treatment_id} planTitle={data.plan?.title ?? null} onSold={load} />
            ) : undefined
          }
        >
          {data.packages.length === 0 && <p className="text-[13.5px] text-cx-text-2">No package sold for this patient yet.</p>}
          <ul className="divide-y divide-cx-line text-[13.5px]">
            {data.packages.map((item) => (
              <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span className="min-w-0 [overflow-wrap:anywhere]">
                  <span className="font-semibold text-cx-text">{item.name}</span>
                  <span className="text-cx-text-2">
                    {' '}
                    · {item.used} of {item.total} used
                    {item.valid_until ? ` · valid until ${shortDate(item.valid_until)}` : ''}
                  </span>
                </span>
                <Pill tone={item.expired ? 'slate' : item.remaining > 0 ? 'green' : 'amber'}>{item.expired ? 'Expired' : `${item.remaining} left`}</Pill>
              </li>
            ))}
          </ul>
        </Panel>
      )}

      {(data.progress.pain.length > 1 || data.progress.outcomes.some((outcome) => outcome.points.length > 1)) && (
        <Panel title="Progress">
          {data.progress.pain.length > 1 && (
            <div>
              <p className="text-[12.5px] font-semibold text-cx-text-2">Pain (0–10), before → after each session</p>
              <ul className="mt-1 flex flex-wrap gap-1.5 text-[12.5px]">
                {data.progress.pain.map((point, index) => (
                  <li key={index} className="rounded-[8px] bg-cx-slate-bg px-2 py-0.5 tabular-nums">
                    #{point.session_number ?? index + 1}: {point.pain_before ?? '–'} → {point.pain_after ?? '–'}
                  </li>
                ))}
              </ul>
            </div>
          )}
          {data.progress.outcomes
            .filter((outcome) => outcome.points.length > 1)
            .map((outcome) => (
              <p key={outcome.measure} className="mt-2 text-[13px] text-cx-text">
                <span className="font-semibold">{outcome.label}</span>
                <span className="text-cx-text-2">: {outcome.points.map((point) => `${point.score}${outcome.max ? `/${outcome.max}` : ''} (${shortDate(point.date)})`).join(' → ')}</span>
              </p>
            ))}
        </Panel>
      )}

      <Panel title="Sessions">
        {data.sessions.length === 0 ? (
          <p className="text-[13.5px] text-cx-text-2">No sessions yet.</p>
        ) : (
          <ul className="space-y-2.5">
            {data.sessions.map((session) => (
              <li key={session.id} className="rounded-[12px] border border-cx-line p-3 text-[13.5px]">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-semibold text-cx-text">
                    Session {session.number ?? ''} · {shortDate(session.date)}
                  </p>
                  <span className="flex items-center gap-1.5">
                    {(session.pain_before !== null || session.pain_after !== null) && (
                      <Pill tone="blue">
                        Pain {session.pain_before ?? '–'} → {session.pain_after ?? '–'}
                      </Pill>
                    )}
                    <Link href={session.consultation_url} className="text-[13px] font-semibold text-cx-blue-ink hover:underline">
                      {session.consultation ?? 'Open'}
                    </Link>
                  </span>
                </div>
                <p className="text-[12.5px] text-cx-text-2">{[session.therapist, session.package, session.signed ? 'signed' : 'not signed'].filter(Boolean).join(' · ')}</p>
                <dl className="mt-1.5 grid gap-1 sm:grid-cols-2">
                  {(['subjective', 'objective', 'assessment', 'plan'] as const).map((key) =>
                    session[key] ? (
                      <div key={key}>
                        <dt className="text-[12px] font-semibold uppercase tracking-wide text-cx-text-2">{key.charAt(0)}</dt>
                        <dd className="whitespace-pre-line text-cx-text [overflow-wrap:anywhere]">{session[key]}</dd>
                      </div>
                    ) : null,
                  )}
                </dl>
                {session.exercises_performed.length > 0 && (
                  <p className="mt-1 text-[12.5px] text-cx-text-2">
                    Exercises:{' '}
                    {session.exercises_performed
                      .map((exercise) => exercise.name)
                      .filter(Boolean)
                      .join(', ')}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <Panel title="Assessments">
        {data.assessments.length === 0 ? (
          <p className="text-[13.5px] text-cx-text-2">No assessment yet.</p>
        ) : (
          <ul className="divide-y divide-cx-line text-[13.5px]">
            {data.assessments.map((assessment) => (
              <li key={assessment.id} className="py-2">
                <p className="font-semibold text-cx-text">
                  {humanize(assessment.kind)} assessment · {shortDate(assessment.date)}
                </p>
                <p className="text-cx-text-2">
                  {[
                    assessment.by,
                    assessment.consultation,
                    assessment.pain_at_rest !== null ? `pain at rest ${assessment.pain_at_rest}` : null,
                    assessment.pain_on_activity !== null ? `on activity ${assessment.pain_on_activity}` : null,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                </p>
                {assessment.presenting_problem && <p className="text-cx-text [overflow-wrap:anywhere]">{assessment.presenting_problem}</p>}
                {assessment.diagnosis && <p className="text-cx-text [overflow-wrap:anywhere]">Diagnosis: {assessment.diagnosis}</p>}
              </li>
            ))}
          </ul>
        )}
      </Panel>

      {data.discharges.length > 0 && (
        <Panel title="Completed plans">
          <ul className="divide-y divide-cx-line text-[13.5px]">
            {data.discharges.map((plan) => (
              <li key={plan.id} className="py-2">
                <span className="font-semibold text-cx-text">{plan.title}</span>
                <span className="text-cx-text-2"> · {[humanize(plan.status), `${plan.done}${plan.planned ? ` of ${plan.planned}` : ''} sessions`, shortDate(plan.closed_at)].filter(Boolean).join(' · ')}</span>
                {plan.note && <p className="text-cx-text-2 [overflow-wrap:anywhere]">{plan.note}</p>}
              </li>
            ))}
          </ul>
        </Panel>
      )}
    </div>
  );
}

type Tone = 'green' | 'slate' | 'blue' | 'amber';
const PILL: Record<Tone, string> = {
  green: 'bg-[#F0FDF4] text-[#166534] border border-[#BBF7D0]',
  slate: 'bg-slate-100 text-slate-700 border border-slate-200',
  blue: 'bg-[#EFF6FF] text-[#1E40AF] border border-[#BFDBFE]',
  amber: 'bg-[#FEFCE8] text-[#B45309] border border-[#FDE68A]',
};

function Pill({ tone, children }: { tone: Tone; children: ReactNode }) {
  return <span className={cn('inline-flex h-6 shrink-0 items-center whitespace-nowrap rounded-full px-2.5 text-[11.5px] font-bold border', PILL[tone])}>{children}</span>;
}

/** Sells a prepaid block of sessions for the active plan's treatment; the key made when the dialog opens stops a double sale. */
export function SellPackage({ url, treatmentId, planTitle, onSold }: { url: string; treatmentId: number; planTitle: string | null; onSold: () => void }) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ name: '', sessions_total: '10', price: '', valid_until: '' });
  const [key, setKey] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const start = () => {
    setForm({ name: planTitle ? `${planTitle} package` : 'Session package', sessions_total: '10', price: '', valid_until: '' });
    setKey(`pkg-p360-${treatmentId}-${Date.now()}`);
    setProblem(null);
    setOpen(true);
  };
  const sell = () => {
    if (busy) {
      return;
    }
    setBusy(true);
    router.post(
      url,
      {
        patient_treatment_id: treatmentId,
        name: form.name.trim(),
        sessions_total: Number(form.sessions_total),
        price: form.price === '' ? null : Number(form.price),
        valid_until: form.valid_until || null,
        idempotency_key: key,
      },
      {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'The package was not sold.'),
        onSuccess: () => {
          setOpen(false);
          onSold();
        },
        onFinish: () => setBusy(false),
      },
    );
  };

  return (
    <>
      <Button size="sm" onClick={start}>
        Sell a package
      </Button>
      <Modal
        open={open}
        onOpenChange={setOpen}
        title="Sell a session package"
        description="Prepaid sessions for the active plan of care. Each completed session can use one."
        size="sm"
        footer={
          <>
            <Button tone="ghost" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button tone="primary" loading={busy} disabled={busy || form.name.trim() === '' || form.sessions_total === ''} onClick={sell}>
              Sell package
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          {problem && (
            <p role="alert" className="rounded-[10px] bg-cx-rose-bg px-3 py-2 text-[13px] font-semibold text-cx-rose-ink">
              {problem}
            </p>
          )}
          <Field label="Package" htmlFor="p360-pkg-name">
            <Input id="p360-pkg-name" value={form.name} maxLength={150} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </Field>
          <div className="grid gap-2 sm:grid-cols-2">
            <Field label="Sessions" htmlFor="p360-pkg-n">
              <Input id="p360-pkg-n" type="number" min={1} max={200} value={form.sessions_total} onChange={(event) => setForm({ ...form, sessions_total: event.target.value })} />
            </Field>
            <Field label="Price" htmlFor="p360-pkg-price" hint="Leave empty to bill it separately.">
              <Input id="p360-pkg-price" type="number" min={0} value={form.price} onChange={(event) => setForm({ ...form, price: event.target.value })} />
            </Field>
          </div>
          <Field label="Valid until (optional)" htmlFor="p360-pkg-until">
            <Input id="p360-pkg-until" type="date" value={form.valid_until} onChange={(event) => setForm({ ...form, valid_until: event.target.value })} />
          </Field>
        </div>
      </Modal>
    </>
  );
}

function Panel({ title, children, aside }: { title: string; children: ReactNode; aside?: ReactNode }) {
  const id = `physio-${title.toLowerCase().replace(/\W+/g, '-')}`;

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
