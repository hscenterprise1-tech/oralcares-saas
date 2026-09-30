import React from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, Building2, Clock, MessageSquare, Timer, User } from 'lucide-react';
import {
  CrmCard,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmFormField,
  CrmSelect,
  CrmTextarea,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { OperationsShell } from './components/OperationsShell';

/**
 * One ticket, and everything that has happened to it.
 *
 * Notes, replies and every status, priority and assignment change share one
 * stream, because a history split across three lists is a history nobody reads.
 * Nothing on this page edits an event once it is written.
 */

interface Event {
  id: number;
  type: string;
  body: string | null;
  from: string | null;
  to: string | null;
  is_internal: boolean;
  actor: string | null;
  at: string | null;
}

interface Props {
  ticket: {
    id: number;
    reference: string;
    subject: string;
    body: string | null;
    category: string | null;
    status: string;
    status_label: string;
    priority: string;
    priority_label: string;
    organization: string | null;
    clinic: string | null;
    assignee: string | null;
    assigned_to: number | null;
    reporter_name: string | null;
    reporter_email: string | null;
    channel: string | null;
    opened_at: string | null;
    opened_by: string | null;
    first_response_due_at: string | null;
    resolution_due_at: string | null;
    first_responded_at: string | null;
    resolved_at: string | null;
    resolution_note: string | null;
    breached_first_response: boolean | null;
    breached_resolution: boolean | null;
  };
  events: Event[];
  allowedNext: Array<{ value: string; label: string }>;
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

const EVENT_LABEL: Record<string, string> = {
  opened: 'Opened',
  note: 'Internal note',
  reply: 'Reply to customer',
  status: 'Status changed',
  priority: 'Priority changed',
  assignment: 'Assignment changed',
};

export default function SupportTicketDetail({ ticket, events, allowedNext, agents, can }: Props) {
  const [comment, setComment] = React.useState('');
  const [internal, setInternal] = React.useState('1');
  const [note, setNote] = React.useState('');

  const post = (body: Record<string, string | number | null>) =>
    router.post(`/company/support/${ticket.id}/update`, body, { preserveScroll: true });

  const addComment = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      `/company/support/${ticket.id}/comment`,
      { body: comment, is_internal: internal === '1' },
      { preserveScroll: true, onSuccess: () => setComment('') },
    );
  };

  return (
    <OperationsShell title={ticket.reference}>
      <Head title={`${ticket.reference} | Support`} />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Support · {ticket.reference}
          </p>
          <h1 className="text-[30px] font-bold leading-[1.08] tracking-[-0.03em] text-ovs-crm-text-primary sm:text-[36px]">
            {ticket.subject}
          </h1>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <CrmStatusPill tone={STATUS_TONE[ticket.status] ?? 'neutral'}>{ticket.status_label}</CrmStatusPill>
            <CrmStatusPill tone={PRIORITY_TONE[ticket.priority] ?? 'neutral'}>{ticket.priority_label}</CrmStatusPill>
            {ticket.breached_resolution === true && <CrmStatusPill tone="bad">Past due</CrmStatusPill>}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={Building2} value={ticket.organization ?? '—'} caption="Account" />
          <CrmPageStat icon={User} value={ticket.assignee ?? 'Unassigned'} caption="Owner" />
          <CrmPageStat
            icon={Timer}
            value={ticket.resolution_due_at ?? 'No target'}
            caption="Resolution due"
          />
        </div>
      </div>

      <div className="mb-4">
        <CrmButton size="sm" icon={ArrowLeft} href="/company/support">
          All tickets
        </CrmButton>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div className="space-y-4">
          <CrmCard className="p-6">
            <h2 className="mb-3 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              What the customer reported
            </h2>
            <p className="whitespace-pre-wrap text-[13px] leading-relaxed text-ovs-crm-text-secondary">
              {ticket.body || 'Nothing was written down when this was opened.'}
            </p>
            <p className="mt-4 border-t border-ovs-crm-line pt-3 text-[11.5px] text-ovs-crm-text-muted">
              {ticket.reporter_name ?? 'Unknown reporter'}
              {ticket.reporter_email && <> · {ticket.reporter_email}</>}
              {ticket.opened_at && <> · opened {ticket.opened_at}</>}
              {ticket.opened_by && <> by {ticket.opened_by}</>}
            </p>
          </CrmCard>

          <CrmCard className="p-6">
            <h2 className="mb-4 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">History</h2>

            <ol className="space-y-3">
              {events.map((event) => (
                <li key={event.id} className="flex gap-3">
                  <span
                    className="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                    style={{ background: event.is_internal ? '#CBD5E1' : '#2FA96B' }}
                    aria-hidden
                  />
                  <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-2 text-[12.5px] font-medium text-ovs-crm-text-primary">
                      {EVENT_LABEL[event.type] ?? event.type}
                      {event.from && event.to && (
                        <span className="text-[11.5px] font-normal text-ovs-crm-text-muted">
                          {event.from} → {event.to}
                        </span>
                      )}
                      {!event.is_internal && <CrmStatusPill tone="good">Customer-visible</CrmStatusPill>}
                    </p>
                    {event.body && (
                      <p className="mt-1 whitespace-pre-wrap text-[12.5px] text-ovs-crm-text-secondary">{event.body}</p>
                    )}
                    <p className="mt-1 text-[11px] text-ovs-crm-text-muted">
                      {event.at}
                      {event.actor && <> · {event.actor}</>}
                    </p>
                  </div>
                </li>
              ))}
            </ol>

            {can.manage && (
              <form onSubmit={addComment} className="mt-5 flex flex-col gap-3 border-t border-ovs-crm-line pt-4">
                <CrmFormField label="Add to the ticket">
                  <CrmTextarea
                    rows={3}
                    required
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    placeholder="A note for the team, or a reply for the customer"
                  />
                </CrmFormField>

                <div className="flex flex-wrap items-end gap-3">
                  <div className="min-w-[200px] flex-1">
                    <CrmFormField
                      label="Visibility"
                      /* Only a reply stops the first-response clock. */
                      hint="A reply starts the customer's answer; an internal note does not."
                    >
                      <CrmSelect value={internal} onChange={(e) => setInternal(e.target.value)}>
                        <option value="1">Internal note</option>
                        <option value="0">Reply to customer</option>
                      </CrmSelect>
                    </CrmFormField>
                  </div>

                  <CrmButton tone="primary" type="submit" icon={MessageSquare}>
                    Add
                  </CrmButton>
                </div>
              </form>
            )}
          </CrmCard>
        </div>

        <div className="space-y-4">
          <CrmCard className="p-6">
            <h2 className="mb-4 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">The clock</h2>

            <dl className="space-y-3">
              {[
                ['First reply due', ticket.first_response_due_at, ticket.breached_first_response],
                ['First replied', ticket.first_responded_at, null],
                ['Resolution due', ticket.resolution_due_at, ticket.breached_resolution],
                ['Resolved', ticket.resolved_at, null],
              ].map(([label, value, breached]) => (
                <div key={String(label)} className="flex items-baseline justify-between gap-3">
                  <dt className="text-[12.5px] text-ovs-crm-text-secondary">{label as string}</dt>
                  <dd className="text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                    {/* "No target" is the honest answer where none is configured. */}
                    {(value as string | null) ?? 'No target'}
                    {breached === true && <span className="ml-1.5 text-ovs-crm-accent-coral-text">late</span>}
                  </dd>
                </div>
              ))}
            </dl>

            {ticket.resolution_note && (
              <p className="mt-4 border-t border-ovs-crm-line pt-3 text-[12px] text-ovs-crm-text-secondary">
                {ticket.resolution_note}
              </p>
            )}
          </CrmCard>

          {can.manage && (
            <CrmCard className="p-6">
              <h2 className="mb-4 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">Actions</h2>

              <div className="flex flex-col gap-3">
                <CrmFormField label="Owner">
                  <CrmSelect
                    value={ticket.assigned_to ? String(ticket.assigned_to) : ''}
                    onChange={(e) => post({ assigned_to: e.target.value ? Number(e.target.value) : null })}
                  >
                    <option value="">Unassigned</option>
                    {agents.map((agent) => (
                      <option key={agent.id} value={agent.id}>
                        {agent.name}
                      </option>
                    ))}
                  </CrmSelect>
                </CrmFormField>

                <CrmFormField label="Priority">
                  <CrmSelect value={ticket.priority} onChange={(e) => post({ priority: e.target.value })}>
                    {['low', 'normal', 'high', 'urgent'].map((priority) => (
                      <option key={priority} value={priority}>
                        {priority.charAt(0).toUpperCase() + priority.slice(1)}
                      </option>
                    ))}
                  </CrmSelect>
                </CrmFormField>

                {allowedNext.length > 0 && (
                  <>
                    <CrmFormField label="Note" hint="Kept against the change.">
                      <CrmTextarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
                    </CrmFormField>

                    <div className="flex flex-wrap gap-2">
                      {allowedNext.map((next) => (
                        <CrmButton
                          key={next.value}
                          size="sm"
                          tone={next.value === 'resolved' ? 'primary' : undefined}
                          onClick={() => post({ status: next.value, note: note || null })}
                        >
                          {next.label}
                        </CrmButton>
                      ))}
                    </div>
                  </>
                )}

                {allowedNext.length === 0 && (
                  <p className="text-[12px] text-ovs-crm-text-muted">
                    <Clock className="mr-1.5 inline h-3.5 w-3.5" aria-hidden />
                    This ticket is closed and cannot be moved further.
                  </p>
                )}
              </div>
            </CrmCard>
          )}
        </div>
      </div>
    </OperationsShell>
  );
}
