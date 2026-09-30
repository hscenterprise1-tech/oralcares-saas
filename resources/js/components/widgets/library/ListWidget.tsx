import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetContainer, WidgetContainerProps } from '../framework';
import { MoreHorizontal } from 'lucide-react';

export interface ListItem {
  id: string;
  title: string;
  subtitle?: string;
  avatarInitials?: string;
  status?: string;
  statusVariant?: 'success' | 'warning' | 'danger' | 'neutral';
  onSelect?: () => void;
}

export interface ListWidgetProps extends Omit<WidgetContainerProps, 'children'> {
  items?: ListItem[];
  currentPage?: number;
  totalPages?: number;
  onPageChange?: (page: number) => void;
}

const BADGE_VARIANTS = {
  success: 'bg-emerald-50 text-emerald-700',
  warning: 'bg-amber-50 text-amber-700',
  danger:  'bg-rose-50 text-rose-700',
  neutral: 'bg-[#F1F5F9] text-[#64748B]',
};

export function ListWidget({
  items = [],
  currentPage,
  totalPages,
  onPageChange,
  ...containerProps
}: ListWidgetProps) {
  return (
    <WidgetContainer
      {...containerProps}
      footer={
        currentPage && totalPages && onPageChange ? (
          <div className="flex items-center justify-between w-full">
            <span>Page {currentPage} of {totalPages}</span>
            <div className="flex items-center gap-1">
              <button
                type="button"
                disabled={currentPage <= 1}
                onClick={() => onPageChange(currentPage - 1)}
                className="px-2 py-1 rounded border border-[#E4E7EE] bg-white hover:bg-[#F8FAFC] disabled:opacity-50"
              >
                Previous
              </button>
              <button
                type="button"
                disabled={currentPage >= totalPages}
                onClick={() => onPageChange(currentPage + 1)}
                className="px-2 py-1 rounded border border-[#E4E7EE] bg-white hover:bg-[#F8FAFC] disabled:opacity-50"
              >
                Next
              </button>
            </div>
          </div>
        ) : (
          containerProps.footer
        )
      }
    >
      {items.length > 0 && (
        <div className="divide-y divide-[#F4F6F9]">
          {items.map((item) => {
            const initials = item.avatarInitials || item.title.slice(0, 2).toUpperCase();
            return (
              <div
                key={item.id}
                onClick={item.onSelect}
                className={cn(
                  'px-4 py-3 flex items-center justify-between gap-3 transition-colors',
                  item.onSelect && 'hover:bg-[#F8FAFC] cursor-pointer',
                )}
              >
                <div className="flex items-center gap-3 min-w-0">
                  <span className="w-8 h-8 rounded-lg bg-[#F8FAFC] border border-[#E4E7EE] flex items-center justify-center text-[11px] font-bold text-[#475569] flex-shrink-0">
                    {initials}
                  </span>
                  <div className="min-w-0">
                    <p className="text-[12.5px] font-bold text-[#0F172A] truncate leading-tight">
                      {item.title}
                    </p>
                    {item.subtitle && (
                      <p className="text-[11px] text-[#64748B] font-medium truncate mt-0.5">
                        {item.subtitle}
                      </p>
                    )}
                  </div>
                </div>

                <div className="flex items-center gap-2 flex-shrink-0">
                  {item.status && (
                    <span
                      className={cn(
                        'text-[10px] font-bold px-2 py-0.5 rounded-md leading-none',
                        BADGE_VARIANTS[item.statusVariant || 'neutral'],
                      )}
                    >
                      {item.status}
                    </span>
                  )}
                  <button
                    type="button"
                    aria-label="Item actions"
                    className="w-7 h-7 rounded-md flex items-center justify-center text-[#94A3B8] hover:text-[#0F172A] hover:bg-[#F1F5F9]"
                  >
                    <MoreHorizontal className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </WidgetContainer>
  );
}
