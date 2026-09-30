import React from 'react';
import { X, CheckSquare } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';

export interface BulkAction {
  label: string;
  icon?: React.ElementType;
  onClick: () => void;
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
}

export interface BulkActionToolbarProps {
  selectedCount: number;
  actions: BulkAction[];
  onClear: () => void;
  className?: string;
}

export function BulkActionToolbar({
  selectedCount,
  actions,
  onClear,
  className,
}: BulkActionToolbarProps) {
  if (selectedCount <= 0) return null;

  return (
    <div
      className={cn(
        'fixed bottom-6 left-1/2 -translate-x-1/2 z-50 animate-in fade-in slide-in-from-bottom-4 duration-200',
        'flex items-center gap-4 px-4 py-2.5 bg-ovs-primary text-white rounded-2xl shadow-ovs-floating border border-ovs-primary-hover',
        className
      )}
    >
      {/* Selected count indicator */}
      <div className="flex items-center gap-2 text-xs font-bold text-white pr-2 border-r border-white/20">
        <CheckSquare className="w-4 h-4 text-ovs-accent" />
        <span>{selectedCount} selected</span>
      </div>

      {/* Action buttons */}
      <div className="flex items-center gap-2">
        {actions.map((act, idx) => {
          const Icon = act.icon;
          return (
            <Button
              key={idx}
              size="sm"
              variant={act.variant || 'secondary'}
              onClick={act.onClick}
              className="gap-1.5 h-8 text-xs font-semibold"
            >
              {Icon && <Icon className="w-3.5 h-3.5" />}
              <span>{act.label}</span>
            </Button>
          );
        })}
      </div>

      {/* Clear selection button */}
      <button
        type="button"
        onClick={onClear}
        className="p-1 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors ml-1"
        aria-label="Clear selection"
      >
        <X className="w-4 h-4" />
      </button>
    </div>
  );
}
