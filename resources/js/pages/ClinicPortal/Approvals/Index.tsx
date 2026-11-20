import { useState, type FormEvent } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Check, HelpCircle, MessageSquareReply, Undo2, X, ShieldCheck } from 'lucide-react';
import { useOwnerModeStepUp } from '@/clinic/ownerModeStepUp';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { StatusBadge } from '@/clinic/ui/badges';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Field, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { LinkTabs } from '@/clinic/ui/Tabs';
import { EmptyState } from '@/clinic/ui/states';
import type { Paginated } from '@/clinic/types';
import { cn } from '@/lib/utils';

interface ThreadEntry {
  at: string;
  user_id: number;
  name: string;
  event: string;
  note: string | null;
}

interface ApprovalRequest {
  id: number;
  action: string;
  action_label: string;
  target: string;
  patient: { id: number; name: string; uhid: string | null; url: string } | null;
  requester: string | null;
  approver: string | null;
  reason: string;
  note: string | null;
  status: 'pending' | 'clarification_requested' | 'executed' | 'rejected' | 'cancelled' | 'expired' | 'approved';
  direct: boolean;
  thread: ThreadEntry[];
  created_at: string | null;
  expires_at: string | null;
  decided_at: string | null;
  can: { decide: boolean; respond: boolean; cancel: boolean };
}

type Links = Record<'approve' | 'reject' | 'clarify' | 'respond' | 'cancel', string>;

interface Props {
  requests: Paginated<ApprovalRequest>;
  tab: 'open' | 'decided';
  counts: { open: number; decided: number };
  /** The clinic's owner: sees every request and decides; anyone else sees their own. */
  owner: boolean;
  ownerMode: boolean;
  links: Links;
}

/** The state, in the shared state colours, always with its word. */
const STATUS: Record<ApprovalRequest['status'], { tone: string; label: string }> = {
  pending: { tone: 'pending', label: 'Waiting for the owner' },
  clarification_requested: { tone: 'waiting', label: 'Question for the requester' },
  executed: { tone: 'completed', label: 'Approved' },
  approved: { tone: 'completed', label: 'Approved' },
  rejected: { tone: 'cancelled', label: 'Rejected' },
  cancelled: { tone: 'withdrawn', label: 'Withdrawn' },
  expired: { tone: 'expired', label: 'Expired' },
};

const EVENTS: Record<string, string> = {
  requested: 'asked',
  executed: 'carried it out',
  approved: 'approved',
  rejected: 'rejected',
  clarification_requested: 'asked a question',
  responded: 'answered',
  cancelled: 'withdrew it',
};

function formatWhen(iso: string | null): string {
  if (!iso) {
    return '';
  }

  return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }).format(new Date(iso));
}

function daysLeft(iso: string | null): number | null {
  if (!iso) {
    return null;
  }

  return Math.max(0, Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000));
}

type Dialog = { kind: 'approve' | 'reject' | 'clarify' | 'respond' | 'cancel'; request: ApprovalRequest } | null;

/**
 * Approvals: destructive changes the team has asked for (removing a document,
 * voiding a bill or a consultation, merging a duplicate patient). The
 * owner decides them in Owner Mode; anyone else follows, answers and can
 * withdraw their own. The server decides who sees and may do what; this page
 * only offers what each request's `can` says.
 */
