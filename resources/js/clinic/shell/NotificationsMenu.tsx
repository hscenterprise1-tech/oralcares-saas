import { useCallback, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import * as Popover from '@radix-ui/react-popover';
import { Bell, CheckCheck, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '../http';
import type { NotificationItem, Paginated, PortalLinks } from '../types';
import { LoadingPanel, StatePanel } from '../ui/StatePanel';

const PAGE_SIZE = 10;
/** The unread count is re-read when the tab regains focus, at most this often. */
const COUNT_REFRESH_MS = 60_000;

const relativeTime = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

export function timeAgo(iso: string, now: number = Date.now()): string {
  const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
  const steps: [Intl.RelativeTimeFormatUnit, number][] = [
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
  ];
  for (const [unit, size] of steps) {
    if (Math.abs(seconds) >= size) {
      return relativeTime.format(Math.round(seconds / size), unit);
    }
  }

  return 'just now';
}

function humanize(value: string | null): string | null {
  return value ? value.replace(/[_-]+/g, ' ').replace(/^\w/, (first) => first.toUpperCase()) : null;
}

/** Only a path inside this site is followed; anything else stays put. */
function safeInternalPath(url: string | undefined): string | null {
  return url && url.startsWith('/') && !url.startsWith('//') ? url : null;
}

/**
 * The bell: an unread count read once per page (and on refocus, at most once
 * a minute), and the latest notifications fetched only when opened. The
 * server limits both to the clinic this session works in.
 */
export function NotificationsMenu({ links }: { links: PortalLinks }) {
  const [open, setOpen] = useState(false);
  const [unread, setUnread] = useState<number | null>(null);
  const [items, setItems] = useState<NotificationItem[] | null>(null);
  const [failure, setFailure] = useState<ClinicHttpErrorKind | null>(null);
  const [loading, setLoading] = useState(false);
  const lastCountAt = useRef(0);

  const loadCount = useCallback(async () => {
    lastCountAt.current = Date.now();
    try {
      const result = await requestJson<{ count: number }>('GET', links.notificationsUnreadCount);
      setUnread(result.count);
    } catch {
      // The badge is a hint; leaving it as it was is the right failure.
    }
  }, [links.notificationsUnreadCount]);

  const loadList = useCallback(async () => {
    setLoading(true);
    setFailure(null);
    try {
      const result = await requestJson<Paginated<NotificationItem>>('GET', links.notifications, {
        query: { per_page: PAGE_SIZE },
      });
      setItems(result.data);
    } catch (error) {
      setFailure(errorKind(error));
    } finally {
      setLoading(false);
    }
  }, [links.notifications]);

  useEffect(() => {
    void loadCount();
    const onFocus = () => {
      if (Date.now() - lastCountAt.current > COUNT_REFRESH_MS) {
        void loadCount();
      }
    };
    window.addEventListener('focus', onFocus);

    return () => window.removeEventListener('focus', onFocus);
  }, [loadCount]);

  const onOpenChange = (next: boolean) => {
    setOpen(next);
    if (next) {
      void loadList();
    }
  };

  const markRead = async (item: NotificationItem) => {
    const target = safeInternalPath(item.data?.url);
    if (!item.read_at) {
      setItems(
        (current) =>
          current?.map((entry) => (entry.id === item.id ? { ...entry, read_at: new Date().toISOString() } : entry)) ??
          null,
      );
      setUnread((count) => (count === null ? count : Math.max(0, count - 1)));
      try {
        await requestJson('PATCH', `${links.notifications}/${encodeURIComponent(item.id)}/read`);
      } catch {
        void loadCount();
      }
    }
    if (target) {
      setOpen(false);
      router.visit(target);
    }
  };

  const markAllRead = async () => {
    try {
      await requestJson('POST', links.notificationsMarkAllRead);
      setItems(
        (current) =>
          current?.map((entry) => ({ ...entry, read_at: entry.read_at ?? new Date().toISOString() })) ?? null,
      );
      setUnread(0);
    } catch (error) {
      setFailure(errorKind(error));
    }
  };

  const badge = unread && unread > 0 ? (unread > 99 ? '99+' : String(unread)) : null;

  return (
    <Popover.Root open={open} onOpenChange={onOpenChange}>
      <Popover.Trigger
        aria-label={badge ? `Notifications, ${unread} unread` : 'Notifications'}
        className="relative flex h-10 w-10 items-center justify-center rounded-full text-clinic-secondary [@media(pointer:coarse)]:h-11 [@media(pointer:coarse)]:w-11 hover:bg-clinic-inset hover:text-clinic-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong data-[state=open]:bg-clinic-inset data-[state=open]:text-clinic-ink"
      >
        <Bell className="h-[18px] w-[18px]" aria-hidden="true" />
        {badge && (
          <span
            aria-hidden="true"
            className="absolute right-0.5 top-0.5 min-w-[18px] rounded-full bg-clinic-danger px-1 text-center text-[10px] font-bold leading-[18px] text-white ring-2 ring-clinic-surface"
          >
            {badge}
          </span>
        )}
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content
          align="end"
          sideOffset={8}
          collisionPadding={16}
          aria-label="Notifications"
          className="z-50 flex max-h-[min(520px,calc(100vh-96px))] w-[min(380px,calc(100vw-32px))] flex-col rounded-2xl border border-clinic-border bg-clinic-surface shadow-[0_16px_40px_-12px_rgba(16,24,40,0.25)] focus:outline-none"
        >
          <div className="flex items-center justify-between gap-2 border-b border-clinic-border px-4 py-3">
            <p className="text-[15px] font-bold text-clinic-ink">Notifications</p>
            {items && items.some((item) => !item.read_at) && (
              <button
                type="button"
                onClick={() => void markAllRead()}
                className="inline-flex h-8 items-center gap-1.5 rounded-full px-2.5 text-[12px] font-semibold text-clinic-brand-strong hover:bg-clinic-brand-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
              >
                <CheckCheck className="h-3.5 w-3.5" aria-hidden="true" /> Mark all read
              </button>
            )}
          </div>

          <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
            {loading && !items && <LoadingPanel label="Loading notifications…" rows={4} />}
            {failure && (
              <StatePanel
                compact
                kind={failure === 'network' ? 'offline' : 'error'}
                title="Notifications could not load"
                description={ERROR_COPY[failure]}
                action={
                  <button
                    type="button"
                    onClick={() => void loadList()}
                    className="inline-flex h-9 items-center gap-1.5 rounded-full border border-clinic-border px-3.5 text-[12.5px] font-semibold text-clinic-ink hover:bg-clinic-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
                  >
                    <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" /> Try again
                  </button>
                }
              />
            )}
            {!failure && items && items.length === 0 && (
              <StatePanel
                compact
                kind="empty"
                title="You are all caught up"
                description="New notifications for this clinic will appear here."
              />
            )}
            {!failure && items && items.length > 0 && (
              <ul className="divide-y divide-clinic-border">
                {items.map((item) => {
                  const body = item.data?.message ?? item.data?.body ?? null;
                  const category = humanize(item.category);

                  return (
                    <li key={item.id}>
                      <button
                        type="button"
                        onClick={() => void markRead(item)}
                        className={cn(
                          'flex w-full gap-3 px-4 py-3 text-left hover:bg-clinic-inset focus-visible:bg-clinic-inset focus-visible:outline-none',
                          !item.read_at && 'bg-clinic-brand-soft/40',
                        )}
                      >
                        <span
                          aria-hidden="true"
                          className={cn(
                            'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                            item.read_at ? 'bg-transparent' : 'bg-clinic-brand-strong',
                          )}
                        />
                        <span className="min-w-0 flex-1">
                          <span className="sr-only">{item.read_at ? 'Read: ' : 'Unread: '}</span>
                          <span className={cn('block text-[13px] text-clinic-ink', !item.read_at && 'font-semibold')}>
                            {item.title ?? 'Notification'}
                          </span>
                          {body && (
                            <span className="mt-0.5 line-clamp-2 block text-[12.5px] leading-snug text-clinic-secondary">
                              {body}
                            </span>
                          )}
                          <span className="mt-1 flex flex-wrap items-center gap-x-2 text-[11.5px] text-clinic-secondary">
                            {category && <span className="font-semibold uppercase tracking-[0.06em]">{category}</span>}
                            <time dateTime={item.created_at}>{timeAgo(item.created_at)}</time>
                          </span>
                        </span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}
