import * as React from 'react';
import { Target, AlertTriangle, Info, CheckCircle2 } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface WorkspaceCopilotProps {
  title: string;
  message: string;
  severity?: 'info' | 'warning' | 'success' | 'critical';
  actions?: React.ReactNode;
}

/**
 * WorkspaceCopilot
 * The AI Insight layer that sits at the top of a workspace or operational content area.
 */
export function WorkspaceCopilot({ 
  title, 
  message, 
  severity = 'info', 
  actions 
}: WorkspaceCopilotProps) {
  
  const config = {
    info: {
      bg: 'from-indigo-500 to-[#00B4C6]',
      iconBg: 'bg-indigo-50',
      iconText: 'text-indigo-600',
      Icon: Target
    },
    warning: {
      bg: 'from-amber-400 to-orange-500',
      iconBg: 'bg-amber-50',
      iconText: 'text-amber-600',
      Icon: AlertTriangle
    },
    critical: {
      bg: 'from-red-500 to-rose-600',
      iconBg: 'bg-red-50',
      iconText: 'text-red-600',
      Icon: AlertTriangle
    },
    success: {
      bg: 'from-emerald-400 to-teal-500',
      iconBg: 'bg-emerald-50',
      iconText: 'text-emerald-600',
      Icon: CheckCircle2
    }
  };

  const activeConfig = config[severity];
  const { Icon } = activeConfig;

  return (
    <div className={cn("bg-gradient-to-r p-[1px] rounded-xl shrink-0 shadow-sm", activeConfig.bg)}>
      <div className="bg-white rounded-[11px] p-4 flex gap-4 items-start">
        <div className={cn("h-10 w-10 rounded-full flex items-center justify-center shrink-0", activeConfig.iconBg)}>
          <Icon className={cn("h-5 w-5", activeConfig.iconText)} />
        </div>
        <div className="flex-1 flex justify-between items-center">
          <div>
            <h3 className="text-sm font-bold text-[#0A2540] mb-1">AI Copilot: {title}</h3>
            <p className="text-xs text-[#64748B] leading-relaxed">
              {message}
            </p>
          </div>
          {actions && (
            <div className="flex gap-2 shrink-0 ml-4">
              {actions}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
