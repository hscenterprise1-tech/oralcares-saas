import React from 'react';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Progress } from '@/ovs/feedback/progress';

export interface OnboardingChecklistItem {
  id: string;
  label: string;
  description?: string;
  completed: boolean;
  onClick?: () => void;
}

export interface OnboardingChecklistProps {
  items: OnboardingChecklistItem[];
  title?: string;
  subtitle?: string;
  className?: string;
}

export function OnboardingChecklist({
  items,
  title = 'Getting Started Checklist',
  subtitle = 'Complete these setup tasks to get your clinic operationally ready',
  className,
}: OnboardingChecklistProps) {
  const completedCount = items.filter((i) => i.completed).length;
  const totalCount = items.length;
  const progressPercent = totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 0;

  return (
    <div className={cn('p-6 bg-white border border-ovs-border-subtle rounded-2xl shadow-2xs space-y-6', className)}>
      {/* Header & Progress Bar */}
      <div className="space-y-3 pb-4 border-b border-ovs-border-subtle">
        <div className="flex items-center justify-between gap-4">
          <div>
            <h3 className="text-base font-bold text-ovs-primary tracking-tight">{title}</h3>
            {subtitle && <p className="text-xs text-ovs-secondary mt-0.5">{subtitle}</p>}
          </div>
          <div className="text-right">
            <span className="text-xs font-mono font-bold text-ovs-accent">
              {progressPercent}% Complete
            </span>
            <span className="text-[10px] text-ovs-secondary block font-mono">
              {completedCount} / {totalCount} Tasks
            </span>
          </div>
        </div>

        {/* Reusing OVS Progress component */}
        <Progress value={progressPercent} className="h-2" />
      </div>

      {/* Checklist Items Feed */}
      <div className="space-y-3">
        {items.map((item) => (
          <div
            key={item.id}
            onClick={item.onClick}
            className={cn(
              'flex items-start gap-3.5 p-3.5 rounded-xl border transition-all cursor-pointer text-left',
              item.completed
                ? 'bg-ovs-semantic-success-bg/20 border-ovs-semantic-success/20'
                : 'bg-white border-ovs-border-subtle hover:border-ovs-border-strong hover:bg-ovs-background/50'
            )}
          >
            {/* Checkmark Circle */}
            <div
              className={cn(
                'w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5 transition-colors',
                item.completed
                  ? 'bg-ovs-semantic-success text-white'
                  : 'border-2 border-ovs-border-strong bg-white text-transparent'
              )}
            >
              <Check className="w-3.5 h-3.5" />
            </div>

            {/* Label & Description */}
            <div className="flex-1 min-w-0">
              <span
                className={cn(
                  'text-xs font-bold block transition-all',
                  item.completed
                    ? 'line-through text-ovs-secondary'
                    : 'text-ovs-primary'
                )}
              >
                {item.label}
              </span>
              {item.description && (
                <p className="text-[11px] text-ovs-secondary mt-0.5 leading-snug">{item.description}</p>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
