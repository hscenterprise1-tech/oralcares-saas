import React from 'react';
import { Check, ChevronRight, ChevronLeft } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';

export interface StepItem {
  id: string;
  label: string;
  description?: string;
  content: React.ReactNode;
}

export interface StepperProps {
  steps: StepItem[];
  currentStep: number;
  onStepChange: (stepIndex: number) => void;
  onComplete?: () => void;
  className?: string;
}

export function Stepper({
  steps,
  currentStep,
  onStepChange,
  onComplete,
  className,
}: StepperProps) {
  const isFirst = currentStep === 0;
  const isLast = currentStep === steps.length - 1;
  const activeStepObj = steps[currentStep] || steps[0];

  const handleNext = () => {
    if (isLast) {
      if (onComplete) onComplete();
    } else {
      onStepChange(currentStep + 1);
    }
  };

  const handleBack = () => {
    if (!isFirst) {
      onStepChange(currentStep - 1);
    }
  };

  return (
    <div className={cn('space-y-6', className)}>
      {/* Horizontal Step Header */}
      <div className="p-6 bg-white border border-ovs-border-subtle rounded-2xl shadow-2xs">
        <div className="relative flex items-center justify-between">
          {/* Connecting Line background */}
          <div className="absolute top-4 left-6 right-6 h-0.5 bg-ovs-border-subtle z-0" />
          
          {steps.map((step, idx) => {
            const isCompleted = idx < currentStep;
            const isActive = idx === currentStep;

            return (
              <div key={step.id} className="relative z-10 flex flex-col items-center group">
                <button
                  type="button"
                  onClick={() => onStepChange(idx)}
                  className={cn(
                    'w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold transition-all shadow-2xs ring-4 ring-white',
                    isCompleted
                      ? 'bg-ovs-semantic-success text-white'
                      : isActive
                      ? 'bg-ovs-accent text-white ring-ovs-accent/20'
                      : 'bg-ovs-background text-ovs-secondary border border-ovs-border-subtle'
                  )}
                >
                  {isCompleted ? <Check className="w-4 h-4" /> : idx + 1}
                </button>
                <div className="mt-2 text-center">
                  <span className={cn(
                    'block text-xs font-bold tracking-tight transition-colors',
                    isActive ? 'text-ovs-accent' : isCompleted ? 'text-ovs-primary' : 'text-ovs-secondary'
                  )}>
                    {step.label}
                  </span>
                  {step.description && (
                    <span className="block text-[10px] text-ovs-secondary/70 max-w-[100px] truncate mt-0.5">
                      {step.description}
                    </span>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Step Content Container */}
      <div className="p-6 bg-white border border-ovs-border-subtle rounded-2xl shadow-2xs space-y-6">
        <div className="pb-4 border-b border-ovs-border-subtle">
          <span className="text-[10px] font-bold text-ovs-accent uppercase font-mono tracking-wider block mb-1">
            Step {currentStep + 1} of {steps.length}
          </span>
          <h3 className="text-base font-bold text-ovs-primary">{activeStepObj?.label}</h3>
          {activeStepObj?.description && (
            <p className="text-xs text-ovs-secondary mt-0.5">{activeStepObj.description}</p>
          )}
        </div>

        <div className="min-h-[160px]">{activeStepObj?.content}</div>

        {/* Stepper Navigation Footer */}
        <div className="flex items-center justify-between pt-4 border-t border-ovs-border-subtle">
          <Button
            type="button"
            variant="secondary"
            size="md"
            onClick={handleBack}
            disabled={isFirst}
            className="gap-1.5"
          >
            <ChevronLeft className="w-4 h-4" />
            <span>Back</span>
          </Button>

          <Button
            type="button"
            variant="primary"
            size="md"
            onClick={handleNext}
            className="gap-1.5"
          >
            <span>{isLast ? 'Complete Wizard' : 'Next Step'}</span>
            <ChevronRight className="w-4 h-4" />
          </Button>
        </div>
      </div>
    </div>
  );
}
