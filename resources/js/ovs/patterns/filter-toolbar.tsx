import React, { useState, useEffect } from 'react';
import { Search, Filter, X, SlidersHorizontal, LayoutGrid, Table as TableIcon, Columns, RotateCcw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';
import {
  Select,
  SelectTrigger,
  SelectContent,
  SelectItem,
  SelectValue,
} from '@/ovs/primitives/select';

export interface QuickFilterOption {
  value: string;
  label: string;
}

export interface QuickFilterConfig {
  key: string;
  label: string;
  options: QuickFilterOption[];
  selectedValue?: string;
}

export interface ActiveFilterChip {
  key: string;
  label: string;
  valueLabel: string;
}

export interface OVSFilterToolbarProps {
  searchQuery?: string;
  onSearchChange?: (query: string) => void;
  searchPlaceholder?: string;
  quickFilters?: QuickFilterConfig[];
  onQuickFilterChange?: (key: string, value: string) => void;
  activeChips?: ActiveFilterChip[];
  onRemoveChip?: (key: string) => void;
  onClearAll?: () => void;
  totalResultsCount?: number;
  resultsLabel?: string;
  activeView?: 'table' | 'kanban' | 'split';
  onViewChange?: (view: 'table' | 'kanban' | 'split') => void;
  supportedViews?: ('table' | 'kanban' | 'split')[];
  actions?: React.ReactNode;
  onOpenMoreFilters?: () => void;
  className?: string;
}

export const OVSFilterToolbar: React.FC<OVSFilterToolbarProps> = ({
  searchQuery = '',
  onSearchChange,
  searchPlaceholder = 'Search records by keyword, clinic, doctor…',
  quickFilters = [],
  onQuickFilterChange,
  activeChips = [],
  onRemoveChip,
  onClearAll,
  totalResultsCount,
  resultsLabel = 'records',
  activeView,
  onViewChange,
  supportedViews,
  actions,
  onOpenMoreFilters,
  className,
}) => {
  const [internalSearch, setInternalSearch] = useState(searchQuery);

  useEffect(() => {
    setInternalSearch(searchQuery);
  }, [searchQuery]);

  const handleSearchInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setInternalSearch(val);
    if (onSearchChange) onSearchChange(val);
  };

  const hasActiveFilters = activeChips.length > 0 || (internalSearch && internalSearch.trim().length > 0);

  return (
    <div className={cn('space-y-3 font-sans text-slate-900', className)}>
      {/* ── TOP FILTER ROW ─────────────────────────────────────────────────── */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        {/* Left cluster: Search Input + Quick Filters */}
        <div className="flex items-center gap-2.5 flex-wrap flex-1">
          {/* Search Input with Hotkey trigger */}
          <div className="relative min-w-[240px] max-w-sm flex-1">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={internalSearch}
              onChange={handleSearchInputChange}
              placeholder={searchPlaceholder}
              className="w-full h-9 pl-9 pr-8 text-xs bg-slate-50 border border-slate-200 rounded-xl text-slate-900 placeholder:text-slate-400 focus:bg-white focus:outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/15 transition-all"
            />
            {internalSearch && (
              <button
                type="button"
                onClick={() => {
                  setInternalSearch('');
                  if (onSearchChange) onSearchChange('');
                }}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5 rounded"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>

          {/* Quick Filter Dropdowns */}
          {quickFilters.map((qf) => (
            <Select
              key={qf.key}
              value={qf.selectedValue || 'all'}
              onValueChange={(val) => onQuickFilterChange && onQuickFilterChange(qf.key, val)}
            >
              <SelectTrigger
                className={cn(
                  'h-9 w-auto min-w-[130px] text-xs font-medium rounded-xl border transition-colors',
                  qf.selectedValue && qf.selectedValue !== 'all'
                    ? 'bg-teal-50 text-teal-900 border-teal-200 font-semibold shadow-2xs'
                    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                )}
              >
                <SelectValue placeholder={qf.label} />
              </SelectTrigger>
              <SelectContent>
                {/* Callers already include their own "All ___" option (e.g. "All
                    Statuses") as the first entry in qf.options — injecting another
                    value="all" item here duplicated it and rendered both labels
                    concatenated together in the trigger. */}
                {qf.options.map((opt) => (
                  <SelectItem key={opt.value} value={opt.value}>
                    {opt.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ))}

          {/* More Filters button */}
          {onOpenMoreFilters && (
            <Button
              size="sm"
              variant="secondary"
              onClick={onOpenMoreFilters}
              className="h-9 px-3 text-xs gap-1.5 text-slate-700"
            >
              <SlidersHorizontal className="w-3.5 h-3.5 text-slate-500" />
              <span>More Filters</span>
            </Button>
          )}
        </div>

        {/* Right cluster: Results count + View toggles + Custom Actions */}
        <div className="flex items-center gap-3 shrink-0 self-end lg:self-auto">
          {typeof totalResultsCount === 'number' && (
            <div className="text-xs text-slate-500 font-medium">
              <strong className="text-slate-900 font-semibold">{totalResultsCount}</strong> {resultsLabel}
            </div>
          )}

          {/* Supported View Toggles */}
          {supportedViews && supportedViews.length > 1 && activeView && onViewChange && (
            <div className="flex items-center gap-0.5 bg-slate-100 p-0.5 rounded-xl border border-slate-200">
              {supportedViews.includes('table') && (
                <button
                  type="button"
                  onClick={() => onViewChange('table')}
                  className={cn(
                    'p-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer',
                    activeView === 'table' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-900'
                  )}
                  title="Table View"
                >
                  <TableIcon className="w-3.5 h-3.5" />
                </button>
              )}
              {supportedViews.includes('kanban') && (
                <button
                  type="button"
                  onClick={() => onViewChange('kanban')}
                  className={cn(
                    'p-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer',
                    activeView === 'kanban' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-900'
                  )}
                  title="Kanban View"
                >
                  <Columns className="w-3.5 h-3.5" />
                </button>
              )}
              {supportedViews.includes('split') && (
                <button
                  type="button"
                  onClick={() => onViewChange('split')}
                  className={cn(
                    'p-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer',
                    activeView === 'split' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-900'
                  )}
                  title="Split Master-Detail View"
                >
                  <LayoutGrid className="w-3.5 h-3.5" />
                </button>
              )}
            </div>
          )}

          {actions}
        </div>
      </div>

      {/* ── ACTIVE FILTER CHIPS ROW (IF FILTERS ACTIVE) ────────────────────── */}
      {hasActiveFilters && (
        <div className="flex items-center gap-2 flex-wrap pt-1 text-xs">
          <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Active:</span>

          {internalSearch && (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-teal-50 text-teal-900 border border-teal-200 text-xs font-medium">
              <span>Keyword: &ldquo;{internalSearch}&rdquo;</span>
              <button
                type="button"
                onClick={() => {
                  setInternalSearch('');
                  if (onSearchChange) onSearchChange('');
                }}
                className="text-teal-600 hover:text-teal-900 cursor-pointer"
              >
                <X className="w-3 h-3" />
              </button>
            </span>
          )}

          {activeChips.map((chip) => (
            <span
              key={chip.key}
              className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-100 text-slate-800 border border-slate-200 text-xs font-medium"
            >
              <span className="text-slate-500">{chip.label}:</span>
              <span className="font-semibold">{chip.valueLabel}</span>
              {onRemoveChip && (
                <button
                  type="button"
                  onClick={() => onRemoveChip(chip.key)}
                  className="text-slate-400 hover:text-slate-700 cursor-pointer"
                >
                  <X className="w-3 h-3" />
                </button>
              )}
            </span>
          ))}

          {onClearAll && (
            <button
              type="button"
              onClick={onClearAll}
              className="text-xs font-semibold text-slate-500 hover:text-rose-600 underline ml-1 cursor-pointer transition-colors"
            >
              Clear All
            </button>
          )}
        </div>
      )}
    </div>
  );
};
