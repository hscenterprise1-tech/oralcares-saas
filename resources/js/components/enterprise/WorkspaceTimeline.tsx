import * as React from 'react';
import { Button } from '@/components/ui/button';
import { Activity } from 'lucide-react';
import { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface WorkspaceTimelineProps {
  title?: string;
  headerActions?: React.ReactNode;
  children: React.ReactNode;
}

/**
 * WorkspaceTimeline
 * Standardized right-column component for Activity and Logs.
 */
export function WorkspaceTimeline({ title = 'Timeline & Logs', headerActions, children }: WorkspaceTimelineProps) {
  return (
    <div className="w-2/5 bg-white rounded-xl border border-[#E5EAF0] p-6 shadow-sm overflow-y-auto custom-scrollbar">
      <div className="flex justify-between items-center mb-6 border-b border-[#F5F7FB] pb-4">
        <h3 className="text-base font-bold text-[#0A2540]">{title}</h3>
        {headerActions ? (
          <div className="flex gap-2">
            {headerActions}
          </div>
        ) : (
          <Button variant="ghost" size="icon" className="h-8 w-8 text-[#64748B]">
            <Activity className="h-4 w-4"/>
          </Button>
        )}
      </div>

      <div className="relative pl-6 border-l-2 border-[#E5EAF0] space-y-8 pb-8 ml-2">
        {children}
      </div>
    </div>
  );
}

export interface TimelineItemProps {
  title: string;
  timestamp: string;
  description: string;
  icon: LucideIcon;
  variant?: 'default' | 'success' | 'warning' | 'error' | 'neutral';
}

/**
 * TimelineItem
 * Standardized timeline entry for WorkspaceTimeline.
 */
export function TimelineItem({ title, timestamp, description, icon: Icon, variant = 'default' }: TimelineItemProps) {
  
  const config = {
    default: {
      iconBg: 'bg-blue-50 text-blue-600',
      cardBg: 'bg-white',
      border: 'border-[#E5EAF0]'
    },
    success: {
      iconBg: 'bg-emerald-50 text-emerald-600',
      cardBg: 'bg-emerald-50/30',
      border: 'border-emerald-100'
    },
    warning: {
      iconBg: 'bg-amber-50 text-amber-600',
      cardBg: 'bg-amber-50/50',
      border: 'border-amber-100'
    },
    error: {
      iconBg: 'bg-red-50 text-red-600',
      cardBg: 'bg-red-50/30',
      border: 'border-red-100'
    },
    neutral: {
      iconBg: 'bg-[#F5F7FB] text-[#64748B]',
      cardBg: 'bg-white',
      border: 'border-[#E5EAF0]'
    }
  };

  const activeConfig = config[variant];

  return (
    <div className="relative">
      <div className={cn("absolute -left-[35px] top-0 h-8 w-8 rounded-full border-2 border-white flex items-center justify-center shadow-sm", activeConfig.iconBg)}>
        <Icon className="h-4 w-4" />
      </div>
      <div className={cn("border rounded-lg p-4 shadow-[0_1px_2px_rgba(15,23,42,0.02)]", activeConfig.cardBg, activeConfig.border)}>
        <div className="flex justify-between mb-1">
          <span className="text-sm font-bold text-[#0A2540]">{title}</span>
          <span className="text-xs text-[#64748B]">{timestamp}</span>
        </div>
        <p className="text-sm text-[#64748B]">{description}</p>
      </div>
    </div>
  );
}
