import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import * as Popover from '@radix-ui/react-popover';
import { Bell } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useNavigation } from '../context';
import { NOTIFICATIONS_CHANGED } from '../notifications';
import { ERROR_COPY, errorKind, requestJson } from '../http';
import type { PortalLinks } from '../types';
import { Spinner } from '../ui/Spinner';

interface ClinicNotification {
  id: string;
  title: string | null;
  data?: { title?: string; message?: string } | null;
  read_at: string | null;
  created_at: string;
}

/** How often the bell re-reads its unread count while the tab is in view. */
const UNREAD_POLL_MS = 60_000;

/**
 * The signed-in user's notifications at this clinic (the server scopes them
 * to the active clinic): an unread count on the bell, kept current every
 * minute while the tab is in view, the latest few when it opens, "mark all
 * read", and the way to all of them.
 */
export function NotificationsMenu({ links }: { links: PortalLinks }) {
  const [unread, setUnread] = useState<number | null>(null);
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<ClinicNotification[] | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const page = useNavigation().find((item) => item.key === 'notifications' && item.available && item.href !== null);

  // Set when the session has ended or access was refused: the bell stops asking.
  const stopped = useRef(false);
  const inFlight = useRef(false);

  const refreshCount = useCallback(() => {
    if (stopped.current || inFlight.current) {
      return;
    }
    inFlight.current = true;
    requestJson<{ count: number }>('GET', links.notificationsUnreadCount)
      .then((result) => setUnread(result.count))
      .catch((error) => {
        const kind = errorKind(error);
        if (kind === 'unauthenticated' || kind === 'session_expired' || kind === 'forbidden') {
          stopped.current = true;
        }
        setUnread(null);
      })
      .finally(() => {
        inFlight.current = false;
      });
  }, [links.notificationsUnreadCount]);

  useEffect(refreshCount, [refreshCount]);

  // Every minute while the tab is in view; at once when it comes back into view.
  useEffect(() => {
    const timer = window.setInterval(() => {
      if (!document.hidden) {
        refreshCount();
      }
    }, UNREAD_POLL_MS);
    const onVisibility = () => {
      if (!document.hidden) {
        refreshCount();
      }
    };
    document.addEventListener('visibilitychange', onVisibility);

    return () => {
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, [refreshCount]);

  useEffect(() => {
    window.addEventListener(NOTIFICATIONS_CHANGED, refreshCount);

    return () => window.removeEventListener(NOTIFICATIONS_CHANGED, refreshCount);
  }, [refreshCount]);

  useEffect(() => {
    if (!open) {
      return;
    }
    setFailure(null);
    requestJson<{ data: ClinicNotification[] }>('GET', links.notifications, { query: { per_page: 8 } })
      .then((page) => setItems(page.data))
      .catch((error) => setFailure(ERROR_COPY[errorKind(error)]));
  }, [open, links.notifications]);

  const markAllRead = () => {
    requestJson('POST', links.notificationsMarkAllRead)
      .then(() => {
        setUnread(0);
        setItems((current) => current?.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })) ?? null);
      })
      .catch((error) => setFailure(ERROR_COPY[errorKind(error)]));
  };

  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      <Popover.Trigger
        aria-label={unread ? `Notifications, ${unread} unread` : 'Notifications'}
        className="relative flex h-11 w-11 items-center justify-center rounded-full text-cx-nav-muted hover:bg-cx-nav-bg hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
      >
        <Bell className="h-[18px] w-[18px]" strokeWidth={1.75} aria-hidden="true" />
        {unread ? (
          <span className="absolute right-1 top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-cx-rose-ink px-1 text-[10.5px] font-bold text-white">
            {unread > 9 ? '9+' : unread}
          </span>
        ) : null}
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content
          align="end"
          sideOffset={8}
          collisionPadding={16}
          className="z-50 w-[min(340px,calc(100vw-32px))] rounded-2xl border border-cx-nav-line bg-white shadow-cx-raised focus:outline-none"
        >
          <div className="flex items-center justify-between border-b border-cx-nav-line px-4 py-3">
            <p className="text-[13.5px] font-semibold text-cx-nav-ink">Notifications</p>
            {Boolean(unread) && (
              <button type="button" onClick={markAllRead} className="rounded-md px-2 py-1 text-[12px] font-semibold text-cx-nav-teal-strong hover:bg-cx-nav-bg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal">
                Mark all read
              </button>
            )}
          </div>
          <div className="max-h-[360px] overflow-y-auto p-1.5" aria-live="polite">
            {failure && <p className="px-3 py-4 text-[12.5px] text-cx-rose-ink">{failure}</p>}
            {!failure && items === null && (
              <p className="flex items-center gap-2 px-3 py-4 text-[12.5px] text-cx-nav-muted">
                <Spinner /> Loading…
              </p>
            )}
            {!failure && items?.length === 0 && <p className="px-3 py-6 text-center text-[12.5px] text-cx-nav-muted">You're all caught up.</p>}
            {items?.map((item) => (
              <div key={item.id} className="flex gap-2.5 rounded-md px-3 py-2.5">
                <span className={cn('mt-1.5 h-2 w-2 shrink-0 rounded-full', item.read_at ? 'bg-transparent' : 'bg-cx-nav-teal')} aria-hidden="true" />
                <span className="min-w-0">
                  <span className="block text-[13px] font-medium text-cx-nav-ink">{item.title ?? item.data?.title ?? 'Notification'}</span>
                  {item.data?.message && <span className="mt-0.5 block text-[12px] text-cx-nav-muted">{item.data.message}</span>}
                  <span className="mt-0.5 block text-[11.5px] text-cx-nav-muted">{new Date(item.created_at).toLocaleString()}</span>
                </span>
              </div>
            ))}
          </div>
          {page?.href && (
            <div className="border-t border-cx-nav-line p-1.5">
              <Link
                href={page.href}
                onClick={() => setOpen(false)}
                className="flex h-10 items-center justify-center rounded-md text-[13px] font-semibold text-cx-nav-ink hover:bg-cx-nav-bg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
              >
                View all notifications
              </Link>
            </div>
          )}
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}
