import * as React from 'react';
import { LucideIcon, Inbox } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface EmptyStateProps {
  icon?: LucideIcon;
  title: string;
  description?: string;
  action?: React.ReactNode;
  className?: string;
}

export function EmptyState({
  icon: Icon = Inbox,
  title,
  description,
  action,
  className,
}: EmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center p-8 text-center rounded-2xl border border-dashed border-slate-200 bg-white/70 backdrop-blur-xs font-sans',
        className
      )}
    >
      <div className="w-12 h-12 rounded-2xl flex items-center justify-center mb-3 shadow-[0_4px_16px_rgba(99,102,241,0.25)] bg-gradient-to-br from-indigo-500 to-indigo-600 text-white">
        <Icon className="w-6 h-6 stroke-[2]" />
      </div>
      <h4 className="text-sm font-bold tracking-tight text-slate-900 mb-1 font-display">
        {title}
      </h4>
      {description && (
        <p className="text-xs text-slate-500 max-w-sm leading-relaxed mb-4 font-sans">
          {description}
        </p>
      )}
      {action && <div className="mt-1">{action}</div>}
    </div>
  );
}
