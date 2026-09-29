import React from 'react';
import { router, usePage } from '@inertiajs/react';
import { CheckCircle2, Circle, ClipboardCheck, Lock } from 'lucide-react';
import { CrmButton, CrmPageHead, CrmPageStat } from '@/pages/Company/design';
import { Card } from '@/pages/Company/design/CrmCardPrimitives';
import { Badge } from '@/pages/Company/design/CrmBadgePrimitives';
import { cn } from '@/lib/utils';
import { ClinicShell } from '../components/ClinicShell';

/*
 * The setup checklist a new clinic sees until onboarding is finished. The
 * steps are the onboarding template for the clinic type — the same list
 * Customer Success works from — so a step ticked off at HQ shows here as done.
 */

interface OnboardingStep {
  key: string;
  label: string;
  description: string | null;
  required: boolean;
  done: boolean;
}

interface OnboardingPageProps {
  clinicName: string | null;
  status: string;
  currentStep: string | null;
  steps: OnboardingStep[];
  canFinish: boolean;
  canManage: boolean;
}

export default function OnboardingIndex({ clinicName, currentStep, steps, canFinish, canManage }: OnboardingPageProps) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [pendingStep, setPendingStep] = React.useState<string | null>(null);
  const [finishing, setFinishing] = React.useState(false);

  const doneCount = steps.filter((step) => step.done).length;

  const markDone = (key: string) => {
    setPendingStep(key);
    router.post(route('clinic.onboarding.update'), { step: key }, {
      preserveScroll: true,
      onFinish: () => setPendingStep(null),
    });
  };

  const finish = () => {
    setFinishing(true);
    router.post(route('clinic.onboarding.complete'), {}, { onFinish: () => setFinishing(false) });
  };

  return (
    <ClinicShell title="Clinic setup">
      <CrmPageHead
        eyebrow="Getting started"
        title={clinicName ? `Set up ${clinicName}` : 'Set up your clinic'}
        description="Work through these steps to open the full portal. Your OralCares onboarding contact sees the same list."
        stats={<CrmPageStat icon={ClipboardCheck} value={`${doneCount}/${steps.length}`} caption="Steps done" />}
        actions={
          canManage ? (
            <CrmButton tone="primary" onClick={finish} disabled={!canFinish} loading={finishing}>
              Finish setup
            </CrmButton>
          ) : undefined
        }
      />

      <div className="space-y-4 pb-12">
        {errors?.step && (
          <p role="alert" className="rounded-2xl border border-ovs-crm-accent-coral/40 bg-white px-4 py-3 text-[13px] font-semibold text-ovs-crm-text-primary">
            {errors.step}
          </p>
        )}

        {!canManage && (
          <Card elevation="surface" className="flex items-start gap-3 border border-ovs-crm-border p-4">
            <Lock className="mt-0.5 h-4 w-4 flex-shrink-0 text-ovs-crm-text-secondary" aria-hidden="true" />
            <p className="text-[13px] text-ovs-crm-text-secondary">
              Your clinic admin completes these steps. The portal opens for everyone once setup is finished.
            </p>
          </Card>
        )}

        {steps.length === 0 ? (
          <Card elevation="surface" className="border border-ovs-crm-border p-8 text-center">
            <p className="text-[13px] text-ovs-crm-text-secondary">
              There are no setup steps for this clinic. {canManage ? 'Finish setup to open the portal.' : 'Ask your clinic admin to finish setup.'}
            </p>
          </Card>
        ) : (
          <ol className="space-y-3">
            {steps.map((step, index) => {
              const isCurrent = !step.done && step.key === currentStep;

              return (
                <li key={step.key}>
                  <Card
                    elevation="surface"
                    className={cn(
                      'flex flex-col gap-3 border p-4 sm:flex-row sm:items-center sm:justify-between',
                      isCurrent ? 'border-ovs-crm-accent-teal-border' : 'border-ovs-crm-border',
                    )}
                  >
                    <div className="flex min-w-0 items-start gap-3">
                      {step.done ? (
                        <CheckCircle2 className="mt-0.5 h-5 w-5 flex-shrink-0 text-ovs-crm-accent-teal" aria-label="Done" />
                      ) : (
                        <Circle className="mt-0.5 h-5 w-5 flex-shrink-0 text-ovs-crm-text-muted" aria-label="Not done" />
                      )}
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-[11px] font-bold uppercase tracking-wide text-ovs-crm-text-secondary">Step {index + 1}</span>
                          <h2 className="text-[14px] font-bold text-ovs-crm-text-primary">{step.label}</h2>
                          {!step.required && <Badge size="compact">Optional</Badge>}
                          {isCurrent && <Badge variant="indigo" size="compact">Next</Badge>}
                        </div>
                        {step.description && <p className="mt-1 text-[13px] text-ovs-crm-text-secondary">{step.description}</p>}
                      </div>
                    </div>

                    <div className="flex-shrink-0 sm:pl-4">
                      {step.done ? (
                        <Badge variant="success" size="compact">Done</Badge>
                      ) : canManage ? (
                        <CrmButton size="sm" onClick={() => markDone(step.key)} loading={pendingStep === step.key}>
                          Mark as done
                        </CrmButton>
                      ) : (
                        <Badge size="compact">To do</Badge>
                      )}
                    </div>
                  </Card>
                </li>
              );
            })}
          </ol>
        )}

        {canManage && !canFinish && steps.length > 0 && (
          <p className="text-[13px] text-ovs-crm-text-secondary">Finish setup becomes available when every required step is done.</p>
        )}
      </div>
    </ClinicShell>
  );
}
