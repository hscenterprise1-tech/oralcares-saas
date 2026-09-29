import React from 'react';
import {
  ResponsiveContainer,
  AreaChart as RechartsAreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
} from 'recharts';
import { colors as tokenColors } from '@/ovs/tokens';

export interface AreaChartProps {
  data: Array<Record<string, any>>;
  xAxisKey?: string;
  dataKeys: Array<{ key: string; name?: string; color?: string }>;
  height?: number;
  className?: string;
}

const DEFAULT_SERIES_COLORS = [
  tokenColors.chart.growth,
  tokenColors.chart.revenue,
  tokenColors.chart.success,
  tokenColors.chart.warning,
];

export function OVSAreaChart({
  data,
  xAxisKey = 'name',
  dataKeys,
  height = 300,
  className,
}: AreaChartProps) {
  return (
    <div className={className} style={{ width: '100%', height }}>
      <ResponsiveContainer width="100%" height="100%">
        <RechartsAreaChart data={data} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
          <defs>
            {dataKeys.map((dk, idx) => {
              const color = dk.color || DEFAULT_SERIES_COLORS[idx % DEFAULT_SERIES_COLORS.length];
              return (
                <linearGradient key={dk.key} id={`grad-${dk.key}`} x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor={color} stopOpacity={0.35} />
                  <stop offset="95%" stopColor={color} stopOpacity={0.0} />
                </linearGradient>
              );
            })}
          </defs>
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
          {dataKeys.map((dk, idx) => {
            const color = dk.color || DEFAULT_SERIES_COLORS[idx % DEFAULT_SERIES_COLORS.length];
            return (
              <Area
                key={dk.key}
                type="monotone"
                dataKey={dk.key}
                name={dk.name || dk.key}
                stroke={color}
                strokeWidth={2.5}
                fillOpacity={1}
                fill={`url(#grad-${dk.key})`}
              />
            );
          })}
        </RechartsAreaChart>
      </ResponsiveContainer>
    </div>
  );
}