export default function ApprovalsIndex({ requests, tab, counts, owner, ownerMode, links }: Props) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [dialog, setDialog] = useState<Dialog>(null);
  const stepUp = useOwnerModeStepUp();
  const empty = requests.total === 0;

  return (
    <ClinicPortalShell title="Approvals">
      <PageHeader
        icon={ShieldCheck}
        title="Approvals"
        description={
          owner
            ? 'Changes your team has asked you to make. Nothing is removed or voided until you approve it.'
            : 'Changes you have asked the owner to make, and what they decided.'
        }
      />

      {owner && !ownerMode && (
        <Notice tone="info" title="Deciding needs Owner Mode" className="mb-4">
          When you approve, reject or ask a question, you will be asked for your password first.
        </Notice>
      )}

      {errors.governance && (
        <Notice tone="danger" title="That could not be done" className="mb-4">
          {errors.governance}
        </Notice>
      )}

      <LinkTabs
        label="Requests"
        className="mb-4"
        tabs={(['open', 'decided'] as const).map((key) => ({
          key,
          label: key === 'open' ? 'Open' : 'Decided',
          href: `${window.location.pathname}${key === 'decided' ? '?tab=decided' : ''}`,
          active: tab === key,
          count: counts[key],
        }))}
      />

      <Card>
        {empty ? (
          <EmptyState
            title={tab === 'open' ? (owner ? 'Nothing is waiting for you' : 'You have no open requests') : 'Nothing decided yet'}
            description={
              tab === 'open'
                ? owner
                  ? 'When someone asks to remove or void a record, it appears here for your decision.'
                  : 'When you ask the owner to remove or void a record, you can follow it here.'
                : 'Approved, rejected, withdrawn and expired requests are kept here.'
            }
          />
        ) : (
          <ul className="divide-y divide-cx-line" aria-label={tab === 'open' ? 'Open requests' : 'Decided requests'}>
            {requests.data.map((request) => (
              <RequestRow key={request.id} request={request} owner={owner} busy={stepUp.busy} onAct={(kind) => setDialog({ kind, request })} />
            ))}
          </ul>
        )}
      </Card>

      {requests.last_page > 1 && (
        <nav aria-label="Pages" className="mt-4 flex items-center justify-between text-[13px] text-cx-nav-muted">
          <span>
            Page {requests.current_page} of {requests.last_page}
          </span>
          <span className="flex gap-2">
            <Button size="sm" disabled={requests.current_page <= 1} onClick={() => router.get(window.location.pathname, { tab, page: requests.current_page - 1 }, { preserveScroll: true })}>
              Newer
            </Button>
            <Button size="sm" disabled={requests.current_page >= requests.last_page} onClick={() => router.get(window.location.pathname, { tab, page: requests.current_page + 1 }, { preserveScroll: true })}>
              Older
            </Button>
          </span>
        </nav>
      )}

      {dialog && (
        <DecisionDialog
          dialog={dialog}
          links={links}
          busy={stepUp.busy}
          onClose={() => setDialog(null)}
          submit={(url, data) => stepUp.submit({ method: 'post', url, data, onSuccess: () => setDialog(null) })}
        />
      )}
      {stepUp.dialog}
    </ClinicPortalShell>
  );
}

function RequestRow({ request, owner, busy, onAct }: { request: ApprovalRequest; owner: boolean; busy: boolean; onAct: (kind: NonNullable<Dialog>['kind']) => void }) {
  const status = STATUS[request.status] ?? { tone: request.status, label: request.status };
  const remaining = request.can.decide || request.can.respond ? daysLeft(request.expires_at) : null;
  const lastQuestion = [...request.thread].reverse().find((entry) => entry.event === 'clarification_requested');

  return (
    <li className="px-5 py-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="text-[14px] font-semibold text-cx-nav-ink">
            {request.action_label}: <span className="font-medium">{request.target}</span>
          </p>
          <p className="mt-0.5 text-[12.5px] text-cx-nav-muted">
            {[owner && request.requester ? `Asked by ${request.requester}` : null, formatWhen(request.created_at), request.direct ? 'Done directly by the owner' : null]
              .filter(Boolean)
              .join(' · ')}
            {request.patient && (
              <>
                {' · '}
                <Link href={request.patient.url} className="font-medium text-cx-nav-ink underline-offset-2 hover:underline">
                  {request.patient.name}
                  {request.patient.uhid ? ` (${request.patient.uhid})` : ''}
                </Link>
              </>
            )}
          </p>
        </div>
        <StatusBadge status={status.tone} label={status.label} />
      </div>

      <p className="mt-2 text-[13.5px] text-cx-text">
        <span className="font-semibold">Why: </span>
        {request.reason}
      </p>
      {request.note && <p className="mt-1 text-[13px] text-cx-text-2">{request.note}</p>}

      {request.status === 'clarification_requested' && lastQuestion?.note && (
        <Notice tone="warning" title={owner ? 'You asked' : 'The owner asks'} className="mt-3">
          {lastQuestion.note}
        </Notice>
      )}

      {request.thread.length > 1 && (
        <details className="mt-3 text-[12.5px] text-cx-nav-muted">
          <summary className="cursor-pointer font-medium text-cx-nav-ink">History ({request.thread.length})</summary>
          <ol className="mt-2 space-y-1.5 border-l border-cx-line pl-3">
            {request.thread.map((entry, index) => (
              <li key={index}>
                <span className="font-medium text-cx-nav-ink">{entry.name}</span> {EVENTS[entry.event] ?? entry.event} · {formatWhen(entry.at)}
                {entry.note && entry.event !== 'requested' && <span className="block text-cx-text-2">{entry.note}</span>}
              </li>
            ))}
          </ol>
        </details>
      )}

      {(request.can.decide || request.can.respond || request.can.cancel) && (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          {request.can.decide && (
            <>
              <Button size="sm" tone="primary" icon={Check} disabled={busy} onClick={() => onAct('approve')}>
                Approve
              </Button>
              <Button size="sm" icon={X} disabled={busy} onClick={() => onAct('reject')}>
                Reject
              </Button>
              <Button size="sm" tone="ghost" icon={HelpCircle} disabled={busy} onClick={() => onAct('clarify')}>
                Ask a question
              </Button>
            </>
          )}
          {request.can.respond && (
            <Button size="sm" tone="primary" icon={MessageSquareReply} disabled={busy} onClick={() => onAct('respond')}>
              Answer
            </Button>
          )}
          {request.can.cancel && (
            <Button size="sm" tone="ghost" icon={Undo2} disabled={busy} onClick={() => onAct('cancel')}>
              Withdraw
            </Button>
          )}
          {remaining !== null && (
            <span className="text-[12px] text-cx-nav-muted">{remaining === 0 ? 'Expires today' : `Expires in ${remaining} ${remaining === 1 ? 'day' : 'days'}`}</span>
          )}
        </div>
      )}
    </li>
  );
}

