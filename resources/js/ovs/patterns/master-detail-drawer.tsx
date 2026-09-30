import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import { X, ExternalLink, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Sheet, SheetContent } from '@/ovs/overlays/sheet';
import { Button } from '@/ovs/primitives/button';

export interface DrawerTab {
  id: string;
  label: string;
  count?: number;
  content: React.ReactNode;
}

export interface OVSMasterDetailDrawerProps {
  open: boolean;
  onClose: () => void;
  title: string;
  subtitle?: string;
  metadata?: string;
  tag?: string;
  statusCluster?: React.ReactNode;
  tabs?: DrawerTab[];
  activeTabId?: string;
  onTabChange?: (id: string) => void;
  actions?: React.ReactNode;
  fullWorkspaceHref?: string;
  onOpenFullWorkspace?: () => void;
  children?: React.ReactNode;
  className?: string;
  widthClass?: string; // defaults to 'sm:max-w-xl' (576px)
}

export const OVSMasterDetailDrawer: React.FC<OVSMasterDetailDrawerProps> = ({
  open,
  onClose,
  title,
  subtitle,
  metadata,
  tag,
  statusCluster,
  tabs,
  activeTabId: externalActiveTab,
  onTabChange,
  actions,
  fullWorkspaceHref,
  onOpenFullWorkspace,
  children,
  className,
  widthClass = 'sm:max-w-xl',
}) => {
  const [internalTab, setInternalTab] = useState<string>(tabs?.[0]?.id || '');
  const currentTab = externalActiveTab || internalTab;

  const handleTabSelect = (tabId: string) => {
    if (onTabChange) onTabChange(tabId);
    else setInternalTab(tabId);
  };

  const activeContent = tabs?.find((t) => t.id === currentTab)?.content;

  return (
    <Sheet open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <SheetContent
        side="right"
        className={cn(
          'p-0 w-full border-l border-slate-200 flex flex-col justify-between overflow-hidden font-sans text-slate-900 shadow-2xl bg-white',
          widthClass,
          className
        )}
        hideCloseButton
      >
        {/* ── 1. DRAWER TOP CONTROL & IDENTITY STRIP ──────────────────────── */}
        <div className="p-5 sm:p-6 border-b border-slate-100 bg-slate-50/70 flex-shrink-0 space-y-3">
          <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-2 min-w-0">
              {tag && (
                <span className="text-[10px] font-mono font-bold uppercase tracking-wider px-2 py-0.5 rounded-md bg-slate-900 text-white shrink-0">
                  {tag}
                </span>
              )}
              <span className="text-xs text-slate-500 font-medium truncate">Fast Context Inspection</span>
            </div>

            <div className="flex items-center gap-1.5 shrink-0">
              {(fullWorkspaceHref || onOpenFullWorkspace) && (
                <>
                  {fullWorkspaceHref ? (
                    <Link
                      href={fullWorkspaceHref}
                      className="h-7 px-2.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-900 text-[11px] font-semibold rounded-lg flex items-center gap-1 transition-colors"
                    >
                      <span>Full Focus</span>
                      <ExternalLink className="w-3 h-3 text-teal-700" />
                    </Link>
                  ) : (
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={onOpenFullWorkspace}
                      className="h-7 px-2.5 text-[11px] font-semibold gap-1"
                    >
                      <span>Full Focus</span>
                      <ExternalLink className="w-3 h-3 text-teal-700" />
                    </Button>
                  )}
                </>
              )}
              <button
                type="button"
                onClick={onClose}
                className="w-7 h-7 rounded-lg bg-white border border-slate-200 hover:bg-slate-100 text-slate-500 hover:text-slate-900 flex items-center justify-center transition-colors cursor-pointer"
                title="Close drawer (Esc)"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
          </div>

          {/* Primary Entity Title & Subtitles */}
          <div>
            <h2 className="text-lg sm:text-xl font-bold text-slate-900 tracking-tight leading-snug">
              {title}
            </h2>
            {(subtitle || metadata) && (
              <div className="flex items-center gap-1.5 mt-1 text-xs text-slate-600 font-normal flex-wrap">
                {subtitle && <span className="font-medium text-slate-800">{subtitle}</span>}
                {subtitle && metadata && <span className="text-slate-300">·</span>}
                {metadata && <span className="text-slate-500">{metadata}</span>}
              </div>
            )}
          </div>

          {/* Status Cluster */}
          {statusCluster && (
            <div className="pt-2 border-t border-slate-200/60 flex items-center gap-2 flex-wrap">
              {statusCluster}
            </div>
          )}
        </div>

        {/* ── 2. TAB NAVIGATION (IF PROVIDED) ────────────────────────────── */}
        {tabs && tabs.length > 0 && (
          <div className="flex border-b border-slate-100 bg-white px-5 sm:px-6 gap-2 overflow-x-auto shrink-0 select-none">
            {tabs.map((tab) => {
              const isActive = tab.id === currentTab;
              return (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => handleTabSelect(tab.id)}
                  className={cn(
                    'py-2.5 px-3 text-xs font-semibold border-b-2 transition-colors flex items-center gap-1.5 whitespace-nowrap cursor-pointer',
                    isActive
                      ? 'border-teal-700 text-teal-900'
                      : 'border-transparent text-slate-500 hover:text-slate-900'
                  )}
                >
                  <span>{tab.label}</span>
                  {typeof tab.count === 'number' && (
                    <span
                      className={cn(
                        'text-[10px] font-bold px-1.5 py-0.2 rounded-full tabular-nums',
                        isActive ? 'bg-teal-100 text-teal-800' : 'bg-slate-100 text-slate-600'
                      )}
                    >
                      {tab.count}
                    </span>
                  )}
                </button>
              );
            })}
          </div>
        )}

        {/* ── 3. SCROLLABLE DRAWER BODY ──────────────────────────────────── */}
        <div className="flex-1 overflow-y-auto p-5 sm:p-6 space-y-5 text-xs text-slate-700">
          {tabs && tabs.length > 0 ? activeContent : children}
        </div>

        {/* ── 4. ATTACHED FOOTER ACTION HUB ──────────────────────────────── */}
        {actions && (
          <div className="p-4 sm:p-5 border-t border-slate-100 bg-slate-50/90 flex items-center justify-between gap-3 flex-shrink-0">
            {actions}
          </div>
        )}
      </SheetContent>
    </Sheet>
  );
};
