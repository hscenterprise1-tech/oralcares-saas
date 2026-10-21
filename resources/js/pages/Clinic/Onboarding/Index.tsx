import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, CheckCircle2, Circle, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useClinicShared } from '@/clinic/context';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Notice } from '@/clinic/ui/Notice';
import { OnboardingBar } from '@/pages/ClinicPortal/Onboarding/components/OnboardingBar';

type ChecklistStep = { key: string; label: string; description: string | null; required: boolean; done: boolean };

type ChecklistProps = {
  clinicName: string | null;
  status: string;
  currentStep: string | null;
  steps: ChecklistStep[];
  canFinish: boolean;
  canManage: boolean;
};

/**
 * The setup checklist of an account onboarded before the guided setup
 * existed (`OnboardingController::legacyIndex`). Its steps are the account's
 * onboarding template, the same list Customer Success works from, so a step
 * ticked at HQ shows here as done. Only a member with settings rights ticks
 * steps or finishes; the server refuses finishing while a required step is open.
 */
export default function OnboardingChecklist({ clinicName, currentStep, steps, canFinish, canManage }: ChecklistProps) {
  const { errors } = useClinicShared();
  const [pending, setPending] = useState<string | null>(null);
  const [finishing, setFinishing] = useState(false);
  const done = steps.filter((step) => step.done).length;
  const percent = steps.length > 0 ? Math.round((done / steps.length) * 100) : 0;
  const busy = pending !== null || finishing;

  const markDone = (key: string) => {
    if (busy) {
      return;
    }
    setPending(key);
    router.post(route('clinic.onboarding.update'), { step: key }, { preserveScroll: true, onFinish: () => setPending(null) });
  };

  const finish = () => {
    if (busy) {
      return;
    }
    setFinishing(true);
    router.post(route('clinic.onboarding.complete'), {}, { preserveScroll: true, onFinish: () => setFinishing(false) });
  };

  return (
    <ClinicPortalShell title="Clinic setup" variant="setup" bar={<OnboardingBar clinicName={clinicName} logoutUrl={route('clinic.logout')} />}>
      <div className="mx-auto max-w-3xl">
        <p className="text-[12px] font-semibold uppercase tracking-wide text-cx-nav-teal-strong">Clinic setup</p>
        <h1 className="mt-1 text-[24px] font-semibold leading-tight tracking-tight text-cx-nav-ink sm:text-[26px]">
          {clinicName ? `Set up ${clinicName}` : 'Set up your clinic'}
        </h1>
        <p className="mt-1 max-w-2xl text-[13.5px] text-cx-nav-muted">
          Work through these steps to open the full portal. Your OralCares onboarding contact sees the same list.
        </p>
        <div className="mt-4 flex items-center gap-3">
          <div
            className="h-2 flex-1 overflow-hidden rounded-full bg-cx-nav-line"
            role="progressbar"
            aria-label="Setup progress"
            aria-valuemin={0}
            aria-valuemax={steps.length}
            aria-valuenow={done}
            aria-valuetext={`${done} of ${steps.length} steps done`}
          >
            <div className="h-full rounded-full bg-cx-nav-teal" style={{ width: `${percent}%` }} />
          </div>
          <span className="shrink-0 text-[12.5px] font-semibold text-cx-nav-ink">
            {done} of {steps.length} done
          </span>
        </div>

        {errors.step && (
          <Notice tone="danger" className="mt-5">
            {errors.step}
          </Notice>
        )}
        {!canManage && (
          <Notice tone="info" title="Your clinic owner is finishing setup" className="mt-5">
            You can use the workspace as soon as they are done.
          </Notice>
        )}

        <Card as="div" className="mt-5">
          {steps.length === 0 ? (
            <p className="px-5 py-6 text-[13px] text-cx-text-2">There are no setup steps for this account. Contact OralCares support.</p>
          ) : (
            <ol className="divide-y divide-cx-line">
              {steps.map((step, index) => {
                const isCurrent = step.key === currentStep && !step.done;

                return (
                  <li key={step.key} className={cn('flex flex-wrap items-start gap-3 px-5 py-4', isCurrent && 'bg-cx-nav-active/40')}>
                    {step.done ? (
                      <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-cx-nav-teal" aria-hidden="true" />
                    ) : (
                      <Circle className="mt-0.5 h-5 w-5 shrink-0 text-cx-text-3" aria-hidden="true" />
                    )}
                    <div className="min-w-0 flex-1">
                      <p className="text-[13.5px] font-semibold text-cx-nav-ink">
                        <span className="sr-only">Step {index + 1}: </span>
                        {step.label}
                        <span className="sr-only">{step.done ? ', done' : ', not done'}</span>
                      </p>
                      {step.description && <p className="mt-0.5 text-[12.5px] text-cx-text-2">{step.description}</p>}
                      <p className="mt-1 text-[11.5px] font-semibold uppercase tracking-wide text-cx-text-3">{step.required ? 'Required' : 'Optional'}</p>
                    </div>
                    {!step.done && canManage && (
                      <Button size="sm" icon={Check} loading={pending === step.key} loadingLabel="Saving…" disabled={busy} onClick={() => markDone(step.key)}>
                        Mark as done
                      </Button>
                    )}
                  </li>
                );
              })}
            </ol>
          )}
          {canManage && (
            <div className="flex flex-col gap-3 border-t border-cx-line px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
              <p className="flex items-center gap-1.5 text-[12.5px] text-cx-text-2">
                {!canFinish && <Lock className="h-3.5 w-3.5" aria-hidden="true" />}
                {canFinish ? 'Every required step is done.' : 'Finish every required step to open the portal.'}
              </p>
              <Button tone="primary" icon={Check} loading={finishing} loadingLabel="Finishing…" disabled={!canFinish || busy} onClick={finish}>
                Finish setup
              </Button>
            </div>
          )}
        </Card>
      </div>
    </ClinicPortalShell>
  );
}