const DIALOGS: Record<NonNullable<Dialog>['kind'], { title: string; field?: { name: string; label: string; required: boolean; hint?: string }; confirm: string; tone: 'primary' | 'danger' | 'secondary' }> = {
  approve: { title: 'Approve and carry out', field: { name: 'note', label: 'Note (optional)', required: false }, confirm: 'Approve', tone: 'primary' },
  reject: { title: 'Reject this request', field: { name: 'reason', label: 'Why are you rejecting it?', required: true }, confirm: 'Reject', tone: 'danger' },
  clarify: { title: 'Ask the requester', field: { name: 'question', label: 'Your question', required: true, hint: 'The request waits for their answer.' }, confirm: 'Send question', tone: 'primary' },
  respond: { title: 'Answer the owner', field: { name: 'answer', label: 'Your answer', required: true }, confirm: 'Send answer', tone: 'primary' },
  cancel: { title: 'Withdraw this request', confirm: 'Withdraw', tone: 'danger' },
};

function DecisionDialog({ dialog, links, busy, onClose, submit }: {
  dialog: NonNullable<Dialog>;
  links: Links;
  busy: boolean;
  onClose: () => void;
  submit: (url: string, data: Record<string, unknown>) => void;
}) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const config = DIALOGS[dialog.kind];
  const [text, setText] = useState('');
  const field = config.field;
  // The server's own checks (a reason too short, a request already decided) arrive as `governance`.
  const fieldError = (field ? errors[field.name] : undefined) ?? errors.governance;

  const send = (event: FormEvent) => {
    event.preventDefault();
    submit(links[dialog.kind].replace('__ID__', String(dialog.request.id)), field ? { [field.name]: text } : {});
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && !busy && onClose()}
      size="sm"
      title={config.title}
      description={
        dialog.kind === 'approve'
          ? `${dialog.request.action_label}: ${dialog.request.target}. This is carried out as soon as you approve.`
          : dialog.kind === 'cancel'
            ? 'The owner will no longer see it. You can ask again later if it is still needed.'
            : `${dialog.request.action_label}: ${dialog.request.target}`
      }
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button tone={config.tone} type="submit" form="approval-decision" loading={busy} disabled={Boolean(field?.required) && text.trim() === ''}>
            {config.confirm}
          </Button>
        </>
      }
    >
      <form id="approval-decision" onSubmit={send} noValidate>
        {!field && fieldError && (
          <Notice tone="danger" className="mb-3">
            {fieldError}
          </Notice>
        )}
        {field && (
          <Field label={field.label} htmlFor="approval-text" error={fieldError} hint={field.hint} required={field.required}>
            <Textarea
              id="approval-text"
              rows={4}
              maxLength={2000}
              value={text}
              invalid={Boolean(fieldError)}
              onChange={(event) => setText(event.target.value)}
              autoFocus
            />
          </Field>
        )}
      </form>
    </Modal>
  );
}
