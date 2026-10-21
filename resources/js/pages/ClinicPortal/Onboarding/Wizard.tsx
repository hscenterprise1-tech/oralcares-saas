import { useCallback, useEffect, useRef, useState, type ComponentType, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { ArrowRight, Check, CircleDashed, CircleMinus, Clock, Lock, Pause, Play, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ERROR_COPY, errorKind, requestJson } from '@/clinic/http';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Notice } from '@/clinic/ui/Notice';
import {
  AppointmentsStep,
  BillingReceptionStep,
  ClinicalResourcesStep,
  ClinicIdentityStep,
  CommunicationStep,
  PrimaryBranchStep,
  ServicesStep,
  StaffInvitationsStep,
  WorkingHoursStep,
} from './components/ClinicSteps';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { OnboardingBar } from './components/OnboardingBar';
import { ReviewStep } from './components/ReviewStep';
import { MyDocumentsStep, MyProfessionalStep, MyProfileStep, MyScheduleStep, MyServicesStep } from './components/StaffSteps';
import { stepUrl, type CurrentStep, type OnboardingLinks, type OnboardingSession, type StepFormProps, type StepStatus, type StepSummary, type StepValues, type WizardProps } from './components/types';

/** Each section's `component()` and its form. `review` is handled on its own. */
const FORMS: Record<string, ComponentType<StepFormProps>> = {
  clinic_identity: ClinicIdentityStep,
  primary_branch: PrimaryBranchStep,
  working_hours: WorkingHoursStep,
  clinical_resources: ClinicalResourcesStep,
  services: ServicesStep,
  appointments: AppointmentsStep,
  billing_reception: BillingReceptionStep,
  communication: CommunicationStep,
  staff_invitations: StaffInvitationsStep,
  my_profile: MyProfileStep,
  my_professional: MyProfessionalStep,
  my_services: MyServicesStep,
  my_schedule: MyScheduleStep,
  my_documents: MyDocumentsStep,
};

const DRAFT_DELAY_MS = 1500;

function newIdempotencyKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
}

