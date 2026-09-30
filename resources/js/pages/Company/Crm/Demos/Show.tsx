import React from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Check, X, RefreshCw, Phone, Mail, User, Ban } from 'lucide-react';
import {
  CrmShell,
  CrmPageHead,
  CrmCard,
  CrmSection,
  CrmStatusPill,
  CrmButton,
  CrmIconButton,
  CrmAvatar,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  type CrmStatusTone,
} from '../components';

/**
 * Demo detail.
 *
 * Replaces the 560px master-detail sheet the demos board used to slide over
 * itself. A demo is a scheduled event people forward, reschedule and chase, so
 * it needs an address; a panel that only exists while you are standing on the
 * board cannot be sent to anyone.
 */

interface Demo {
  id: number;
  scheduled_at: string | null;
  duration_minutes: number | null;
  status: string | { value: string };
  meeting_link: string | null;
  location: string | null;
  agenda: string | null;
  outcome: string | null;
  notes: string | null;
  cancellation_reason: string | null;
  cancelled_at: string | null;
  cancelled_by?: { id: number; name: string } | null;
  lead?: {
    id: number;
    company_name: string;
    contact_name?: string | null;
    phone?: string | null;
    email?: string | null;
  } | null;
  presenter?: { id: number; name: string } | null;
}

/** A stored timestamp is not something to show a person. */
const appointmentTime = (value: string): string => {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;

  return d.toLocaleString('en-IN', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
  });
};

/** Which service the call is on, without making anyone read a URL. */
const meetingHost = (url: string): string => {
  try {
    return new URL(url).hostname.replace(/^www./, '');
  } catch {
    return '';
  }
};

const STATUS_TONE: Record<string, CrmStatusTone> = {
  requested: 'neutral',
  scheduled: 'warn',
  confirmed: 'warn',
  completed: 'good',
  no_show: 'bad',
  cancelled: 'neutral',
  rescheduled: 'warn',
};

const readStatus = (status: Demo['status']): string =>
  (typeof status === 'object' && status !== null ? status.value : String(status || 'requested')).toLowerCase();

const humanize = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

