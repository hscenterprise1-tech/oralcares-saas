import React, { useEffect, useRef, useState, useCallback } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';
import { Badge } from '@/ovs/primitives/badge';
import { IconChip, type IconChipTone } from '@/ovs/primitives/icon-chip';
import {
  Plus,
  Bell,
  User,
  LogOut,
  Menu,
  Briefcase,
  Stethoscope,
  CheckSquare,
  FileText,
  ChevronDown,
  Search,
  Loader2,
  CheckCheck,
} from 'lucide-react';

import { CommandPalette } from '@/components/enterprise-shell/CommandPalette';

export interface ExecutiveHeaderProps {
  onToggleMobileDrawer?: () => void;
  className?: string;
}

const QUICK_CREATE_ITEMS = [
  { label: 'New Lead',         href: '/company/crm/leads/create',            icon: Briefcase,   badge: 'CRM',     tone: 'indigo' as IconChipTone },
] as const;

const BADGE_VARIANTS: Record<string, 'info' | 'success' | 'warning' | 'neutral'> = {
  CRM:     'info',
  Network: 'success',
  Finance: 'warning',
  Ops:     'neutral',
};

// Fully opaque, high-contrast surface for every header popover — never translucent,
// so page content never bleeds through and reduces legibility.
const POPOVER_SURFACE = cn(
  'bg-white border border-ovs-border rounded-2xl',
  'ring-1 ring-ovs-primary/[0.05]',
  'shadow-ovs-floating',
);

function QuickCreateMenu() {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  return (
    <div ref={ref} className="relative flex-shrink-0">
      <Button
        variant="brand-gradient"
        size="sm"
        onClick={() => setOpen((o) => !o)}
        aria-label="Quick create"
        aria-expanded={open}
        aria-haspopup="menu"
        className="gap-1.5 h-[38px] px-3.5 sm:px-4 rounded-xl shadow-ovs-brandGlow hover:shadow-ovs-brandGlowHover hover:-translate-y-0.5 font-bold select-none"
      >
        <Plus className="w-4 h-4 flex-shrink-0" strokeWidth={2} />
        <span className="hidden sm:inline">New</span>
      </Button>

      {open && (
        <>
          <div className="fixed inset-0 z-40" aria-hidden="true" onClick={() => setOpen(false)} />
          <div
            role="menu"
            className={cn(
              'absolute right-0 top-[calc(100%+8px)] z-50 w-56 p-1.5',
              POPOVER_SURFACE,
              'animate-in fade-in slide-in-from-top-1 duration-150',
            )}
          >
            <p className="text-[10px] font-bold text-ovs-text-tertiary tracking-wider uppercase px-2.5 pt-1.5 pb-1 font-display">
              Quick Create
            </p>
            <div className="space-y-0.5">
              {QUICK_CREATE_ITEMS.map((item) => {
                const Icon = item.icon;
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    role="menuitem"
                    onClick={() => setOpen(false)}
                    className="w-full flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-medium text-ovs-text-secondary hover:bg-ovs-background hover:text-ovs-text-primary transition-all duration-150 focus-visible:outline-none font-sans group"
                  >
                    <IconChip
                      tone={item.tone}
                      size="xs"
                      icon={<Icon className="w-3 h-3" strokeWidth={2} />}
                    />
                    <span className="flex-1 truncate text-xs font-semibold text-ovs-text-primary group-hover:text-ovs-text-primary">{item.label}</span>
                    <Badge variant={BADGE_VARIANTS[item.badge] || 'neutral'} size="compact" className="text-[10px] py-0 px-1.5">
                      {item.badge}
                    </Badge>
                  </Link>
                );
              })}
            </div>
          </div>
        </>
      )}
    </div>
  );
}

interface NotificationItem {
  id: string;
  title: string;
  body: string;
  read_at: string | null;
  created_at: string;
  action_url?: string | null;
}