function timeOf(iso: string | null): string | null {
  if (!iso) {
    return null;
  }
  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? null : date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

/**
 * Clinic setup (and an invited staff member's own setup): the steps the
 * onboarding engine resolved for this clinic's template, one at a time,
 * auto-saved as drafts and applied only when submitted. Which session, which
 * clinic and who may edit are decided on the server; this page renders what
 * it is given.
 */
export default function Wizard(props: WizardProps) {
  if (props.mode === 'waiting') {
    return <Waiting kind={props.session.kind} clinicName={props.clinicName} links={props.links} />;
  }

  return <Editor {...props} />;
}

function Waiting({ kind, clinicName, links }: { kind: string; clinicName: string | null; links: OnboardingLinks }) {
  const [checking, setChecking] = useState(false);

  return (
    <ClinicPortalShell title="Clinic setup" variant="setup" bar={<OnboardingBar clinicName={clinicName} logoutUrl={links.logout} />}>
      <Card className="mx-auto max-w-xl px-6 py-8 text-center sm:px-8">
        <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-cx-nav-active text-cx-nav-teal-strong">
          <Clock className="h-6 w-6" aria-hidden="true" />
        </span>
        <h1 className="mt-4 text-[20px] font-semibold tracking-tight text-cx-nav-ink">{clinicName ? `${clinicName} is still being set up` : 'Your clinic is still being set up'}</h1>
        <p className="mx-auto mt-2 max-w-md text-[13.5px] text-cx-nav-muted">
          {kind === 'clinic'
            ? 'The clinic owner or an administrator is finishing setup. You can use the workspace as soon as they are done.'
            : 'Your setup is not ready to continue yet. Check again in a little while.'}
        </p>
        <Button
          className="mt-6"
          icon={RefreshCw}
          loading={checking}
          loadingLabel="Checking…"
          onClick={() => {
            setChecking(true);
            router.reload({ onFinish: () => setChecking(false) });
          }}
        >
          Check again
        </Button>
      </Card>
    </ClinicPortalShell>
  );
}

function Editor({ mode, session, steps, step, clinicName, links }: Extract<WizardProps, { mode: 'edit' | 'paused' }>) {
  const blocked = session.status === 'blocked';
  const isStaff = session.kind === 'staff';
  const position = Math.max(0, steps.findIndex((item) => item.key === step.key));
  const percent = session.progress.total > 0 ? Math.round((session.progress.done / session.progress.total) * 100) : 0;

  return (
    <ClinicPortalShell title={isStaff ? 'Your setup' : 'Clinic setup'} variant="setup" bar={<OnboardingBar clinicName={clinicName} logoutUrl={links.logout} />}>
      <div className="mb-6">
        <p className="text-[12px] font-semibold uppercase tracking-wide text-cx-nav-teal-strong">{isStaff ? 'Your setup' : `Clinic setup · ${session.template_label}`}</p>
        <h1 className="mt-1 text-[24px] font-semibold leading-tight tracking-tight text-cx-nav-ink sm:text-[26px]">
          {isStaff ? 'Set up your profile' : 'Set up your clinic'}
        </h1>
        <p className="mt-1 max-w-2xl text-[13.5px] text-cx-nav-muted">
          {isStaff
            ? 'A few details about you and your work. Each step is saved as you go.'
            : 'Work through each step once. Everything is saved as you go, so you can stop and come back. Finishing opens the clinic to your team.'}
        </p>
        <div className="mt-4 flex items-center gap-3">
          <div
            className="h-2 flex-1 overflow-hidden rounded-full bg-cx-nav-line"
            role="progressbar"
            aria-label="Setup progress"
            aria-valuemin={0}
            aria-valuemax={session.progress.total}
            aria-valuenow={session.progress.done}
            aria-valuetext={`${session.progress.done} of ${session.progress.total} steps done`}
          >
            <div className="h-full rounded-full bg-cx-nav-teal motion-safe:transition-[width]" style={{ width: `${percent}%` }} />
          </div>
          <span className="shrink-0 text-[12.5px] font-semibold text-cx-nav-ink">
            {session.progress.done} of {session.progress.total} done
          </span>
        </div>
      </div>

      {blocked && (
        <Notice tone="danger" title="Setup is on hold" className="mb-5">
          {session.blocked_reason ?? 'This clinic cannot be set up right now. Contact OralCares support.'}
        </Notice>
      )}
      {mode === 'paused' && !blocked && <PausedNotice links={links} />}

      <div className="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)] lg:items-start">
        <StepRail steps={steps} current={step.key} links={links} />
        <StepEditor
          // One editor per step: a failed save (which may come back with the
          // step now "in progress" from its draft) keeps what was typed and the errors.
          key={step.key}
          step={step}
          position={position}
          total={steps.length}
          isFinal={position === steps.length - 1}
          links={links}
          disabled={mode === 'paused' || blocked}
          canPause={mode === 'edit' && !blocked}
        />
      </div>
    </ClinicPortalShell>
  );
}

function PausedNotice({ links }: { links: OnboardingLinks }) {
  const [resuming, setResuming] = useState(false);

  return (
    <Notice
      tone="info"
      title="Setup is paused"
      className="mb-5"
      action={
        <Button
          tone="primary"
          size="sm"
          icon={Play}
          loading={resuming}
          loadingLabel="Resuming…"
          onClick={() => {
            setResuming(true);
            router.post(links.resume, {}, { preserveScroll: true, onFinish: () => setResuming(false) });
          }}
        >
          Resume
        </Button>
      }
    >
      Everything you entered is kept. Resume to carry on where you left off.
    </Notice>
  );
}

const RAIL_ICON: Record<StepStatus, typeof Check> = {
  completed: Check,
  skipped: CircleMinus,
  pending: CircleDashed,
  in_progress: CircleDashed,
  blocked: Lock,
};

function StepRail({ steps, current, links }: { steps: StepSummary[]; current: string; links: OnboardingLinks }) {
  const list = (
    <ol className="space-y-1">
      {steps.map((item, index) => {
        const Icon = RAIL_ICON[item.status] ?? CircleDashed;
        const active = item.key === current;
        const done = item.status === 'completed';

        return (
          <li key={item.key}>
            <button
              type="button"
              onClick={() => router.get(links.index, { step: item.key })}
              aria-current={active ? 'step' : undefined}
              className={cn(
                'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-[13px] motion-safe:transition-colors',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal [@media(pointer:coarse)]:min-h-11',
                active ? 'bg-cx-nav-active font-semibold text-cx-nav-ink' : 'text-cx-nav-muted hover:bg-cx-nav-hover hover:text-cx-nav-ink',
              )}
            >
              <span
                className={cn(
                  'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold',
                  done ? 'bg-cx-nav-teal text-white' : active ? 'border-2 border-cx-nav-teal text-cx-nav-teal-strong' : 'border border-cx-nav-line bg-white text-cx-nav-muted',
                )}
                aria-hidden="true"
              >
                {done ? <Icon className="h-3.5 w-3.5" strokeWidth={2.5} /> : index + 1}
              </span>
              <span className="min-w-0 flex-1 truncate">{item.title}</span>
              <span className="sr-only">
                {item.status === 'completed' ? ', done' : item.status === 'skipped' ? ', skipped' : item.required ? ', not done' : ', optional, not done'}
              </span>
              {!item.required && item.status !== 'completed' && (
                <span className="shrink-0 text-[10.5px] font-semibold uppercase tracking-wide text-cx-text-3" aria-hidden="true">
                  {item.status === 'skipped' ? 'Skipped' : 'Optional'}
                </span>
              )}
            </button>
          </li>
        );
      })}
    </ol>
  );

  return (
    <>
      <Card as="div" className="hidden p-2 lg:sticky lg:top-6 lg:block">
        <nav aria-label="Setup steps">{list}</nav>
      </Card>
      <Card as="div" className="lg:hidden">
        <details className="group">
          <summary className="flex cursor-pointer list-none items-center justify-between gap-2 rounded-2xl px-4 py-3 text-[13px] font-semibold text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal [@media(pointer:coarse)]:min-h-11">
            All steps
            <ArrowRight className="h-4 w-4 text-cx-nav-muted motion-safe:transition-transform group-open:rotate-90" aria-hidden="true" />
          </summary>
          <nav aria-label="Setup steps" className="px-2 pb-2">
            {list}
          </nav>
        </details>
      </Card>
    </>
  );
}

type DraftState = { state: 'idle' | 'saving' | 'saved' | 'failed'; at: string | null; message?: string };

function StepEditor({
  step,
  position,
  total,
  isFinal,
  links,
  disabled,
  canPause,
}: {
  step: CurrentStep;
  position: number;
  total: number;
  isFinal: boolean;
  links: OnboardingLinks;
  disabled: boolean;
  canPause: boolean;
}) {
  const [values, setValues] = useState<StepValues>(step.values);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<'submit' | 'skip' | 'pause' | null>(null);
  const [draft, setDraft] = useState<DraftState>({ state: step.has_draft ? 'saved' : 'idle', at: step.draft_saved_at });
  const latest = useRef(values);
  const dirty = useRef(false);
  const timer = useRef<number | null>(null);
  const idempotencyKey = useRef(newIdempotencyKey());
  const summaryRef = useRef<HTMLDivElement>(null);
  const locked = disabled || busy !== null;

  const saveDraft = useCallback(async () => {
    if (timer.current !== null) {
      window.clearTimeout(timer.current);
      timer.current = null;
    }
    if (!dirty.current || disabled) {
      return;
    }
    dirty.current = false;
    setDraft((current) => ({ ...current, state: 'saving' }));
    try {
      const saved = await requestJson<{ saved_at: string | null }>('PUT', stepUrl(links.draft, step.key), { body: { values: latest.current } });
      setDraft({ state: 'saved', at: saved.saved_at });
    } catch (error) {
      const kind = errorKind(error);
      if (kind === 'conflict') {
        // Setup was finished elsewhere; let the server say where to go.
        router.reload();

        return;
      }
      dirty.current = true;
      setDraft((current) => ({ ...current, state: 'failed', message: ERROR_COPY[kind] }));
    }
  }, [disabled, links.draft, step.key]);

  // Leaving the step (another step chosen) keeps what was typed as its draft.
  const draftUrl = stepUrl(links.draft, step.key);
  useEffect(
    () => () => {
      if (timer.current !== null) {
        window.clearTimeout(timer.current);
      }
      if (dirty.current && !disabled) {
        void requestJson('PUT', draftUrl, { body: { values: latest.current } }).catch(() => undefined);
      }
    },
    [disabled, draftUrl],
  );

  const set = (key: string, value: unknown) => {
    setValues((current) => {
      const next = { ...current, [key]: value };
      latest.current = next;

      return next;
    });
    dirty.current = true;
    if (timer.current !== null) {
      window.clearTimeout(timer.current);
    }
    timer.current = window.setTimeout(() => void saveDraft(), DRAFT_DELAY_MS);
  };

  const showErrors = (received: Record<string, string>) => {
    setErrors(received);
    window.requestAnimationFrame(() => summaryRef.current?.focus());
  };

  const stopDraftTimer = () => {
    if (timer.current !== null) {
      window.clearTimeout(timer.current);
      timer.current = null;
    }
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (locked) {
      return;
    }
    stopDraftTimer();
    // The submission carries these values; no draft is written behind it.
    const wasDirty = dirty.current;
    dirty.current = false;
    setBusy('submit');
    router.post(
      stepUrl(links.submit, step.key),
      { idempotency_key: idempotencyKey.current, values: latest.current } as unknown as Record<string, never>,
      {
        preserveScroll: false,
        preserveState: true,
        onSuccess: () => {
          idempotencyKey.current = newIdempotencyKey();
        },
        onError: (received) => {
          dirty.current = wasDirty;
          showErrors(received as Record<string, string>);
        },
        onFinish: () => setBusy(null),
      },
    );
  };

  const skip = () => {
    stopDraftTimer();
    dirty.current = false;
    setBusy('skip');
    router.post(stepUrl(links.skip, step.key), {}, { onError: (received) => showErrors(received as Record<string, string>), onFinish: () => setBusy(null) });
  };

  const pause = async () => {
    setBusy('pause');
    await saveDraft();
    router.post(links.pause, {}, { onFinish: () => setBusy(null) });
  };

  const goTo = async (key: string) => {
    await saveDraft();
    router.get(links.index, { step: key });
  };

  const form: StepFormProps = { values, set, errors, meta: step.meta, disabled: locked };
  const Form = FORMS[step.component];
  const isReview = step.component === 'review';
  const fieldErrors = Object.entries(errors).filter(([key]) => key !== 'step');
  const savedAt = timeOf(draft.at);

  return (
    <Card as="div" className="min-w-0">
      <form onSubmit={submit} noValidate aria-labelledby="step-title" aria-describedby="step-description">
        <div className="border-b border-cx-line px-5 py-4 sm:px-6">
          <p className="text-[12px] font-semibold text-cx-text-3">
            Step {position + 1} of {total}
            {!step.required && ' · Optional'}
          </p>
          <div className="mt-0.5 flex flex-wrap items-center gap-2">
            <h2 id="step-title" className="text-[18px] font-semibold tracking-tight text-cx-nav-ink">
              {step.title}
            </h2>
            {step.status === 'completed' && (
              <span className="inline-flex items-center gap-1 rounded-full bg-cx-green-bg px-2 py-0.5 text-[11px] font-semibold text-cx-green-ink">
                <Check className="h-3 w-3" aria-hidden="true" /> Saved
              </span>
            )}
            {step.status === 'skipped' && <span className="rounded-full bg-cx-slate-bg px-2 py-0.5 text-[11px] font-semibold text-cx-slate-ink">Skipped</span>}
          </div>
          <p id="step-description" className="mt-1 text-[13px] text-cx-nav-muted">
            {step.description}
          </p>
        </div>

        <div className="space-y-5 px-5 py-5 sm:px-6">
          <div ref={summaryRef} tabIndex={-1} className="space-y-2 focus:outline-none">
            {errors.step && <Notice tone="danger">{errors.step}</Notice>}
            {fieldErrors.length > 0 && (
              <Notice tone="danger" title="Some details need attention">
                <ul className="list-disc space-y-0.5 pl-4">
                  {fieldErrors.slice(0, 6).map(([key, message]) => (
                    <li key={key}>{message}</li>
                  ))}
                </ul>
              </Notice>
            )}
          </div>

          {isReview ? (
            <ReviewStep form={form} onEdit={(key) => void goTo(key)} />
          ) : Form ? (
            <Form {...form} />
          ) : (
            <Notice tone="warning" title="This step cannot be shown here">
              Contact OralCares support to finish it.
            </Notice>
          )}
        </div>

        <div className="flex flex-col-reverse gap-3 border-t border-cx-line px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <p className="min-h-5 text-[12px] text-cx-text-3" aria-live="polite">
            {draft.state === 'saving' && 'Saving draft…'}
            {draft.state === 'saved' && savedAt && `Draft saved at ${savedAt}`}
            {draft.state === 'failed' && <span className="font-medium text-clinic-danger">Draft not saved. {draft.message}</span>}
          </p>
          <div className="flex flex-wrap items-center justify-end gap-2">
            {canPause && (
              <Button tone="ghost" icon={Pause} onClick={() => void pause()} loading={busy === 'pause'} loadingLabel="Saving…" disabled={locked}>
                Finish later
              </Button>
            )}
            {!step.required && !isReview && step.status !== 'completed' && step.status !== 'skipped' && (
              <Button onClick={skip} loading={busy === 'skip'} loadingLabel="Skipping…" disabled={locked}>
                Skip for now
              </Button>
            )}
            {(Form || isReview) && (
              <Button type="submit" tone="primary" icon={isFinal ? Check : undefined} loading={busy === 'submit'} loadingLabel={isFinal ? 'Finishing…' : 'Saving…'} disabled={locked}>
                {isFinal ? 'Finish setup' : 'Save and continue'}
              </Button>
            )}
          </div>
        </div>
      </form>
    </Card>
  );
}
