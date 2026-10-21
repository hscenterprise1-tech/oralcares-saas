import { useCallback, useEffect, useState } from 'react';
import { CheckCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useClinicShared } from '@/clinic/context';
import { ERROR_COPY, errorKind, requestJson } from '@/clinic/http';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { NOTIFICATIONS_CHANGED } from '@/clinic/notifications';
import type { NotificationItem, Paginated } from '@/clinic/types';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '@/clinic/ui/states';

const PER_PAGE = 20;

type Filter = 'all' | 'unread';

/**
 * The member's notifications at this clinic, all of them: the bell's feed
 * with its history, an unread filter, and marking read. The shared API binds
 * every call to the clinic of this session.
 */
export default function Notifications({ links }: { links: { read: string } }) {
  const { portalLinks } = useClinicShared();
  const [filter, setFilter] = useState<Filter>('all');
  const [items, setItems] = useState<NotificationItem[] | null>(null);
  const [page, setPage] = useState<{ current: number; last: number } | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const load = useCallback(
    async (pageNumber: number) => {
      if (!portalLinks) {
        return;
      }
      setFailure(null);
      try {
        const result = await requestJson<Paginated<NotificationItem>>('GET', portalLinks.notifications, {
          query: { per_page: PER_PAGE, page: pageNumber, ...(filter === 'unread' ? { is_read: 0 } : {}) },
        });
        setItems((current) => (pageNumber > 1 && current ? [...current, ...result.data] : result.data));
        setPage({ current: result.current_page, last: result.last_page });
      } catch (error) {
        setFailure(ERROR_COPY[errorKind(error)]);
      }
    },
    [portalLinks, filter],
  );

  useEffect(() => {
    setItems(null);
    void load(1);
  }, [load]);

  const changed = () => window.dispatchEvent(new Event(NOTIFICATIONS_CHANGED));

  const markRead = async (item: NotificationItem) => {
    setActionError(null);
    try {
      await requestJson('PATCH', links.read.replace('__ID__', encodeURIComponent(item.id)));
      setItems((current) =>
        filter === 'unread'
          ? (current?.filter((entry) => entry.id !== item.id) ?? null)
          : (current?.map((entry) => (entry.id === item.id ? { ...entry, read_at: new Date().toISOString() } : entry)) ?? null),
      );
      changed();
    } catch (error) {
      setActionError(ERROR_COPY[errorKind(error)]);
    }
  };

  const markAllRead = async () => {
    if (!portalLinks) {
      return;
    }
    setActionError(null);
    try {
      await requestJson('POST', portalLinks.notificationsMarkAllRead);
      setItems((current) => (filter === 'unread' ? [] : (current?.map((entry) => ({ ...entry, read_at: entry.read_at ?? new Date().toISOString() })) ?? null)));
      changed();
    } catch (error) {
      setActionError(ERROR_COPY[errorKind(error)]);
    }
  };

  const loadMore = async () => {
    if (!page) {
      return;
    }
    setLoadingMore(true);
    await load(page.current + 1);
    setLoadingMore(false);
  };

  const hasUnread = items?.some((item) => !item.read_at) ?? false;

  return (
    <ClinicPortalShell title="Notifications">
      <PageHeader
        title="Notifications"
        description="Updates for you at this clinic."
        actions={
          <Button size="sm" icon={CheckCheck} onClick={() => void markAllRead()} disabled={!hasUnread}>
            Mark all read
          </Button>
        }
      />

      <div role="group" aria-label="Show" className="mb-4 flex gap-2">
        {(['all', 'unread'] as const).map((key) => (
          <button
            key={key}
            type="button"
            aria-pressed={filter === key}
            onClick={() => setFilter(key)}
            className={cn(
              'h-9 rounded-md px-3.5 text-[13px] font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal',
              filter === key ? 'bg-cx-nav-ink text-white' : 'border border-cx-nav-line bg-white text-cx-nav-ink hover:bg-cx-nav-bg',
            )}
          >
            {key === 'all' ? 'All' : 'Unread'}
          </button>
        ))}
      </div>

      {actionError && (
        <p role="alert" className="mb-3 rounded-md bg-cx-rose-bg px-3 py-2 text-[12.5px] font-medium text-cx-rose-ink">
          {actionError}
        </p>
      )}

      <Card>
        {failure ? (
          <ErrorState description={failure} onRetry={() => void load(1)} />
        ) : items === null ? (
          <LoadingState label="Loading notifications" rows={4} />
        ) : items.length === 0 ? (
          <EmptyState title={filter === 'unread' ? 'No unread notifications' : "You're all caught up"} description={filter === 'unread' ? 'Everything here has been read.' : 'New updates for you will appear here.'} />
        ) : (
          <ul className="divide-y divide-cx-line">
            {items.map((item) => {
              const title = item.title ?? 'Notification';
              const message = item.data?.message ?? item.data?.body ?? null;

              return (
                <li key={item.id} className="flex items-start gap-3 px-5 py-4">
                  <span className={cn('mt-1.5 h-2 w-2 shrink-0 rounded-full', item.read_at ? 'bg-transparent' : 'bg-cx-nav-teal')} aria-hidden="true" />
                  <div className="min-w-0 flex-1">
                    <p className={cn('break-words text-[14px] text-cx-nav-ink', !item.read_at && 'font-semibold')}>
                      {!item.read_at && <span className="sr-only">Unread: </span>}
                      {title}
                    </p>
                    {message && message !== title && <p className="mt-0.5 break-words text-[13px] text-cx-nav-muted">{message}</p>}
                    <p className="mt-1 text-[12px] text-cx-nav-muted">{new Date(item.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}</p>
                  </div>
                  {!item.read_at && (
                    <Button size="sm" tone="ghost" onClick={() => void markRead(item)}>
                      Mark read
                    </Button>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </Card>

      {items && page && page.current < page.last && !failure && (
        <div className="mt-4 flex justify-center">
          <Button size="sm" loading={loadingMore} onClick={() => void loadMore()}>
            Show older
          </Button>
        </div>
      )}
    </ClinicPortalShell>
  );
}
