import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetContainer, WidgetContainerProps } from '../framework';

export interface MetricData {
  number: string | number;
  label: string;
  icon?: React.ElementType;
  badge?: {
    text: string;
    variant?: 'primary' | 'success' | 'warning' | 'danger' | 'neutral';
  };
  footerText?: string;
}

export interface MetricWidgetProps extends Omit<WidgetContainerProps, 'children'> {
  data?: MetricData;
}

const BADGE_VARIANTS = {
  primary: 'bg-[#EEF2FF] text-[#4F46E5]',
  success: 'bg-emerald-50 text-emerald-700',
  warning: 'bg-amber-50 text-amber-700',
  danger:  'bg-rose-50 text-rose-700',
  neutral: 'bg-[#F1F5F9] text-[#64748B]',
};

export function MetricWidget({ data, ...containerProps }: MetricWidgetProps) {
  const Icon = data?.icon;

  return (
    <WidgetContainer
      {...containerProps}
      footer={data?.footerText || containerProps.footer}
    >
      {data && (
        <div className="p-5 flex items-start justify-between gap-4">
          <div className="space-y-1">
            <div className="text-[26px] font-extrabold text-[#0F172A] leading-none tracking-tight">
              {data.number}
            </div>
            <p className="text-[12px] font-semibold text-[#64748B]">
              {data.label}
            </p>
          </div>

          <div className="flex flex-col items-end gap-2">
            {Icon && (
              <div className="w-9 h-9 rounded-xl bg-[#F8FAFC] border border-[#E4E7EE] flex items-center justify-center text-[#475569]">
                <Icon className="w-4 h-4" strokeWidth={1.75} />
              </div>
            )}
            {data.badge && (
              <span
                className={cn(
                  'text-[10px] font-bold px-2 py-0.5 rounded-md leading-none',
                  BADGE_VARIANTS[data.badge.variant || 'neutral'],
                )}
              >
                {data.badge.text}
              </span>
            )}
          </div>
        </div>
      )}
    </WidgetContainer>
  );
}
