import React from 'react';
import { Head, router } from '@inertiajs/react';
import { AlertCircle, Bell, Inbox, Send, Settings2 } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmHairlineBar,
  CrmDataState,
} from '@/pages/Company/design';
import { OperationsShell } from './components/OperationsShell';

/**
 * Operations → Notifications
 *
 * Explicitly not a second notification system. The existing module already has
 * channels, enums, jobs, a listener, an engine, preferences and an inbox; none
 * of it is duplicated or replaced here. What was missing is the operational
 * view — the inbox answers "what did I get", and nothing answered "what has
 * this company been sending, and what failed".
 *
 * Read-only. A delivery outcome is a record of what happened.
 */

const HUE = { yellow: '#E0B21E', green: '#2FA96B', coral: '#EF5B39', blue: '#2F6BE4' } as const;

interface Props {
  notifications: Array<{
    id: string | number;
    title: string | null;
    type: string | null;
    category: string | null;
    priority: string | null;
    read: boolean;
    archived: boolean;
    at: string | null;
  }>;
  logs: Array<{ id: number; channel: string | null; status: string | null; at: string | null }>;
  filters: Record<string, string | undefined>;
  summary: {
    total: number;
    unread: number;
    archived: number;
    delivered: number;
    failed: number;
    log_total: number;
  };
  byCategory: Record<string, number>;
  byChannel: Record<string, number>;
  inboxUrl: string;
  preferencesUrl: string;
}

const STATUS_TONE: Record<string, 'good' | 'bad' | 'warn' | 'neutral'> = {
  sent: 'good',
  delivered: 'good',
  failed: 'bad',
  pending: 'warn',
  queued: 'warn',
};

