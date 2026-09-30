import React, { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, Check, Plus, Search, Send, X } from 'lucide-react';
import {
  CrmShell,
  CrmPageHead,
  CrmCard,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmDataState,
  inr,
} from '@/pages/Company/design';
import type { CommercialListPayload, QuotationRow, QuotationStatusValue } from './commercial';
import {
  RECONCILIATION_LABEL,
  RECONCILIATION_TONE,
  STATUS_TONE,
  settledPercent,
  writtenDate,
} from './commercialPresentation';
import { QuotationCreateModal } from './components/QuotationCreateModal';
import { QuotationActionModal, type QuotationAction } from './components/QuotationActionModal';

/**
 * Commercials.
 *
 * Rebuilt against the server contract rather than extended. The page it
 * replaces listed proposals and nothing else — a module named Commercials could
 * not answer *what have we billed, and who has paid?*, because agreements and
 * payments were only ever visible from inside one deal.
 *
 * Order is urgency, decided server-side, and a money discrepancy sits above
 * everything: an overpaid or internally inconsistent document is the only state
 * where the record itself is wrong rather than merely unfinished.
 */

const GROUPS: Array<{ key: string; label: string; match: (r: QuotationRow) => boolean; urgent?: boolean }> = [
  {
    key: 'attention',
    label: 'Money does not reconcile',
    match: (r) => Boolean(r.settlement?.needsAttention),
    urgent: true,
  },
  {
    key: 'awaiting-money',
    label: 'Approved, awaiting payment',
    match: (r) => r.status === 'approved'
      && !r.settlement?.needsAttention
      && (r.settlement?.reconciliation.state === 'unpaid' || r.settlement?.reconciliation.state === 'partially_paid'),
    urgent: true,
  },
  { key: 'negotiation', label: 'In negotiation', match: (r) => r.status === 'negotiation' && !r.settlement?.needsAttention, urgent: true },
  { key: 'sent', label: 'Awaiting the customer', match: (r) => r.status === 'sent' && !r.settlement?.needsAttention },
  {
    key: 'approved',
    label: 'Settled',
    /*
     * Only the ones actually settled. Without the payment-state check this
     * matched every approved row, so an unpaid quotation appeared twice — once
     * under "awaiting payment" and again here, which reads as two records.
     */
    match: (r) => r.status === 'approved'
      && !r.settlement?.needsAttention
      && r.settlement?.reconciliation.state === 'paid',
  },
  { key: 'draft', label: 'Drafts', match: (r) => r.status === 'draft' && !r.settlement?.needsAttention },
  { key: 'closed', label: 'Closed', match: (r) => ['rejected', 'expired', 'converted'].includes(r.status) && !r.settlement?.needsAttention },
];

