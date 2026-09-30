import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetContainer, WidgetContainerProps } from '../framework';
import { Clock, CheckCircle2, Calendar } from 'lucide-react';

export interface ReminderItem {
  id: string;
  title: string;
  dueDate: string;
  priority?: 'high' | 'medium' | 'low';
  assignedUser?: string;
  isCompleted?: boolean;
}

export interface ReminderWidgetProps extends Omit<WidgetContainerProps, 'children'> {
  reminders?: ReminderItem[];
  onComplete?: (id: string) => void;
  onReschedule?: (id: string) => void;
}

const PRIORITY_BADGES = {
  high:   'bg-rose-50 text-rose-700',
  medium: 'bg-amber-50 text-amber-700',
  low:    'bg-slate-100 text-slate-700',
};

export function ReminderWidget({
  reminders = [],
  onComplete,
  onReschedule,
  ...containerProps
}: ReminderWidgetProps) {
  return (
    <WidgetContainer {...containerProps}>
      {reminders.length > 0 && (
        <div className="divide-y divide-[#F4F6F9]">
          {reminders.map((item) => (
            <div key={item.id} className="p-4 flex items-start justify-between gap-3 hover:bg-[#F8FAFC] transition-colors">
              <div className="flex items-start gap-3 min-w-0">
                <button
                  type="button"
                  onClick={() => onComplete?.(item.id)}
                  aria-label={`Mark "${item.title}" complete`}
                  className={cn(
                    'mt-0.5 w-4.5 h-4.5 rounded-full flex items-center justify-center transition-colors flex-shrink-0',
                    item.isCompleted ? 'text-emerald-600' : 'text-[#CBD5E1] hover:text-[#4F46E5]',
                  )}
                >
                  <CheckCircle2 className="w-4 h-4" />
                </button>

                <div className="min-w-0">
                  <p
                    className={cn(
                      'text-[12.5px] font-bold leading-tight truncate',
                      item.isCompleted ? 'line-through text-[#94A3B8]' : 'text-[#0F172A]',
                    )}
                  >
                    {item.title}
                  </p>
                  <div className="flex items-center gap-2.5 mt-1 text-[11px] text-[#64748B] font-medium">
                    <span className="flex items-center gap-1 font-mono text-[10px]">
                      <Clock className="w-3 h-3 text-[#94A3B8]" />
                      {item.dueDate}
                    </span>
                    {item.assignedUser && (
                      <span className="truncate">Assignee: {item.assignedUser}</span>
                    )}
                  </div>
                </div>
              </div>

              <div className="flex items-center gap-2 flex-shrink-0">
                {item.priority && (
                  <span
                    className={cn(
                      'text-[9.5px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wider',
                      PRIORITY_BADGES[item.priority],
                    )}
                  >
                    {item.priority}
                  </span>
                )}

                {onReschedule && (
                  <button
                    type="button"
                    onClick={() => onReschedule(item.id)}
                    aria-label="Reschedule reminder"
                    className="p-1 rounded text-[#94A3B8] hover:text-[#0F172A] hover:bg-[#F1F5F9]"
                  >
                    <Calendar className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </WidgetContainer>
  );
}
