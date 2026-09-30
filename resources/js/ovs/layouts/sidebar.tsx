import React, { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { useNavAuthorization } from '@/hooks/useNavAuthorization';
import { Badge } from '@/ovs/primitives/badge';
import {
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  LucideIcon,
} from 'lucide-react';
import {
  SidebarNavItem,
  SidebarNavSection,
  EXECUTIVE_TOP_LEVEL_DASHBOARD,
  EXECUTIVE_NAV_GROUPS,
  ENTERPRISE_NAV_GROUPS,
} from './sidebar-configs';

// Export canonical interfaces
export type OVSSidebarNavItem = SidebarNavItem;
export type OVSSidebarNavSection = SidebarNavSection;

// ─────────────────────────────────────────────────────────────────────────────
// SESSION-PERSISTED UI STATE (accordion expansion + scroll position)
// ─────────────────────────────────────────────────────────────────────────────
// The sidebar lives inside each page's own render tree (not an Inertia
// persistent layout), so it fully unmounts and remounts on every navigation.
// Without this, every click into a nav item collapses whatever section the
// user had open and snaps the sidebar scroll back to the top — it looks and
// feels like a hard page reload even though Inertia is doing an SPA visit.
// Persisting to sessionStorage survives the remount and keeps the sidebar
// exactly where the user left it, for the lifetime of the browser tab.
const EXPANDED_GROUPS_KEY = 'ovs-sidebar-expanded-groups';
const SCROLL_POSITION_KEY = 'ovs-sidebar-scroll-top';

function readStoredExpandedGroups(): Record<string, boolean> | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.sessionStorage.getItem(EXPANDED_GROUPS_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function writeStoredExpandedGroups(state: Record<string, boolean>) {
  if (typeof window === 'undefined') return;
  try {
    window.sessionStorage.setItem(EXPANDED_GROUPS_KEY, JSON.stringify(state));
  } catch {
    // Ignore storage failures (private browsing, quota, etc.)
  }
}

export interface OVSSidebarProps {
  brandName?: string;
  brandSubtitle?: string;
  variant?: 'executive' | 'enterprise' | 'ovs' | 'default';
  sections?: SidebarNavSection[];
  topItem?: SidebarNavItem;
  collapsed?: boolean;
  onToggleCollapse?: () => void;
  activePath?: string;
  headerAction?: React.ReactNode;
  footerNode?: React.ReactNode;
  className?: string;
}

// ─────────────────────────────────────────────────────────────────────────────
// REUSABLE BADGE PRIMITIVE (TABULAR & QUIET)
// ─────────────────────────────────────────────────────────────────────────────

export function SidebarBadge({
  content,
  variant = 'neutral',
  active = false,
}: {
  content: string | number;
  variant?: 'neutral' | 'urgent' | 'live';
  active?: boolean;
}) {
  // Reserved for genuinely real-time-backed counts only (e.g. a live queue count).
  // Do not attach to static/decorative labels.
  if (variant === 'live') {
    return (
      <Badge
        variant="info"
        className="text-[10px] px-1.5 py-0.2 rounded-full font-bold gap-1 leading-none bg-ovs-semantic-teal-bg text-ovs-semantic-teal-text border-transparent"
      >
        <span className="w-1.5 h-1.5 rounded-full bg-ovs-semantic-teal animate-pulse" />
        LIVE
      </Badge>
    );
  }

  return (
    <Badge
      variant={variant === 'urgent' ? 'danger' : active ? 'info' : 'neutral'}
      className={cn(
        'text-[10px] px-1.5 py-0.2 rounded-full font-mono font-semibold tabular-nums border-transparent transition-colors',
        active
          ? 'bg-ovs-semantic-teal text-white'
          : variant === 'urgent'
          ? 'bg-ovs-semantic-danger-bg text-ovs-semantic-danger-text'
          : 'bg-ovs-background text-ovs-text-secondary'
      )}
    >
      {content}
    </Badge>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// REFINED BRAND HEADER (LINEAR / STRIPE QUALITY)
// ─────────────────────────────────────────────────────────────────────────────

export function SidebarBrandHeader({
  brandName = 'OralCares',
  collapsed = false,
  onToggleCollapse,
  headerAction,
}: {
  brandName?: string;
  collapsed?: boolean;
  onToggleCollapse?: () => void;
  headerAction?: React.ReactNode;
}) {
  return (
    <div
      className={cn(
        'pt-3 h-[84px] flex-shrink-0 flex items-center justify-between px-4 border-b border-ovs-border-subtle transition-all select-none',
        collapsed && 'justify-center px-2'
      )}
    >
      <div className="flex items-center gap-2.5 min-w-0">
        <div className="w-8 h-8 rounded-lg bg-ovs-primary text-white flex items-center justify-center shadow-ovs-keyline flex-shrink-0">
          <svg className="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" />
          </svg>
        </div>
        {!collapsed && (
          <span className="text-[15px] font-bold text-ovs-primary leading-none tracking-tight font-display truncate">
            {brandName}
          </span>
        )}
      </div>

      {!collapsed && headerAction && <div>{headerAction}</div>}

      {onToggleCollapse && (
        <button
          type="button"
          onClick={onToggleCollapse}
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          className="w-7 h-7 rounded-md flex items-center justify-center text-ovs-text-tertiary hover:text-ovs-text-primary hover:bg-ovs-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-semantic-teal/40 transition-all"
        >
          {collapsed ? <ChevronRight className="w-4 h-4" strokeWidth={1.75} /> : <ChevronLeft className="w-4 h-4" strokeWidth={1.75} />}
        </button>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// CORE AUTHORITATIVE SIDEBAR ENGINE
// ─────────────────────────────────────────────────────────────────────────────

export function OVSSidebar({
  brandName = 'OralCares',
  variant = 'executive',
  sections,
  topItem,
  collapsed = false,
  onToggleCollapse,
  activePath,
  headerAction,
  footerNode,
  className = '',
}: OVSSidebarProps) {
  const { url, props } = usePage();
  const isMasterAdmin = (props as any)?.auth?.user?.user_type === 'master_admin';

  const resolvedPath = activePath ?? url;

  // Resolve Sections
  const effectiveSections =
    sections ??
    (variant === 'enterprise'
      ? ENTERPRISE_NAV_GROUPS
      : EXECUTIVE_NAV_GROUPS);

  const effectiveTopItem =
    topItem ?? (variant === 'executive' ? EXECUTIVE_TOP_LEVEL_DASHBOARD : undefined);

  // Accordion state — restored from sessionStorage so it survives the remount
  // that happens on every Inertia page visit (see note above). Falls back to
  // "everything open" for a brand-new tab/session, same as before.
  const [expandedGroups, setExpandedGroups] = useState<Record<string, boolean>>(() => {
    const stored = readStoredExpandedGroups();
    const initial: Record<string, boolean> = {};
    effectiveSections.forEach((g) => {
      initial[g.id] = stored?.[g.id] ?? true;
    });
    return initial;
  });

  const toggleGroup = (groupId: string) => {
    setExpandedGroups((prev) => {
      const next = { ...prev, [groupId]: !prev[groupId] };
      writeStoredExpandedGroups(next);
      return next;
    });
  };

  // Scroll position — restored on mount so the visible section stays in view
  // instead of snapping back to the top after every navigation.
  const navRef = useRef<HTMLElement>(null);

  useEffect(() => {
    if (typeof window === 'undefined' || !navRef.current) return;
    const stored = window.sessionStorage.getItem(SCROLL_POSITION_KEY);
    if (stored) {
      navRef.current.scrollTop = parseInt(stored, 10) || 0;
    }
  }, []);

  const handleNavScroll = (e: React.UIEvent<HTMLElement>) => {
    if (typeof window === 'undefined') return;
    try {
      window.sessionStorage.setItem(SCROLL_POSITION_KEY, String(e.currentTarget.scrollTop));
    } catch {
      // Ignore storage failures
    }
  };

  /*
   * Authorization guard — the same rule every other Company navigation surface
   * asks (`useNavAuthorization`), not a second copy of it.
   *
   * This used to hand-roll its own version, including an "empty permission
   * list means show everything" fallback that `useNavAuthorization` had
   * already had removed elsewhere for inverting the rule it was bolted onto: a
   * user with no grants at all was shown every destination in the product and
   * refused by each route in turn. It was dormant here only because the one
   * config this component renders today (`ENTERPRISE_NAV_GROUPS`) declares no
   * `permission` on its item — the moment a gated item is added, the fallback
   * would have silently reintroduced the exact bug this hook exists to close.
   */
  const isAuthorized = useNavAuthorization();

  const visibleGroups = effectiveSections
    .filter((g) => isAuthorized(g.permission))
    .map((g) => ({
      ...g,
      items: g.items.filter((i) => isAuthorized(i.permission) && ! (i.hideForMasterAdmin && isMasterAdmin)),
    }))
    /*
     * A heading with nothing under it is not navigation. The module nav has
     * always dropped these; this renderer did not, so a section whose items
     * were all filtered away still printed its title.
     */
    .filter((g) => g.items.length > 0);

  const isItemActive = (item: SidebarNavItem): boolean => {
    if (!item.href || item.href === '#') return false;
    /*
     * Deals owns both URL families: its own, and the legacy /leads paths that
     * Deal Detail still lives under. The record URL was deliberately not
     * changed in Phase 2, so the sidebar has to recognise both to stay lit
     * while a rep is inside a deal.
     */
    if (item.href === '/company/crm/deals') {
      return (
        resolvedPath.startsWith('/company/crm/deals') ||
        resolvedPath.startsWith('/company/crm/leads')
      );
    }
    return (
      resolvedPath === item.href ||
      (item.href !== '/company/dashboard' &&
        item.href !== '/company/crm/dashboard' &&
        resolvedPath.startsWith(item.href))
    );
  };

  const isGroupActive = (group: SidebarNavSection): boolean => {
    return group.items.some(
      (i) => isItemActive(i) || (i.children && i.children.some((c) => isItemActive(c)))
    );
  };

  const isDashboardActive = effectiveTopItem ? isItemActive(effectiveTopItem) : false;

  return (
    <aside
      aria-label="Primary navigation"
      className={cn(
        'flex-shrink-0 flex flex-col bg-white border-r border-ovs-border-subtle h-full',
        'overflow-hidden transition-[width] duration-200 ease-in-out font-sans antialiased select-none',
        collapsed ? 'w-[72px]' : 'w-[260px]',
        className
      )}
    >
      {/* 1. BRAND HEADER */}
      <SidebarBrandHeader
        brandName={brandName}
        collapsed={collapsed}
        onToggleCollapse={onToggleCollapse}
        headerAction={headerAction}
      />

      {/* 2. SCROLLABLE NAVIGATION CONTAINER */}
      <nav
        ref={navRef}
        onScroll={handleNavScroll}
        aria-label="Workspace navigation"
        className={cn(
          'flex-1 overflow-y-auto [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden',
          collapsed ? 'px-2 pt-3 pb-3' : 'px-3 pt-3 pb-4'
        )}
      >
        {/* STANDALONE TOP-LEVEL ITEM (DASHBOARD) */}
        {effectiveTopItem && (
          <div className="mb-2">
            <Link
              href={effectiveTopItem.href}
              prefetch
              aria-current={isDashboardActive ? 'page' : undefined}
              aria-label={collapsed ? effectiveTopItem.label : undefined}
              title={collapsed ? effectiveTopItem.label : undefined}
              className={cn(
                'relative group w-full flex items-center gap-2.5 rounded-xl text-[13px] font-medium transition-all duration-150',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-accent/40',
                collapsed ? 'justify-center px-2 py-2 min-h-[38px]' : 'px-3 py-2 min-h-[36px]',
                isDashboardActive
                  ? 'bg-gradient-to-r from-ovs-accent/[0.12] via-ovs-accent/[0.06] to-transparent text-ovs-text-primary font-bold border border-ovs-accent/20 shadow-ovs-keyline'
                  : 'text-ovs-text-secondary hover:bg-ovs-background hover:text-ovs-text-primary'
              )}
            >
              {isDashboardActive && (
                <span
                  className="absolute left-0 top-1/2 -translate-y-1/2 w-[3.5px] h-3/4 bg-gradient-to-b from-ovs-accent to-ovs-brand rounded-r-full shadow-ovs-glow"
                  aria-hidden="true"
                />
              )}
              {effectiveTopItem.icon && (
                <effectiveTopItem.icon
                  aria-hidden="true"
                  className={cn(
                    'w-4 h-4 flex-shrink-0 transition-colors duration-150',
                    isDashboardActive ? 'text-ovs-accent' : 'text-ovs-text-tertiary group-hover:text-ovs-text-secondary'
                  )}
                  strokeWidth={1.75}
                />
              )}
              {!collapsed && (
                <span className="truncate flex-1 tracking-tight leading-none font-display">
                  {effectiveTopItem.label}
                </span>
              )}
            </Link>
          </div>
        )}

        {/* SECTION NAVIGATION RENDERER */}
        <div className="space-y-3 mt-1">
          {visibleGroups.map((group) => {
            const isOpen = !collapsed && expandedGroups[group.id] !== false;
            const groupActive = isGroupActive(group);

            return (
              <div key={group.id} className="space-y-0.5">
                {/* Section Header */}
                {!collapsed ? (
                  <button
                    type="button"
                    onClick={() => toggleGroup(group.id)}
                    aria-expanded={isOpen}
                    className="w-full flex items-center justify-between px-2.5 pt-2 pb-1 text-left group focus:outline-none"
                  >
                    <span
                      className={cn(
                        'text-[10.5px] font-bold tracking-[0.07em] uppercase transition-colors font-display',
                        groupActive ? 'text-ovs-text-primary' : 'text-ovs-text-tertiary group-hover:text-ovs-text-secondary'
                      )}
                    >
                      {group.title}
                    </span>
                    <ChevronDown
                      className={cn(
                        'w-3 h-3 text-ovs-text-tertiary transition-transform duration-150',
                        isOpen ? 'rotate-0' : '-rotate-90'
                      )}
                      strokeWidth={1.75}
                    />
                  </button>
                ) : (
                  <div
                    title={group.title}
                    className={cn(
                      'w-full flex justify-center py-1.5 text-ovs-text-tertiary text-[10px] font-bold uppercase',
                      groupActive && 'text-ovs-accent'
                    )}
                  >
                    <span className="w-1.5 h-1.5 rounded-full bg-ovs-border-strong" />
                  </div>
                )}

                {/* Section Children Menu Items */}
                {isOpen && group.items.length > 0 && (
                  <div className="space-y-0.5">
                    {group.items.map((item) => {
                      const Icon = item.icon;
                      const active = isItemActive(item);
                      const hasChildren = item.children && item.children.length > 0;

                      return (
                        <div key={item.id} className="flex flex-col">
                          <Link
                            href={item.href && item.href !== '#' ? item.href : '#'}
                            prefetch
                            aria-current={active ? 'page' : undefined}
                            title={collapsed ? item.label : undefined}
                            className={cn(
                              'relative group w-full flex items-center gap-2.5 rounded-xl text-[13px] font-medium transition-all duration-150',
                              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-accent/40',
                              collapsed ? 'justify-center px-2 py-2 min-h-[38px]' : 'px-3 py-2 min-h-[36px]',
                              active
                                ? 'bg-gradient-to-r from-ovs-accent/[0.12] via-ovs-accent/[0.06] to-transparent text-ovs-text-primary font-bold border border-ovs-accent/20 shadow-ovs-keyline'
                                : 'text-ovs-text-secondary hover:bg-ovs-background hover:text-ovs-text-primary'
                            )}
                          >
                            {active && (
                              <span
                                className="absolute left-0 top-1/2 -translate-y-1/2 w-[3.5px] h-3/4 bg-gradient-to-b from-ovs-accent to-ovs-brand rounded-r-full shadow-ovs-glow"
                                aria-hidden="true"
                              />
                            )}
                            {Icon && (
                              <Icon
                                aria-hidden="true"
                                className={cn(
                                  'w-4 h-4 flex-shrink-0 transition-colors duration-150',
                                  active ? 'text-ovs-accent' : 'text-ovs-text-tertiary group-hover:text-ovs-text-secondary'
                                )}
                                strokeWidth={1.75}
                              />
                            )}
                            {!collapsed && (
                              <span className="truncate flex-1 tracking-tight leading-none font-sans">
                                {item.label}
                              </span>
                            )}
                            {!collapsed && item.badge !== undefined && (
                              <SidebarBadge
                                content={item.badge}
                                variant={item.badgeVariant || 'neutral'}
                                active={active}
                              />
                            )}
                          </Link>

                          {/* Nested Sub-items (e.g. Accounts -> Organizations / Customers) */}
                          {hasChildren && !collapsed && (
                            <div className="mt-0.5 ml-4 pl-3.5 border-l border-ovs-border space-y-0.5">
                              {item.children!.map((child) => {
                                const childActive = isItemActive(child);
                                return (
                                  <Link
                                    key={child.id}
                                    href={child.href}
                                    className={cn(
                                      'block px-2.5 py-1.5 text-xs rounded-md transition-all truncate font-medium',
                                      childActive
                                        ? 'text-ovs-text-primary font-semibold bg-ovs-accent/[0.08]'
                                        : 'text-ovs-text-secondary hover:text-ovs-text-primary hover:bg-ovs-background'
                                    )}
                                  >
                                    {child.label}
                                  </Link>
                                );
                              })}
                            </div>
                          )}
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </nav>

      {/* Optional Custom Footer Node */}
      {footerNode && <div className="p-3 border-t border-ovs-border">{footerNode}</div>}
    </aside>
  );
}

// Canonical export
export { OVSSidebar as OvsSidebar };
