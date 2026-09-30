import React from 'react';
import { cn } from '@/lib/utils';
import { ViewSwitchOption } from './types';
import { Search, Filter, Calendar, Download, LayoutGrid, List, Table, Kanban } from 'lucide-react';

export interface WidgetToolbarProps {
  searchQuery?: string;
  onSearchChange?: (query: string) => void;
  searchPlaceholder?: string;
  filterOptions?: { label: string; value: string }[];
  selectedFilter?: string;
  onFilterChange?: (value: string) => void;
  dateRangeLabel?: string;
  onDateRangeClick?: () => void;
  onExport?: () => void;
  viewSwitchOptions?: ViewSwitchOption[];
  currentView?: ViewSwitchOption;
  onViewChange?: (view: ViewSwitchOption) => void;
  className?: string;
  customControls?: React.ReactNode;
}

const VIEW_ICONS: Record<ViewSwitchOption, React.ElementType> = {
  grid: LayoutGrid,
  list: List,
  table: Table,
  kanban: Kanban,
};

export function WidgetToolbar({
  searchQuery,
  onSearchChange,
  searchPlaceholder = 'Filter records...',
  filterOptions = [],
  selectedFilter,
  onFilterChange,
  dateRangeLabel,
  onDateRangeClick,
  onExport,
  viewSwitchOptions = [],
  currentView,
  onViewChange,
  className = '',
  customControls,
}: WidgetToolbarProps) {
  const hasControls =
    onSearchChange ||
    filterOptions.length > 0 ||
    onDateRangeClick ||
    onExport ||
    viewSwitchOptions.length > 0 ||
    customControls;

  if (!hasControls) return null;

  return (
    <div className={cn('px-5 py-2.5 bg-[#F8FAFC] border-b border-[#E4E7EE] flex items-center justify-between gap-3 flex-wrap select-none', className)}>
      {/* Left controls: Search & Filter */}
      <div className="flex items-center gap-2 flex-1 min-w-[200px]">
        {onSearchChange && (
          <div className="relative flex-1 max-w-xs">
            <Search className="w-3.5 h-3.5 text-[#94A3B8] absolute left-2.5 top-1/2 -translate-y-1/2" strokeWidth={1.75} />
            <input
              type="text"
              value={searchQuery ?? ''}
              onChange={(e) => onSearchChange(e.target.value)}
              placeholder={searchPlaceholder}
              className="w-full h-8 pl-8 pr-3 bg-white border border-[#E4E7EE] rounded-lg text-[12px] text-[#0F172A] placeholder:text-[#94A3B8] focus:outline-none focus:ring-2 focus:ring-[#4F46E5]/30 focus:border-[#4F46E5] transition-all"
            />
          </div>
        )}

        {filterOptions.length > 0 && onFilterChange && (
          <div className="relative">
            <select
              value={selectedFilter ?? ''}
              onChange={(e) => onFilterChange(e.target.value)}
              aria-label="Filter options"
              className="h-8 pl-8 pr-7 bg-white border border-[#E4E7EE] rounded-lg text-[12px] font-medium text-[#475569] focus:outline-none focus:ring-2 focus:ring-[#4F46E5]/30 focus:border-[#4F46E5] transition-all appearance-none cursor-pointer"
            >
              {filterOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
            <Filter className="w-3.5 h-3.5 text-[#94A3B8] absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" strokeWidth={1.75} />
          </div>
        )}
      </div>

      {/* Right controls: Custom, Date Range, Export, View Switch */}
      <div className="flex items-center gap-2 flex-shrink-0">
        {customControls}

        {onDateRangeClick && (
          <button
            type="button"
            onClick={onDateRangeClick}
            className="h-8 px-2.5 bg-white border border-[#E4E7EE] rounded-lg text-[12px] font-medium text-[#475569] hover:bg-[#F8FAFC] hover:text-[#0F172A] flex items-center gap-1.5 transition-all"
          >
            <Calendar className="w-3.5 h-3.5 text-[#94A3B8]" strokeWidth={1.75} />
            <span>{dateRangeLabel || 'Date Range'}</span>
          </button>
        )}

        {onExport && (
          <button
            type="button"
            onClick={onExport}
            aria-label="Export data"
            className="h-8 px-2.5 bg-white border border-[#E4E7EE] rounded-lg text-[12px] font-medium text-[#475569] hover:bg-[#F8FAFC] hover:text-[#0F172A] flex items-center gap-1.5 transition-all"
          >
            <Download className="w-3.5 h-3.5 text-[#94A3B8]" strokeWidth={1.75} />
            <span className="hidden sm:inline">Export</span>
          </button>
        )}

        {viewSwitchOptions.length > 0 && onViewChange && (
          <div className="flex items-center bg-[#F1F5F9] p-0.5 rounded-lg border border-[#E4E7EE]">
            {viewSwitchOptions.map((opt) => {
              const ViewIcon = VIEW_ICONS[opt];
              const active = currentView === opt;
              return (
                <button
                  key={opt}
                  type="button"
                  onClick={() => onViewChange(opt)}
                  aria-label={`Switch to ${opt} view`}
                  className={cn(
                    'w-7 h-7 rounded-md flex items-center justify-center text-[12px] font-medium transition-all',
                    active
                      ? 'bg-white text-[#4F46E5] shadow-2xs font-bold'
                      : 'text-[#64748B] hover:text-[#0F172A]',
                  )}
                >
                  <ViewIcon className="w-3.5 h-3.5" strokeWidth={1.75} />
                </button>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
