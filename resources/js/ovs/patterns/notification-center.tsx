import React from 'react';
import { Bell, Check, Info, CheckCircle, AlertTriangle, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Badge } from '@/ovs/primitives/badge';
import { Button } from '@/ovs/primitives/button';
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuItem,
} from '@/ovs/overlays/dropdown-menu';

export interface NotificationItem {
  id: string;
  title: string;
  description: string;
  timestamp: string;
  isRead: boolean;
  type?: 'info' | 'success' | 'warning' | 'error';
  icon?: React.ElementType;
}

export interface NotificationCenterProps {
  notifications: NotificationItem[];
  onMarkAsRead?: (id: string) => void;
  onMarkAllAsRead?: () => void;
  onNotificationClick?: (notification: NotificationItem) => void;
  className?: string;
}

export function NotificationCenter({
  notifications,
  onMarkAsRead,
  onMarkAllAsRead,
  onNotificationClick,
  className,
}: NotificationCenterProps) {
  const unreadCount = notifications.filter((n) => !n.isRead).length;

  const getTypeIcon = (type?: string, CustomIcon?: React.ElementType) => {
    if (CustomIcon) return CustomIcon;
    switch (type) {
      case 'success': return CheckCircle;
      case 'warning': return AlertTriangle;
      case 'error':   return AlertCircle;
      default:        return Info;
    }
  };

  const getTypeColor = (type?: string) => {
    switch (type) {
      case 'success': return 'text-ovs-semantic-success-text bg-ovs-semantic-success-bg';
      case 'warning': return 'text-ovs-semantic-warning-text bg-ovs-semantic-warning-bg';
      case 'error':   return 'text-ovs-semantic-danger-text bg-ovs-semantic-danger-bg';
      default:        return 'text-ovs-semantic-info-text bg-ovs-semantic-info-bg';
    }
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          className={cn(
            'relative p-2 rounded-xl text-ovs-secondary hover:text-ovs-primary hover:bg-ovs-background transition-colors focus:outline-none focus:ring-2 focus:ring-ovs-accent/30',
            className
          )}
          aria-label="Open Notifications"
        >
          <Bell className="w-5 h-5" />
          {unreadCount > 0 && (
            <span className="absolute top-1 right-1 flex h-4 min-w-[16px] px-1 items-center justify-center rounded-full bg-ovs-accent text-[9px] font-bold text-white shadow-2xs">
              {unreadCount > 99 ? '99+' : unreadCount}
            </span>
          )}
        </button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-80 sm:w-96 p-0 shadow-ovs-floating rounded-2xl overflow-hidden border border-ovs-border-subtle">
        {/* Header */}
        <div className="flex items-center justify-between p-3.5 bg-ovs-background border-b border-ovs-border-subtle">
          <div className="flex items-center gap-2">
            <span className="text-xs font-bold text-ovs-primary">Notifications</span>
            {unreadCount > 0 && (
              <Badge variant="warning" className="text-[10px] py-0 px-1.5 font-bold">
                {unreadCount} unread
              </Badge>
            )}
          </div>

          {unreadCount > 0 && onMarkAllAsRead && (
            <button
              type="button"
              onClick={onMarkAllAsRead}
              className="text-[11px] font-semibold text-ovs-accent hover:underline flex items-center gap-1"
            >
              <Check className="w-3 h-3" /> Mark all read
            </button>
          )}
        </div>

        {/* Scrollable Notification List */}
        <div className="max-h-80 overflow-y-auto divide-y divide-ovs-border-subtle">
          {notifications.length === 0 ? (
            <div className="p-8 text-center text-xs text-ovs-secondary">
              No notifications at this time.
            </div>
          ) : (
            notifications.map((item) => {
              const IconComp = getTypeIcon(item.type, item.icon);
              const colorClasses = getTypeColor(item.type);

              return (
                <div
                  key={item.id}
                  onClick={() => {
                    if (onMarkAsRead && !item.isRead) onMarkAsRead(item.id);
                    if (onNotificationClick) onNotificationClick(item);
                  }}
                  className={cn(
                    'p-3.5 flex items-start gap-3 transition-colors cursor-pointer text-left',
                    item.isRead ? 'bg-white hover:bg-ovs-background/60' : 'bg-ovs-accent/5 hover:bg-ovs-accent/10'
                  )}
                >
                  <div className={cn('w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5', colorClasses)}>
                    <IconComp className="w-3.5 h-3.5" />
                  </div>

                  <div className="flex-1 min-w-0 space-y-0.5">
                    <div className="flex items-center justify-between gap-1">
                      <span className={cn('text-xs font-bold truncate', item.isRead ? 'text-ovs-primary' : 'text-ovs-primary font-extrabold')}>
                        {item.title}
                      </span>
                      <span className="text-[10px] text-ovs-secondary flex-shrink-0">{item.timestamp}</span>
                    </div>
                    <p className="text-[11px] text-ovs-secondary leading-snug line-clamp-2">{item.description}</p>
                  </div>

                  {!item.isRead && (
                    <span className="w-2 h-2 rounded-full bg-ovs-accent flex-shrink-0 mt-1" title="Unread" />
                  )}
                </div>
              );
            })
          )}
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
