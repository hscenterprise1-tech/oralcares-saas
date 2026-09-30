import React from 'react';
import { Head, router } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowUpRight,
  Cable,
  Inbox,
  MessageSquare,
  Plug,
  Send,
  ShieldCheck,
  ShieldAlert,
} from 'lucide-react';
import {
  AdministrationShell,
  CrmCard,
  CrmCardIcon,
  CrmPageStat,
  CrmSparkline,
  CrmStatusPill,
  CrmValue,
  CrmButton,
  type CrmStatusTone,
} from '@/pages/Company/design';

/**
 * Company → Integrations
 *
 * Read-only. Numbers are connected and disconnected in Marketing settings,
 * which owns that flow; this says whether what was connected is still working.
 *
 * Nothing on the page retries, replays or re-sends. The send path already owns
 * that, and a second one would drift from it.
 */

const HUE = {
  green: '#2FA96B',
  blue: '#2F6BE4',
  violet: '#7C6FE0',
  orange: '#F59245',
  coral: '#EF5B39',
  slate: '#64748B',
} as const;

/* The rail is Administration's, from COMPANY_MODULES. It used to point at Marketing. */

interface Connection {
  id: number;
  name: string;
  provider: string;
  purpose: string | null;
  number: string | null;
  verified_name: string | null;
  status: string;
  connection_status: string;
  last_connected_at: string | null;
  last_webhook_at: string | null;
  has_access_token: boolean;
  has_verify_token: boolean;
  quiet: boolean;
  hours_silent: number | null;
}

interface Props {
  connections: Connection[];
  inbound: {
    days: number;
    total: number;
    by_status: Record<string, number>;
    by_signature: Record<string, number>;
    signature_enforced: boolean;
    unverified: number;
    failures: Array<{
      id: number;
      event_type: string;
      provider: string;
      error: string | null;
      signature: string | null;
      account_id: number | null;
      at: string | null;
    }>;
    recent: Array<{
      id: number;
      event_type: string;
      provider: string;
      status: string;
      signature: string | null;
      account_id: number | null;
      at: string | null;
    }>;
    daily: Array<{ date: string; count: number }>;
  };
  outbound: {
    days: number;
    total: number;
    by_status: Record<string, number>;
    failures: Array<{
      id: number;
      account_id: number | null;
      type: string | null;
      template: string | null;
      reason: string | null;
      at: string | null;
    }>;
  };
  unavailable: Record<string, { rows: number; scope: string }>;
  filters: { days: number };
  can: { manageMarketingSettings: boolean };
}

/**
 * A connection is described by two columns that disagree in practice —
 * `status` reading `connected` while `connection_status` reads `inactive` is
 * the state both live numbers are in right now. Neither column is picked as
 * the winner: where they differ the label says both, because a single word
 * would be choosing which one to believe.
 */
function connectionState(connection: Connection): { tone: CrmStatusTone; label: string } {
  if (connection.status === 'error') {
    return { tone: 'bad', label: 'Error' };
  }

  if (connection.status !== 'connected' || connection.connection_status !== 'active') {
    return { tone: 'warn', label: `${connection.status} · ${connection.connection_status}` };
  }

  return connection.quiet
    ? { tone: 'warn', label: 'Quiet' }
    : { tone: 'good', label: 'Receiving' };
}

function signatureTone(result: string | null): CrmStatusTone {
  if (result === 'verified') return 'good';
  if (result === 'invalid' || result === 'missing_signature') return 'bad';

  return 'neutral';
}

const SIGNATURE_LABEL: Record<string, string> = {
  verified: 'Signed by Meta',
  invalid: 'Bad signature',
  missing_signature: 'No signature sent',
  unconfigured: 'Not checked',
  unrecorded: 'Arrived unchecked',
};

