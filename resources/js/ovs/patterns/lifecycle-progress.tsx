import React from 'react';
import { cn } from '@/lib/utils';
import {
  Check,
  PauseCircle,
  AlertTriangle,
  Minus,
} from 'lucide-react';

export type LifecycleStageStatus =
  | 'completed'
  | 'current'
  | 'upcoming'
  | 'blocked'
  | 'error'
  | 'skipped'
  | 'optional';

export interface LifecycleStage {
  id: string;
  label: string;
  description?: string;
  status?: LifecycleStageStatus;
  timestamp?: string;
  actor?: string;
  subtext?: string;
  isOptional?: boolean;
}

export interface OVSLifecycleProgressProps {
  stages: LifecycleStage[];
  currentStageId?: string;
  orientation?: 'horizontal' | 'vertical';
  density?: 'compact' | 'default' | 'comfortable';
  responsiveMode?: 'auto' | 'desktop' | 'tablet' | 'mobile';
  interactive?: boolean;
  onStageClick?: (stage: LifecycleStage, index: number) => void;
  showTelemetry?: boolean;
  className?: string;
}

export const OVSLifecycleProgress: React.FC<OVSLifecycleProgressProps> = ({
  stages,
  currentStageId,
  orientation = 'horizontal',
  density = 'default',
  responsiveMode = 'auto',
  interactive = false,
  onStageClick,
  showTelemetry = true,
  className,
}) => {
  // Determine active index
  const currentIndex = currentStageId
    ? stages.findIndex((s) => s.id === currentStageId)
    : stages.findIndex((s) => s.status === 'current');

  const activeStageIdx = currentIndex >= 0 ? currentIndex : 0;
  const activeStage = stages[activeStageIdx] || stages[0];

  const resolveStatus = (stage: LifecycleStage, index: number): LifecycleStageStatus => {
    if (stage.status) return stage.status;
    if (currentIndex >= 0) {
      if (index < currentIndex) return 'completed';
      if (index === currentIndex) return 'current';
      return 'upcoming';
    }
    return 'upcoming';
  };

  const isVertical = orientation === 'vertical';

  const nodeSizes = {
    compact: 'w-4.5 h-4.5 text-[9px]',
    default: 'w-5 h-5 text-[10px]',
    comfortable: 'w-5.5 h-5.5 text-[10.5px]',
  }[density];

  const iconSizes = {
    compact: 'w-2.5 h-2.5',
    default: 'w-3 h-3',
    comfortable: 'w-3.5 h-3.5',
  }[density];

  const renderNodeIcon = (status: LifecycleStageStatus, idx: number, isOptional?: boolean) => {
    switch (status) {
      case 'completed':
        return (
          <div className={cn('rounded-full bg-teal-50 text-teal-700 border border-teal-200/90 flex items-center justify-center font-mono font-bold shrink-0', nodeSizes)}>
            <Check className={cn(iconSizes, 'stroke-[2.5]')} />
          </div>
        );
      case 'current':
        return (
          <div className={cn('rounded-full bg-slate-900 text-white flex items-center justify-center font-mono font-bold shadow-2xs shrink-0 ring-2 ring-slate-900/10', nodeSizes)}>
            <span>{idx + 1}</span>
          </div>
        );
      case 'blocked':
        return (
          <div className={cn('rounded-full bg-amber-50 text-amber-800 border border-amber-300 flex items-center justify-center font-mono font-bold shrink-0', nodeSizes)}>
            <PauseCircle className={iconSizes} />
          </div>
        );
      case 'error':
        return (
          <div className={cn('rounded-full bg-rose-50 text-rose-800 border border-rose-300 flex items-center justify-center font-mono font-bold shrink-0', nodeSizes)}>
            <AlertTriangle className={iconSizes} />
          </div>
        );
      case 'skipped':
        return (
          <div className={cn('rounded-full border border-dashed border-slate-300 bg-slate-50 text-slate-400 flex items-center justify-center font-mono font-bold shrink-0', nodeSizes)}>
            <Minus className={iconSizes} />
          </div>
        );
      case 'optional':
        return (
          <div className={cn('rounded-full border border-dashed border-slate-300 bg-slate-50 text-slate-400 flex items-center justify-center font-mono font-bold shrink-0', nodeSizes)}>
            <span>{idx + 1}</span>
          </div>
        );
      case 'upcoming':
      default:
        return (
          <div className={cn(
            'rounded-full bg-white text-slate-400 border border-slate-200 flex items-center justify-center font-mono font-normal shrink-0',
            isOptional && 'border-dashed bg-slate-50',
            nodeSizes
          )}>
            <span>{idx + 1}</span>
          </div>
        );
    }
  };

  // ── 1. VERTICAL SPINE PRESENTATION ─────────────────────────────────────────
  if (isVertical) {
    return (
      <nav aria-label="Lifecycle Progress" className={cn('w-full font-sans select-none', className)}>
        <ol className="list-none m-0 p-0 space-y-0">
          {stages.map((stage, idx) => {
            const status = resolveStatus(stage, idx);
            const isLast = idx === stages.length - 1;
            const isClickable = interactive && onStageClick;

            return (
              <li key={stage.id} aria-current={status === 'current' ? 'step' : undefined} className="relative flex gap-3">
                {/* Thin Vertical Spine */}
                <div className="flex flex-col items-center shrink-0">
                  {renderNodeIcon(status, idx, stage.isOptional)}
                  {!isLast && (
                    <div
                      className={cn(
                        'w-px flex-1 my-1 transition-colors min-h-[20px]',
                        status === 'completed' ? 'bg-teal-600/70' : 'bg-slate-200'
                      )}
                      aria-hidden="true"
                    />
                  )}
                </div>

                {/* Stage Text Block */}
                <div
                  className={cn(
                    'pb-3.5 min-w-0 flex-1',
                    isClickable && 'cursor-pointer hover:opacity-85'
                  )}
                  onClick={() => isClickable && onStageClick(stage, idx)}
                >
                  <div className="flex items-center gap-1.5">
                    <span
                      className={cn(
                        'text-[11.5px] tracking-tight',
                        status === 'current' && 'font-bold text-slate-900',
                        status === 'completed' && 'font-medium text-slate-800',
                        status === 'blocked' && 'font-medium text-amber-900',
                        status === 'error' && 'font-medium text-rose-900',
                        (status === 'upcoming' || status === 'skipped') && 'font-normal text-slate-400'
                      )}
                    >
                      {stage.label}
                    </span>
                    {stage.isOptional && (
                      <span className="text-[9.5px] text-slate-400 font-normal">(Optional)</span>
                    )}
                  </div>

                  {showTelemetry && (stage.timestamp || stage.actor || stage.subtext) && (
                    <div className="text-[10px] text-slate-400 truncate mt-0.5 font-normal">
                      {stage.timestamp && <span>{stage.timestamp}</span>}
                      {stage.timestamp && stage.actor && <span className="mx-1">·</span>}
                      {stage.actor && <span>{stage.actor}</span>}
                      {stage.subtext && !stage.timestamp && <span>{stage.subtext}</span>}
                    </div>
                  )}
                </div>
              </li>
            );
          })}
        </ol>
      </nav>
    );
  }

  // Determine modes
  const isDesktop = responsiveMode === 'desktop' || responsiveMode === 'auto';
  const isMobile = responsiveMode === 'mobile';
  const isTablet = responsiveMode === 'tablet';

  return (
    <nav aria-label="Lifecycle Progress" className={cn('w-full font-sans select-none', className)}>
      {/* ── 2. DESKTOP & WIDE HORIZONTAL RAIL ─────────────────────────────────── */}
      {(!isMobile && !isTablet) && (
        <ol
          className={cn(
            'items-center w-full list-none m-0 p-0',
            responsiveMode === 'auto' ? 'hidden md:flex' : 'flex'
          )}
        >
          {stages.map((stage, idx) => {
            const status = resolveStatus(stage, idx);
            const isClickable = interactive && onStageClick;
            const isLast = idx === stages.length - 1;

            return (
              <React.Fragment key={stage.id}>
                {/* Step Item */}
                <li
                  aria-current={status === 'current' ? 'step' : undefined}
                  className="flex items-center shrink-0"
                >
                  <button
                    type="button"
                    disabled={!isClickable}
                    onClick={() => isClickable && onStageClick(stage, idx)}
                    className={cn(
                      'flex items-center gap-1.5 text-left transition-all select-none',
                      isClickable ? 'cursor-pointer hover:opacity-85 focus:outline-none focus:ring-1 focus:ring-teal-500/30 rounded p-0.5 -m-0.5' : 'cursor-default'
                    )}
                  >
                    {renderNodeIcon(status, idx, stage.isOptional)}

                    <div className="shrink-0">
                      <div className="flex items-center gap-1">
                        <span
                          className={cn(
                            'text-[11px] tracking-tight whitespace-nowrap',
                            status === 'current' && 'font-bold text-slate-900',
                            status === 'completed' && 'font-medium text-slate-800',
                            status === 'blocked' && 'font-medium text-amber-900',
                            status === 'error' && 'font-medium text-rose-900',
                            (status === 'upcoming' || status === 'skipped') && 'font-normal text-slate-400'
                          )}
                        >
                          {stage.label}
                        </span>
                        {stage.isOptional && (
                          <span className="text-[9px] text-slate-400 font-normal">(Opt)</span>
                        )}
                      </div>

                      {showTelemetry && (stage.timestamp || stage.actor || stage.subtext) && (
                        <div className="text-[9.5px] text-slate-400 whitespace-nowrap font-normal">
                          {stage.timestamp && <span>{stage.timestamp}</span>}
                          {stage.timestamp && stage.actor && <span className="mx-1">·</span>}
                          {stage.actor && <span>{stage.actor}</span>}
                          {stage.subtext && !stage.timestamp && <span>{stage.subtext}</span>}
                        </div>
                      )}
                    </div>
                  </button>
                </li>

                {/* Hairline Connector between nodes */}
                {!isLast && (
                  <div
                    className={cn(
                      'flex-1 min-w-[8px] h-px mx-2 transition-colors rounded-full shrink',
                      status === 'completed' ? 'bg-teal-600/70' : 'bg-slate-200'
                    )}
                    aria-hidden="true"
                  />
                )}
              </React.Fragment>
            );
          })}
        </ol>
      )}

      {/* ── 3. COMPACT MODE (TABLET / MOBILE / NARROW CONTAINER) ─────────────── */}
      {(isMobile || isTablet || responsiveMode === 'auto') && (
        <div
          className={cn(
            'flex items-center justify-between py-2 px-3 bg-slate-50/80 border border-slate-200/80 rounded-lg text-xs',
            responsiveMode === 'auto' ? 'md:hidden' : ''
          )}
        >
          <div className="flex items-center gap-2 min-w-0">
            <span className="text-[10px] font-mono font-bold text-teal-700 bg-teal-50 px-1.5 py-0.5 rounded border border-teal-200/60 shrink-0">
              {activeStageIdx + 1} / {stages.length}
            </span>
            <span className="font-bold text-slate-900 text-xs truncate">
              {activeStage.label}
            </span>
          </div>

          {showTelemetry && (activeStage.timestamp || activeStage.actor) && (
            <span className="text-[10px] text-slate-500 shrink-0 font-normal">
              {activeStage.timestamp} {activeStage.actor ? `· ${activeStage.actor}` : ''}
            </span>
          )}
        </div>
      )}
    </nav>
  );
};
