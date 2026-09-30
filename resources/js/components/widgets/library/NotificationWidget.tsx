import React, { useState } from 'react';
import { cn } from '@/lib/utils';
import { WidgetContainer, WidgetContainerProps } from '../framework';
import { Bell, CheckCheck, ExternalLink } from 'lucide-react';

export interface NotificationItem {
  id: string;
  title: string;
  body: string;
  timestamp: string;
  category: string;
  priority?: 'high' | 'medium' | 'low';
  isRead?: boolean;
  actionUrl?: string;
}

export interface NotificationWidgetProps extends Omit<WidgetContainerProps, 'children'> {
  notifications?: NotificationItem[];
  onMarkRead?: (id: string) => void;
  onMarkAllRead?: () => void;
}

const PRIORITY_BADGES = {
  high:   'bg-rose-50 text-rose-700',
  medium: 'bg-amber-50 text-amber-700',
  low:    'bg-slate-100 text-slate-700',
};

export function NotificationWidget({
  notifications = [],
  onMarkRead,
  onMarkAllRead,
  ...containerProps
}: NotificationWidgetProps) {
  const [filter, setFilter] = useState<'all' | 'unread'>('all');

  const filtered = notifications.filter((n) => (filter === 'unread' ? !n.isRead : true));
  const unreadCount = notifications.filter((n) => !n.isRead).length;

  return (
    <WidgetContainer
      {...containerProps}
      toolbar={{
        customControls: (
          <div className="flex items-center justify-between w-full">
            <div className="flex items-center bg-[#F1F5F9] p-0.5 rounded-lg border border-[#E4E7EE] text-[11px] font-bold">
              <button
                type="button"
                onClick={() => setFilter('all')}
                className={cn(
                  'px-2.5 py-1 rounded-md transition-all',
                  filter === 'all' ? 'bg-white text-[#0F172A] shadow-2xs' : 'text-[#64748B] hover:text-[#0F172A]',
                )}
              >
                All ({notifications.length})
              </button>
              <button
                type="button"
                onClick={() => setFilter('unread')}
                className={cn(
                  'px-2.5 py-1 rounded-md transition-all',
                  filter === 'unread' ? 'bg-white text-[#4F46E5] shadow-2xs' : 'text-[#64748B] hover:text-[#0F172A]',
                )}
              >
                Unread ({unreadCount})
              </button>
            </div>

            {onMarkAllRead && unreadCount > 0 && (
              <button
                type="button"
                onClick={onMarkAllRead}
                className="text-[11px] font-bold text-[#4F46E5] hover:text-[#3730a3] flex items-center gap-1 transition-colors"
              >
                <CheckCheck className="w-3.5 h-3.5" /> Mark all read
              </button>
            )}
          </div>
        ),
      }}
    >
      {filtered.length > 0 && (
        <div className="divide-y divide-[#F4F6F9]">
          {filtered.map((item) => (
            <div
              key={item.id}
              className={cn(
                'p-4 flex items-start gap-3 transition-colors',
                item.isRead ? 'bg-white' : 'bg-[#F8FAFC]',
              )}
            >
              <div className="flex-shrink-0 mt-1">
                <span
                  className={cn(
                    'block w-2 h-2 rounded-full',
                    item.isRead ? 'bg-[#CBD5E1]' : 'bg-[#4F46E5]',
                  )}
                />
              </div>

              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <span className="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.2 rounded bg-[#F1F5F9] text-[#64748B]">
                    {item.category}
                  </span>
                  {item.priority && (
                    <span
                      className={cn(
                        'text-[9.5px] font-bold px-1.5 py-0.2 rounded uppercase tracking-wider',
                        PRIORITY_BADGES[item.priority],
                      )}
                    >
                      {item.priority}
                    </span>
                  )}
                  <span className="text-[10px] font-mono text-[#94A3B8] ml-auto">
                    {item.timestamp}
                  </span>
                </div>

                <p className={cn('text-[12.5px] mt-1 leading-snug', item.isRead ? 'font-medium text-[#475569]' : 'font-bold text-[#0F172A]')}>
                  {item.title}
                </p>
                <p className="text-[11.5px] text-[#64748B] mt-0.5 leading-normal font-medium">
                  {item.body}
                </p>

                {(onMarkRead || item.actionUrl) && (
                  <div className="flex items-center gap-3 mt-2 text-[11px] font-bold">
                    {!item.isRead && onMarkRead && (
                      <button
                        type="button"
                        onClick={() => onMarkRead(item.id)}
                        className="text-[#4F46E5] hover:text-[#3730a3] transition-colors"
                      >
                        Mark as read
                      </button>
                    )}
                    {item.actionUrl && (
                      <a
                        href={item.actionUrl}
                        className="text-[#475569] hover:text-[#0F172A] flex items-center gap-1 transition-colors"
                      >
                        <span>Open record</span>
                        <ExternalLink className="w-3 h-3 text-[#94A3B8]" />
                      </a>
                    )}
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </WidgetContainer>
  );
}
