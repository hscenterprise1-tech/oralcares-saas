import * as React from 'react';
import { cn } from '@/lib/utils';
import { LucideIcon } from 'lucide-react';

export interface WorkspaceMetricsProps {
  children: React.ReactNode;
  columns?: 2 | 3 | 4 | 5;
}

/**
 * WorkspaceMetrics
 * A grid container for KPI Cards.
 */
export function WorkspaceMetrics({ children, columns = 4 }: WorkspaceMetricsProps) {
  return (
    <div className={cn("grid gap-4 shrink-0", {
      'grid-cols-2': columns === 2,
      'grid-cols-3': columns === 3,
      'grid-cols-4': columns === 4,
      'grid-cols-5': columns === 5,
    })}>
      {children}
    </div>
  );
}

export interface KPICardProps {
  label: string;
  value: React.ReactNode;
  icon?: LucideIcon;
  trend?: {
    value: string;
    direction: 'up' | 'down' | 'neutral';
  };
  progress?: {
    value: number; // 0-100
    colorClass?: string;
  };
  variant?: 'default' | 'solid';
}

/**
 * KPICard
 * Standardized metric card for workspaces.
 */
export function KPICard({ label, value, icon: Icon, trend, progress, variant = 'default' }: KPICardProps) {
  if (variant === 'solid') {
    return (
      <div className="bg-[#F5F7FB] rounded-lg p-3 border border-[#E5EAF0]">
        <p className="text-xs font-bold text-[#64748B] uppercase tracking-wider mb-1">{label}</p>
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <h3 className="text-xl font-bold text-[#0A2540]">{value}</h3>
            {progress && (
              <div className="h-1.5 w-16 bg-slate-200 rounded-full overflow-hidden shrink-0">
                <div className={cn("h-full", progress.colorClass || "bg-[#00B4C6]")} style={{ width: `${progress.value}%` }} />
              </div>
            )}
          </div>
          {Icon && <Icon className="h-4 w-4 text-[#64748B]" />}
        </div>
      </div>
    );
  }

  // Default white card variant
  return (
    <div className="bg-white rounded-xl border border-[#E5EAF0] shadow-sm overflow-hidden flex flex-col">
      <div className="p-5 flex items-center gap-4">
        {Icon && (
          <div className="h-12 w-12 rounded-lg bg-[#F5F7FB] text-[#64748B] border border-[#E5EAF0] flex items-center justify-center shrink-0">
            <Icon className="h-6 w-6" />
          </div>
        )}
        <div className="flex-1">
          <p className="text-xs font-bold text-[#64748B] uppercase tracking-wider mb-1">{label}</p>
          <div className="flex items-baseline gap-2">
            <h3 className="text-2xl font-bold text-[#0A2540]">{value}</h3>
          </div>
        </div>
      </div>
      {progress && (
        <div className="h-1 bg-[#F5F7FB] w-full">
          <div className={cn("h-full", progress.colorClass || "bg-[#00B4C6]")} style={{ width: `${progress.value}%` }} />
        </div>
      )}
    </div>
  );
}