export default function NotificationsWorkspace({
  notifications,
  logs,
  filters,
  summary,
  byCategory,
  byChannel,
  inboxUrl,
  preferencesUrl,
}: Props) {
  const apply = (changes: Record<string, string | undefined>) =>
    router.get(
      '/company/notifications-ops',
      { ...filters, ...changes },
      { preserveState: true, preserveScroll: true, replace: true },
    );

  const categoryMax = Math.max(...Object.values(byCategory ?? {}), 1);
  const channelMax = Math.max(...Object.values(byChannel ?? {}), 1);

  return (
    <OperationsShell title="Notifications">
      <Head title="Notifications | Customer Operations" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Customer Operations
          </p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Notifications
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            What the system has been sending, and whether it arrived
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={Bell} value={summary.total} caption="Notifications" />
          <CrmPageStat icon={Send} value={summary.delivered} caption="Delivered" />
          <CrmPageStat icon={AlertCircle} value={summary.failed} caption="Failed" />
          <CrmPageStat icon={Inbox} value={summary.unread} caption="Unread" />
        </div>
      </div>

      <CrmCard className="mb-4 flex flex-wrap items-center justify-between gap-4 px-5 py-4">
        <div className="flex items-start gap-3">
          <CrmCardIcon icon={Settings2} hue={HUE.blue} />
          <div>
            <p className="text-[13px] font-semibold text-ovs-crm-text-primary">One notification system, two views</p>
            {/* Nothing here duplicates the existing module — it reads it. */}
            <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
              This is the operational side. Your own inbox and delivery preferences live where they always have.
            </p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <CrmButton size="sm" href={inboxUrl}>
            My inbox
          </CrmButton>
          <CrmButton size="sm" href={preferencesUrl}>
            Preferences
          </CrmButton>
        </div>
      </CrmCard>

      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <CrmCard className="p-6">
          <h2 className="mb-4 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">By category</h2>

          {Object.keys(byCategory ?? {}).length === 0 ? (
            <p className="py-6 text-center text-[13px] text-ovs-crm-text-muted">Nothing sent yet.</p>
          ) : (
            <ul className="space-y-2.5">
              {Object.entries(byCategory).map(([category, total]) => (
                <li key={category} className="flex items-center gap-3">
                  <span className="w-[130px] shrink-0 truncate text-[12.5px] text-ovs-crm-text-secondary">
                    {category || 'uncategorised'}
                  </span>
                  <CrmHairlineBar value={total} max={categoryMax} color={HUE.yellow} className="flex-1" />
                  <span className="w-[46px] shrink-0 text-right text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                    {total}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CrmCard>

        <CrmCard className="p-6">
          <h2 className="mb-4 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">By channel</h2>

          {Object.keys(byChannel ?? {}).length === 0 ? (
            <p className="py-6 text-center text-[13px] text-ovs-crm-text-muted">
              No delivery attempts recorded yet.
            </p>
          ) : (
            <ul className="space-y-2.5">
              {Object.entries(byChannel).map(([channel, total]) => (
                <li key={channel} className="flex items-center gap-3">
                  <span className="w-[130px] shrink-0 truncate text-[12.5px] text-ovs-crm-text-secondary">
                    {channel || 'unknown'}
                  </span>
                  <CrmHairlineBar value={total} max={channelMax} color={HUE.green} className="flex-1" />
                  <span className="w-[46px] shrink-0 text-right text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                    {total}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CrmCard>
      </div>

      <CrmCard className="mb-3 flex flex-wrap items-end gap-3 px-5 py-3.5">
        <div className="min-w-[200px] flex-1">
          <CrmFormField label="Search">
            <CrmInput
              type="search"
              placeholder="Title or type"
              defaultValue={filters.search ?? ''}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  apply({ search: (e.target as HTMLInputElement).value || undefined });
                }
              }}
            />
          </CrmFormField>
        </div>

        <div className="min-w-[170px]">
          <CrmFormField label="Category">
            <CrmSelect value={filters.category ?? ''} onChange={(e) => apply({ category: e.target.value || undefined })}>
              <option value="">Any category</option>
              {Object.keys(byCategory ?? {}).map((category) => (
                <option key={category} value={category}>
                  {category}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </div>

        <div className="min-w-[150px]">
          <CrmFormField label="Delivery">
            <CrmSelect value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })}>
              <option value="">Any outcome</option>
              <option value="sent">Sent</option>
              <option value="delivered">Delivered</option>
              <option value="failed">Failed</option>
            </CrmSelect>
          </CrmFormField>
        </div>
      </CrmCard>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div>
          {notifications.length === 0 ? (
            <CrmDataState
              kind="empty"
              icon={Bell}
              title="Nothing sent yet"
              description="Notifications appear here as the system raises them across the company."
            />
          ) : (
            <div className="space-y-2">
              {notifications.map((notification) => (
                <CrmCard key={notification.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
                  <span className="min-w-[200px] flex-1">
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="text-[13px] font-medium text-ovs-crm-text-primary">
                        {notification.title ?? notification.type}
                      </span>
                      {!notification.read && <CrmStatusPill tone="warn">Unread</CrmStatusPill>}
                      {notification.archived && <CrmStatusPill tone="neutral">Archived</CrmStatusPill>}
                    </span>
                    <span className="mt-0.5 block text-[11.5px] text-ovs-crm-text-muted">
                      {notification.category ?? 'uncategorised'}
                      {notification.priority && <> · {notification.priority}</>}
                    </span>
                  </span>

                  <span className="min-w-[120px] text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                    {notification.at}
                  </span>
                </CrmCard>
              ))}
            </div>
          )}
        </div>

        <CrmCard className="p-6">
          <h2 className="mb-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">Delivery log</h2>
          <p className="mb-4 text-[11.5px] text-ovs-crm-text-muted">
            {summary.log_total} attempt{summary.log_total === 1 ? '' : 's'} recorded
          </p>

          {logs.length === 0 ? (
            <p className="py-6 text-center text-[12.5px] text-ovs-crm-text-muted">No attempts recorded.</p>
          ) : (
            <ul className="space-y-2">
              {logs.slice(0, 12).map((log) => (
                <li key={log.id} className="flex flex-wrap items-center justify-between gap-2">
                  <span className="text-[12px] text-ovs-crm-text-secondary">{log.channel ?? 'unknown'}</span>
                  <span className="flex items-center gap-2">
                    <CrmStatusPill tone={STATUS_TONE[String(log.status)] ?? 'neutral'}>
                      {log.status ?? 'unknown'}
                    </CrmStatusPill>
                    <span className="text-[11px] tabular-nums text-ovs-crm-text-muted">{log.at}</span>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CrmCard>
      </div>
    </OperationsShell>
  );
}
