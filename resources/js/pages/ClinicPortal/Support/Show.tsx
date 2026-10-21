import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Card } from '@/clinic/ui/Card';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { formatWhen, StatusChip } from './StatusChip';
import type { SupportHistoryEntry, SupportTicket } from './types';

/**
 * One support request: what the clinic asked, where it stands, and what the
 * OralCares team has said. The server sends only the team's replies and the
 * status changes; its internal notes stay on the desk.
 */
export default function SupportShow({ ticket, history, links }: { ticket: SupportTicket; history: SupportHistoryEntry[]; links: { index: string } }) {
  return (
    <ClinicPortalShell title={ticket.reference}>
      <PageHeader
        eyebrow={
          <Link
            href={links.index}
            className="inline-flex items-center gap-1.5 rounded-md text-[13px] font-medium text-cx-nav-muted hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
          >
            <ArrowLeft className="h-4 w-4" strokeWidth={1.75} aria-hidden="true" /> All requests
          </Link>
        }
        title={ticket.subject}
        description={[ticket.reference, ticket.raised_by ? `Raised by ${ticket.raised_by}` : null, formatWhen(ticket.created_at)].filter(Boolean).join(' · ')}
        actions={<StatusChip status={ticket.status} className="text-[13px]" />}
      />

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <Card className="p-5">
          <h2 className="text-[14.5px] font-semibold text-cx-nav-ink">Your request</h2>
          <p className="mt-2 whitespace-pre-line break-words text-[14px] leading-relaxed text-cx-nav-ink">{ticket.body || 'No description was given.'}</p>
        </Card>

        <Card className="p-5">
          <h2 className="text-[14.5px] font-semibold text-cx-nav-ink">History</h2>
          <ol className="mt-3 space-y-4">
            {history.map((entry) => (
              <li key={entry.id} className="relative border-l-2 border-cx-nav-line pl-4">
                <p className="text-[13px] font-semibold text-cx-nav-ink">
                  {entry.kind === 'opened' && 'Request raised'}
                  {entry.kind === 'reply' && 'OralCares support replied'}
                  {entry.kind === 'status' && <>Status changed to {entry.status?.label ?? 'a new state'}</>}
                </p>
                {entry.body && <p className="mt-1 whitespace-pre-line break-words text-[13px] text-cx-nav-ink">{entry.body}</p>}
                <p className="mt-0.5 text-[12px] text-cx-nav-muted">{formatWhen(entry.at)}</p>
              </li>
            ))}
          </ol>
          <p className="mt-5 border-t border-cx-line pt-3 text-[12.5px] text-cx-nav-muted">
            Replies from the OralCares team appear here. To add more detail, raise a new request and mention {ticket.reference}.
          </p>
        </Card>
      </div>
    </ClinicPortalShell>
  );
}
