import React from 'react';
import { Card, CardContent } from '@/ovs/primitives/card';
import { cn } from '@/lib/utils';
import { ArrowUpRight, ArrowDownRight, Minus } from 'lucide-react';

export interface StatCardProps {
  title: string;
  value: string;
  trend?: 'up' | 'down' | 'neutral';
  trendValue?: string;
  icon?: React.ReactNode;
  tone?: 'accent' | 'teal' | 'indigo' | 'success' | 'warning' | 'danger' | 'info' | 'purple' | 'neutral' | 'slate' | 'brand';
  className?: string;
  description?: string;
}

export function StatCard({
  title,
  value,
  trend,
  trendValue,
  icon,
  tone = 'accent',
  className,
  description,
}: StatCardProps) {
  // Numeric values ("0", "1,284") read fine as a big hero number. Status-word
  // values ("Offline", "Connected") are much longer strings — rendering them
  // at the same huge size makes them look heavier/bigger than a single digit
  // sitting next to them in the same row, so scale the value down once it
  // stops being short-number-shaped.
  const isCompactValue = value.replace(/[,.\s%]/g, '').length <= 5;

  // Full tinted card body per tone (not just a thin accent) — a soft gradient
  // wash across the whole card plus a rich gradient icon badge, so each tile
  // reads as fully "in its color" rather than mostly white with a hint of it.
  const getToneConfig = (t: string) => {
    switch (t) {
      case 'teal':
        return {
          card: 'bg-gradient-to-br from-teal-50 via-teal-50/60 to-white border-teal-200/80',
          icon: 'bg-gradient-to-br from-teal-400 via-teal-500 to-teal-700 text-white shadow-[0_3px_10px_rgba(13,148,136,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-teal-300/40',
          hoverBorder: 'hover:border-teal-300',
        };
      case 'success':
      case 'emerald':
        return {
          card: 'bg-gradient-to-br from-emerald-50 via-emerald-50/60 to-white border-emerald-200/80',
          icon: 'bg-gradient-to-br from-emerald-400 via-emerald-500 to-emerald-700 text-white shadow-[0_3px_10px_rgba(16,185,129,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-emerald-300/40',
          hoverBorder: 'hover:border-emerald-300',
        };
      case 'warning':
      case 'amber':
        return {
          card: 'bg-gradient-to-br from-amber-50 via-amber-50/60 to-white border-amber-200/80',
          icon: 'bg-gradient-to-br from-amber-400 via-amber-500 to-amber-700 text-white shadow-[0_3px_10px_rgba(245,158,11,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-amber-300/40',
          hoverBorder: 'hover:border-amber-300',
        };
      case 'danger':
      case 'rose':
        return {
          card: 'bg-gradient-to-br from-rose-50 via-rose-50/60 to-white border-rose-200/80',
          icon: 'bg-gradient-to-br from-rose-400 via-rose-500 to-rose-700 text-white shadow-[0_3px_10px_rgba(244,63,94,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-rose-300/40',
          hoverBorder: 'hover:border-rose-300',
        };
      case 'info':
      case 'blue':
        return {
          card: 'bg-gradient-to-br from-blue-50 via-blue-50/60 to-white border-blue-200/80',
          icon: 'bg-gradient-to-br from-blue-400 via-blue-500 to-blue-700 text-white shadow-[0_3px_10px_rgba(59,130,246,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-blue-300/40',
          hoverBorder: 'hover:border-blue-300',
        };
      case 'purple':
        return {
          card: 'bg-gradient-to-br from-purple-50 via-purple-50/60 to-white border-purple-200/80',
          icon: 'bg-gradient-to-br from-purple-400 via-purple-500 to-purple-700 text-white shadow-[0_3px_10px_rgba(168,85,247,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-purple-300/40',
          hoverBorder: 'hover:border-purple-300',
        };
      case 'neutral':
      case 'slate':
        return {
          card: 'bg-gradient-to-br from-slate-50 via-slate-50/60 to-white border-slate-200/80',
          icon: 'bg-gradient-to-br from-slate-500 via-slate-600 to-slate-800 text-white shadow-[0_3px_10px_rgba(71,85,105,0.3),inset_0_1px_0_rgba(255,255,255,0.15)] border-slate-400/40',
          hoverBorder: 'hover:border-slate-300',
        };
      case 'brand':
      case 'indigo':
      case 'accent':
      default:
        return {
          card: 'bg-gradient-to-br from-indigo-50 via-indigo-50/60 to-white border-indigo-200/80',
          icon: 'bg-gradient-to-br from-indigo-400 via-indigo-500 to-indigo-700 text-white shadow-[0_3px_10px_rgba(99,102,241,0.35),inset_0_1px_0_rgba(255,255,255,0.25)] border-indigo-300/40',
          hoverBorder: 'hover:border-indigo-300',
        };
    }
  };

  const config = getToneConfig(tone);

  return (
    <Card
      elevation="surface"
      className={cn(
        'relative overflow-hidden transition-all duration-150 hover:-translate-y-0.5 hover:shadow-ovs-medium rounded-2xl border',
        config.card,
        config.hoverBorder,
        className
      )}
    >
      <CardContent className="p-3.5 sm:p-4 pt-4 space-y-2">
        <div className="flex items-center justify-between gap-1.5">
          <p className="flex-1 min-w-0 text-[10.5px] leading-none font-bold text-slate-600 uppercase tracking-wider truncate font-display">{title}</p>
          {icon && (
            <div
              className={cn(
                'flex h-[32px] w-[32px] items-center justify-center rounded-lg shrink-0 self-center font-bold border transition-transform duration-150 group-hover:scale-105 [&>svg]:w-3.5 [&>svg]:h-3.5 [&>svg]:shrink-0',
                config.icon
              )}
            >
              {icon}
            </div>
          )}
        </div>

        <div className="flex items-baseline justify-between gap-1.5">
          <h2
            className={cn(
              'flex-1 min-w-0 truncate font-extrabold tracking-tight text-slate-900 font-display tabular-nums leading-none',
              isCompactValue ? 'text-2xl sm:text-[26px]' : 'text-lg sm:text-xl'
            )}
          >
            {value}
          </h2>

          {trend && trendValue && (
            <span
              className={cn(
                'inline-flex items-center text-[10px] font-bold px-1.5 py-0.2 rounded-full shrink-0 border tabular-nums leading-tight',
                trend === 'up'
                  ? 'bg-ovs-semantic-success-bg text-ovs-semantic-success-text border-ovs-semantic-success-border'
                  : trend === 'down'
                  ? 'bg-ovs-semantic-danger-bg text-ovs-semantic-danger-text border-ovs-semantic-danger-border'
                  : 'bg-ovs-semantic-neutral-bg text-ovs-semantic-neutral-text border-ovs-semantic-neutral-border'
              )}
            >
              {trend === 'up' && <ArrowUpRight className="mr-0.5 h-3 w-3" />}
              {trend === 'down' && <ArrowDownRight className="mr-0.5 h-3 w-3" />}
              {trend === 'neutral' && <Minus className="mr-0.5 h-3 w-3" />}
              {trendValue}
            </span>
          )}
        </div>

        {description && <p className="text-[11px] text-slate-500 font-normal leading-tight truncate">{description}</p>}
      </CardContent>
    </Card>
  );
}
