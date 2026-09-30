import React, { useState } from 'react';
import { cn } from '@/lib/utils';
import {
  Check,
  ChevronRight,
  ChevronLeft,
  AlertTriangle,
  Loader2,
  Save,
  CheckCircle2,
} from 'lucide-react';
import { Button } from '@/ovs/primitives/button';

export interface WizardStep {
  id: string;
  title: string;
  description?: string;
  isOptional?: boolean;
  content?: React.ReactNode;
  validate?: () => boolean | string | null | Promise<boolean | string | null>;
}

export interface OVSWizardStepperProps {
  steps: WizardStep[];
  currentStep: number;
  onStepChange: (stepIndex: number) => void;
  onComplete?: () => void;
  onSaveAndExit?: () => void;
  allowStepJump?: boolean;
  density?: 'compact' | 'default' | 'comfortable';
  responsiveMode?: 'auto' | 'desktop' | 'tablet' | 'mobile';
  nextLabel?: string;
  completeLabel?: string;
  backLabel?: string;
  saveAndExitLabel?: string;
  isSubmitting?: boolean;
  children?: React.ReactNode;
  className?: string;
}

export const OVSWizardStepper: React.FC<OVSWizardStepperProps> = ({
  steps,
  currentStep,
  onStepChange,
  onComplete,
  onSaveAndExit,
  allowStepJump = true,
  density = 'default',
  responsiveMode = 'auto',
  nextLabel = 'Next Step',
  completeLabel = 'Complete',
  backLabel = 'Back',
  saveAndExitLabel = 'Save & Exit',
  isSubmitting = false,
  children,
  className,
}) => {
  const [validationError, setValidationError] = useState<string | null>(null);
  const [isValidating, setIsValidating] = useState(false);

  const isFirst = currentStep === 0;
  const isLast = currentStep === steps.length - 1;
  const activeStep = steps[currentStep] || steps[0];

  const handleNext = async () => {
    setValidationError(null);

    // Run step validation if configured
    if (activeStep.validate) {
      setIsValidating(true);
      try {
        const result = await activeStep.validate();
        setIsValidating(false);

        if (result === false) {
          setValidationError('Please complete all required fields before proceeding.');
          return;
        } else if (typeof result === 'string' && result.trim()) {
          setValidationError(result);
          return;
        }
      } catch (err: any) {
        setIsValidating(false);
        setValidationError(err.message || 'Validation failed for this step.');
        return;
      }
    }

    if (isLast) {
      if (onComplete) onComplete();
    } else {
      onStepChange(currentStep + 1);
    }
  };

  const handleBack = () => {
    setValidationError(null);
    if (!isFirst) {
      onStepChange(currentStep - 1);
    }
  };

  const handleStepJump = (targetIndex: number) => {
    if (!allowStepJump) return;
    if (targetIndex < currentStep) {
      setValidationError(null);
      onStepChange(targetIndex);
    }
  };

  const progressPercent = Math.round(((currentStep + 1) / steps.length) * 100);

  const isDesktop = responsiveMode === 'desktop' || responsiveMode === 'auto';
  const isMobile = responsiveMode === 'mobile';
  const isTablet = responsiveMode === 'tablet';

  return (
    <div className={cn('w-full space-y-3 font-sans text-slate-900', className)}>
      {/* ─── 1. STEP CONTEXT STRIP (FLAT, NON-DOMINANT PROGRESS CONTEXT) ─────── */}
      <nav aria-label="Wizard Steps" className="w-full">
        {/* Desktop Step Rail */}
        {(!isMobile && !isTablet) && (
          <ol
            className={cn(
              'items-center w-full p-0 m-0 list-none py-1',
              responsiveMode === 'auto' ? 'hidden md:flex' : 'flex'
            )}
          >
            {steps.map((step, idx) => {
              const isCompleted = idx < currentStep;
              const isCurrent = idx === currentStep;
              const isUpcoming = idx > currentStep;
              const isJumpable = isCompleted && allowStepJump;
              const isLastStep = idx === steps.length - 1;

              return (
                <React.Fragment key={step.id}>
                  {/* Step Item */}
                  <li
                    aria-current={isCurrent ? 'step' : undefined}
                    className="flex items-center shrink-0"
                  >
                    <button
                      type="button"
                      disabled={!isJumpable && !isCurrent}
                      onClick={() => isJumpable && handleStepJump(idx)}
                      className={cn(
                        'flex items-center gap-1.5 text-left transition-all select-none',
                        isJumpable ? 'cursor-pointer hover:opacity-85 focus:outline-none focus:ring-1 focus:ring-teal-500/30 rounded p-0.5 -m-0.5' : 'cursor-default'
                      )}
                    >
                      {/* Quiet Node */}
                      <div
                        className={cn(
                          'w-4.5 h-4.5 rounded-full flex items-center justify-center font-mono font-bold shrink-0 transition-all text-[9.5px]',
                          isCompleted && 'bg-teal-50 text-teal-700 border border-teal-200/90',
                          isCurrent && 'bg-slate-900 text-white shadow-2xs ring-2 ring-slate-900/10',
                          isUpcoming && 'bg-white text-slate-400 border border-slate-200 font-normal'
                        )}
                      >
                        {isCompleted ? <Check className="w-2.5 h-2.5 stroke-[2.5]" /> : idx + 1}
                      </div>

                      {/* Step Title */}
                      <div className="shrink-0 whitespace-nowrap">
                        <span
                          className={cn(
                            'text-[11.5px] tracking-tight',
                            isCurrent && 'font-bold text-slate-900',
                            isCompleted && 'font-medium text-slate-700',
                            isUpcoming && 'font-normal text-slate-400'
                          )}
                        >
                          {step.title}
                        </span>
                        {step.isOptional && (
                          <span className="text-[9px] text-slate-400 font-normal ml-1">
                            (Opt)
                          </span>
                        )}
                      </div>
                    </button>
                  </li>

                  {/* Hairline Connector between nodes */}
                  {!isLastStep && (
                    <div
                      className={cn(
                        'flex-1 min-w-[12px] h-px mx-2.5 transition-colors rounded-full shrink',
                        isCompleted ? 'bg-teal-600/70' : 'bg-slate-200'
                      )}
                      aria-hidden="true"
                    />
                  )}
                </React.Fragment>
              );
            })}
          </ol>
        )}

        {/* Compact Mode Progress Header (Tablet/Mobile) */}
        {(isMobile || isTablet || responsiveMode === 'auto') && (
          <div
            className={cn(
              'space-y-1.5 py-1.5 px-3 bg-slate-50/80 border border-slate-200/80 rounded-lg text-xs',
              responsiveMode === 'auto' ? 'md:hidden' : ''
            )}
          >
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-mono font-bold text-slate-500 uppercase">
                Step {currentStep + 1} of {steps.length}: <strong className="text-slate-900 font-bold">{activeStep.title}</strong>
              </span>
              <span className="text-[10px] font-mono text-slate-500">
                {progressPercent}% Complete
              </span>
            </div>
            <div className="w-full h-1 bg-slate-200 rounded-full overflow-hidden">
              <div
                className="h-full bg-teal-600 rounded-full transition-all duration-200"
                style={{ width: `${progressPercent}%` }}
              />
            </div>
          </div>
        )}
      </nav>

      {/* ─── 2. ACTIVE WORKSPACE (SINGLE OVS SURFACE — WHERE USER WORKS) ───────── */}
      <div className="bg-white rounded-xl border border-slate-200/90 shadow-2xs overflow-hidden">
        {/* Unified Step Header */}
        <div className="px-5 sm:px-6 py-3.5 border-b border-slate-100 bg-slate-50/30 flex items-center justify-between">
          <div>
            <h2 className="text-sm font-bold text-slate-900 tracking-tight">
              {activeStep.title}
            </h2>
            {activeStep.description && (
              <p className="text-xs text-slate-500 mt-0.5">{activeStep.description}</p>
            )}
          </div>
          <span className="text-[10px] font-mono font-bold text-teal-700 bg-teal-50 px-2 py-0.5 rounded border border-teal-200/60 uppercase">
            Step {currentStep + 1} of {steps.length}
          </span>
        </div>

        {/* Inline Step Validation Notice */}
        {validationError && (
          <div className="mx-5 sm:mx-6 mt-3.5 p-3 bg-rose-50 border border-rose-200 rounded-lg text-xs text-rose-900 flex items-start gap-2 animate-in fade-in-0 duration-150">
            <AlertTriangle className="w-4 h-4 text-rose-600 flex-shrink-0 mt-0.5" />
            <div>
              <div className="font-semibold text-rose-900">Validation Notice</div>
              <div className="text-rose-700 text-[11px] mt-0.5">{validationError}</div>
            </div>
          </div>
        )}

        {/* Step Body Canvas */}
        <div className="p-5 sm:p-6 min-h-[190px]">
          {children || activeStep.content}
        </div>

        {/* ─── 3. ATTACHED ACTION FOOTER ──────────────────────────────────────── */}
        <div className="flex items-center justify-between px-5 sm:px-6 py-3.5 border-t border-slate-100 bg-slate-50/30">
          <Button
            type="button"
            variant="secondary"
            size="md"
            onClick={handleBack}
            disabled={isFirst || isSubmitting || isValidating}
            className="gap-1.5 cursor-pointer text-xs"
          >
            <ChevronLeft className="w-3.5 h-3.5" />
            <span>{backLabel}</span>
          </Button>

          <div className="flex items-center gap-2">
            {onSaveAndExit && (
              <Button
                type="button"
                variant="outline"
                size="md"
                onClick={onSaveAndExit}
                disabled={isSubmitting || isValidating}
                className="hidden sm:inline-flex gap-1.5 cursor-pointer text-slate-600 hover:text-slate-900 text-xs"
              >
                <Save className="w-3.5 h-3.5" />
                <span>{saveAndExitLabel}</span>
              </Button>
            )}

            <Button
              type="button"
              variant="primary"
              size="md"
              onClick={handleNext}
              disabled={isSubmitting || isValidating}
              className="gap-1.5 cursor-pointer min-w-[95px] sm:min-w-[105px] text-xs"
            >
              {isValidating ? (
                <>
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  <span>Validating...</span>
                </>
              ) : isLast ? (
                <>
                  <span>{completeLabel}</span>
                  <CheckCircle2 className="w-3.5 h-3.5" />
                </>
              ) : (
                <>
                  <span>{nextLabel}</span>
                  <ChevronRight className="w-3.5 h-3.5" />
                </>
              )}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};