export default function DemoShow({ demo }: { demo: Demo }) {
  const status = readStatus(demo.status);
  const isOpen = !['completed', 'no_show', 'cancelled'].includes(status);

  /*
   * Only the actions that genuinely take no input are posted bare.
   *
   * "Confirm" and "No-show" are complete on their own. "Reschedule" needs a new
   * time and "Mark complete" needs an outcome, and both were posted with an
   * empty body against validators that require those fields — so two of the
   * four buttons on this page failed every time they were pressed. They now
   * collect what the endpoint asks for, in the same shape the demos board uses.
   */
  const act = (action: string, data: Record<string, string> = {}) =>
    router.post(`/company/crm/demos/${demo.id}/${action}`, data, { preserveScroll: true });

  const [rescheduling, setRescheduling] = React.useState(false);
  const [completing, setCompleting] = React.useState(false);
  const [cancelling, setCancelling] = React.useState(false);

  const [rescheduleAt, setRescheduleAt] = React.useState('');
  const [meetingLink, setMeetingLink] = React.useState(demo.meeting_link ?? '');

  const [outcome, setOutcome] = React.useState('interested');
  const [feedback, setFeedback] = React.useState('');
  const [nextAction, setNextAction] = React.useState('');

  const [cancellationReason, setCancellationReason] = React.useState('');

  const submitReschedule = (e: React.FormEvent) => {
    e.preventDefault();
    act('reschedule', { scheduled_at: rescheduleAt, meeting_link: meetingLink });
    setRescheduling(false);
  };

  const submitComplete = (e: React.FormEvent) => {
    e.preventDefault();
    act('complete', { outcome, feedback, next_action: nextAction });
    setCompleting(false);
  };

  const submitCancel = (e: React.FormEvent) => {
    e.preventDefault();
    act('cancel', { cancellation_reason: cancellationReason });
    setCancelling(false);
  };

  return (
    <CrmShell title={demo.lead?.company_name ?? 'Demo'}>
      <CrmIconButton
        icon={ArrowLeft}
        label={demo.lead ? 'Back to deal' : 'Back to My Work'}
        tone="ghost"
        href={demo.lead ? route('company.crm.leads.show', demo.lead.id) : route('company.crm.my-workspace')}
        className="mb-2"
      />

      <CrmPageHead
        eyebrow="Sales"
        title={demo.lead?.company_name ?? 'Product demo'}
        actions={
          isOpen && (
            <>
              {status !== 'confirmed' && (
                <CrmButton icon={Check} onClick={() => act('confirm')}>Confirm</CrmButton>
              )}
              <CrmButton icon={RefreshCw} onClick={() => setRescheduling(true)}>Reschedule</CrmButton>
              <CrmButton icon={X} onClick={() => act('no-show')}>No-show</CrmButton>
              <CrmButton tone="primary" icon={Check} onClick={() => setCompleting(true)}>
                Mark complete
              </CrmButton>
              <CrmButton icon={Ban} onClick={() => setCancelling(true)}>Cancel demo</CrmButton>
            </>
          )
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        <CrmCard step={0} className="lg:col-span-8">
          <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                {demo.scheduled_at ? appointmentTime(demo.scheduled_at) : 'Not yet scheduled'}
              </h2>
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                {[
                  demo.duration_minutes ? `${demo.duration_minutes} minutes` : null,
                  demo.location,
                  demo.presenter ? `Presented by ${demo.presenter.name}` : null,
                ]
                  .filter(Boolean)
                  .join(' · ') || 'No schedule details recorded'}
              </p>
            </div>
            <CrmStatusPill tone={STATUS_TONE[status] ?? 'neutral'}>{humanize(status)}</CrmStatusPill>
          </div>

          {demo.meeting_link && (
            <a
              href={demo.meeting_link}
              target="_blank"
              rel="noreferrer"
              className="crm-lift mb-4 flex items-center gap-3 rounded-[16px] bg-ovs-crm-surface-inset p-3 hover:bg-white hover:shadow-ovs-crmCard"
            >
              <CalendarDays className="h-4 w-4 shrink-0 text-ovs-crm-text-secondary" aria-hidden="true" />
              <span className="min-w-0 flex-1 truncate text-[13px] text-ovs-crm-text-primary">
                Join the meeting
                <span className="ml-1.5 text-[11.5px] text-ovs-crm-text-muted">
                  {meetingHost(demo.meeting_link)}
                </span>
              </span>
              <span className="shrink-0 text-[11.5px] font-medium text-ovs-crm-text-secondary">Open</span>
            </a>
          )}

          {demo.agenda && (
            <div className="mb-4">
              <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Agenda</p>
              <p className="text-[13px] leading-relaxed text-ovs-crm-text-secondary">{demo.agenda}</p>
            </div>
          )}

          {demo.outcome && (
            <div className="mb-4">
              <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Outcome</p>
              <p className="text-[13px] leading-relaxed text-ovs-crm-text-secondary">{demo.outcome}</p>
            </div>
          )}

          {demo.notes && (
            <p className="border-t border-ovs-crm-border pt-4 text-[13px] leading-relaxed text-ovs-crm-text-secondary">
              {demo.notes}
            </p>
          )}

          {status === 'cancelled' && (
            <div className="mt-4 rounded-[14px] border border-ovs-crm-border bg-ovs-crm-surface-inset p-3">
              <p className="mb-1 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Cancellation reason
              </p>
              <p className="text-[13px] leading-relaxed text-ovs-crm-text-secondary">
                {demo.cancellation_reason ?? 'No reason recorded.'}
              </p>
              <p className="mt-1.5 text-[11.5px] text-ovs-crm-text-muted">
                {[
                  demo.cancelled_at ? appointmentTime(demo.cancelled_at) : null,
                  demo.cancelled_by ? `by ${demo.cancelled_by.name}` : null,
                ]
                  .filter(Boolean)
                  .join(' · ')}
              </p>
            </div>
          )}
        </CrmCard>

        <CrmSection step={1} title="Lead" description="Who this demo is for" className="lg:col-span-4">
          {demo.lead ? (
            <>
              <Link
                href={`/company/crm/leads/${demo.lead.id}`}
                className="crm-lift flex items-center gap-3 rounded-[16px] bg-ovs-crm-surface-inset p-3 hover:bg-white hover:shadow-ovs-crmCard"
              >
                <CrmAvatar name={demo.lead.company_name} size="sm" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                    {demo.lead.company_name}
                  </p>
                  <p className="truncate text-[11.5px] text-ovs-crm-text-muted">Open the lead</p>
                </div>
              </Link>

              <div className="mt-3 space-y-2 text-[11.5px]">
                {demo.lead.contact_name && (
                  <p className="flex items-center gap-2 text-ovs-crm-text-secondary">
                    <User className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                    {demo.lead.contact_name}
                  </p>
                )}
                {demo.lead.phone && (
                  <a href={`tel:${demo.lead.phone}`} className="flex items-center gap-2 text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary">
                    <Phone className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                    {demo.lead.phone}
                  </a>
                )}
                {demo.lead.email && (
                  <a href={`mailto:${demo.lead.email}`} className="flex items-center gap-2 truncate text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary">
                    <Mail className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                    {demo.lead.email}
                  </a>
                )}
              </div>
            </>
          ) : (
            <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">
              This demo is not linked to a lead.
            </p>
          )}
        </CrmSection>
      </div>

      <CrmModal
        open={rescheduling}
        onClose={() => setRescheduling(false)}
        title="Reschedule this demo"
        description="The new time, and where it happens."
        footer={
          <>
            <CrmButton onClick={() => setRescheduling(false)}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="demo-reschedule">
              Reschedule
            </CrmButton>
          </>
        }
      >
        <form id="demo-reschedule" onSubmit={submitReschedule} className="flex flex-col gap-3.5">
          <CrmFormField label="New time" required>
            <CrmInput
              type="datetime-local"
              required
              value={rescheduleAt}
              onChange={(e) => setRescheduleAt(e.target.value)}
            />
          </CrmFormField>

          <CrmFormField label="Meeting link">
            <CrmInput value={meetingLink} onChange={(e) => setMeetingLink(e.target.value)} />
          </CrmFormField>
        </form>
      </CrmModal>

      <CrmModal
        open={completing}
        onClose={() => setCompleting(false)}
        title="Complete this demo"
        description="What came of it."
        footer={
          <>
            <CrmButton onClick={() => setCompleting(false)}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="demo-complete">
              Mark complete
            </CrmButton>
          </>
        }
      >
        <form id="demo-complete" onSubmit={submitComplete} className="flex flex-col gap-3.5">
          <CrmFormField label="Outcome" required hint="This is what moves the deal to its next stage.">
            <CrmSelect required value={outcome} onChange={(e) => setOutcome(e.target.value)}>
              <option value="highly_interested">Very interested</option>
              <option value="interested">Interested</option>
              <option value="trial_requested">Wants a trial</option>
              <option value="quotation_requested">Wants a quotation</option>
              <option value="needs_followup">Needs another conversation</option>
              <option value="no_decision">No decision yet</option>
              <option value="not_interested">Not interested</option>
              <option value="lost">Lost</option>
            </CrmSelect>
          </CrmFormField>

          <CrmFormField label="What happens next">
            <CrmInput value={nextAction} onChange={(e) => setNextAction(e.target.value)} />
          </CrmFormField>

          <CrmFormField label="Feedback" hint="What they said, in their words.">
            <CrmTextarea rows={3} value={feedback} onChange={(e) => setFeedback(e.target.value)} />
          </CrmFormField>
        </form>
      </CrmModal>

      <CrmModal
        open={cancelling}
        onClose={() => setCancelling(false)}
        title="Cancel this demo"
        description="This keeps the record — it marks it cancelled, with a reason."
        footer={
          <>
            <CrmButton onClick={() => setCancelling(false)}>Keep demo</CrmButton>
            <CrmButton tone="primary" type="submit" form="demo-cancel">
              Cancel demo
            </CrmButton>
          </>
        }
      >
        <form id="demo-cancel" onSubmit={submitCancel} className="flex flex-col gap-3.5">
          <CrmFormField label="Cancellation reason" required>
            <CrmTextarea
              rows={3}
              required
              value={cancellationReason}
              onChange={(e) => setCancellationReason(e.target.value)}
            />
          </CrmFormField>
        </form>
      </CrmModal>
    </CrmShell>
  );
}
