import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetContainer, WidgetContainerProps } from '../framework';

export type ChartType = 'line' | 'area' | 'bar' | 'donut';

export interface ChartWidgetProps extends Omit<WidgetContainerProps, 'children'> {
  type?: ChartType;
  height?: number | string;
  chartContent?: React.ReactNode;
}

const TYPE_LABELS: Record<ChartType, string> = {
  line:  'Line Chart Surface',
  area:  'Area Chart Surface',
  bar:   'Bar Chart Surface',
  donut: 'Donut Chart Surface',
};

export function ChartWidget({
  type = 'line',
  height = 220,
  chartContent,
  ...containerProps
}: ChartWidgetProps) {
  return (
    <WidgetContainer {...containerProps}>
      <div className="p-4 flex flex-col justify-center items-center w-full">
        {chartContent ? (
          <div style={{ height }} className="w-full">
            {chartContent}
          </div>
        ) : (
          <div
            style={{ height }}
            className="w-full rounded-xl border border-dashed border-[#CBD5E1] bg-[#F8FAFC] flex flex-col items-center justify-center p-6 text-center select-none"
          >
            <p className="text-[10px] font-bold text-[#94A3B8] tracking-widest uppercase">
              {TYPE_LABELS[type]}
            </p>
            <p className="text-[12px] font-medium text-[#64748B] mt-1">
              Framework visualization container ready for telemetry stream.
            </p>
          </div>
        )}
      </div>
    </WidgetContainer>
  );
}
