import React from 'react';
import { X, CheckSquare } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';

export interface BulkActionItem {
  label: string;
  onClick: () => void;
  icon?: React.ElementType;
  variant?: 'primary' | 'secondary' | 'brand' | 'destructive' | 'outline';
  disabled?: boolean;
}

export interface OVSBulkActionBarProps {
  selectedCount: number;
  actions: BulkActionItem[];
  dangerAction?: BulkActionItem;
  onClear: () => void;
  mode?: 'attached' | 'floating';
  entityLabel?: string;
  className?: string;
}

export const OVSBulkActionBar: React.FC<OVSBulkActionBarProps> = ({
  selectedCount,
  actions,
  dangerAction,
  onClear,
  mode = 'attached',
  entityLabel = 'record',
  className,
}) => {
  if (selectedCount <= 0) return null;

  const labelString = selectedCount === 1 ? entityLabel : `${entityLabel}s`;

  if (mode === 'floating') {
    return (
      <div
        className={cn(
          'fixed bottom-6 left-1/2 -translate-x-1/2 z-50 animate-in fade-in slide-in-from-bottom-4 duration-200',
          'flex items-center gap-3 px-4 py-2.5 bg-slate-900 text-white rounded-2xl shadow-xl border border-slate-800',
          className
        )}
      >
        <div className="flex items-center gap-2 text-xs font-semibold text-white pr-3 border-r border-slate-700">
          <CheckSquare className="w-4 h-4 text-teal-400" />
          <span>
            <strong className="text-teal-400 font-bold">{selectedCount}</strong> {labelString} selected
          </span>
        </div>

        <div className="flex items-center gap-2">
          {actions.map((act, idx) => {
            const Icon = act.icon;
            return (
              <Button
                key={idx}
                size="sm"
                variant={act.variant === 'brand' ? 'brand' : 'secondary'}
                onClick={act.onClick}
                disabled={act.disabled}
                className="h-8 text-xs font-semibold gap-1.5"
              >
                {Icon && <Icon className="w-3.5 h-3.5" />}
                <span>{act.label}</span>
              </Button>
            );
          })}
          {dangerAction && (
            <Button
              size="sm"
              variant="destructive"
              onClick={dangerAction.onClick}
              disabled={dangerAction.disabled}
              className="h-8 text-xs font-semibold gap-1.5"
            >
              {dangerAction.icon && <dangerAction.icon className="w-3.5 h-3.5" />}
              <span>{dangerAction.label}</span>
            </Button>
          )}
        </div>

        <button
          type="button"
          onClick={onClear}
          className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors ml-1 cursor-pointer"
          title="Clear selection"
        >
          <X className="w-4 h-4" />
        </button>
      </div>
    );
  }

  // Canonical Attached In-Surface Mode
  return (
    <div
      className={cn(
        'px-4 py-2.5 bg-slate-900 text-white text-xs flex flex-col sm:flex-row items-center justify-between gap-3 select-none transition-all duration-150',
        className
      )}
    >
      <div className="flex items-center gap-2">
        <span className="w-2 h-2 rounded-full bg-teal-400 animate-pulse" />
        <span className="font-bold text-teal-300">{selectedCount}</span>
        <span className="text-slate-300">{labelString} selected</span>
      </div>

      <div className="flex items-center gap-2 flex-wrap">
        <Button
          size="sm"
          variant="secondary"
          onClick={onClear}
          className="h-7 px-2.5 text-[11px] font-medium bg-slate-800 border-slate-700 text-slate-300 hover:text-white hover:bg-slate-700"
        >
          Clear Selection
        </Button>

        {actions.map((act, idx) => {
          const Icon = act.icon;
          return (
            <Button
              key={idx}
              size="sm"
              variant={act.variant === 'brand' ? 'brand' : 'secondary'}
              onClick={act.onClick}
              disabled={act.disabled}
              className="h-7 px-2.5 text-[11px] font-semibold gap-1"
            >
              {Icon && <Icon className="w-3 h-3" />}
              <span>{act.label}</span>
            </Button>
          );
        })}

        {dangerAction && (
          <Button
            size="sm"
            variant="destructive"
            onClick={dangerAction.onClick}
            disabled={dangerAction.disabled}
            className="h-7 px-2.5 text-[11px] font-semibold gap-1"
          >
            {dangerAction.icon && <dangerAction.icon className="w-3 h-3" />}
            <span>{dangerAction.label}</span>
          </Button>
        )}
      </div>
    </div>
  );
};
