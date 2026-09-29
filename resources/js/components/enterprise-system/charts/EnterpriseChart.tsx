import React from 'react';
import { 
  AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, 
  BarChart, Bar, FunnelChart, Funnel, LabelList, Cell
} from 'recharts';

export type ChartType = 'area' | 'bar' | 'funnel';

export interface ChartSeries {
  key: string;
  name?: string;
  color?: string; // Expects a CSS variable or hex. Defaults to interactive color.
}

export interface EnterpriseChartProps {
  type: ChartType;
  data: any[];
  xAxisKey?: string;
  series: ChartSeries[];
  formatYAxis?: (value: number) => string;
  formatTooltip?: (value: number) => string;
  height?: string | number;
  layout?: 'horizontal' | 'vertical';
  barSize?: number;
}

const CustomTooltip = ({ active, payload, label, formatTooltip }: any) => {
  if (active && payload && payload.length) {
    return (
      <div className="bg-surface p-3 rounded-xl shadow-md border border-ovs-border-subtle-default text-content-strong">
        {label && <p className="text-[11px] font-semibold uppercase tracking-wider text-content-muted mb-1">{label}</p>}
        {payload.map((entry: any, index: number) => (
          <p key={index} className="font-bold text-[15px]" style={{ color: entry.color || 'var(--color-interactive)' }}>
            {formatTooltip ? formatTooltip(entry.value) : entry.value}
          </p>
        ))}
      </div>
    );
  }
  return null;
};

export function EnterpriseChart({
  type,
  data,
  xAxisKey = 'name',
  series,
  formatYAxis,
  formatTooltip,
  height = '100%',
  layout = 'horizontal',
  barSize,
}: EnterpriseChartProps) {
  
  const defaultColor = '#00B4C6'; // fallback if CSS var fails in SVG

  const renderAreaChart = () => (
    <AreaChart data={data} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
      <defs>
        {series.map((s, i) => (
          <linearGradient key={`color-${s.key}`} id={`color-${s.key}`} x1="0" y1="0" x2="0" y2="1">
            <stop offset="5%" stopColor={s.color || defaultColor} stopOpacity={0.3}/>
            <stop offset="95%" stopColor={s.color || defaultColor} stopOpacity={0}/>
          </linearGradient>
        ))}
      </defs>
      <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#E2E8F0" />
      <XAxis 
        dataKey={xAxisKey} 
        axisLine={false} 
        tickLine={false} 
        tick={{ fontSize: 12, fill: '#64748B' }} 
        dy={10}
      />
      <YAxis 
        axisLine={false} 
        tickLine={false} 
        tick={{ fontSize: 12, fill: '#64748B' }}
        tickFormatter={formatYAxis}
      />
      <Tooltip content={<CustomTooltip formatTooltip={formatTooltip} />} />
      {series.map((s) => (
        <Area 
          key={s.key}
          type="monotone" 
          dataKey={s.key} 
          stroke={s.color || defaultColor} 
          strokeWidth={3}
          fillOpacity={1} 
          fill={`url(#color-${s.key})`} 
        />
      ))}
    </AreaChart>
  );

  const renderBarChart = () => (
    <BarChart data={data} layout={layout} margin={{ top: 10, right: 10, left: -20, bottom: 0 }} barSize={barSize}>
      <CartesianGrid strokeDasharray="3 3" horizontal={layout === 'vertical'} vertical={layout === 'horizontal'} stroke="#E2E8F0" />
      {layout === 'vertical' ? (
        <>
          <XAxis type="number" hide />
          <YAxis 
            dataKey={xAxisKey} 
            type="category" 
            axisLine={false} 
            tickLine={false} 
            tick={{ fontSize: 12, fill: '#64748B', fontWeight: 500 }}
            width={60}
          />
        </>
      ) : (
        <>
          <XAxis 
            dataKey={xAxisKey} 
            axisLine={false} 
            tickLine={false} 
            tick={{ fontSize: 12, fill: '#64748B' }} 
            dy={10}
          />
          <YAxis 
            axisLine={false} 
            tickLine={false} 
            tick={{ fontSize: 12, fill: '#64748B' }}
            tickFormatter={formatYAxis}
          />
        </>
      )}
      <Tooltip content={<CustomTooltip formatTooltip={formatTooltip} />} cursor={{ fill: 'transparent' }} />
      {series.map((s) => (
        <Bar 
          key={s.key}
          dataKey={s.key} 
          fill={s.color || defaultColor}
          radius={layout === 'vertical' ? [0, 8, 8, 0] : [4, 4, 0, 0]}
        >
          {data.map((entry, index) => (
            <Cell key={`cell-${index}`} fill={entry.color || s.color || defaultColor} />
          ))}
        </Bar>
      ))}
    </BarChart>
  );

  const renderFunnelChart = () => (
    <FunnelChart>
      <Tooltip content={<CustomTooltip formatTooltip={formatTooltip} />} />
      <Funnel
        dataKey={series[0]?.key || "value"}
        data={data}
        isAnimationActive
      >
        <LabelList position="right" fill="#334155" stroke="none" dataKey={xAxisKey} />
        {data.map((entry, index) => (
          <Cell key={`cell-${index}`} fill={entry.fill || defaultColor} />
        ))}
      </Funnel>
    </FunnelChart>
  );

  return (
    <div className="h-full w-full" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        {type === 'area' ? renderAreaChart() : 
         type === 'bar' ? renderBarChart() : 
         renderFunnelChart()}
      </ResponsiveContainer>
    </div>
  );
}
