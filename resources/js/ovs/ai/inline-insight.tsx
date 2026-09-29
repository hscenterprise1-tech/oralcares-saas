import React from 'react';
import { Sparkles, AlertTriangle, CheckCircle2, ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';

export type InlineInsightSeverity = 'neutral' | 'info' | 'warning' | 'critical' | 'success';
export type InlineInsightConfidence = 'high' | 'moderate' | 'low';

export interface OVSInlineInsightProps {
  label?: string;
  message: string;
  severity?: InlineInsightSeverity;
  confidence?: InlineInsightConfidence;
  icon?: React.ElementType;
  action?: {
    label: string;
    onClick: () => void;
  };
  size?: 'sm' | 'md';
  className?: string;
}

export function OVSInlineInsight({
  label = 'AI Insight',
  message,
  severity = 'info',
  confidence,
  icon: Icon = Sparkles,
  action,
  size = 'md',
  className,
}: OVSInlineInsightProps) {
  const severityStyles = {
    neutral: {
      container: 'bg-slate-50 border-slate-200/80 text-slate-800',
      icon: 'text-slate-500',
      badge: 'bg-slate-200/70 text-slate-700',
    },
    info: {
      container: 'bg-teal-50/70 border-teal-200/70 text-teal-950',
      icon: 'text-teal-700',
      badge: 'bg-teal-100 text-teal-800 border border-teal-200/60',
    },
    warning: {
      container: 'bg-amber-50/70 border-amber-200/80 text-amber-950',
      icon: 'text-amber-700',
      badge: 'bg-amber-100 text-amber-800 border border-amber-200/60',
    },
    critical: {
      container: 'bg-rose-50/70 border-rose-200/80 text-rose-950',
      icon: 'text-rose-700',
      badge: 'bg-rose-100 text-rose-800 border border-rose-200/60',
    },
    success: {
      container: 'bg-emerald-50/70 border-emerald-200/80 text-emerald-950',
      icon: 'text-emerald-700',
      badge: 'bg-emerald-100 text-emerald-800 border border-emerald-200/60',
    },
  }[severity];

  const sizeStyles = {
    sm: {
      container: 'px-2.5 py-1 text-[11px] gap-2 rounded-md',
      icon: 'w-3 h-3',
      label: 'text-[9.5px]',
    },
    md: {
      container: 'px-3 py-1.5 text-xs gap-2.5 rounded-lg',
      icon: 'w-3.5 h-3.5',
      label: 'text-[10px]',
    },
  }[size];

  const confidenceLabels = {
    high: 'High Confidence',
    moderate: 'Needs Review',
    low: 'Advisory',
  };

  return (
    <div
      role="status"
      aria-live="polite"
      className={cn(
        'inline-flex items-center border font-sans select-none max-w-full',
        severityStyles.container,
        sizeStyles.container,
        className
      )}
    >
      <div className="flex items-center gap-1.5 shrink-0">
        <Icon className={cn(sizeStyles.icon, severityStyles.icon, 'shrink-0')} />
        {label && (
          <span
            className={cn(
              'font-mono font-bold uppercase tracking-wider px-1.5 py-0.2 rounded shrink-0',
              sizeStyles.label,
              severityStyles.badge
            )}
          >
            {label}
          </span>
        )}
      </div>

      <span className="font-medium truncate leading-tight flex-1 min-w-0">
        {message}
      </span>

      {confidence && (
        <span className="text-[9.5px] font-mono text-slate-400 shrink-0 hidden sm:inline">
          · {confidenceLabels[confidence]}
        </span>
      )}

      {action && (
        <button
          type="button"
          onClick={action.onClick}
          className="ml-auto inline-flex items-center gap-1 font-bold text-teal-800 hover:text-teal-950 hover:underline cursor-pointer shrink-0 text-[11px]"
        >
          <span>{action.label}</span>
          <ArrowRight className="w-3 h-3" />
        </button>
      )}
    </div>
  );
}
