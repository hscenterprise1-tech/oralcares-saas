import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetContainer, WidgetContainerProps } from '../framework';
import { TrendingUp, TrendingDown, Minus } from 'lucide-react';

export interface KpiData {
  primaryValue: string | number;
  secondaryValue?: string | number;
  trend?: 'up' | 'down' | 'neutral';
  trendPercent?: string | number;
  comparisonText?: string;
  statusText?: string;
  statusVariant?: 'success' | 'warning' | 'danger' | 'neutral';
}

export interface ExecutiveKpiWidgetProps extends Omit<WidgetContainerProps, 'children'> {
  data?: KpiData;
  showSparkline?: boolean;
}

const TREND_ICONS = {
  up: TrendingUp,
  down: TrendingDown,
  neutral: Minus,
};

const TREND_COLORS = {
  up: 'text-emerald-600 bg-emerald-50',
  down: 'text-rose-600 bg-rose-50',
  neutral: 'text-slate-600 bg-slate-100',
};

export function ExecutiveKpiWidget({
  data,
  showSparkline = true,
  ...containerProps
}: ExecutiveKpiWidgetProps) {
  const TrendIcon = data?.trend ? TREND_ICONS[data.trend] : null;

  return (
    <WidgetContainer {...containerProps}>
      {data && (
        <div className="p-5 flex flex-col justify-between h-full space-y-4">
          <div className="flex items-start justify-between gap-3">
            <div>
              <div className="text-[28px] font-extrabold text-[#0F172A] leading-none tracking-tight">
                {data.primaryValue}
              </div>
              {data.secondaryValue && (
                <p className="text-[12px] text-[#64748B] font-medium mt-1">
                  Sub-total: {data.secondaryValue}
                </p>
              )}
            </div>

            {data.trend && TrendIcon && (
              <div
                className={cn(
                  'flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-bold leading-none',
                  TREND_COLORS[data.trend],
                )}
              >
                <TrendIcon className="w-3.5 h-3.5" strokeWidth={2} />
                {data.trendPercent && <span>{data.trendPercent}%</span>}
              </div>
            )}
          </div>

          {(data.comparisonText || data.statusText) && (
            <div className="flex items-center justify-between text-[11px] font-medium text-[#64748B] pt-1">
              {data.comparisonText && <span>{data.comparisonText}</span>}
              {data.statusText && (
                <span className="text-[10px] font-bold uppercase tracking-wider text-[#94A3B8]">
                  {data.statusText}
                </span>
              )}
            </div>
          )}

          {showSparkline && (
            <div className="w-full h-8 pt-2">
              <div className="w-full h-full rounded bg-[#F8FAFC] border border-dashed border-[#E4E7EE] flex items-center justify-center text-[10px] font-mono text-[#94A3B8]">
                Sparkline Surface
              </div>
            </div>
          )}
        </div>
      )}
    </WidgetContainer>
  );
}
