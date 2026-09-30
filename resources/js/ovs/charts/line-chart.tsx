import React from 'react';
import {
  ResponsiveContainer,
  LineChart as RechartsLineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
} from 'recharts';
import { colors as tokenColors } from '@/ovs/tokens';

export interface LineChartProps {
  data: Array<Record<string, any>>;
  xAxisKey?: string;
  dataKeys: Array<{ key: string; name?: string; color?: string }>;
  height?: number;
  className?: string;
}

const DEFAULT_SERIES_COLORS = [
  tokenColors.chart.revenue,
  tokenColors.chart.growth,
  tokenColors.chart.warning,
  tokenColors.chart.success,
  tokenColors.chart.danger,
  tokenColors.chart.neutral,
];

export function OVSLineChart({
  data,
  xAxisKey = 'name',
  dataKeys,
  height = 300,
  className,
}: LineChartProps) {
  return (
    <div className={className} style={{ width: '100%', height }}>
      <ResponsiveContainer width="100%" height="100%">
        <RechartsLineChart data={data} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke={tokenColors.border.subtle} vertical={false} />
          <XAxis
            dataKey={xAxisKey}
            tick={{ fontSize: 11, fill: tokenColors.text.tertiary }}
            axisLine={{ stroke: tokenColors.border.subtle }}
            tickLine={false}
          />
          <YAxis
            tick={{ fontSize: 11, fill: tokenColors.text.tertiary }}
            axisLine={false}
            tickLine={false}
          />
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
          {dataKeys.length > 1 && <Legend wrapperStyle={{ fontSize: '11px', paddingTop: '8px' }} />}
          {dataKeys.map((dk, idx) => (
            <Line
              key={dk.key}
              type="monotone"
              dataKey={dk.key}
              name={dk.name || dk.key}
              stroke={dk.color || DEFAULT_SERIES_COLORS[idx % DEFAULT_SERIES_COLORS.length]}
              strokeWidth={2.5}
              dot={{ r: 3.5, strokeWidth: 1 }}
              activeDot={{ r: 6 }}
            />
          ))}
        </RechartsLineChart>
      </ResponsiveContainer>
    </div>
  );
}
