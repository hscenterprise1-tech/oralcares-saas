import React from 'react';
import { Sparkles, RefreshCw, AlertTriangle, CheckCircle2, ShieldAlert, ArrowRight, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';
import { TypingIndicator } from '@/ovs/ai/typing-indicator';

export type CopilotConfidenceTier = 'high' | 'moderate' | 'low';
export type CopilotState = 'idle' | 'loading' | 'streaming' | 'success' | 'needs-review' | 'error' | 'no-context';

export interface CopilotAction {
  label: string;
  onClick: () => void;
  variant?: 'primary' | 'secondary' | 'outline' | 'danger';
  icon?: React.ElementType;
  disabled?: boolean;
}

export interface OVSContextCopilotProps {
  title?: string;
  entityContext?: string;
  confidence?: CopilotConfidenceTier;
  state?: CopilotState;
  summary: string;
  evidenceTitle?: string;
  evidence?: string[];
  actions?: CopilotAction[];
  streamingContent?: string;
  errorMessage?: string;
  onRefresh?: () => void;
  onCancelStreaming?: () => void;
  className?: string;
}

export function OVSContextCopilot({
  title = 'AI Contextual Copilot',
  entityContext,
  confidence = 'high',
  state = 'success',
  summary,
  evidenceTitle = 'Why this is recommended',
  evidence = [],
  actions = [],
  streamingContent,
  errorMessage = 'AI recommendation engine temporarily unavailable.',
  onRefresh,
  onCancelStreaming,
  className,
}: OVSContextCopilotProps) {
  const confidenceBadges = {
    high: {
      label: 'High Confidence',
      style: 'bg-emerald-50 text-emerald-800 border-emerald-200/80',
    },
    moderate: {
      label: 'Moderate · Needs Review',
      style: 'bg-amber-50 text-amber-800 border-amber-200/80',
    },
    low: {
      label: 'Advisory Only',
      style: 'bg-slate-100 text-slate-700 border-slate-200',
    },
  }[confidence];

  return (
    <div
      role="region"
      aria-label="AI Contextual Copilot"
      className={cn(
        'bg-white rounded-xl border border-slate-200/90 shadow-2xs font-sans text-slate-900 overflow-hidden',
        className
      )}
    >
      {/* ── 1. HEADER STRIP (ENTITY CONTEXT & CONFIDENCE) ───────────────────── */}
      <div className="p-3.5 sm:p-4 bg-slate-50/80 border-b border-slate-100 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2.5 min-w-0">
          <div className="w-6 h-6 rounded-md bg-teal-50 border border-teal-200/80 flex items-center justify-center text-teal-700 shrink-0">
            <Sparkles className="w-3.5 h-3.5" />
          </div>
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <h4 className="text-xs font-bold text-slate-900 tracking-tight truncate">{title}</h4>
              <span
                className={cn(
                  'text-[9.5px] font-mono font-bold uppercase tracking-wider px-1.5 py-0.2 rounded border shrink-0',
                  confidenceBadges.style
                )}
              >
                {confidenceBadges.label}
              </span>
            </div>
            {entityContext && (
              <p className="text-[11px] text-slate-500 font-normal truncate mt-0.5">{entityContext}</p>
            )}
          </div>
        </div>

        {onRefresh && state !== 'loading' && state !== 'streaming' && (
          <button
            type="button"
            onClick={onRefresh}
            title="Refresh AI Analysis"
            className="w-7 h-7 rounded-md bg-white border border-slate-200 hover:bg-slate-50 text-slate-500 hover:text-slate-900 flex items-center justify-center transition-colors cursor-pointer shrink-0"
          >
            <RefreshCw className="w-3.5 h-3.5" />
          </button>
        )}
      </div>

      {/* ── 2. STATE CONTAINER ──────────────────────────────────────────────── */}
      <div className="p-4 sm:p-5 space-y-4">
        {/* Loading / Thinking State */}
        {state === 'loading' && (
          <div className="py-4 space-y-3">
            <TypingIndicator label="Synthesizing clinic telemetry and interaction history…" />
            <div className="space-y-1.5 animate-pulse">
              <div className="h-3.5 bg-slate-100 rounded w-3/4" />
              <div className="h-3.5 bg-slate-100 rounded w-1/2" />
            </div>
          </div>
        )}

        {/* Error State */}
        {state === 'error' && (
          <div className="p-3.5 rounded-lg bg-rose-50/70 border border-rose-200/80 text-xs text-rose-900 flex items-start gap-3">
            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
            <div className="space-y-1 flex-1">
              <div className="font-bold">Recommendation Engine Unavailable</div>
              <p className="text-[11.5px] text-rose-700 leading-relaxed">{errorMessage}</p>
              {onRefresh && (
                <button
                  type="button"
                  onClick={onRefresh}
                  className="font-bold underline text-rose-900 hover:text-rose-950 mt-1 cursor-pointer"
                >
                  Retry Analysis
                </button>
              )}
            </div>
          </div>
        )}

        {/* No Context State */}
        {state === 'no-context' && (
          <div className="py-4 text-center text-xs text-slate-500 space-y-1">
            <p className="font-semibold text-slate-700">No telemetry recorded for this record yet.</p>
            <p className="text-[11px] text-slate-400">Add an activity or communication note to generate contextual AI insights.</p>
          </div>
        )}

        {/* Streaming State */}
        {state === 'streaming' && (
          <div className="space-y-3">
            <div className="text-xs text-slate-800 leading-relaxed whitespace-pre-line font-mono bg-slate-50 p-3 rounded-lg border border-slate-100">
              {streamingContent || summary}
              <span className="inline-block w-1.5 h-3 bg-teal-600 ml-1 animate-pulse" />
            </div>
            {onCancelStreaming && (
              <div className="flex justify-end">
                <Button size="sm" variant="outline" onClick={onCancelStreaming} className="h-7 text-xs gap-1">
                  <XCircle className="w-3 h-3 text-slate-400" />
                  <span>Cancel Generation</span>
                </Button>
              </div>
            )}
          </div>
        )}

        {/* Success or Needs-Review State */}
        {(state === 'success' || state === 'needs-review' || state === 'idle') && (
          <>
            {/* Operational Summary / Interpretation */}
            <div className="text-xs font-semibold text-slate-900 leading-relaxed">
              {summary}
            </div>

            {/* Evidence Block (Why this is recommended) */}
            {evidence.length > 0 && (
              <div className="p-3 rounded-lg bg-slate-50/80 border border-slate-200/70 space-y-1.5">
                <span className="text-[10px] font-mono font-bold uppercase tracking-wider text-slate-500 block">
                  {evidenceTitle}:
                </span>
                <ul className="space-y-1 text-xs text-slate-700">
                  {evidence.map((item, idx) => (
                    <li key={idx} className="flex items-start gap-2 text-[11.5px]">
                      <span className="w-1.5 h-1.5 rounded-full bg-teal-600 shrink-0 mt-1.5" />
                      <span>{item}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {/* Executable Business Action Group */}
            {actions.length > 0 && (
              <div className="pt-2 border-t border-slate-100 flex flex-wrap items-center gap-2">
                {actions.map((act, idx) => {
                  const Icon = act.icon;
                  return (
                    <Button
                      key={idx}
                      size="sm"
                      variant={act.variant || (idx === 0 ? 'primary' : 'outline')}
                      disabled={act.disabled}
                      onClick={act.onClick}
                      className="cursor-pointer gap-1.5"
                    >
                      {Icon && <Icon className="w-3.5 h-3.5" />}
                      <span>{act.label}</span>
                    </Button>
                  );
                })}
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