function NotificationCenter() {
  const [open, setOpen] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);
  const [notifications, setNotifications] = useState<NotificationItem[]>([]);
  const [loading, setLoading] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  const fetchUnreadCount = useCallback(async () => {
    try {
      const res = await fetch('/api/notifications/unread-count', {
        headers: { Accept: 'application/json' },
      });
      if (res.ok) {
        const json = await res.json();
        setUnreadCount(json.count ?? 0);
      }
    } catch {
      // Keep existing
    }
  }, []);

  useEffect(() => {
    fetchUnreadCount();
  }, [fetchUnreadCount]);

  const fetchNotifications = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch('/api/notifications?per_page=10', {
        headers: { Accept: 'application/json' },
      });
      if (res.ok) {
        const json = await res.json();
        const items = Array.isArray(json) ? json : (json.data ?? []);
        const formatted: NotificationItem[] = items.map((item: any) => ({
          id: item.id,
          title: item.data?.title || item.title || 'System Notification',
          body: item.data?.message || item.data?.body || item.body || item.type || '',
          read_at: item.read_at ?? null,
          created_at: item.created_at ?? '',
          action_url: item.data?.action_url || item.url || null,
        }));
        setNotifications(formatted);
      }
    } catch {
      // Graceful fallback
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (open) {
      fetchNotifications();
    }
  }, [open, fetchNotifications]);

  const handleMarkAsRead = async (id: string, e?: React.MouseEvent) => {
    if (e) e.stopPropagation();
    try {
      await fetch(`/api/notifications/${id}/read`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
        },
      });
      setNotifications((prev) =>
        prev.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n))
      );
      setUnreadCount((prev) => Math.max(0, prev - 1));
    } catch {
      // Ignore
    }
  };

  const handleMarkAllAsRead = async () => {
    try {
      await fetch('/api/notifications/mark-all-read', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
        },
      });
      setNotifications((prev) =>
        prev.map((n) => ({ ...n, read_at: new Date().toISOString() }))
      );
      setUnreadCount(0);
    } catch {
      // Ignore
    }
  };

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  return (
    <div ref={ref} className="relative flex-shrink-0">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-label="Notifications"
        aria-expanded={open}
        aria-haspopup="dialog"
        className={cn(
          'relative w-[38px] h-[38px] rounded-xl flex items-center justify-center transition-all duration-150',
          'text-ovs-text-secondary hover:text-ovs-text-primary bg-transparent hover:bg-ovs-background border border-transparent',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-accent/30',
        )}
      >
        <Bell className="w-[17px] h-[17px]" strokeWidth={1.75} />
        {unreadCount > 0 && (
          <span
            aria-hidden="true"
            className="absolute top-2.5 right-2.5 w-2 h-2 rounded-full bg-ovs-accent ring-2 ring-white"
          />
        )}
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-40" aria-hidden="true" onClick={() => setOpen(false)} />
          <div
            role="dialog"
            aria-label="Notifications"
            className={cn(
              'absolute right-0 top-[calc(100%+8px)] z-50 w-72 overflow-hidden',
              POPOVER_SURFACE,
              'animate-in fade-in slide-in-from-top-1 duration-150',
            )}
          >
            <div className="flex items-center justify-between px-3.5 py-2.5 border-b border-ovs-border-subtle">
              <p className="text-xs font-bold text-ovs-text-primary font-display">Notifications</p>
              {unreadCount > 0 && (
                <button
                  type="button"
                  onClick={handleMarkAllAsRead}
                  className="text-[11px] font-semibold text-ovs-accent hover:underline transition-colors duration-150 flex items-center gap-1 font-sans"
                >
                  <CheckCheck className="w-3 h-3" /> Mark all read
                </button>
              )}
            </div>

            {loading ? (
              <div className="p-6 flex flex-col items-center justify-center text-center space-y-2">
                <Loader2 className="w-5 h-5 text-ovs-accent animate-spin" />
                <p className="text-xs text-ovs-text-tertiary">Loading notifications...</p>
              </div>
            ) : notifications.length === 0 ? (
              <div className="p-6 flex flex-col items-center justify-center text-center space-y-1.5">
                <Bell className="w-6 h-6 text-ovs-border-strong stroke-[1.5]" />
                <p className="text-xs font-bold text-ovs-text-secondary font-display">No notifications</p>
                <p className="text-[11px] text-ovs-text-tertiary">You're all caught up!</p>
              </div>
            ) : (
              <div className="divide-y divide-ovs-border-subtle max-h-72 overflow-y-auto">
                {notifications.map((item) => {
                  const isUnread = !item.read_at;
                  return (
                    <div
                      key={item.id}
                      onClick={() => {
                        if (isUnread) handleMarkAsRead(item.id);
                        if (item.action_url) {
                          setOpen(false);
                          window.location.href = item.action_url;
                        }
                      }}
                      className={cn(
                        'px-3.5 py-2.5 flex gap-2.5 hover:bg-ovs-background cursor-pointer transition-colors duration-150',
                        isUnread && 'bg-ovs-accent-light/40'
                      )}
                    >
                      <div className="flex-shrink-0 mt-1">
                        <span className={cn('block w-1.5 h-1.5 rounded-full', isUnread ? 'bg-ovs-accent' : 'bg-ovs-border-strong')} />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className={cn('text-xs leading-none truncate', isUnread ? 'font-bold text-ovs-text-primary font-display' : 'font-medium text-ovs-text-secondary')}>
                          {item.title}
                        </p>
                        {item.body && (
                          <p className="text-[11px] text-ovs-text-secondary font-normal mt-0.5 leading-tight truncate font-sans">{item.body}</p>
                        )}
                        {item.created_at && (
                          <p className="text-[10px] text-ovs-text-tertiary font-mono mt-1">
                            {new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                          </p>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            <div className="border-t border-ovs-border-subtle px-3.5 py-2">
              <Link
                href="/notifications"
                onClick={() => setOpen(false)}
                className="text-xs font-bold text-ovs-accent hover:underline transition-colors duration-150 font-sans"
              >
                View all notifications →
              </Link>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

interface ProfileMenuProps {
  name: string;
  role: string;
  email: string;
}

function ProfileMenu({ name, role, email }: ProfileMenuProps) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const initials = name.split(' ').map((n) => n[0]).slice(0, 2).join('').toUpperCase();

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, []);

  return (
    <div ref={ref} className="relative flex-shrink-0">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-label={`Account menu for ${name}`}
        aria-expanded={open}
        aria-haspopup="menu"
        className={cn(
          'flex items-center gap-2.5 h-[38px] px-2 rounded-xl transition-all duration-150 select-none',
          'hover:bg-ovs-background border border-transparent hover:border-ovs-border',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-accent/30',
        )}
      >
        <span
          aria-hidden="true"
          className="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 bg-gradient-to-br from-ovs-accent to-ovs-accent-hover text-white text-xs font-bold leading-none shadow-ovs-keyline font-display"
        >
          {initials}
        </span>

        <div className="hidden md:flex flex-col items-start text-left leading-none">
          <span className="text-xs font-bold text-ovs-text-primary truncate max-w-36 tracking-tight font-display">
            {name}
          </span>
          <span className="text-[10px] text-ovs-text-secondary font-normal truncate max-w-36 mt-0.5 font-sans">
            {role}
          </span>
        </div>

        <ChevronDown
          className={cn(
            'hidden md:block w-3.5 h-3.5 text-ovs-text-tertiary flex-shrink-0 transition-transform duration-150',
            open && 'rotate-180',
          )}
          strokeWidth={1.75}
        />
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-40" aria-hidden="true" onClick={() => setOpen(false)} />
          <div
            role="menu"
            className={cn(
              'absolute right-0 top-[calc(100%+8px)] z-50 w-56 p-1.5',
              POPOVER_SURFACE,
              'animate-in fade-in slide-in-from-top-1 duration-150',
            )}
          >
            <div className="px-3 py-2 mb-0.5">
              <p className="text-xs font-bold text-ovs-text-primary leading-tight truncate font-display">{name}</p>
              <p className="text-[11px] text-ovs-text-secondary font-medium leading-none truncate mt-0.5 font-sans">{role}</p>
              <p className="text-[10px] text-ovs-text-tertiary font-mono leading-none truncate mt-1">{email}</p>
            </div>

            <div className="h-px bg-ovs-border-subtle mx-1 mb-0.5" />

            {[
              { href: '/profile',               icon: User,     label: 'Profile & Security', tone: 'indigo' as IconChipTone },
            ].map((item) => {
              const Icon = item.icon;
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  role="menuitem"
                  onClick={() => setOpen(false)}
                  className="flex items-center gap-2.5 px-2.5 py-1.5 rounded-xl text-xs font-medium text-ovs-text-secondary hover:bg-ovs-background hover:text-ovs-text-primary transition-all duration-150 focus-visible:outline-none font-sans group"
                >
                  <IconChip
                    tone={item.tone}
                    size="xs"
                    icon={<Icon className="w-3 h-3" strokeWidth={2} />}
                  />
                  <span className="flex-1 truncate text-xs font-semibold text-ovs-text-primary group-hover:text-ovs-text-primary">{item.label}</span>
                </Link>
              );
            })}

            <div className="h-px bg-ovs-border-subtle mx-1 mt-0.5 mb-0.5" />

            <Link
              href="/company/logout"
              method="post"
              as="button"
              role="menuitem"
              onClick={() => setOpen(false)}
              className="w-full flex items-center gap-2.5 px-2.5 py-1.5 rounded-xl text-xs font-bold text-ovs-semantic-danger-text hover:bg-ovs-semantic-danger-bg transition-all duration-150 focus-visible:outline-none font-sans group"
            >
              <IconChip
                tone="rose"
                size="xs"
                icon={<LogOut className="w-3 h-3" strokeWidth={2} />}
              />
              <span className="flex-1 truncate text-xs text-left">Sign out</span>
            </Link>
          </div>
        </>
      )}
    </div>
  );
}

export function ExecutiveHeader({ onToggleMobileDrawer, className }: ExecutiveHeaderProps) {
  const { auth } = usePage() as any;
  const user = {
    name:  auth?.user?.name  || 'Harsh Contractor',
    role:  auth?.user?.role  || 'Master Admin',
    email: auth?.user?.email || 'admin@oralcares.in',
  };

  return (
    <div className="sticky top-0 z-30 pt-3 px-4 sm:px-6 bg-transparent flex-shrink-0">
      <header
        role="banner"
        className={cn(
          'w-full h-[72px] px-4 sm:px-6',
          'bg-white border border-ovs-border-subtle ring-1 ring-ovs-primary/[0.04] rounded-2xl shadow-ovs-elevated',
          'flex items-center justify-between gap-4 sm:gap-6',
          'font-sans antialiased select-none transition-all duration-150',
          className
        )}
      >
        {/* LEFT SECTION: MOBILE DRAWER TOGGLE & GLOBAL SEARCH ENTRY POINT */}
        <div className="flex items-center gap-2.5 flex-1 min-w-0">
          {onToggleMobileDrawer && (
            <button
              type="button"
              onClick={onToggleMobileDrawer}
              aria-label="Open navigation"
              className={cn(
                'lg:hidden flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center',
                'text-ovs-text-secondary hover:text-ovs-text-primary hover:bg-ovs-background',
                'transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-accent/30',
              )}
            >
              <Menu className="w-4 h-4" strokeWidth={1.75} />
            </button>
          )}

          {/* CANONICAL GLOBAL COMMAND PALETTE INSTANTIATION */}
          <div className="flex-1 w-full max-w-[520px]">
            <CommandPalette variant="inline" />
          </div>
        </div>

        {/* RIGHT UTILITY GROUP: QUICK CREATE, NOTIFICATIONS, PROFILE MENU */}
        <div className="flex items-center gap-2 flex-shrink-0">
          <QuickCreateMenu />
          <div className="w-px h-5 bg-ovs-border flex-shrink-0 hidden sm:block" aria-hidden="true" />
          <div className="flex items-center gap-1">
            <NotificationCenter />
            <ProfileMenu name={user.name} role={user.role} email={user.email} />
          </div>
        </div>
      </header>
    </div>
  );
}

// Canonical re-export
export { ExecutiveHeader as OvsHeader };
