import React from 'react';
import {
  ResponsiveContainer,
  PieChart as RechartsPieChart,
  Pie,
  Cell,
  Tooltip,
  Legend,
} from 'recharts';
import { colors as tokenColors } from '@/ovs/tokens';

export interface DonutChartProps {
  data: Array<{ name: string; value: number; color?: string }>;
  dataKey?: string;
  nameKey?: string;
  height?: number;
  className?: string;
}

const DEFAULT_PALETTE = [
  tokenColors.chart.revenue,
  tokenColors.chart.growth,
  tokenColors.chart.warning,
  tokenColors.chart.success,
  tokenColors.chart.danger,
  tokenColors.chart.neutral,
];

export function OVSDonutChart({
  data,
  dataKey = 'value',
  nameKey = 'name',
  height = 300,
  className,
}: DonutChartProps) {
  return (
    <div className={className} style={{ width: '100%', height }}>
      <ResponsiveContainer width="100%" height="100%">
        <RechartsPieChart margin={{ top: 10, right: 10, left: 10, bottom: 10 }}>
          <Tooltip
            contentStyle={{
              backgroundColor: tokenColors.surface.DEFAULT,
              borderColor: tokenColors.border.subtle,
              borderRadius: '12px',
              boxShadow: '0 10px 30px rgba(0,0,0,0.08)',
              fontSize: '12px',
              color: tokenColors.text.primary,
            }}
          />
          <Legend wrapperStyle={{ fontSize: '11px', paddingTop: '8px' }} />
          <Pie
            data={data}
            cx="50%"
            cy="50%"
            innerRadius={60}
            outerRadius={90}
            paddingAngle={3}
            dataKey={dataKey}
            nameKey={nameKey}
          >
            {data.map((entry, index) => (
              <Cell
                key={`cell-${index}`}
                fill={entry.color || DEFAULT_PALETTE[index % DEFAULT_PALETTE.length]}
                stroke={tokenColors.surface.DEFAULT}
                strokeWidth={2}
              />
            ))}
          </Pie>
        </RechartsPieChart>
      </ResponsiveContainer>
    </div>
  );
}
