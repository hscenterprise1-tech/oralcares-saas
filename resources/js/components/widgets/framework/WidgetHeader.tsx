import React, { useState, useRef, useEffect } from 'react';
import { cn } from '@/lib/utils';
import { RefreshCw, MoreVertical } from 'lucide-react';

export interface WidgetHeaderMenuItem {
  label: string;
  onClick: () => void;
  icon?: React.ElementType;
  danger?: boolean;
}

export interface WidgetHeaderProps {
  title: string;
  subtitle?: string;
  icon?: React.ElementType;
  badge?: {
    text: string;
    variant?: 'primary' | 'success' | 'warning' | 'danger' | 'neutral';
  };
  actions?: React.ReactNode;
  onRefresh?: () => void;
  isRefreshing?: boolean;
  moreMenuItems?: WidgetHeaderMenuItem[];
  lastUpdated?: string;
  className?: string;
}

const BADGE_VARIANTS = {
  primary: 'bg-[#EEF2FF] text-[#4F46E5]',
  success: 'bg-emerald-50 text-emerald-700',
  warning: 'bg-amber-50 text-amber-700',
  danger:  'bg-rose-50 text-rose-700',
  neutral: 'bg-[#F1F5F9] text-[#64748B]',
};

export function WidgetHeader({
  title,
  subtitle,
  icon: Icon,
  badge,
  actions,
  onRefresh,
  isRefreshing = false,
  moreMenuItems = [],
  lastUpdated,
  className = '',
}: WidgetHeaderProps) {
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!menuOpen) return;
    const handler = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [menuOpen]);

  return (
    <div className={cn('px-5 pt-4 pb-3 flex items-start justify-between gap-3 border-b border-[#E4E7EE] select-none', className)}>
      {/* Title block */}
      <div className="flex items-start gap-2.5 min-w-0">
        {Icon && (
          <div className="w-7 h-7 rounded-lg bg-[#F8FAFC] border border-[#E4E7EE] flex items-center justify-center text-[#475569] flex-shrink-0 mt-0.5">
            <Icon className="w-3.5 h-3.5" strokeWidth={1.75} />
          </div>
        )}
        <div className="min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <h3 className="text-[13px] font-bold text-[#0F172A] leading-tight truncate">
              {title}
            </h3>
            {badge && (
              <span
                className={cn(
                  'text-[10px] font-bold px-1.5 py-[2px] rounded-md leading-none flex-shrink-0',
                  BADGE_VARIANTS[badge.variant || 'neutral'],
                )}
              >
                {badge.text}
              </span>
            )}
          </div>
          {subtitle && (
            <p className="text-[11px] text-[#64748B] font-medium leading-tight truncate mt-0.5">
              {subtitle}
            </p>
          )}
          {lastUpdated && (
            <p className="text-[10px] text-[#94A3B8] font-mono leading-none mt-1">
              Updated {lastUpdated}
            </p>
          )}
        </div>
      </div>

      {/* Header controls & actions */}
      <div className="flex items-center gap-1.5 flex-shrink-0">
        {actions}

        {onRefresh && (
          <button
            type="button"
            onClick={onRefresh}
            disabled={isRefreshing}
            aria-label="Refresh widget"
            className="w-7 h-7 rounded-lg flex items-center justify-center text-[#94A3B8] hover:text-[#0F172A] hover:bg-[#F8FAFC] transition-all disabled:opacity-50"
          >
            <RefreshCw className={cn('w-3.5 h-3.5', isRefreshing && 'animate-spin text-[#4F46E5]')} strokeWidth={1.75} />
          </button>
        )}

        {moreMenuItems.length > 0 && (
          <div ref={menuRef} className="relative">
            <button
              type="button"
              onClick={() => setMenuOpen((o) => !o)}
              aria-label="Widget options"
              aria-expanded={menuOpen}
              className="w-7 h-7 rounded-lg flex items-center justify-center text-[#94A3B8] hover:text-[#0F172A] hover:bg-[#F8FAFC] transition-all"
            >
              <MoreVertical className="w-3.5 h-3.5" strokeWidth={1.75} />
            </button>

            {menuOpen && (
              <div
                role="menu"
                className="absolute right-0 top-[calc(100%+4px)] z-30 w-44 bg-white border border-[#E4E7EE] rounded-xl shadow-[0_8px_24px_rgba(0,0,0,0.09)] p-1 space-y-px animate-in fade-in duration-150"
              >
                {moreMenuItems.map((item, idx) => {
                  const ItemIcon = item.icon;
                  return (
                    <button
                      key={idx}
                      type="button"
                      role="menuitem"
                      onClick={() => {
                        item.onClick();
                        setMenuOpen(false);
                      }}
                      className={cn(
                        'w-full flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-[12px] font-medium transition-all text-left',
                        item.danger
                          ? 'text-rose-600 hover:bg-rose-50'
                          : 'text-[#475569] hover:bg-[#F8FAFC] hover:text-[#0F172A]',
                      )}
                    >
                      {ItemIcon && <ItemIcon className="w-3.5 h-3.5 flex-shrink-0" strokeWidth={1.75} />}
                      <span className="truncate">{item.label}</span>
                    </button>
                  );
                })}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