export default function CommercialIndex({
  rows = [],
  counts,
  money,
  filters,
  can,
  leads = [],
  statuses = [],
  packages = [],
  addons = [],
}: CommercialListPayload) {
  const [search, setSearch] = useState(filters?.search ?? '');
  const [status, setStatus] = useState<QuotationStatusValue | null>(filters?.status ?? null);
  const [creating, setCreating] = useState(false);
  const [action, setAction] = useState<{ row: QuotationRow; kind: QuotationAction } | null>(null);

  const visible = useMemo(() => {
    const term = search.trim().toLowerCase();

    return rows.filter((row) => {
      if (status && row.status !== status) return false;
      if (!term) return true;

      return `${row.company} ${row.number ?? ''} ${row.owner ?? ''}`.toLowerCase().includes(term);
    });
  }, [rows, search, status]);

  const grouped = useMemo(
    () => GROUPS
      .map((g) => ({ ...g, rows: visible.filter(g.match) }))
      .filter((g) => g.rows.length > 0),
    [visible],
  );

  return (
    <CrmShell title="Commercials">
      <div className="pb-16">
        <CrmPageHead
          eyebrow="CRM"
          title="Commercials"
          actions={can.create ? (
            <CrmButton tone="primary" icon={Plus} onClick={() => setCreating(true)}>
              New quotation
            </CrmButton>
          ) : undefined}
        />

        {/*
          * The money, before the list. Agreed and settled are shown side by
          * side on purpose — the gap between them is the number a commercial
          * team actually chases.
          */}
        <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          <Tally label="Proposed" value={inr(money.proposed)} />
          <Tally label="Approved" value={inr(money.approved)} />
          <Tally label="Agreed" value={inr(money.agreed)} />
          <Tally label="Settled" value={inr(money.settled)} tone="good" />
          <Tally label="Outstanding" value={inr(money.outstanding)} tone={money.outstanding > 0 ? 'warn' : undefined} />
        </div>

        {counts.needsAttention > 0 && (
          <p className="mb-5 flex items-start gap-2 rounded-2xl bg-ovs-crm-accent-coral-soft px-4 py-3 text-sm text-ovs-crm-accent-coral-text">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>
              {counts.needsAttention} {counts.needsAttention === 1 ? 'agreement does' : 'agreements do'} not
              reconcile — the money received does not match what was agreed.
            </span>
          </p>
        )}

        {/* Search and the status views */}
        <div className="mb-5 flex flex-col gap-3 lg:flex-row lg:items-center">
          <div className="relative flex-1">
            <label className="sr-only" htmlFor="commercial-search">Search commercials</label>
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ovs-crm-text-secondary" aria-hidden="true" />
            <CrmInput
              id="commercial-search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by company, number or owner"
              className="pl-9"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <ViewChip label="All" count={counts.total} active={status === null} onClick={() => setStatus(null)} />
            {statuses.map((s) => (
              <ViewChip
                key={s.value}
                label={s.label}
                count={rows.filter((r) => r.status === s.value).length}
                active={status === s.value}
                onClick={() => setStatus(status === s.value ? null : s.value)}
              />
            ))}
          </div>
        </div>

        {grouped.length === 0 ? (
          <CrmDataState
            kind="empty"
            title={search || status ? 'Nothing matches' : 'No commercials yet'}
            description={search || status
              ? 'Try a different search or view.'
              : 'Quotations appear here once one is raised against a deal.'}
          />
        ) : (
          <div className="space-y-8">
            {grouped.map((group) => (
              <section key={group.key} aria-labelledby={`group-${group.key}`}>
                <h2
                  id={`group-${group.key}`}
                  className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-ovs-crm-text-secondary"
                >
                  {group.urgent && <AlertTriangle className="h-3.5 w-3.5 text-ovs-crm-accent-coral" aria-hidden="true" />}
                  {group.label}
                  <span className="font-normal">{group.rows.length}</span>
                </h2>

                <div className="space-y-3">
                  {group.rows.map((row, index) => (
                    <CommercialRow
                      key={row.id}
                      row={row}
                      step={index}
                      onAction={(kind) => setAction({ row, kind })}
                    />
                  ))}
                </div>
              </section>
            ))}
          </div>
        )}
      </div>

      <QuotationCreateModal
        isOpen={creating}
        onClose={() => setCreating(false)}
        leads={leads}
        packages={packages}
        addons={addons}
      />

      {action && (
        <QuotationActionModal
          isOpen
          kind={action.kind}
          quotationId={action.row.id}
          company={action.row.company}
          onClose={() => setAction(null)}
        />
      )}
    </CrmShell>
  );
}

function Tally({ label, value, tone }: { label: string; value: string; tone?: 'good' | 'warn' }) {
  return (
    <CrmCard className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">{label}</p>
      <p className={`mt-1 text-lg font-semibold tabular-nums ${
        tone === 'good' ? 'text-ovs-crm-accent-green-text'
          : tone === 'warn' ? 'text-ovs-crm-accent-yellow-text'
            : 'text-ovs-crm-text-primary'
      }`}
      >
        {value}
      </p>
    </CrmCard>
  );
}

