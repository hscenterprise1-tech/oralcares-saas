import React from 'react';
import { TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface KPITrend {
  direction: 'up' | 'down' | 'neutral';
  value: string;
  label?: string;
}

export interface OVSKPICardProps {
  label: string;
  value: string | number;
  trend?: KPITrend;
  subtitle?: string;
  icon?: React.ElementType;
  variant?: 'default' | 'teal' | 'warning' | 'danger' | 'slate';
  microVisualization?: React.ReactNode;
  onClick?: () => void;
  className?: string;
}

export const OVSKPICard: React.FC<OVSKPICardProps> = ({
  label,
  value,
  trend,
  subtitle,
  icon: Icon,
  variant = 'default',
  microVisualization,
  onClick,
  className,
}) => {
  const isClickable = Boolean(onClick);

  return (
    <div
      onClick={onClick}
      className={cn(
        'p-5 bg-ovs-surface border border-ovs-border rounded-2xl shadow-ovs-surface font-sans transition-all duration-200 relative overflow-hidden',
        isClickable && 'hover:border-ovs-border-strong hover:shadow-ovs-medium hover:-translate-y-0.5 cursor-pointer select-none',
        variant === 'teal' && 'border-ovs-semantic-teal-border bg-ovs-semantic-teal-bg/30',
        variant === 'warning' && 'border-ovs-semantic-warning-border bg-ovs-semantic-warning-bg/30',
        variant === 'danger' && 'border-ovs-semantic-danger-border bg-ovs-semantic-danger-bg/30',
        className
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <span className="text-[11px] font-bold uppercase tracking-wider text-ovs-text-secondary truncate">
          {label}
        </span>
        {Icon && (
          <div className="w-[32px] h-[32px] rounded-lg bg-ovs-semantic-neutral-bg border border-ovs-semantic-neutral-border flex items-center justify-center text-ovs-text-primary shrink-0 shadow-ovs-keyline">
            <Icon className="w-3.5 h-3.5" />
          </div>
        )}
      </div>

      <div className="mt-2 flex items-baseline justify-between gap-2">
        <span className="text-2xl sm:text-3xl font-bold text-ovs-text-primary tracking-tight tabular-nums font-display">
          {value}
        </span>
      </div>

      {(trend || subtitle || microVisualization) && (
        <div className="mt-3 pt-2.5 border-t border-ovs-border-subtle flex items-center justify-between gap-2 text-xs">
          {trend && (
            <div className="flex items-center gap-1.5 font-medium">
              <span
                className={cn(
                  'inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[11px] font-bold tabular-nums border',
                  trend.direction === 'up' && 'bg-ovs-semantic-success-bg text-ovs-semantic-success-text border-ovs-semantic-success-border',
                  trend.direction === 'down' && 'bg-ovs-semantic-danger-bg text-ovs-semantic-danger-text border-ovs-semantic-danger-border',
                  trend.direction === 'neutral' && 'bg-ovs-semantic-neutral-bg text-ovs-semantic-neutral-text border-ovs-semantic-neutral-border'
                )}
              >
                {trend.direction === 'up' && <TrendingUp className="w-3 h-3" />}
                {trend.direction === 'down' && <TrendingDown className="w-3 h-3" />}
                {trend.direction === 'neutral' && <Minus className="w-3 h-3" />}
                <span>{trend.value}</span>
              </span>
              {trend.label && <span className="text-ovs-text-tertiary text-[11px] truncate">{trend.label}</span>}
            </div>
          )}

          {subtitle && !trend && <span className="text-ovs-text-secondary text-[11px] truncate">{subtitle}</span>}
          {microVisualization && <div className="shrink-0">{microVisualization}</div>}
        </div>
      )}
    </div>
  );
};

export interface SummaryStripMetric {
  key: string;
  label: string;
  value: string | number;
  sublabel?: string;
  trend?: string;
  trendDirection?: 'up' | 'down' | 'neutral';
  statusColor?: string;
  onClick?: () => void;
}

export interface OVSSummaryStripProps {
  metrics: SummaryStripMetric[];
  className?: string;
}

export const OVSSummaryStrip: React.FC<OVSSummaryStripProps> = ({ metrics, className }) => {
  return (
    <div
      className={cn(
        'grid grid-cols-2 md:grid-cols-4 divide-y md:divide-y-0 md:divide-x divide-ovs-border-subtle bg-ovs-surface border border-ovs-border rounded-2xl shadow-ovs-surface overflow-hidden font-sans',
        className
      )}
    >
      {metrics.map((m) => {
        const isClickable = Boolean(m.onClick);
        return (
          <div
            key={m.key}
            onClick={m.onClick}
            className={cn(
              'p-5 flex flex-col justify-between transition-colors',
              isClickable && 'hover:bg-ovs-background/80 cursor-pointer select-none'
            )}
          >
            <div className="flex items-center justify-between gap-2">
              <span className="text-[11px] font-bold uppercase tracking-wider text-ovs-text-secondary truncate">
                {m.label}
              </span>
              {m.trend && (
                <span
                  className={cn(
                    'text-[10px] font-bold px-2 py-0.5 rounded-full tabular-nums border',
                    m.trendDirection === 'up' ? 'text-ovs-semantic-success-text bg-ovs-semantic-success-bg border-ovs-semantic-success-border' : 'text-ovs-semantic-danger-text bg-ovs-semantic-danger-bg border-ovs-semantic-danger-border'
                  )}
                >
                  {m.trend}
                </span>
              )}
            </div>

            <div className="mt-2 flex items-baseline gap-2">
              <span className="text-xl sm:text-2xl font-bold text-ovs-text-primary tracking-tight tabular-nums font-display">
                {m.value}
              </span>
              {m.sublabel && (
                <span className="text-[11px] text-ovs-text-secondary font-normal truncate">
                  {m.sublabel}
                </span>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
};
