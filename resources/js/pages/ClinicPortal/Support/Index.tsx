import { useState, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ChevronRight, Plus, LifeBuoy } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Field, Input, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState } from '@/clinic/ui/states';
import { formatWhen, StatusChip } from './StatusChip';
import type { SupportTicketRow } from './types';

interface Props {
  tickets: { data: SupportTicketRow[]; current_page: number; last_page: number; total: number };
  /** `clinic`: every request of the clinic (the owner, settings managers); `mine`: the member's own. */
  scope: 'clinic' | 'mine';
  links: { store: string; show: string };
}

/**
 * OralCares support from the clinic: raise a request and follow the ones
 * already raised. Requests go to the support desk's own queue; the server
 * shows only this clinic's, and only a member's own unless they run it.
 */
export default function SupportIndex({ tickets, scope, links }: Props) {
  const [asking, setAsking] = useState(false);
  const empty = tickets.total === 0;

  return (
    <ClinicPortalShell title="Support">
      <PageHeader
        icon={LifeBuoy}
        title="Support"
        description={
          scope === 'clinic'
            ? "Ask the OralCares team for help. Every request your clinic has raised is listed here."
            : 'Ask the OralCares team for help. The requests you have raised are listed here.'
        }
        actions={
          <Button tone="primary" size="sm" icon={Plus} onClick={() => setAsking(true)}>
            New request
          </Button>
        }
      />

      <Card>
        {empty ? (
          <EmptyState
            title="No requests yet"
            description="When something isn't working or you need a hand, raise a request and the OralCares team will pick it up."
            action={
              <Button size="sm" icon={Plus} onClick={() => setAsking(true)}>
                New request
              </Button>
            }
          />
        ) : (
          <ul className="divide-y divide-cx-line" aria-label="Support requests">
            {tickets.data.map((ticket) => (
              <li key={ticket.id}>
                <Link
                  href={links.show.replace('__ID__', String(ticket.id))}
                  className="flex items-center gap-4 px-5 py-4 hover:bg-cx-nav-bg/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cx-nav-teal"
                >
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[14px] font-semibold text-cx-nav-ink">{ticket.subject}</p>
                    <p className="mt-0.5 truncate text-[12.5px] text-cx-nav-muted">
                      {[ticket.reference, scope === 'clinic' ? ticket.raised_by : null, `Updated ${formatWhen(ticket.updated_at)}`].filter(Boolean).join(' · ')}
                    </p>
                  </div>
                  <StatusChip status={ticket.status} />
                  <ChevronRight className="h-[18px] w-[18px] shrink-0 text-cx-nav-muted" strokeWidth={1.75} aria-hidden="true" />
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {tickets.last_page > 1 && (
        <nav aria-label="Pages" className="mt-4 flex items-center justify-between text-[13px] text-cx-nav-muted">
          <span>
            Page {tickets.current_page} of {tickets.last_page}
          </span>
          <span className="flex gap-2">
            <Button size="sm" disabled={tickets.current_page <= 1} onClick={() => router.get(window.location.pathname, { page: tickets.current_page - 1 }, { preserveScroll: true })}>
              Newer
            </Button>
            <Button size="sm" disabled={tickets.current_page >= tickets.last_page} onClick={() => router.get(window.location.pathname, { page: tickets.current_page + 1 }, { preserveScroll: true })}>
              Older
            </Button>
          </span>
        </nav>
      )}

      <NewRequestDialog open={asking} onOpenChange={setAsking} action={links.store} />
    </ClinicPortalShell>
  );
}

function NewRequestDialog({ open, onOpenChange, action }: { open: boolean; onOpenChange: (open: boolean) => void; action: string }) {
  const form = useForm({ subject: '', body: '' });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post(action, { onSuccess: () => form.reset() });
  };

  return (
    <Modal
      open={open}
      onOpenChange={(next) => {
        onOpenChange(next);
        if (!next) {
          form.clearErrors();
        }
      }}
      title="New support request"
      description="Tell the OralCares team what you need. Include what you were doing and what you expected to happen."
      footer={
        <>
          <Button onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button tone="primary" type="submit" form="support-request" loading={form.processing} loadingLabel="Sending">
            Send request
          </Button>
        </>
      }
    >
      <form id="support-request" onSubmit={submit} className="space-y-4" noValidate>
        <Field label="Subject" htmlFor="support-subject" error={form.errors.subject} required>
          <Input
            id="support-subject"
            value={form.data.subject}
            maxLength={160}
            invalid={Boolean(form.errors.subject)}
            aria-describedby={form.errors.subject ? 'support-subject-error' : undefined}
            onChange={(event) => form.setData('subject', event.target.value)}
            autoFocus
          />
        </Field>
        <Field label="What do you need help with?" htmlFor="support-body" error={form.errors.body} hint="Up to 5,000 characters." required>
          <Textarea
            id="support-body"
            rows={6}
            value={form.data.body}
            maxLength={5000}
            invalid={Boolean(form.errors.body)}
            aria-describedby={form.errors.body ? 'support-body-error' : 'support-body-hint'}
            onChange={(event) => form.setData('body', event.target.value)}
          />
        </Field>
      </form>
    </Modal>
  );
}