export default function IntegrationsWorkspace({
  connections,
  inbound,
  outbound,
  unavailable,
  filters,
  can,
}: Props) {
  const [tab, setTab] = React.useState<'connections' | 'inbound' | 'outbound'>('connections');

  const live = connections.filter((c) => c.status === 'connected' && c.connection_status === 'active').length;
  const failed = inbound.by_status.failed ?? 0;
  const outboundFailed = outbound.by_status.failed ?? 0;

  const setDays = (days: number) =>
    router.get('/company/integrations', { days }, { preserveState: true, replace: true });

  return (
    <AdministrationShell title="Integrations">
      <Head title="Integrations | Company" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        {/*
          Not `shrink-0`: at 375 that holds the block at the subtitle's full
          width and the sentence is clipped mid-word by the shell's overflow.
        */}
        <div className="min-w-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Company</p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Integrations
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            What this company is connected to, and whether it is still working
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={Cable} value={`${live}/${connections.length}`} caption="Connections live" />
          <CrmPageStat icon={Inbox} value={inbound.total} caption={`Events · ${inbound.days}d`} />
          <CrmPageStat icon={AlertTriangle} value={failed} caption="Events failed" />
          <CrmPageStat icon={Send} value={outboundFailed} caption="Sends failed" />
        </div>
      </div>

      {/*
        The one thing on this page an operator can act on today. Signature
        verification is off until an app secret is set, and every event that
        arrives meanwhile is accepted from anyone who can reach the endpoint.
      */}
      {! inbound.signature_enforced && (
        <CrmCard className="mb-4 flex flex-wrap items-start gap-3 px-5 py-4" frostHue={HUE.orange}>
          <CrmCardIcon icon={ShieldAlert} hue={HUE.orange} />
          <div className="min-w-0 flex-1">
            <p className="text-[13px] font-semibold text-ovs-crm-text-primary">
              Inbound webhooks are accepted without proving they came from Meta
            </p>
            <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
              No app secret is configured, so the signature on each request is not checked. Set{' '}
              <code className="rounded bg-ovs-crm-surface-inset px-1 py-0.5 text-[11px]">META_WHATSAPP_APP_SECRET</code>{' '}
              to the value from the Meta app dashboard and every request that is not signed with it will be refused.
              {inbound.unverified > 0 && (
                <> {inbound.unverified} of the last {inbound.days} days of events arrived unchecked.</>
              )}
            </p>
          </div>
        </CrmCard>
      )}

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div
          role="tablist"
          aria-label="Integrations view"
          className="flex flex-wrap items-center gap-1 rounded-full bg-ovs-crm-surface-inset p-1"
        >
          {(
            [
              ['connections', 'Connections'],
              ['inbound', 'Inbound events'],
              ['outbound', 'Outbound sends'],
            ] as const
          ).map(([key, label]) => (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={tab === key}
              onClick={() => setTab(key)}
              className={
                tab === key
                  ? 'rounded-full bg-ovs-crm-ink px-3.5 py-1.5 text-[12px] font-semibold text-white transition-colors'
                  : 'rounded-full px-3.5 py-1.5 text-[12px] font-semibold text-ovs-crm-text-secondary transition-colors hover:text-ovs-crm-text-primary'
              }
            >
              {label}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-1 rounded-full bg-ovs-crm-surface-inset p-1">
          {[7, 30, 90].map((days) => (
            <button
              key={days}
              type="button"
              onClick={() => setDays(days)}
              aria-pressed={filters.days === days}
              className={
                filters.days === days
                  ? 'rounded-full bg-ovs-crm-ink px-3 py-1 text-[11.5px] font-semibold text-white'
                  : 'rounded-full px-3 py-1 text-[11.5px] font-semibold text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary'
              }
            >
              {days}d
            </button>
          ))}
        </div>
      </div>

      {tab === 'connections' && (
        <>
          {connections.length === 0 ? (
            <CrmCard className="p-6">
              <div className="py-10 text-center">
                <p className="text-[13px] text-ovs-crm-text-secondary">Nothing is connected yet.</p>
                <p className="mt-1 text-[12px] text-ovs-crm-text-muted">
                  WhatsApp numbers are connected in Marketing settings.
                </p>
                {can.manageMarketingSettings && (
                  <CrmButton href="/company/marketing/settings" className="mt-4">
                    Open Marketing settings
                  </CrmButton>
                )}
              </div>
            </CrmCard>
          ) : (
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              {connections.map((connection) => (
                <CrmCard key={connection.id} className="p-5">
                  <div className="flex items-start gap-3">
                    <CrmCardIcon icon={MessageSquare} hue={connection.quiet ? HUE.orange : HUE.green} />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[13.5px] font-semibold text-ovs-crm-text-primary">
                          {connection.name}
                        </span>
                        <CrmStatusPill tone={connectionState(connection).tone}>
                          {connectionState(connection).label}
                        </CrmStatusPill>
                      </div>
                      <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                        {connection.provider} · {connection.number ?? 'no number'}
                        {connection.purpose && <> · {connection.purpose}</>}
                      </p>
                    </div>
                  </div>

                  <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3">
                    <div>
                      <dt className="text-[10.5px] font-semibold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                        Last event
                      </dt>
                      <dd className="mt-0.5 text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                        {connection.last_webhook_at ?? 'never'}
                        {connection.hours_silent !== null && connection.hours_silent > 0 && (
                          <span className="text-ovs-crm-text-muted"> · {connection.hours_silent}h ago</span>
                        )}
                      </dd>
                    </div>
                    <div>
                      <dt className="text-[10.5px] font-semibold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                        Last connected
                      </dt>
                      <dd className="mt-0.5 text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                        {connection.last_connected_at ?? '—'}
                      </dd>
                    </div>
                  </dl>

                  {/*
                    Whether the credentials are set, never what they are. The
                    verify token is shown in Marketing settings because an
                    operator has to paste it into Meta; a health view has no
                    reason to hold a secret.
                  */}
                  <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-ovs-crm-border pt-3">
                    <CrmStatusPill tone={connection.has_access_token ? 'good' : 'bad'}>
                      {connection.has_access_token ? 'Access token set' : 'No access token'}
                    </CrmStatusPill>
                    <CrmStatusPill tone={connection.has_verify_token ? 'good' : 'warn'}>
                      {connection.has_verify_token ? 'Verify token set' : 'No verify token'}
                    </CrmStatusPill>
                  </div>
                </CrmCard>
              ))}
            </div>
          )}

          <CrmCard className="mt-4 p-6">
            <div className="mb-3 flex items-start gap-3">
              <div className="min-w-0 flex-1">
                <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  Not available on the Company side
                </h2>
                <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                  Stated rather than stubbed, so nobody looks for a screen that does not exist
                </p>
              </div>
              <CrmCardIcon icon={Plug} hue={HUE.slate} />
            </div>

            <ul className="space-y-2">
              {[
                {
                  key: 'api_tokens',
                  title: 'API tokens',
                  detail:
                    'The token table is keyed on a clinic employee, so there is no row shape a company user could hold one as. Issuing company API access is an architecture decision, not a missing screen.',
                },
                {
                  key: 'outbound_webhooks',
                  title: 'Outbound webhooks to your own systems',
                  detail:
                    'Subscriptions are clinic-scoped and nothing dispatches to them, so subscribing here would deliver nothing.',
                },
                {
                  key: 'api_audit',
                  title: 'API request log',
                  detail: 'Also clinic-scoped, and no company request path writes to it.',
                },
              ].map((row) => (
                <li key={row.key} className="rounded-[14px] border border-ovs-crm-border px-3.5 py-2.5">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-[12.5px] font-medium text-ovs-crm-text-primary">{row.title}</span>
                    <CrmStatusPill tone="neutral">
                      {unavailable[row.key]?.rows ?? 0} rows · {unavailable[row.key]?.scope}
                    </CrmStatusPill>
                  </div>
                  <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-secondary">{row.detail}</p>
                </li>
              ))}
            </ul>
          </CrmCard>
        </>
      )}

      {tab === 'inbound' && (
        <>
          <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { label: 'Received', value: inbound.total, hue: HUE.blue },
              { label: 'Processed', value: inbound.by_status.processed ?? 0, hue: HUE.green },
              { label: 'Still pending', value: inbound.by_status.pending ?? 0, hue: HUE.violet },
              { label: 'Failed', value: failed, hue: HUE.coral },
            ].map((cell) => (
              <CrmCard key={cell.label} className="px-5 py-4">
                <span className="block h-1.5 w-9 rounded-full" style={{ background: cell.hue }} aria-hidden />
                <CrmValue value={cell.value} size="minor" className="mt-2" />
                <p className="mt-1 text-[11.5px] text-ovs-crm-text-muted">{cell.label}</p>
              </CrmCard>
            ))}
          </div>

          <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <CrmCard className="p-6">
              <div className="mb-3 flex items-start gap-3">
                <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  Daily volume
                </h2>
                <CrmCardIcon icon={Inbox} hue={HUE.blue} />
              </div>
              {inbound.total === 0 ? (
                <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">
                  No events in the last {inbound.days} days.
                </p>
              ) : (
                <>
                  <CrmSparkline
                    points={inbound.daily.map((d) => d.count)}
                    color={HUE.blue}
                    variant="bars"
                    width={520}
                    height={90}
                    className="w-full"
                  />
                  <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
                    {inbound.daily[0]?.date} → {inbound.daily[inbound.daily.length - 1]?.date}
                  </p>
                </>
              )}
            </CrmCard>

            <CrmCard className="p-6">
              <div className="mb-3 flex items-start gap-3">
                <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  How each event proved itself
                </h2>
                <CrmCardIcon icon={ShieldCheck} hue={inbound.signature_enforced ? HUE.green : HUE.orange} />
              </div>
              {Object.keys(inbound.by_signature).length === 0 ? (
                <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">
                  No events in the last {inbound.days} days.
                </p>
              ) : (
                <ul className="space-y-2">
                  {Object.entries(inbound.by_signature).map(([result, count]) => (
                    <li
                      key={result}
                      className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[14px] border border-ovs-crm-border px-3.5 py-2"
                    >
                      <CrmStatusPill tone={signatureTone(result === 'unrecorded' ? null : result)}>
                        {SIGNATURE_LABEL[result] ?? result}
                      </CrmStatusPill>
                      <span className="flex-1 text-right text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">
                        {count}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CrmCard>
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <CrmCard className="p-6">
              <div className="mb-3 flex items-start gap-3">
                <div className="min-w-0 flex-1">
                  <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                    Events that failed
                  </h2>
                  <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                    The reason each one gave, in full
                  </p>
                </div>
                <CrmCardIcon icon={AlertTriangle} hue={HUE.coral} />
              </div>
              {inbound.failures.length === 0 ? (
                <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">Nothing has failed.</p>
              ) : (
                <ul className="space-y-2">
                  {inbound.failures.map((failure) => (
                    <li key={failure.id} className="rounded-[14px] border border-ovs-crm-border px-3.5 py-2.5">
                      <div className="flex flex-wrap items-center gap-2">
                        <CrmStatusPill tone="bad">{failure.event_type}</CrmStatusPill>
                        <span className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">{failure.at}</span>
                      </div>
                      <p className="mt-1 break-words text-[12px] text-ovs-crm-text-secondary">
                        {failure.error ?? 'No reason was recorded.'}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </CrmCard>

            <CrmCard className="p-6">
              <div className="mb-3 flex items-start gap-3">
                <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  Latest events
                </h2>
                <CrmCardIcon icon={ArrowUpRight} hue={HUE.violet} />
              </div>
              {inbound.recent.length === 0 ? (
                <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">No events yet.</p>
              ) : (
                <div className="overflow-x-auto">
                  <ul className="min-w-[380px] space-y-1.5">
                    {inbound.recent.map((event) => (
                      <li
                        key={event.id}
                        className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[14px] border border-ovs-crm-border px-3.5 py-2"
                      >
                        <CrmStatusPill
                          tone={event.status === 'failed' ? 'bad' : event.status === 'processed' ? 'good' : 'neutral'}
                        >
                          {event.status}
                        </CrmStatusPill>
                        <span className="min-w-[140px] flex-1 text-[12.5px] text-ovs-crm-text-primary">
                          {event.event_type}
                        </span>
                        <span className="text-[11px] tabular-nums text-ovs-crm-text-muted">{event.at}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </CrmCard>
          </div>
        </>
      )}

      {tab === 'outbound' && (
        <>
          <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
              /* Everything sent in the window — not the `sent` status, which is one of the four below. */
              { label: 'Messages out', value: outbound.total, hue: HUE.blue },
              { label: 'Awaiting the provider', value: (outbound.by_status.queued ?? 0) + (outbound.by_status.sent ?? 0), hue: HUE.orange },
              { label: 'Delivered', value: outbound.by_status.delivered ?? 0, hue: HUE.green },
              { label: 'Failed', value: outboundFailed, hue: HUE.coral },
            ].map((cell) => (
              <CrmCard key={cell.label} className="px-5 py-4">
                <span className="block h-1.5 w-9 rounded-full" style={{ background: cell.hue }} aria-hidden />
                <CrmValue value={cell.value} size="minor" className="mt-2" />
                <p className="mt-1 text-[11.5px] text-ovs-crm-text-muted">{cell.label}</p>
              </CrmCard>
            ))}
          </div>

          <CrmCard className="p-6">
            <div className="mb-3 flex items-start gap-3">
              <div className="min-w-0 flex-1">
                <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  Sends that failed
                </h2>
                <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                  What the provider refused, and why it said it did
                </p>
              </div>
              <CrmCardIcon icon={Send} hue={HUE.coral} />
            </div>
            {outbound.failures.length === 0 ? (
              <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">
                Nothing has failed in the last {outbound.days} days.
              </p>
            ) : (
              <ul className="space-y-2">
                {outbound.failures.map((failure) => (
                  <li key={failure.id} className="rounded-[14px] border border-ovs-crm-border px-3.5 py-2.5">
                    <div className="flex flex-wrap items-center gap-2">
                      <CrmStatusPill tone="bad">{failure.type ?? 'message'}</CrmStatusPill>
                      {failure.template && (
                        <span className="text-[11.5px] text-ovs-crm-text-muted">{failure.template}</span>
                      )}
                      <span className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">{failure.at}</span>
                    </div>
                    <p className="mt-1 break-words text-[12px] text-ovs-crm-text-secondary">
                      {failure.reason ?? 'No reason was recorded.'}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </CrmCard>
        </>
      )}
    </AdministrationShell>
  );
}
