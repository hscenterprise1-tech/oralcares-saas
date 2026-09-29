import React from 'react';
import { Link, router } from '@inertiajs/react';
import { MarketingShell } from '../../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmSection,
  CrmTabs,
  CrmTable,
  CrmPagination,
  CrmStatusPill,
  CrmAvatar,
  CrmButton,
  toneClasses,
  type CrmColumn,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { Activity, AlertTriangle, ExternalLink, Loader } from 'lucide-react';
import { humanize } from '../labels';

/**
 * Every automation run, in one place.
 *
 * This page was rendered by a controller that had existed for a long time and
 * never existed on disk — so both execution routes threw the moment anyone
 * reached them, and the sidebar entry pointed at a third URL that matched no
 * route at all. The whole feature was unreachable three different ways.
 *
 * The question it answers is the one no per-automation page can: is anything
 * failing right now, across all the rules? So failures lead, and the status
 * filter is the primary control rather than an afterthought.
 */

interface ContactItem {
  id: number;
  name: string;
  phone: string;
  organization: string | null;
}

interface LeadItem {
  id: number;
  company_name: string;
  status: string;
}

interface AutomationRef {
  id: number;
  name: string;
}

interface ExecutionRow {
  id: number;
  status: 'queued' | 'running' | 'waiting' | 'completed' | 'stopped' | 'failed' | 'cancelled';
  stop_reason: string | null;
  last_error: string | null;
  started_at: string | null;
  completed_at: string | null;
  automation?: AutomationRef | null;
  contact?: ContactItem | null;
  lead?: LeadItem | null;
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface PageProps {
  executions: Paginated<ExecutionRow>;
  /** Present when scoped to one automation; null on the cross-automation log. */
  automation: AutomationRef | null;
  filters: { status: string | null };
  stats: {
    total: number;
    running: number;
    completed: number;
    failed: number;
    stopped: number;
  };
}

const EXECUTION_TONE: Record<string, CrmStatusTone> = {
  queued: 'neutral',
  running: 'warn',
  waiting: 'warn',
  completed: 'good',
  stopped: 'neutral',
  failed: 'bad',
  cancelled: 'neutral',
};

/**
 * Date and time, because "did this run before or after I changed the rule" is
 * the question people actually bring to a log. A date alone cannot answer it.
 */
const stamp = (iso: string | null) => {
  if (! iso) return '—';
  const d = new Date(iso);

  if (Number.isNaN(d.getTime())) return '—';

  return d.toLocaleString('en-IN', {
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
  });
};

type FilterKey = 'all' | 'failed' | 'running' | 'completed';

const FILTER_STATUS: Record<FilterKey, string | undefined> = {
  all: undefined,
  failed: 'failed',
  running: 'running',
  completed: 'completed',
};

export default function ExecutionsIndex({ executions, automation, filters, stats }: PageProps) {
  const basePath = automation
    ? `/company/marketing/automations/${automation.id}/executions`
    : '/company/marketing/executions';

  const activeFilter: FilterKey =
    filters.status === 'failed' ? 'failed'
      : filters.status === 'running' ? 'running'
        : filters.status === 'completed' ? 'completed'
          : 'all';

  const go = (key: FilterKey) => {
    const status = FILTER_STATUS[key];
    router.get(basePath, status ? { status } : {}, { preserveState: true, replace: true });
  };

  const columns: CrmColumn<ExecutionRow>[] = [
    {
      key: 'contact',
      header: 'Contact',
      cell: (row) => (
        <div className="flex min-w-0 items-center gap-2.5">
          <CrmAvatar name={row.contact?.name ?? 'Run'} size="sm" />
          <div className="min-w-0">
            <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
              {row.contact?.name ?? `Run #${row.id}`}
            </p>
            {row.contact?.organization && (
              <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                {row.contact.organization}
              </p>
            )}
          </div>
        </div>
      ),
    },
    ...(automation
      ? []
      : [{
        key: 'automation',
        header: 'Automation',
        width: '200px',
        cell: (row: ExecutionRow) => (
          row.automation ? (
            <Link
              href={`/company/marketing/automations/${row.automation.id}`}
              className="text-[13px] text-ovs-crm-text-secondary underline-offset-2 hover:underline"
            >
              {row.automation.name}
            </Link>
          ) : <span className="text-[13px] text-ovs-crm-text-muted">—</span>
        ),
      } as CrmColumn<ExecutionRow>]),
    {
      key: 'outcome',
      header: 'Outcome',
      cell: (row) => (
        /*
         * A failure's message is the entire reason to open this page, so it is
         * shown in full rather than truncated behind a click.
         */
        <p className="text-[12.5px] leading-relaxed text-ovs-crm-text-secondary">
          {row.last_error
            ? <span className={toneClasses('coral').text}>{row.last_error}</span>
            : row.stop_reason
              ? humanize(row.stop_reason)
              : row.lead
                ? 'Created a lead'
                : '—'}
        </p>
      ),
    },
    {
      key: 'started_at',
      header: 'Started',
      width: '150px',
      cell: (row) => (
        <span className="text-[12.5px] tabular-nums text-ovs-crm-text-secondary">
          {stamp(row.started_at)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      width: '120px',
      cell: (row) => (
        <CrmStatusPill tone={EXECUTION_TONE[row.status] ?? 'neutral'}>
          {humanize(row.status)}
        </CrmStatusPill>
      ),
    },
    {
      key: 'actions',
      header: '',
      width: '96px',
      align: 'right',
      cell: (row) => (
        <CrmButton size="sm" icon={ExternalLink} href={`/company/marketing/automations/executions/${row.id}`}>
          Open
        </CrmButton>
      ),
    },
  ];

  const title = automation ? `${automation.name} — runs` : 'Execution logs';

  return (
    <MarketingShell title={title}>

      <CrmPageHead
        eyebrow="Marketing"
        title={title}
        stats={
          <>
            <CrmPageStat icon={Activity} value={stats.total} caption="Runs recorded" />
            <CrmPageStat icon={Loader} value={stats.running} caption="Still in flight" />
            <CrmPageStat
              icon={AlertTriangle}
              value={stats.failed}
              caption="Failed and needing a look"
            />
          </>
        }
      />

      <CrmTabs<FilterKey>
        className="mb-3"
        value={activeFilter}
        onChange={go}
        tabs={[
          { key: 'all', label: 'All', count: stats.total },
          { key: 'failed', label: 'Failed', count: stats.failed },
          { key: 'running', label: 'In flight', count: stats.running },
          { key: 'completed', label: 'Completed', count: stats.completed },
        ]}
      />

      <CrmSection
        flush
        title="Runs"
        description={`${executions.total.toLocaleString('en-IN')} run${executions.total === 1 ? '' : 's'}`}
      >
        {executions.data.length === 0 ? (
          <CrmCard className="px-6 py-12 text-center">
            <Activity className="mx-auto mb-3 h-6 w-6 text-ovs-crm-text-muted" aria-hidden="true" />
            <p className="text-[14px] font-medium text-ovs-crm-text-primary">
              {activeFilter === 'failed' ? 'Nothing has failed' : 'No runs yet'}
            </p>
            <p className="mt-1 text-[12.5px] text-ovs-crm-text-muted">
              {activeFilter === 'failed'
                ? 'Every automation run has completed or is still in flight.'
                : 'Runs appear here the moment an automation is triggered.'}
            </p>
          </CrmCard>
        ) : (
          <>
            <CrmTable<ExecutionRow>
              columns={columns}
              rows={executions.data}
              rowKey={(row) => row.id}
            />

            <CrmPagination
              links={executions.links}
              from={executions.from}
              to={executions.to}
              total={executions.total}
            />
          </>
        )}
      </CrmSection>
    </MarketingShell>
  );
}
