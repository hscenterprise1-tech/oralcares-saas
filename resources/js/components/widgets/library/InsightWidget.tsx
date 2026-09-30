import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetContainer, WidgetContainerProps } from '../framework';
import { Sparkles, ArrowRight } from 'lucide-react';

export interface InsightData {
  summary: string;
  recommendation?: string;
  priority?: 'high' | 'medium' | 'low';
  confidenceScore?: number; // 0 - 100
  actionLabel?: string;
  onAction?: () => void;
}

export interface InsightWidgetProps extends Omit<WidgetContainerProps, 'children'> {
  insight?: InsightData;
}

const PRIORITY_BADGES = {
  high:   'bg-rose-50 text-rose-700 border-rose-200',
  medium: 'bg-amber-50 text-amber-700 border-amber-200',
  low:    'bg-blue-50 text-blue-700 border-blue-200',
};

export function InsightWidget({ insight, ...containerProps }: InsightWidgetProps) {
  return (
    <WidgetContainer {...containerProps}>
      {insight && (
        <div className="p-5 space-y-4">
          <div className="flex items-center justify-between gap-2">
            <div className="flex items-center gap-1.5 text-[11px] font-bold text-[#4F46E5] uppercase tracking-wider">
              <Sparkles className="w-3.5 h-3.5" strokeWidth={2} />
              <span>AI Insight</span>
            </div>

            <div className="flex items-center gap-2">
              {insight.confidenceScore !== undefined && (
                <span className="text-[10px] font-mono text-[#94A3B8]">
                  {insight.confidenceScore}% confidence
                </span>
              )}
              {insight.priority && (
                <span
                  className={cn(
                    'text-[9.5px] font-bold px-1.5 py-0.5 rounded border uppercase tracking-wider',
                    PRIORITY_BADGES[insight.priority],
                  )}
                >
                  {insight.priority} priority
                </span>
              )}
            </div>
          </div>

          <div className="space-y-2">
            <p className="text-[13px] font-semibold text-[#0F172A] leading-relaxed">
              {insight.summary}
            </p>

            {insight.recommendation && (
              <div className="p-3 rounded-lg bg-[#F8FAFC] border border-[#E4E7EE] text-[12px] text-[#475569] leading-normal font-medium">
                <span className="font-bold text-[#0F172A]">Recommendation: </span>
                {insight.recommendation}
              </div>
            )}
          </div>

          {insight.actionLabel && insight.onAction && (
            <div className="pt-1">
              <button
                type="button"
                onClick={insight.onAction}
                className="px-3.5 py-2 rounded-lg bg-[#0A2540] hover:bg-[#0d2d4f] text-white text-[12px] font-semibold flex items-center gap-1.5 transition-all shadow-2xs active:scale-95"
              >
                <span>{insight.actionLabel}</span>
                <ArrowRight className="w-3.5 h-3.5" />
              </button>
            </div>
          )}
        </div>
      )}
    </WidgetContainer>
  );
}
