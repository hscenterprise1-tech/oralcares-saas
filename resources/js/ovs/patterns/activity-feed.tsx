import React from 'react';
import { cn } from '@/lib/utils';
import { Avatar } from '@/ovs/primitives/avatar';

export interface ActivityFeedItem {
  id: string;
  actor: {
    name: string;
    avatarUrl?: string;
    fallback?: string;
  };
  action: string;
  timestamp: string;
  icon?: React.ElementType;
  iconColor?: string;
  details?: string;
}

export interface ActivityFeedProps {
  items: ActivityFeedItem[];
  className?: string;
}

export function ActivityFeed({ items, className }: ActivityFeedProps) {
  if (!items || items.length === 0) {
    return (
      <div className={cn('p-6 text-center text-xs text-ovs-secondary border border-ovs-border-subtle rounded-xl bg-ovs-background/50', className)}>
        No recent activity recorded.
      </div>
    );
  }

  return (
    <div className={cn('relative space-y-4 before:absolute before:left-5 before:top-3 before:bottom-3 before:w-0.5 before:bg-ovs-border-subtle', className)}>
      {items.map((item) => {
        const ActionIcon = item.icon;
        const fallbackInitials = item.actor.fallback || item.actor.name.substring(0, 2).toUpperCase();

        return (
          <div key={item.id} className="relative flex items-start gap-4 pl-2 group">
            {/* Actor Avatar or Icon Badge */}
            <div className="relative z-10 flex-shrink-0">
              <Avatar
                size="md"
                src={item.actor.avatarUrl}
                fallback={fallbackInitials}
                className="ring-4 ring-white shadow-2xs"
              />
              {ActionIcon && (
                <span className={cn(
                  'absolute -bottom-1 -right-1 w-4 h-4 rounded-full bg-white border border-ovs-border-subtle flex items-center justify-center text-[9px]',
                  item.iconColor || 'text-ovs-accent'
                )}>
                  <ActionIcon className="w-2.5 h-2.5" />
                </span>
              )}
            </div>

            {/* Activity Content Box */}
            <div className="flex-1 bg-white border border-ovs-border-subtle rounded-xl p-3.5 shadow-2xs group-hover:border-ovs-border-strong transition-all space-y-1">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-bold text-ovs-primary">{item.actor.name}</span>
                <span className="text-[10px] font-medium text-ovs-secondary">{item.timestamp}</span>
              </div>
              <p className="text-xs text-ovs-secondary leading-relaxed">{item.action}</p>
              {item.details && (
                <div className="mt-2 p-2 bg-ovs-background rounded-lg border border-ovs-border-subtle text-[11px] text-ovs-primary font-mono leading-tight">
                  {item.details}
                </div>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}
