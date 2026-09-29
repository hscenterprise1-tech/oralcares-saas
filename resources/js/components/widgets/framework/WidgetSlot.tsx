import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetSlotRules } from './types';

export interface WidgetSlotProps {
  rules: WidgetSlotRules;
  children?: React.ReactNode;
  className?: string;
  emptyLabel?: string;
}

export function WidgetSlot({
  rules,
  children,
  className = '',
  emptyLabel = 'Empty Widget Slot',
}: WidgetSlotProps) {
  const childArray = React.Children.toArray(children);
  const count = childArray.length;
  const isOverLimit = rules.maxWidgets ? count > rules.maxWidgets : false;

  const layoutClasses = {
    grid:  'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6',
    stack: 'flex flex-col gap-6',
    flex:  'flex flex-wrap gap-6 items-start',
  }[rules.layoutBehaviour || 'stack'];

  return (
    <div className={cn('relative w-full', className)}>
      {isOverLimit && (
        <div className="mb-2 p-2 rounded-lg bg-amber-50 border border-amber-200 text-[11px] font-semibold text-amber-800">
          Slot "{rules.zoneId}" exceeds maximum widget limit ({rules.maxWidgets}).
        </div>
      )}

      {count === 0 ? (
        <div className="w-full min-h-[140px] rounded-xl border border-dashed border-[#CBD5E1] bg-white/50 p-6 flex flex-col justify-center items-center text-center select-none">
          <p className="text-[10px] font-bold text-[#94A3B8] tracking-widest uppercase">
            Slot: {rules.zoneId}
          </p>
          <p className="text-[12px] font-medium text-[#64748B] mt-1">
            {emptyLabel}
          </p>
          {rules.allowedCategories && (
            <div className="flex items-center gap-1.5 mt-2 flex-wrap justify-center">
              {rules.allowedCategories.map((cat) => (
                <span key={cat} className="text-[9.5px] font-mono px-1.5 py-0.5 rounded bg-[#F1F5F9] text-[#64748B]">
                  {cat}
                </span>
              ))}
            </div>
          )}
        </div>
      ) : (
        <div className={layoutClasses}>{childArray}</div>
      )}
    </div>
  );
}
