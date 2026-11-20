import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Eye, History, MessageCircle, Pencil, Printer, ScrollText, ShieldCheck, Trash2, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Modal } from '@/clinic/ui/Modal';
import type { Patient360Props } from '../../types';
import { useEndpoint } from '../store';
import { BTN_SM, Card, Empty, FilterSelect, Pager, Pending, Pill, Row, Split, day, humanize, type Paged, type Tone } from '../ui';

type TimelineRow = { id: number; type: string; category: string; title: string; description: string | null; at: string | null; actor: string | null; open_url: string | null };
type TimelinePage = Paged<TimelineRow> & { categories: string[] };
type MessageRow = { id: number; kind: string; channel: string; status: string; scheduled_for: string | null; sent_at: string | null; created_at: string | null; failed_reason: string | null; text: string | null };
type ShareOption = { kind: string; id: number; label: string; detail: string; preview: string; blocked: string | null };
type MessagesPage = Paged<MessageRow> & { provider_connected: boolean; share?: { url: string; options: ShareOption[] } };
type RequestRow = { id: number; action: string; target: string; reason: string; status: string; requester: string | null; approver: string | null; created_at: string | null; decided_at: string | null; last_note: string | null };
type AuditRow = { id: number; event: string; label: string; actor: string | null; subject: string | null; ip: string | null; at: string | null };

export const CATEGORY_TONE: Record<string, Tone> = {
  registration: 'teal',
  record: 'slate',
  appointment: 'blue',
  check_in: 'teal',
  consultation: 'blue',
  prescription: 'mint',
  investigation: 'violet',
  treatment: 'violet',
  dental: 'blue',
  physio: 'violet',
  lab: 'amber',
  document: 'blue',
  billing: 'mint',
  communication: 'teal',
  follow_up: 'amber',
  governance: 'rose',
};
export const CATEGORY_LABEL: Record<string, string> = {
  registration: 'Patient',
  record: 'Profile',
  appointment: 'Appointments',
  check_in: 'Visits',
  consultation: 'Clinical',
  prescription: 'Prescriptions',
  investigation: 'Investigations',
  treatment: 'Cases & plans',
  dental: 'Dental',
  physio: 'Physio',
  lab: 'Lab',
  document: 'Documents',
  billing: 'Billing',
  communication: 'Messages',
  follow_up: 'Follow-ups',
  governance: 'Requests',
};
const DOT: Record<Tone, string> = { teal: 'bg-[#0E9F87]', blue: 'bg-[#2B6FD8]', violet: 'bg-[#6B48DE]', amber: 'bg-[#E9821A]', rose: 'bg-[#E5484D]', mint: 'bg-[#12A877]', slate: 'bg-[#64748B]' };

function Activity({ endpoint }: { endpoint: string }) {
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState<string | null>(null);
  const query: Record<string, string | number> = {};
  if (page > 1) {
    query.page = page;
  }
  if (category) {
    query.category = category;
  }
  const state = useEndpoint<TimelinePage>(endpoint, Object.keys(query).length ? query : undefined);
  const base = useEndpoint<TimelinePage>(endpoint);
  const categories = base.data?.categories ?? [];
  const groups = (state.data?.data ?? []).reduce<{ day: string; rows: TimelineRow[] }[]>((list, row) => {
    const label = day(row.at) ?? 'Undated';
    const last = list[list.length - 1];
    if (last && last.day === label) {
      last.rows.push(row);
    } else {
      list.push({ day: label, rows: [row] });
    }

    return list;
  }, []);

  return (
    <Card
      title="Activity"
      caption="Everything that happened on this record, newest first"
      icon={History}
      tone="violet"
      action={
        categories.length > 1 ? (
          <FilterSelect
            label="Show activity"
            value={category ?? 'all'}
            onChange={(value) => {
              setCategory(value === 'all' ? null : value);
              setPage(1);
            }}
            options={[{ value: 'all', label: 'All activity' }, ...categories.map((value) => ({ value, label: CATEGORY_LABEL[value] ?? humanize(value), tone: CATEGORY_TONE[value] }))]}
          />
        ) : undefined
      }
    >
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : groups.length === 0 ? (
        <Empty>Nothing recorded yet.</Empty>
      ) : (
        <div className={cn('space-y-5', state.loading && 'opacity-60')}>
          {groups.map((group) => (
            <section key={group.day}>
              <p className="mb-2 text-[11.5px] font-semibold uppercase tracking-wide text-cx-text-3">{group.day}</p>
              <ol className="relative space-y-3 border-l border-cx-line pl-5">
                {group.rows.map((row) => {
                  const tone = CATEGORY_TONE[row.category] ?? 'slate';

                  return (
                    <li key={row.id} className="relative">
                      <span className={cn('absolute -left-[26px] top-4 h-2.5 w-2.5 rounded-full ring-4 ring-white', DOT[tone])} aria-hidden="true" />
                      <div className="rounded-2xl border border-[#EDF1F7] bg-[#FBFCFE] px-3.5 py-3">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                          <p className="flex flex-wrap items-center gap-2 text-[13.5px] font-semibold text-cx-nav-ink">
                            {row.title}
                            <Pill tone={tone}>{CATEGORY_LABEL[row.category] ?? humanize(row.category)}</Pill>
                          </p>
                          <span className="shrink-0 text-[11.5px] tabular-nums text-cx-text-3">{row.at ? new Date(row.at).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }) : ''}</span>
                        </div>
                        {row.description && <p className="mt-1 text-[12.5px] text-cx-text-2 [overflow-wrap:anywhere]">{row.description}</p>}
                        <div className="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-[12px]">
                          <span className="text-cx-text-3">{row.actor ? `By ${row.actor}` : 'Recorded by the system'}</span>
                          {row.open_url && (
                            <Link href={row.open_url} className="font-semibold text-cx-primary hover:underline">
                              Open record
                            </Link>
                          )}
                        </div>
                      </div>
                    </li>
                  );
                })}
              </ol>
            </section>
          ))}
        </div>
      )}
      {state.data && <Pager page={state.data.meta.page} last={state.data.meta.last_page} onPage={setPage} busy={state.loading} />}
    </Card>
  );
}

