import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, LifeBuoy, Plus, Timer, UserX } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  CrmDataState,
  CrmPagination,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { OperationsShell } from './components/OperationsShell';

/**
 * Operations → Support
 *
 * The vendor's queue across every customer. A ticket carries the account it
 * belongs to, which is what keeps it attributable — it is not a CRM task, and
 * nothing here writes to `tasks`.
 *
 * Where no SLA target is configured, a ticket shows no due date at all rather
 * than implying it is comfortably on time.
 */

const HUE = { coral: '#EF5B39', orange: '#F59245', green: '#2FA96B', blue: '#2F6BE4' } as const;

interface Ticket {
  id: number;
  reference: string;
  subject: string;
  category: string | null;
  status: string;
  status_label: string;
  priority: string;
  priority_label: string;
  organization: string | null;
  assignee: string | null;
  opened_at: string | null;
  resolution_due_at: string | null;
  breached_first_response: boolean | null;
  breached_resolution: boolean | null;
}

interface Props {
  tickets: {
    data: Ticket[];
    links?: Array<{ url: string | null; label: string; active: boolean }>;
    from?: number | null;
    to?: number | null;
    total?: number | null;
  };
  filters: Record<string, string | undefined>;
  summary: { open: number; unassigned: number; urgent: number; resolved_total: number };
  slaConfigured: boolean;
  slaTargets: Record<string, { first_response_minutes: number | null; resolution_minutes: number | null }>;
  organizations: Array<{ id: number; name: string }>;
  agents: Array<{ id: number; name: string }>;
  can: { manage: boolean };
}

const STATUS_TONE: Record<string, CrmStatusTone> = {
  open: 'warn',
  pending: 'info',
  resolved: 'good',
  closed: 'neutral',
};

const PRIORITY_TONE: Record<string, CrmStatusTone> = {
  urgent: 'bad',
  high: 'warn',
  normal: 'neutral',
  low: 'neutral',
};

const PRIORITIES = ['low', 'normal', 'high', 'urgent'] as const;

