import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetContainer, WidgetContainerProps } from '../framework';

export interface ActivityItem {
  id: string;
  user: string;
  avatarInitials?: string;
  action: string;
  category: string;
  timestamp: string;
}

export interface ActivityFeedWidgetProps extends Omit<WidgetContainerProps, 'children'> {
  activities?: ActivityItem[];
  onLoadMore?: () => void;
  hasMore?: boolean;
}

export function ActivityFeedWidget({
  activities = [],
  onLoadMore,
  hasMore = false,
  ...containerProps
}: ActivityFeedWidgetProps) {
  return (
    <WidgetContainer {...containerProps}>
      {activities.length > 0 && (
        <div className="p-4 space-y-4">
          <div className="relative pl-3 space-y-4 before:absolute before:left-6 before:top-2 before:bottom-2 before:w-px before:bg-[#E4E7EE]">
            {activities.map((item) => {
              const initials = item.avatarInitials || item.user.split(' ').map((n) => n[0]).join('').slice(0, 2).toUpperCase();
              return (
                <div key={item.id} className="relative flex items-start gap-3 text-[12px]">
                  {/* Timeline avatar node */}
                  <span className="relative z-10 w-6 h-6 rounded-full bg-[#EEF2FF] text-[#4F46E5] text-[9.5px] font-bold flex items-center justify-center flex-shrink-0 ring-2 ring-white">
                    {initials}
                  </span>

                  <div className="flex-1 min-w-0">
                    <p className="font-semibold text-[#0F172A] leading-snug">
                      {item.user} <span className="font-normal text-[#475569]">{item.action}</span>
                    </p>
                    <div className="flex items-center gap-2 mt-0.5">
                      <span className="text-[9.5px] font-bold uppercase tracking-wider px-1.5 py-0.2 rounded bg-[#F1F5F9] text-[#64748B]">
                        {item.category}
                      </span>
                      <span className="text-[10px] text-[#94A3B8] font-mono">{item.timestamp}</span>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>

          {hasMore && onLoadMore && (
            <div className="pt-2 text-center">
              <button
                type="button"
                onClick={onLoadMore}
                className="text-[11.5px] font-bold text-[#4F46E5] hover:text-[#3730a3] transition-colors"
              >
                Load more activities ↓
              </button>
            </div>
          )}
        </div>
      )}
    </WidgetContainer>
  );
}