function ViewChip({ label, count, active, onClick }: { label: string; count: number; active: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-medium transition ${
        active
          ? 'bg-ovs-crm-ink text-white'
          : 'bg-ovs-crm-surface-inset text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary'
      }`}
    >
      {label}
      <span className={active ? 'text-white/70' : 'text-ovs-crm-text-secondary'}>{count}</span>
    </button>
  );
}

/**
 * One commercial record: the proposal, and whatever it became.
 *
 * The agreement column is the half the old list did not have. Without it a rep
 * could see that a quotation was approved and still had no way to learn whether
 * the customer had paid.
 */
function CommercialRow({
  row,
  step,
  onAction,
}: {
  row: QuotationRow;
  step: number;
  onAction: (kind: QuotationAction) => void;
}) {
  const attention = Boolean(row.settlement?.needsAttention);
  const reconciliation = row.settlement?.reconciliation;

  return (
    <CrmCard step={step} className={`p-4 sm:p-5 ${attention ? 'ring-1 ring-ovs-crm-accent-coral/40' : ''}`}>
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        {/* Who and what */}
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <Link
              href={route('company.crm.quotations.show', row.id)}
              className="truncate text-base font-semibold text-ovs-crm-text-primary hover:underline"
            >
              {row.company}
            </Link>
            <CrmStatusPill tone={STATUS_TONE[row.status]}>{row.statusLabel}</CrmStatusPill>
            {row.expiringSoon && (
              <span className="text-xs font-medium text-ovs-crm-accent-yellow-text">Expiring soon</span>
            )}
          </div>

          <p className="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-ovs-crm-text-secondary">
            <span className="tabular-nums text-xs">{row.number ?? '—'}</span>
            <span>· {row.lineCount} {row.lineCount === 1 ? 'line' : 'lines'}</span>
            {row.validUntil && <span>· valid to {writtenDate(row.validUntil)}</span>}
          </p>
        </div>

        {/* The money */}
        <div className="lg:w-44 lg:shrink-0">
          <p className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary lg:hidden">Value</p>
          <p className="text-base font-semibold tabular-nums text-ovs-crm-text-primary">
            {inr(row.grandTotal)}
          </p>
        </div>

        {/* Whether it turned into money */}
        <div className="lg:w-52 lg:shrink-0">
          {row.settlement && reconciliation ? (
            <>
              <div className="flex items-center gap-2">
                <CrmStatusPill tone={RECONCILIATION_TONE[reconciliation.state]}>
                  {RECONCILIATION_LABEL[reconciliation.state]}
                </CrmStatusPill>
              </div>
              <p className="mt-1 text-xs tabular-nums text-ovs-crm-text-secondary">
                {inr(reconciliation.settled)} of {inr(reconciliation.expected)}
              </p>
              {reconciliation.state !== 'mismatch' && (
                <div
                  className="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-ovs-crm-surface-inset"
                  role="progressbar"
                  aria-valuenow={settledPercent(reconciliation.settled, reconciliation.expected)}
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-label="Settled"
                >
                  <div
                    className={`h-full rounded-full ${attention ? 'bg-ovs-crm-accent-coral' : 'bg-ovs-crm-accent-green'}`}
                    style={{ width: `${settledPercent(reconciliation.settled, reconciliation.expected)}%` }}
                  />
                </div>
              )}
            </>
          ) : (
            /*
             * Not zeros. An unapproved proposal is not money owed, which is a
             * different thing from an approved one nobody has paid.
             */
            <p className="text-sm text-ovs-crm-text-secondary">Not approved yet</p>
          )}
        </div>

        {/* Owner */}
        <div className="min-w-0 lg:w-32 lg:shrink-0">
          <p className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary lg:hidden">Owner</p>
          <p className="truncate text-sm text-ovs-crm-text-secondary" title={row.owner ?? 'Unassigned'}>
            {row.owner ?? 'Unassigned'}
          </p>
        </div>

        {/* What to do about it */}
        <div className="flex shrink-0 items-center gap-2">
          {row.can.update && row.status === 'draft' && (
            <CrmButton tone="ghost" size="sm" onClick={() => onAction('send')}>
              <Send className="h-3.5 w-3.5" aria-hidden="true" />
              Send
            </CrmButton>
          )}

          {row.can.approve && ['sent', 'negotiation'].includes(row.status) && (
            <>
              <CrmButton tone="ghost" size="sm" onClick={() => onAction('approve')}>
                <Check className="h-3.5 w-3.5" aria-hidden="true" />
                Approve
              </CrmButton>
              <CrmButton tone="ghost" size="sm" onClick={() => onAction('reject')}>
                <X className="h-3.5 w-3.5" aria-hidden="true" />
                Reject
              </CrmButton>
            </>
          )}

          <Link
            href={route('company.crm.quotations.show', row.id)}
            className="inline-flex items-center gap-1 rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-xs font-medium text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
          >
            Open
            <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
        </div>
      </div>
    </CrmCard>
  );
}