const MESSAGE_TONE: Record<string, Tone> = { sent: 'mint', simulated: 'slate', queued: 'blue', scheduled: 'blue', sending: 'blue', failed: 'rose', withdrawn: 'slate' };

function Messages({ endpoint }: { endpoint: string }) {
  const state = useEndpoint<MessagesPage>(endpoint);
  const [confirming, setConfirming] = useState<ShareOption | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const share = state.data?.share;

  return (
    <Card title="Messages" icon={MessageCircle} tone="teal" cap={300}>
      {problem && <p role="alert" className="mb-2 text-[12.5px] font-medium text-cx-rose-ink">{problem}</p>}
      {share && share.options.length > 0 && (() => {
        const reasons = Array.from(new Set(share.options.map((option) => option.blocked)));
        const allBlocked = reasons.length === 1 && reasons[0] !== null;

        return allBlocked ? (
          <p className="mb-3 rounded-2xl bg-cx-amber-bg/50 px-3 py-2.5 text-[12.5px] text-cx-amber-ink">
            <b>Send on WhatsApp:</b> {reasons[0]}
          </p>
        ) : (
          <div className="mb-3 rounded-2xl bg-cx-teal-bg/40 p-3">
            <p className="text-[12px] font-semibold uppercase tracking-wide text-cx-teal-ink">Send on WhatsApp</p>
            <ul className="mt-1.5 space-y-1.5">
              {share.options.map((option) => (
                <li key={`${option.kind}-${option.id}`} className="flex items-center justify-between gap-2 text-[12.5px]">
                  <span className="min-w-0">
                    <span className="font-semibold text-cx-nav-ink">{option.label}</span> <span className="text-cx-text-2">· {option.detail}</span>
                    {option.blocked && <span className="block text-cx-amber-ink">{option.blocked}</span>}
                  </span>
                  <button type="button" className={cn(BTN_SM, 'shrink-0')} disabled={option.blocked !== null || busy} onClick={() => setConfirming(option)}>
                    Send
                  </button>
                </li>
              ))}
            </ul>
          </div>
        );
      })()}
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : state.data.data.length === 0 ? (
        <Empty>No messages yet.</Empty>
      ) : (
        <ul className="space-y-2">
          {state.data.data.map((row) => (
            <li key={row.id} className="rounded-xl bg-cx-slate-bg/50 px-3 py-2.5 text-[12.5px]">
              <p className="flex flex-wrap items-center justify-between gap-2 font-semibold text-cx-nav-ink">
                {row.kind}
                <Pill tone={MESSAGE_TONE[row.status] ?? 'slate'}>{humanize(row.status)}</Pill>
              </p>
              <p className="text-cx-text-2">{[humanize(row.channel), day(row.sent_at ?? row.created_at), row.scheduled_for ? `for ${row.scheduled_for}` : null].filter(Boolean).join(' · ')}</p>
              {row.failed_reason && <p className="text-cx-rose-ink">{row.failed_reason}</p>}
            </li>
          ))}
        </ul>
      )}
      {confirming && share && (
        <Modal
          open
          onOpenChange={(open) => !open && setConfirming(null)}
          title={`Send: ${confirming.label}`}
          description="This message goes to the patient's mobile number on WhatsApp."
          size="sm"
          footer={
            <>
              <Button tone="ghost" onClick={() => setConfirming(null)}>Cancel</Button>
              <Button
                tone="primary"
                loading={busy}
                onClick={() =>
                  router.post(share.url, { kind: confirming.kind, id: confirming.id }, {
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => setBusy(true),
                    onFinish: () => setBusy(false),
                    onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'Not sent.'),
                    onSuccess: () => {
                      setProblem(null);
                      setConfirming(null);
                      void state.reload();
                    },
                  })
                }
              >
                Send
              </Button>
            </>
          }
        >
          <p className="whitespace-pre-line rounded-xl bg-cx-slate-bg px-3 py-2 text-[13.5px] text-cx-text [overflow-wrap:anywhere]">{confirming.preview}</p>
        </Modal>
      )}
    </Card>
  );
}