export default function SupportWorkspace({
  tickets,
  filters,
  summary,
  slaConfigured,
  slaTargets,
  organizations,
  agents,
  can,
}: Props) {
  const [form, setForm] = React.useState({
    open: false,
    subject: '',
    body: '',
    organizationId: '',
    reporterName: '',
    reporterEmail: '',
    priority: 'normal',
    assignedTo: '',
  });

  const [slaOpen, setSlaOpen] = React.useState(false);
  const [targets, setTargets] = React.useState(() =>
    PRIORITIES.map((priority) => ({
      priority,
      first_response_minutes: slaTargets?.[priority]?.first_response_minutes ?? '',
      resolution_minutes: slaTargets?.[priority]?.resolution_minutes ?? '',
    })),
  );

  const rows = tickets.data ?? [];

  const apply = (changes: Record<string, string | undefined>) =>
    router.get(
      '/company/support',
      { ...filters, ...changes },
      { preserveState: true, preserveScroll: true, replace: true },
    );

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      '/company/support',
      {
        subject: form.subject,
        body: form.body || null,
        organization_id: form.organizationId ? Number(form.organizationId) : null,
        reporter_name: form.reporterName || null,
        reporter_email: form.reporterEmail || null,
        priority: form.priority,
        assigned_to: form.assignedTo ? Number(form.assignedTo) : null,
      },
      { onSuccess: () => setForm({ ...form, open: false, subject: '', body: '' }) },
    );
  };

  const saveSla = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      '/company/support/sla',
      {
        targets: targets.map((target) => ({
          priority: target.priority,
          first_response_minutes: target.first_response_minutes === '' ? null : Number(target.first_response_minutes),
          resolution_minutes: target.resolution_minutes === '' ? null : Number(target.resolution_minutes),
        })),
      },
      { preserveScroll: true, onSuccess: () => setSlaOpen(false) },
    );
  };

  return (
    <OperationsShell title="Support">
      <Head title="Support | Customer Operations" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Customer Operations
          </p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Support
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            Customers waiting on us, across every account
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={LifeBuoy} value={summary.open} caption="Open" />
          <CrmPageStat icon={UserX} value={summary.unassigned} caption="Unassigned" />
          <CrmPageStat icon={AlertTriangle} value={summary.urgent} caption="Urgent" />
          <CrmPageStat icon={CheckCircle2} value={summary.resolved_total} caption="Resolved" />
        </div>
      </div>

      {!slaConfigured && (
        <CrmCard className="mb-4 flex flex-wrap items-center justify-between gap-4 px-5 py-4">
          <div className="flex items-start gap-3">
            <CrmCardIcon icon={Timer} hue={HUE.orange} />
            <div>
              <p className="text-[13px] font-semibold text-ovs-crm-text-primary">No response targets set</p>
              {/*
                How long a customer should wait is a commitment the business
                makes. Until it is set, no ticket carries a due date and none is
                described as on time.
              */}
              <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
                Tickets have no due dates until you say how long each priority may take.
              </p>
            </div>
          </div>
          {can.manage && (
            <CrmButton size="sm" onClick={() => setSlaOpen(true)}>
              Set response targets
            </CrmButton>
          )}
        </CrmCard>
      )}

      <CrmCard className="mb-3 flex flex-wrap items-end gap-3 px-5 py-3.5">
        <div className="min-w-[200px] flex-1">
          <CrmFormField label="Search">
            <CrmInput
              type="search"
              placeholder="Subject, reference or email"
              defaultValue={filters.search ?? ''}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  apply({ search: (e.target as HTMLInputElement).value || undefined });
                }
              }}
            />
          </CrmFormField>
        </div>

        <div className="min-w-[150px]">
          <CrmFormField label="Status">
            <CrmSelect value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value || undefined })}>
              <option value="">All statuses</option>
              <option value="open">Open</option>
              <option value="pending">Waiting on customer</option>
              <option value="resolved">Resolved</option>
              <option value="closed">Closed</option>
            </CrmSelect>
          </CrmFormField>
        </div>

        <div className="min-w-[140px]">
          <CrmFormField label="Priority">
            <CrmSelect value={filters.priority ?? ''} onChange={(e) => apply({ priority: e.target.value || undefined })}>
              <option value="">Any priority</option>
              {PRIORITIES.map((priority) => (
                <option key={priority} value={priority}>
                  {priority.charAt(0).toUpperCase() + priority.slice(1)}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </div>

        {can.manage && (
          <>
            <CrmButton onClick={() => setSlaOpen(true)}>Targets</CrmButton>
            <CrmButton tone="primary" icon={Plus} onClick={() => setForm({ ...form, open: true })}>
              New ticket
            </CrmButton>
          </>
        )}
      </CrmCard>

      {rows.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={LifeBuoy}
          title="Nothing in the queue"
          description="A ticket names the customer, what went wrong and how urgent it is, and keeps its whole history."
        />
      ) : (
        <div className="space-y-2">
          {rows.map((ticket) => (
            <Link key={ticket.id} href={`/company/support/${ticket.id}`} className="block">
              <CrmCard className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 transition hover:border-ovs-crm-accent/40">
                <span className="min-w-[200px] flex-1">
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="text-[13.5px] font-medium text-ovs-crm-text-primary">{ticket.subject}</span>
                    <CrmStatusPill tone={STATUS_TONE[ticket.status] ?? 'neutral'}>{ticket.status_label}</CrmStatusPill>
                    <CrmStatusPill tone={PRIORITY_TONE[ticket.priority] ?? 'neutral'}>
                      {ticket.priority_label}
                    </CrmStatusPill>
                    {ticket.breached_resolution === true && <CrmStatusPill tone="bad">Past due</CrmStatusPill>}
                  </span>
                  <span className="mt-0.5 block text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                    {ticket.reference}
                    {ticket.organization && <> · {ticket.organization}</>}
                    {ticket.opened_at && <> · opened {ticket.opened_at}</>}
                  </span>
                </span>

                <span className="min-w-[130px] text-[12px] text-ovs-crm-text-secondary">
                  {ticket.assignee ?? <span className="text-ovs-crm-text-muted">Unassigned</span>}
                </span>

                <span className="min-w-[130px] text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                  {/* No target configured reads as such, not as a comfortable margin. */}
                  {ticket.resolution_due_at ? `due ${ticket.resolution_due_at}` : 'no target'}
                </span>
              </CrmCard>
            </Link>
          ))}

          <CrmPagination
            links={tickets.links}
            from={tickets.from}
            to={tickets.to}
            total={tickets.total}
            className="pt-1"
          />
        </div>
      )}

      <CrmModal
        open={form.open}
        onClose={() => setForm({ ...form, open: false })}
        title="New ticket"
        description="Link it to the customer's account so it stays attributable."
        footer={
          <>
            <CrmButton onClick={() => setForm({ ...form, open: false })}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="support-ticket-form">
              Open ticket
            </CrmButton>
          </>
        }
      >
        <form id="support-ticket-form" onSubmit={submit} className="flex flex-col gap-3.5">
          <CrmFormField label="Subject" required>
            <CrmInput required value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} />
          </CrmFormField>

          <CrmFormField label="What happened">
            <CrmTextarea rows={3} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />
          </CrmFormField>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="Account" hint="Optional — a ticket can arrive before it is matched.">
              <CrmSelect value={form.organizationId} onChange={(e) => setForm({ ...form, organizationId: e.target.value })}>
                <option value="">Not yet known</option>
                {organizations.map((organization) => (
                  <option key={organization.id} value={organization.id}>
                    {organization.name}
                  </option>
                ))}
              </CrmSelect>
            </CrmFormField>

            <CrmFormField label="Priority" required>
              <CrmSelect required value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
                {PRIORITIES.map((priority) => (
                  <option key={priority} value={priority}>
                    {priority.charAt(0).toUpperCase() + priority.slice(1)}
                  </option>
                ))}
              </CrmSelect>
            </CrmFormField>

            <CrmFormField label="Reporter">
              <CrmInput value={form.reporterName} onChange={(e) => setForm({ ...form, reporterName: e.target.value })} />
            </CrmFormField>

            <CrmFormField label="Reporter email">
              <CrmInput
                type="email"
                value={form.reporterEmail}
                onChange={(e) => setForm({ ...form, reporterEmail: e.target.value })}
              />
            </CrmFormField>
          </div>

          <CrmFormField label="Assign to">
            <CrmSelect value={form.assignedTo} onChange={(e) => setForm({ ...form, assignedTo: e.target.value })}>
              <option value="">Leave unassigned</option>
              {agents.map((agent) => (
                <option key={agent.id} value={agent.id}>
                  {agent.name}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </form>
      </CrmModal>

      <CrmModal
        open={slaOpen}
        onClose={() => setSlaOpen(false)}
        title="Response targets"
        description="In minutes. Leave a field blank and that priority carries no due date at all."
        footer={
          <>
            <CrmButton onClick={() => setSlaOpen(false)}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="sla-form">
              Save targets
            </CrmButton>
          </>
        }
      >
        <form id="sla-form" onSubmit={saveSla} className="flex flex-col gap-3">
          {targets.map((target, index) => (
            <div key={target.priority} className="grid grid-cols-[90px_1fr_1fr] items-end gap-3">
              <span className="pb-2 text-[12.5px] font-medium text-ovs-crm-text-primary">
                {target.priority.charAt(0).toUpperCase() + target.priority.slice(1)}
              </span>

              <CrmFormField label="First reply">
                <CrmInput
                  type="number"
                  min={1}
                  placeholder="none"
                  value={String(target.first_response_minutes)}
                  onChange={(e) =>
                    setTargets(targets.map((row, i) => (i === index ? { ...row, first_response_minutes: e.target.value } : row)))
                  }
                />
              </CrmFormField>

              <CrmFormField label="Resolution">
                <CrmInput
                  type="number"
                  min={1}
                  placeholder="none"
                  value={String(target.resolution_minutes)}
                  onChange={(e) =>
                    setTargets(targets.map((row, i) => (i === index ? { ...row, resolution_minutes: e.target.value } : row)))
                  }
                />
              </CrmFormField>
            </div>
          ))}
        </form>
      </CrmModal>
    </OperationsShell>
  );
}
