import React, { useState } from 'react';
import {
  Phone,
  MessageSquare,
  Mail,
  Calendar,
  FlaskConical,
  FileText,
  CheckSquare,
  Square,
  UserCheck,
  Clock,
  AlertTriangle,
  Sparkles,
  Paperclip,
  ChevronDown,
  ChevronUp,
  ExternalLink,
  Search,
  Filter,
  FileDown,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';
import { Avatar } from '@/ovs/primitives/avatar';

export type TimelineEventClass =
  | 'communication'
  | 'commercial'
  | 'task'
  | 'system'
  | 'note';

export type TimelineMode = 'full' | 'drawer' | 'compact';

export type TimelineFilterCategory =
  | 'all'
  | 'communication'
  | 'commercial'
  | 'task'
  | 'note';

export interface TimelineActor {
  name: string;
  role?: string;
  initials?: string;
  type?: 'user' | 'system' | 'ai' | 'contact';
}

export interface TimelineAttachment {
  id: string | number;
  name: string;
  size?: string;
  url?: string;
  type?: 'pdf' | 'audio' | 'doc' | string;
}

export interface TimelineAction {
  label: string;
  onClick: () => void;
  icon?: React.ElementType;
  variant?: 'primary' | 'secondary' | 'brand' | 'outline';
}

export interface OVSTimelineItem {
  id: string | number;
  eventClass: TimelineEventClass;
  type: string;
  title: string;
  description?: string;
  actor?: TimelineActor;
  timestamp: string; // e.g. '11 Aug · 4:00 PM'
  relativeTime?: string; // e.g. '2h ago'
  dateGroup?: string; // e.g. 'Today', 'Yesterday', 'Earlier This Week'
  icon?: React.ElementType;
  monetaryValue?: string; // e.g. '₹4.20L ARR'
  badge?: {
    label: string;
    variant?: 'default' | 'teal' | 'amber' | 'emerald' | 'rose' | 'slate';
  };
  action?: TimelineAction;
  expandableContent?: string | React.ReactNode;
  attachments?: TimelineAttachment[];
  isUnread?: boolean;
  metadata?: string | Record<string, any>;
  isCompleted?: boolean; // For task items
}

export interface OVSGlobalTimelineProps {
  items: OVSTimelineItem[];
  mode?: TimelineMode;
  showFilters?: boolean;
  showSearch?: boolean;
  searchPlaceholder?: string;
  emptyText?: string;
  groupByDate?: boolean;
  onActionClick?: (item: OVSTimelineItem) => void;
  className?: string;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. DEFAULT ICON & COLOR MAPPINGS (RESTRICTED OVS SEMANTICS)
// ─────────────────────────────────────────────────────────────────────────────

function getEventIcon(item: OVSTimelineItem): React.ElementType {
  if (item.icon) return item.icon;
  const type = (item.type || '').toLowerCase();

  switch (item.eventClass) {
    case 'commercial':
      if (type.includes('quote') || type.includes('proposal')) return FileText;
      if (type.includes('demo')) return Calendar;
      if (type.includes('trial')) return FlaskConical;
      if (type.includes('won')) return CheckSquare;
      if (type.includes('lost')) return AlertTriangle;
      return FileText;
    case 'communication':
      if (type.includes('call')) return Phone;
      if (type.includes('whatsapp')) return MessageSquare;
      if (type.includes('email')) return Mail;
      return MessageSquare;
    case 'task':
      return item.isCompleted ? CheckSquare : Square;
    case 'note':
      return FileText;
    case 'system':
    default:
      if (type.includes('assign')) return UserCheck;
      if (type.includes('stage')) return Clock;
      return Clock;
  }
}

function getNodeStyles(item: OVSTimelineItem, isCompact: boolean) {
  if (item.eventClass === 'system') {
    return {
      nodeClass: isCompact
        ? 'w-4 h-4 bg-slate-100 border border-slate-300 text-slate-500'
        : 'w-5 h-5 bg-slate-100 border border-slate-300 text-slate-500',
      iconClass: 'w-2.5 h-2.5',
    };
  }

  const baseSize = isCompact ? 'w-6 h-6' : 'w-7 h-7';
  const type = (item.type || '').toLowerCase();

  switch (item.eventClass) {
    case 'commercial':
      return {
        nodeClass: cn(baseSize, 'bg-amber-50 border border-amber-200 text-amber-800 shadow-2xs'),
        iconClass: isCompact ? 'w-3 h-3' : 'w-3.5 h-3.5',
      };
    case 'communication':
      if (type.includes('whatsapp')) {
        return {
          nodeClass: cn(baseSize, 'bg-emerald-50 border border-emerald-200 text-emerald-800 shadow-2xs'),
          iconClass: isCompact ? 'w-3 h-3' : 'w-3.5 h-3.5',
        };
      }
      if (type.includes('call')) {
        return {
          nodeClass: cn(baseSize, 'bg-blue-50 border border-blue-200 text-blue-800 shadow-2xs'),
          iconClass: isCompact ? 'w-3 h-3' : 'w-3.5 h-3.5',
        };
      }
      return {
        nodeClass: cn(baseSize, 'bg-indigo-50 border border-indigo-200 text-indigo-800 shadow-2xs'),
        iconClass: isCompact ? 'w-3 h-3' : 'w-3.5 h-3.5',
      };
    case 'task':
      return {
        nodeClass: cn(
          baseSize,
          item.isCompleted
            ? 'bg-emerald-50 border border-emerald-200 text-emerald-800'
            : 'bg-slate-50 border border-slate-200 text-slate-600',
          'shadow-2xs'
        ),
        iconClass: isCompact ? 'w-3 h-3' : 'w-3.5 h-3.5',
      };
    case 'note':
      return {
        nodeClass: cn(baseSize, 'bg-amber-50/80 border border-amber-200 text-amber-800 shadow-2xs'),
        iconClass: isCompact ? 'w-3 h-3' : 'w-3.5 h-3.5',
      };
    default:
      return {
        nodeClass: cn(baseSize, 'bg-white border border-slate-200 text-slate-600 shadow-2xs'),
        iconClass: isCompact ? 'w-3 h-3' : 'w-3.5 h-3.5',
      };
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. TIMELINE EVENT ROW COMPONENT
// ─────────────────────────────────────────────────────────────────────────────

function TimelineItemRow({
  item,
  mode,
}: {
  item: OVSTimelineItem;
  mode: TimelineMode;
}) {
  const [expanded, setExpanded] = useState(false);
  const isCompact = mode === 'compact';
  const isDrawer = mode === 'drawer';
  const isFull = mode === 'full';

  const IconComponent = getEventIcon(item);
  const { nodeClass, iconClass } = getNodeStyles(item, isCompact);
  const isSystem = item.eventClass === 'system';
  const isNote = item.eventClass === 'note';

  // ── COMPACT MODE (1-Line Summary) ──────────────────────────────────────────
  if (isCompact) {
    return (
      <div className="relative flex items-center gap-3 pl-7 py-1 text-xs font-sans">
        {/* Node */}
        <div className={cn('absolute left-0 top-1/2 -translate-y-1/2 rounded-full flex items-center justify-center shrink-0 z-10', nodeClass)}>
          <IconComponent className={iconClass} />
        </div>

        {/* Content */}
        <div className="flex items-center justify-between gap-2 min-w-0 flex-1">
          <span className={cn('truncate', isSystem ? 'text-slate-500 font-normal' : 'text-slate-900 font-semibold')}>
            {item.title}
          </span>
          <span className="text-[10px] text-slate-400 shrink-0 font-normal">
            {item.relativeTime || (item.timestamp ? item.timestamp.split('·')[0].trim() : '')}
          </span>
        </div>
      </div>
    );
  }

  // ── SYSTEM AUDIT ROW (Quiet) ───────────────────────────────────────────────
  if (isSystem) {
    return (
      <div className="relative flex items-start gap-3 pl-8 py-1 font-sans text-xs">
        <div className={cn('absolute left-1 top-1 rounded-full flex items-center justify-center shrink-0 z-10', nodeClass)}>
          <IconComponent className={iconClass} />
        </div>

        <div className="flex flex-col sm:flex-row sm:items-baseline justify-between gap-2 min-w-0 flex-1 text-xs text-slate-500">
          <p className="leading-relaxed">
            <span className="font-medium text-slate-700">{item.title}</span>
            {item.description && <span className="ml-1.5 text-slate-500">{item.description}</span>}
          </p>
          <div className="flex items-center gap-2 shrink-0 text-slate-500 font-normal">
            {item.actor && (
              <>
                <span className="font-semibold text-slate-700">{item.actor.name}</span>
                {item.actor.role && (
                  <>
                    <span className="text-slate-300 select-none">·</span>
                    <span className="text-slate-500">{item.actor.role}</span>
                  </>
                )}
                <span className="text-slate-300 select-none">·</span>
              </>
            )}
            <time className="text-slate-500">{item.timestamp}</time>
          </div>
        </div>
      </div>
    );
  }

  // ── STANDARD EVENT ROW (Full & Drawer Mode) ────────────────────────────────
  return (
    <div className="relative flex items-start gap-3.5 pl-9 py-1 font-sans text-slate-900">
      {/* Node */}
      <div className={cn('absolute left-0 top-0.5 rounded-full flex items-center justify-center shrink-0 z-10', nodeClass)}>
        <IconComponent className={iconClass} />
      </div>

      {/* Main Body */}
      <div
        className={cn(
          'flex-1 rounded-xl p-3.5 transition-all text-xs space-y-2',
          isNote
            ? 'bg-amber-50/40 border border-amber-200/80'
            : 'bg-white border border-slate-200/90 shadow-2xs hover:border-slate-300'
        )}
      >
        {/* Top Title & Value Header */}
        <div className="flex items-start justify-between gap-2">
          <div className="flex items-center gap-2 flex-wrap min-w-0">
            {item.isUnread && (
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0" title="Unread event" />
            )}
            <h4 className={cn('font-bold leading-snug truncate', isNote ? 'text-amber-950 font-semibold' : 'text-slate-900')}>
              {item.title}
            </h4>
            {item.badge && (
              <span
                className={cn(
                  'text-[10px] font-bold px-1.5 py-0.2 rounded border leading-none shrink-0',
                  item.badge.variant === 'emerald' && 'bg-emerald-50 text-emerald-800 border-emerald-200',
                  item.badge.variant === 'amber' && 'bg-amber-50 text-amber-800 border-amber-200',
                  item.badge.variant === 'teal' && 'bg-teal-50 text-teal-800 border-teal-200',
                  item.badge.variant === 'rose' && 'bg-rose-50 text-rose-800 border-rose-200',
                  (!item.badge.variant || item.badge.variant === 'default' || item.badge.variant === 'slate') &&
                    'bg-slate-100 text-slate-700 border-slate-200'
                )}
              >
                {item.badge.label}
              </span>
            )}
          </div>

          {/* Right Monetary Value (if present) */}
          {item.monetaryValue && (
            <div className="text-right shrink-0">
              <span className="text-xs font-bold text-slate-900 tabular-nums">
                {item.monetaryValue}
              </span>
            </div>
          )}
        </div>

        {/* Description / Content Body */}
        {item.description && (
          <p className={cn('leading-relaxed', isNote ? 'text-amber-900/90 italic' : 'text-slate-600', isDrawer && 'line-clamp-2')}>
            {item.description}
          </p>
        )}

        {/* Expandable Payload Content (If provided) */}
        {item.expandableContent && (
          <div>
            {expanded && (
              <div className="pt-2 text-[11px] text-slate-700 leading-relaxed border-t border-slate-100 animate-in fade-in duration-150">
                {item.expandableContent}
              </div>
            )}
            <button
              type="button"
              onClick={() => setExpanded(!expanded)}
              className="inline-flex items-center gap-1 text-[11px] font-semibold text-teal-700 hover:text-teal-900 mt-1 cursor-pointer"
            >
              <span>{expanded ? 'Show less' : 'Show details'}</span>
              {expanded ? <ChevronUp className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />}
            </button>
          </div>
        )}

        {/* Attached Documents Row (PDFs, recordings, SLA) */}
        {item.attachments && item.attachments.length > 0 && isFull && (
          <div className="pt-2 border-t border-slate-100 flex items-center gap-2 flex-wrap">
            {item.attachments.map((att) => (
              <a
                key={att.id}
                href={att.url || '#'}
                onClick={(e) => {
                  if (!att.url) e.preventDefault();
                }}
                className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-50 border border-slate-200 text-slate-800 text-[11px] font-medium hover:bg-slate-100 transition-colors"
              >
                <Paperclip className="w-3 h-3 text-slate-400" />
                <span className="truncate max-w-[200px]">{att.name}</span>
                {att.size && <span className="text-[10px] text-slate-400">({att.size})</span>}
                <FileDown className="w-3 h-3 text-slate-400 ml-0.5" />
              </a>
            ))}
          </div>
        )}

        {/* Metadata Footer: Actor + Timestamp + Contextual Action */}
        <div className="pt-2.5 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 text-xs text-slate-500">
          <div className="flex items-center gap-2 flex-wrap min-w-0">
            {item.actor && (
              <div className="flex items-center gap-2 shrink-0">
                {/* 20px Circular Avatar */}
                {item.actor.type === 'system' || item.actor.name.toLowerCase().includes('system') ? (
                  <span className="w-5 h-5 rounded-full bg-slate-100 border border-slate-200/90 flex items-center justify-center text-slate-600 shrink-0 shadow-2xs">
                    <UserCheck className="w-3 h-3" />
                  </span>
                ) : item.actor.type === 'ai' || item.actor.name.toLowerCase().includes('ai') ? (
                  <span className="w-5 h-5 rounded-full bg-teal-50 border border-teal-200 flex items-center justify-center text-teal-700 shrink-0 shadow-2xs">
                    <Sparkles className="w-3 h-3" />
                  </span>
                ) : (
                  <Avatar
                    fallback={item.actor.initials || item.actor.name.slice(0, 2).toUpperCase()}
                    size="xs"
                  />
                )}

                {/* Primary Actor Name */}
                <span className="font-semibold text-slate-800">{item.actor.name}</span>

                {/* Secondary Role */}
                {item.actor.role && (
                  <>
                    <span className="text-slate-300 select-none">·</span>
                    <span className="text-slate-500 font-normal">{item.actor.role}</span>
                  </>
                )}
              </div>
            )}

            {item.actor && <span className="text-slate-300 select-none">·</span>}

            {/* Tertiary Timestamp */}
            <time className="font-normal text-slate-500 shrink-0">{item.timestamp}</time>
            {item.relativeTime && (
              <span className="text-[11px] text-slate-400 shrink-0">({item.relativeTime})</span>
            )}
          </div>

          {/* Contextual Single Primary Action Button */}
          {item.action && (
            <Button
              size="sm"
              variant={item.action.variant === 'brand' ? 'brand' : 'secondary'}
              onClick={item.action.onClick}
              className="h-6.5 px-2.5 text-[11px] font-semibold gap-1 self-start sm:self-auto shrink-0"
            >
              {item.action.icon && <item.action.icon className="w-3 h-3" />}
              <span>{item.action.label}</span>
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. CANONICAL OVS GLOBAL TIMELINE
// ─────────────────────────────────────────────────────────────────────────────

export const OVSGlobalTimeline: React.FC<OVSGlobalTimelineProps> = ({
  items = [],
  mode = 'full',
  showFilters = false,
  showSearch = false,
  searchPlaceholder = 'Search timeline events…',
  emptyText = 'No activity items recorded yet.',
  groupByDate = true,
  className,
}) => {
  const [activeCategory, setActiveCategory] = useState<TimelineFilterCategory>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');

  // Filter items
  const filteredItems = (items || []).filter((item) => {
    if (!item) return false;
    if (activeCategory !== 'all' && item.eventClass !== activeCategory) {
      return false;
    }
    if (searchQuery.trim().length > 0) {
      const q = searchQuery.toLowerCase();
      const matchTitle = (item.title || '').toLowerCase().includes(q);
      const matchDesc = (item.description || '').toLowerCase().includes(q);
      const matchActor = (item.actor?.name || '').toLowerCase().includes(q);
      if (!matchTitle && !matchDesc && !matchActor) return false;
    }
    return true;
  });

  // Group items by dateGroup if enabled
  const groupedItems: { [group: string]: OVSTimelineItem[] } = {};
  if (groupByDate && mode !== 'compact') {
    for (const item of filteredItems) {
      const group = item.dateGroup || 'Recent Activity';
      if (!groupedItems[group]) groupedItems[group] = [];
      groupedItems[group].push(item);
    }
  }

  const hasGroups = Object.keys(groupedItems).length > 0;

  return (
    <div className={cn('space-y-4 font-sans text-slate-900', className)}>
      {/* Optional Filter & Search Controls Header */}
      {(showFilters || showSearch) && (
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 pb-2 border-b border-slate-100">
          {showFilters && (
            <div className="flex items-center gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden text-xs">
              {[
                { id: 'all', label: 'All' },
                { id: 'communication', label: 'Communications' },
                { id: 'commercial', label: 'Commercial' },
                { id: 'task', label: 'Tasks' },
                { id: 'note', label: 'Notes' },
              ].map((tab) => (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => setActiveCategory(tab.id as TimelineFilterCategory)}
                  className={cn(
                    'px-2.5 py-1 rounded-lg font-semibold transition-all cursor-pointer whitespace-nowrap text-[11px]',
                    activeCategory === tab.id
                      ? 'bg-slate-900 text-white shadow-2xs'
                      : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                  )}
                >
                  {tab.label}
                </button>
              ))}
            </div>
          )}

          {showSearch && (
            <div className="relative min-w-[180px] max-w-xs">
              <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder={searchPlaceholder}
                className="w-full h-7.5 pl-8 pr-3 text-[11px] bg-slate-50 border border-slate-200 rounded-lg text-slate-900 placeholder:text-slate-400 focus:bg-white focus:outline-none focus:border-teal-600 transition-colors"
              />
            </div>
          )}
        </div>
      )}

      {/* Empty State */}
      {filteredItems.length === 0 && (
        <div className="p-8 text-center border border-dashed border-slate-200 rounded-2xl bg-white text-xs text-slate-500">
          {emptyText}
        </div>
      )}

      {/* Continuous Timeline Stream */}
      {filteredItems.length > 0 && (
        <div
          className={cn(
            'relative space-y-3.5',
            'before:absolute before:left-3.5 before:top-2 before:bottom-2 before:w-[1.5px] before:bg-slate-200'
          )}
        >
          {hasGroups
            ? Object.entries(groupedItems).map(([groupTitle, groupEvents]) => (
                <div key={groupTitle} className="space-y-3 pt-1">
                  {/* Date Divider Pill */}
                  <div className="relative pl-8">
                    <span className="text-[10px] font-mono font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-slate-100 text-slate-600 border border-slate-200">
                      {groupTitle}
                    </span>
                  </div>

                  {/* Group Items */}
                  {groupEvents.map((item) => (
                    <TimelineItemRow key={item.id} item={item} mode={mode} />
                  ))}
                </div>
              ))
            : filteredItems.map((item) => (
                <TimelineItemRow key={item.id} item={item} mode={mode} />
              ))}
        </div>
      )}
    </div>
  );
};

// Backwards compatibility export
export const OVSTimeline = OVSGlobalTimeline;