const REQUEST_TONE: Record<string, Tone> = { pending: 'amber', executed: 'mint', approved: 'mint', rejected: 'rose', cancelled: 'slate', expired: 'slate', needs_info: 'amber' };

function Requests({ endpoint }: { endpoint: string }) {
  const state = useEndpoint<Paged<RequestRow>>(endpoint);

  return (
    <Card title="Requests" caption="Governed changes and who decided" icon={ScrollText} tone="amber">
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : state.data.data.length === 0 ? (
        <Empty>No requests.</Empty>
      ) : (
        <ul className="space-y-2">
          {state.data.data.map((row) => (
            <li key={row.id} className="rounded-xl bg-cx-slate-bg/50 px-3 py-2.5 text-[12.5px]">
              <p className="flex flex-wrap items-center justify-between gap-2 font-semibold text-cx-nav-ink">
                {humanize(row.action)} · {row.target}
                <Pill tone={REQUEST_TONE[row.status] ?? 'slate'}>{humanize(row.status)}</Pill>
              </p>
              <p className="text-cx-text-2">{row.reason}</p>
              <p className="text-cx-text-3">{[row.requester, day(row.created_at), row.approver ? `decided by ${row.approver}` : null].filter(Boolean).join(' · ')}</p>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

const AUDIT_LOOK: [RegExp, { icon: LucideIcon; tone: Tone }][] = [
  [/view|open/i, { icon: Eye, tone: 'blue' }],
  [/print|download|export/i, { icon: Printer, tone: 'teal' }],
  [/delete|remov|void/i, { icon: Trash2, tone: 'rose' }],
  [/update|edit|chang|correct/i, { icon: Pencil, tone: 'amber' }],
];

function clock(iso: string | null): string {
  return iso ? new Date(iso).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }) : '';
}

/** Consecutive identical events (same action, same person, same day) shown as one line with a count. */
function groupAudit(rows: AuditRow[]): { key: number; event: string; label: string; actor: string | null; count: number; first: string | null; last: string | null }[] {
  const groups: ReturnType<typeof groupAudit> = [];
  for (const row of rows) {
    const previous = groups[groups.length - 1];
    if (previous && previous.event === row.event && previous.actor === row.actor && day(previous.first) === day(row.at)) {
      previous.count++;
      previous.first = row.at;
    } else {
      groups.push({ key: row.id, event: row.event, label: row.label, actor: row.actor, count: 1, first: row.at, last: row.at });
    }
  }

  return groups;
}

function Audit({ endpoint }: { endpoint: string }) {
  const [page, setPage] = useState(1);
  const state = useEndpoint<Paged<AuditRow>>(endpoint, page > 1 ? { page } : undefined);

  return (
    <Card title="Audit" caption="Who opened, printed or changed this record" icon={ShieldCheck} tone="slate" cap={300}>
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : state.data.data.length === 0 ? (
        <Empty>No audited events yet.</Empty>
      ) : (
        <ul className="space-y-2">
          {groupAudit(state.data.data).map((group) => {
            const look = AUDIT_LOOK.find(([pattern]) => pattern.test(group.event))?.[1] ?? { icon: ShieldCheck, tone: 'slate' as Tone };

            return (
              <li key={group.key}>
                <Row
                  icon={look.icon}
                  tone={look.tone}
                  title={humanize(group.label.toLowerCase())}
                  meta={[group.actor ?? 'System', day(group.first)].filter(Boolean).join(' · ')}
                  aside={
                    <>
                      {group.count > 1 && <Pill tone="slate">×{group.count}</Pill>}
                      <span className="text-[11.5px] tabular-nums text-cx-text-3">{group.count > 1 && clock(group.first) !== clock(group.last) ? `${clock(group.first)} – ${clock(group.last)}` : clock(group.last)}</span>
                    </>
                  }
                />
              </li>
            );
          })}
        </ul>
      )}
      {state.data && <Pager page={state.data.meta.page} last={state.data.meta.last_page} onPage={setPage} busy={state.loading} />}
    </Card>
  );
}

/** Timeline (spec §6): the record's activity by day, with messages, requests and the audit beside it. Patient-wide. */
export function TimelineZone({ props, sections }: { props: Patient360Props; sections: string[] }) {
  const { endpoints } = props;

  return (
    <Split
      mainSpan={7}
      main={sections.includes('timeline') && endpoints.timeline ? <Activity endpoint={endpoints.timeline} /> : null}
      side={[
        sections.includes('communication') && endpoints.communication && <Messages key="messages" endpoint={endpoints.communication} />,
        sections.includes('requests') && endpoints.requests && <Requests key="requests" endpoint={endpoints.requests} />,
        sections.includes('audit') && endpoints.audit && <Audit key="audit" endpoint={endpoints.audit} />,
      ]}
    />
  );
}
