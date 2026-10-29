import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Skeleton } from '@/clinic/ui/Spinner';
import { EmptyState, ErrorState, PermissionState } from '@/clinic/ui/states';
import { humanize } from '@/clinic/ui/visuals';
import { ClinicHttpError, ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '@/clinic/http';
import { shortDate } from '../format';

/*
 * The patient's record sections: Timeline (what happened, filtered to what
 * this role may know), Appointments (booked, attended, missed, cancelled —
 * changed on the Appointments page), Consents (recorded and withdrawn, each
 * for its own purpose) and Audit (owners and administrators). Each loads
 * from its own endpoint; the server decides who may open it.
 */

type Paged<T> = { data: T[]; meta: { page: number; last_page: number; total: number } };

function usePage<T>(endpoint: string) {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<ClinicHttpErrorKind | null>(null);
  const load = useCallback(
    (target: number) => {
      setError(null);
      requestJson<T>('GET', endpoint, { query: { page: target } })
        .then((result) => {
          setData(result);
          setPage(target);
        })
        .catch((failure) => setError(errorKind(failure)));
    },
    [endpoint],
  );
  useEffect(() => load(1), [load]);

  return { data, error, page, load };
}

function Frame({ title, error, loading, onRetry, children }: { title: string; error: ClinicHttpErrorKind | null; loading: boolean; onRetry: () => void; children: ReactNode }) {
  if (error === 'forbidden') {
    return <PermissionState />;
  }
  if (error) {
    return <ErrorState title={`${title} could not be loaded`} description={ERROR_COPY[error]} onRetry={onRetry} />;
  }
  if (loading) {
    return (
      <div role="status" aria-label={`Loading ${title.toLowerCase()}`} className="space-y-2">
        <Skeleton className="h-16 w-full rounded-2xl" />
        <Skeleton className="h-16 w-full rounded-2xl" />
      </div>
    );
  }

  return <>{children}</>;
}

function Pager({ meta, page, onPage, label }: { meta: Paged<unknown>['meta']; page: number; onPage: (page: number) => void; label: string }) {
  if (meta.last_page <= 1) {
    return null;
  }

  return (
    <nav aria-label={`Pages of ${label}`} className="mt-3 flex items-center justify-between gap-3">
      <Button icon={ChevronLeft} disabled={page <= 1} onClick={() => onPage(page - 1)}>
        Newer
      </Button>
      <p className="text-[13px] text-cx-text-2">
        Page {meta.page} of {meta.last_page}
      </p>
      <Button disabled={page >= meta.last_page} onClick={() => onPage(page + 1)}>
        <span className="inline-flex items-center gap-1.5">
          Older <ChevronRight className="h-4 w-4" aria-hidden="true" />
        </span>
      </Button>
    </nav>
  );
}

type Tone = 'green' | 'slate' | 'rose' | 'blue' | 'amber';
const PILL: Record<Tone, string> = {
  green: 'bg-cx-green-bg text-cx-green-ink',
  slate: 'bg-cx-slate-bg text-cx-slate-ink',
  rose: 'bg-cx-rose-bg text-cx-rose-ink',
  blue: 'bg-cx-m-blue-bg text-cx-blue-ink',
  amber: 'bg-cx-amber-bg text-cx-amber-ink',
};

function Pill({ tone, children }: { tone: Tone; children: ReactNode }) {
  return <span className={cn('inline-flex h-6 shrink-0 items-center whitespace-nowrap rounded-full px-2.5 text-[12px] font-semibold', PILL[tone])}>{children}</span>;
}

/* ------------------------------------------------------------ timeline */

type TimelineRow = { id: number; type: string; category: string; title: string; description: string | null; at: string | null; actor: string | null; open_url: string | null };

export function TimelineSection({ endpoint }: { endpoint: string }) {
  const { data, error, page, load } = usePage<Paged<TimelineRow>>(endpoint);

  return (
    <Frame title="Timeline" error={error} loading={!data} onRetry={() => load(page)}>
      {data && data.meta.total === 0 ? (
        <EmptyState title="Nothing recorded yet" description="Registrations, visits, consultations, bills and changes to the record appear here as they happen." />
      ) : (
        data && (
          <Card as="section" aria-label="Timeline" className="p-4">
            <ol className="divide-y divide-cx-line">
              {data.data.map((row) => (
                <li key={row.id} className="grid gap-1 py-2.5 sm:grid-cols-[120px_minmax(0,1fr)] sm:gap-3">
                  <span className="text-[12.5px] font-semibold tabular-nums text-cx-text-2">{shortDate(row.at)}</span>
                  <div className="min-w-0">
                    <p className="text-[14px] font-semibold text-cx-text [overflow-wrap:anywhere]">
                      {row.open_url ? (
                        <Link href={row.open_url} className="hover:underline">
                          {row.title}
                        </Link>
                      ) : (
                        row.title
                      )}
                    </p>
                    {(row.description || row.actor) && <p className="text-[13px] text-cx-text-2 [overflow-wrap:anywhere]">{[row.description, row.actor ? `by ${row.actor}` : null].filter(Boolean).join(' · ')}</p>}
                  </div>
                </li>
              ))}
            </ol>
            <Pager meta={data.meta} page={page} onPage={load} label="timeline" />
          </Card>
        )
      )}
    </Frame>
  );
}

/* ------------------------------------------------------------ appointments */

type AppointmentRow = {
  id: number;
  date_label: string;
  time_label: string;
  duration: number | null;
  practitioner: string | null;
  branch: string | null;
  type: string | null;
  status: string | null;
  status_label: string | null;
  notes: string | null;
  actions: { view_url: string };
};

const APPOINTMENT_TONE: Record<string, Tone> = { scheduled: 'blue', confirmed: 'blue', checked_in: 'green', arrived: 'green', in_consultation: 'green', completed: 'green', cancelled: 'slate', no_show: 'amber' };

/** Every booking, newest first; changing one opens its day on the Appointments page. */
export function AppointmentsSection({ endpoint }: { endpoint: string }) {
  const { data, error, page, load } = usePage<Paged<AppointmentRow>>(endpoint);

  return (
    <Frame title="Appointments" error={error} loading={!data} onRetry={() => load(page)}>
      {data && data.meta.total === 0 ? (
        <EmptyState title="No appointments" description="Bookings made for this patient appear here, with what happened to each." />
      ) : (
        data && (
          <Card as="section" aria-label="Appointments" className="p-4">
            <ul className="divide-y divide-cx-line">
              {data.data.map((row) => (
                <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                  <div className="min-w-0">
                    <p className="text-[14px] font-semibold text-cx-text">
                      {row.date_label} · {row.time_label}
                    </p>
                    <p className="text-[13px] text-cx-text-2 [overflow-wrap:anywhere]">
                      {[row.type ? humanize(row.type) : null, row.practitioner, row.branch, row.duration ? `${row.duration} min` : null].filter(Boolean).join(' · ')}
                    </p>
                    {row.notes && <p className="text-[12.5px] text-cx-text-2 [overflow-wrap:anywhere]">{row.notes}</p>}
                  </div>
                  <span className="flex items-center gap-2">
                    <Pill tone={APPOINTMENT_TONE[row.status ?? ''] ?? 'slate'}>{row.status_label ?? humanize(row.status ?? '')}</Pill>
                    <Link href={row.actions.view_url} className="text-[13px] font-semibold text-cx-blue-ink hover:underline">
                      Open day
                    </Link>
                  </span>
                </li>
              ))}
            </ul>
            <Pager meta={data.meta} page={page} onPage={load} label="appointments" />
          </Card>
        )
      )}
    </Frame>
  );
}

/* ------------------------------------------------------------ consents */

type ConsentRow = {
  id: number;
  type: string;
  version: string | null;
  status: string;
  method: string | null;
  captured_by: string | null;
  captured_at: string | null;
  revoked_at: string | null;
  revocation_reason: string | null;
};
type ConsentsPayload = { data: ConsentRow[]; options: { key: string; label: string; version: string | null; text: string | null }[]; methods: string[]; can_manage: boolean };

/**
 * Each consent for its own purpose (treatment, communication, data…): who
 * recorded it, how, when, and its withdrawal. One consent never stands for
 * another. Recording and withdrawing need consent.manage (checked again).
 */
export function ConsentsSection({ endpoint }: { endpoint: string }) {
  const [data, setData] = useState<ConsentsPayload | null>(null);
  const [error, setError] = useState<ClinicHttpErrorKind | null>(null);
  const [recording, setRecording] = useState(false);
  const [revoking, setRevoking] = useState<ConsentRow | null>(null);
  const load = useCallback(() => {
    requestJson<ConsentsPayload>('GET', endpoint)
      .then(setData)
      .catch((failure) => setError(errorKind(failure)));
  }, [endpoint]);
  useEffect(load, [load]);
  const label = (key: string) => data?.options.find((option) => option.key === key)?.label ?? humanize(key);

  return (
    <Frame title="Consents" error={error} loading={!data} onRetry={load}>
      {data && (
        <Card as="section" aria-label="Consents" className="p-4">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-[15px] font-semibold text-cx-text">Consents</h2>
            {data.can_manage && data.options.length > 0 && <Button onClick={() => setRecording(true)}>Record consent</Button>}
          </div>
          {data.data.length === 0 ? (
            <p className="text-[13.5px] text-cx-text-2">No consent recorded. Each purpose — treatment, messages, data — is recorded separately.</p>
          ) : (
            <ul className="divide-y divide-cx-line">
              {data.data.map((row) => (
                <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                  <div className="min-w-0">
                    <p className="text-[14px] font-semibold text-cx-text">
                      {label(row.type)} {row.version && <span className="font-normal text-cx-text-2">· version {row.version}</span>}
                    </p>
                    <p className="text-[13px] text-cx-text-2">{[row.method ? humanize(row.method) : null, row.captured_by ? `by ${row.captured_by}` : null, shortDate(row.captured_at)].filter(Boolean).join(' · ')}</p>
                    {row.revoked_at && (
                      <p className="text-[12.5px] text-cx-rose-ink">
                        Withdrawn {shortDate(row.revoked_at)}
                        {row.revocation_reason ? ` — ${row.revocation_reason}` : ''}
                      </p>
                    )}
                  </div>
                  <span className="flex items-center gap-2">
                    <Pill tone={row.status === 'revoked' ? 'slate' : row.status === 'granted' ? 'green' : 'amber'}>{row.status === 'revoked' ? 'Withdrawn' : humanize(row.status)}</Pill>
                    {data.can_manage && row.status !== 'revoked' && (
                      <Button size="sm" tone="ghost" onClick={() => setRevoking(row)}>
                        Withdraw
                      </Button>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}
      {recording && data && (
        <RecordConsent
          payload={data}
          endpoint={endpoint}
          onClose={() => setRecording(false)}
          onDone={() => {
            setRecording(false);
            load();
          }}
        />
      )}
      {revoking && (
        <RevokeConsent
          row={revoking}
          label={label(revoking.type)}
          endpoint={endpoint}
          onClose={() => setRevoking(null)}
          onDone={() => {
            setRevoking(null);
            load();
          }}
        />
      )}
    </Frame>
  );
}

function failureMessage(failure: unknown): string {
  const body = failure instanceof ClinicHttpError ? (failure.body as { message?: string; errors?: Record<string, string[]> } | null) : null;

  return Object.values(body?.errors ?? {})[0]?.[0] ?? body?.message ?? 'Not saved.';
}

function RecordConsent({ payload, endpoint, onClose, onDone }: { payload: ConsentsPayload; endpoint: string; onClose: () => void; onDone: () => void }) {
  const [type, setType] = useState(payload.options[0]?.key ?? '');
  const [method, setMethod] = useState(payload.methods.includes('written') ? 'written' : (payload.methods[0] ?? ''));
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const option = payload.options.find((item) => item.key === type);

  const save = () => {
    setBusy(true);
    requestJson('POST', endpoint, { body: { type, method, version: option?.version ?? null, text: option?.text ?? null } })
      .then(onDone)
      .catch((failure) => setError(failureMessage(failure)))
      .finally(() => setBusy(false));
  };

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title="Record consent"
      description="The patient's consent for this purpose only, with the text they agreed to."
      size="md"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button tone="primary" loading={busy} disabled={!type || !method} onClick={save}>
            Record
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {error && (
          <p role="alert" className="text-[13px] font-semibold text-cx-rose-ink">
            {error}
          </p>
        )}
        <Field label="Purpose" htmlFor="consent-type">
          <Select id="consent-type" value={type} onChange={(event) => setType(event.target.value)} options={payload.options.map((item) => ({ value: item.key, label: item.label }))} />
        </Field>
        <Field label="How it was given" htmlFor="consent-method">
          <Select id="consent-method" value={method} onChange={(event) => setMethod(event.target.value)} options={payload.methods.map((value) => ({ value, label: humanize(value) }))} />
        </Field>
        {option?.text && <p className="max-h-48 overflow-y-auto whitespace-pre-line rounded-[10px] bg-cx-slate-bg px-3 py-2 text-[13px] text-cx-text">{option.text}</p>}
      </div>
    </Modal>
  );
}

function RevokeConsent({ row, label, endpoint, onClose, onDone }: { row: ConsentRow; label: string; endpoint: string; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title={`Withdraw: ${label}`}
      description="The consent stays on record, marked withdrawn with the reason."
      size="sm"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Back
          </Button>
          <Button
            tone="danger"
            disabled={reason.trim().length < 3}
            onClick={() =>
              requestJson('POST', `${endpoint}/${row.id}/revoke`, { body: { reason: reason.trim() } })
                .then(onDone)
                .catch((failure) => setError(failureMessage(failure)))
            }
          >
            Withdraw
          </Button>
        </>
      }
    >
      {error && (
        <p role="alert" className="mb-2 text-[13px] font-semibold text-cx-rose-ink">
          {error}
        </p>
      )}
      <Field label="Reason" htmlFor="consent-reason" required>
        <Input id="consent-reason" value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} />
      </Field>
    </Modal>
  );
}

/* ------------------------------------------------------------ audit */

type AuditRow = { id: number; event: string; label: string; actor: string | null; subject: string | null; ip: string | null; at: string | null };

/** Who opened, printed or changed this patient's record (owners and administrators only). */
export function AuditSection({ endpoint }: { endpoint: string }) {
  const { data, error, page, load } = usePage<Paged<AuditRow>>(endpoint);

  return (
    <Frame title="Audit" error={error} loading={!data} onRetry={() => load(page)}>
      {data && (
        <Card as="section" aria-label="Audit" className="p-4">
          {data.meta.total === 0 ? (
            <p className="text-[13.5px] text-cx-text-2">No audited events yet.</p>
          ) : (
            <ol className="divide-y divide-cx-line">
              {data.data.map((row) => (
                <li key={row.id} className="grid gap-1 py-2 text-[13.5px] sm:grid-cols-[150px_minmax(0,1fr)] sm:gap-3">
                  <span className="tabular-nums text-cx-text-2">{row.at ? new Date(row.at).toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'}</span>
                  <span className="min-w-0 [overflow-wrap:anywhere]">
                    <span className="font-semibold text-cx-text">{row.label}</span>
                    <span className="text-cx-text-2"> · {[row.actor ?? 'System', row.subject].filter(Boolean).join(' · ')}</span>
                  </span>
                </li>
              ))}
            </ol>
          )}
          <Pager meta={data.meta} page={page} onPage={load} label="audit" />
        </Card>
      )}
    </Frame>
  );
}

/* ------------------------------------------------------------ clinical */

type EncounterRow = {
  id: number;
  number: string | null;
  date: string | null;
  practitioner: string | null;
  branch: string | null;
  status: string;
  void_reason: string | null;
  complaint: string | null;
  diagnoses: { description: string; code: string | null; primary: boolean }[];
  counts: { procedures: number; prescriptions: number; orders: number };
  follow_up_on: string | null;
  url: string | null;
};

const ENCOUNTER_TONE: Record<string, Tone> = { signed: 'green', draft: 'amber', closed: 'slate', voided: 'rose' };

/** Every consultation, newest first, opening into the consultation itself. */
export function ClinicalSection({ endpoint }: { endpoint: string }) {
  const { data, error, page, load } = usePage<Paged<EncounterRow>>(endpoint);

  return (
    <Frame title="Clinical records" error={error} loading={!data} onRetry={() => load(page)}>
      {data && data.meta.total === 0 ? (
        <EmptyState title="No consultations yet" description="Consultations appear here once a clinician starts one." />
      ) : (
        data && (
          <Card as="section" aria-label="Consultations" className="p-4">
            <ul className="divide-y divide-cx-line">
              {data.data.map((row) => (
                <li key={row.id} className="py-2.5">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-[14px] font-semibold text-cx-text">
                      {row.url ? (
                        <Link href={row.url} className="hover:underline">
                          {row.number ?? 'Consultation'}
                        </Link>
                      ) : (
                        (row.number ?? 'Consultation')
                      )}
                      <span className="font-normal text-cx-text-2"> · {[shortDate(row.date), row.practitioner, row.branch].filter(Boolean).join(' · ')}</span>
                    </p>
                    <Pill tone={ENCOUNTER_TONE[row.status] ?? 'slate'}>{row.status === 'draft' ? 'Open' : humanize(row.status)}</Pill>
                  </div>
                  {row.complaint && <p className="text-[13.5px] text-cx-text [overflow-wrap:anywhere]">{row.complaint}</p>}
                  {row.diagnoses.length > 0 && <p className="text-[13px] text-cx-text-2 [overflow-wrap:anywhere]">Diagnosis: {row.diagnoses.map((d) => [d.description, d.code].filter(Boolean).join(' ')).join('; ')}</p>}
                  <p className="text-[12.5px] text-cx-text-2">
                    {[
                      row.counts.prescriptions ? `${row.counts.prescriptions} prescription${row.counts.prescriptions > 1 ? 's' : ''}` : null,
                      row.counts.orders ? `${row.counts.orders} investigation order${row.counts.orders > 1 ? 's' : ''}` : null,
                      row.counts.procedures ? `${row.counts.procedures} procedure${row.counts.procedures > 1 ? 's' : ''}` : null,
                      row.follow_up_on ? `follow-up ${shortDate(row.follow_up_on)}` : null,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                  {row.void_reason && <p className="text-[12.5px] text-cx-rose-ink">Voided: {row.void_reason}</p>}
                </li>
              ))}
            </ul>
            <Pager meta={data.meta} page={page} onPage={load} label="consultations" />
          </Card>
        )
      )}
    </Frame>
  );
}

/* ------------------------------------------------------------ requests */

type RequestRow = {
  id: number;
  action: string;
  target: string;
  reason: string;
  status: string;
  direct: boolean;
  requester: string | null;
  approver: string | null;
  created_at: string | null;
  decided_at: string | null;
  last_note: string | null;
};

const REQUEST_TONE: Record<string, Tone> = { pending: 'amber', executed: 'green', approved: 'green', rejected: 'rose', cancelled: 'slate', expired: 'slate', needs_info: 'amber' };

/** Governed changes asked for or made on this patient's records (removals, voids, merges, death records), with who decided. */
export function RequestsSection({ endpoint }: { endpoint: string }) {
  const { data, error, page, load } = usePage<Paged<RequestRow>>(endpoint);

  return (
    <Frame title="Requests" error={error} loading={!data} onRetry={() => load(page)}>
      {data && data.meta.total === 0 ? (
        <EmptyState title="No requests" description="Removals, voids and other governed changes asked for on this patient's records appear here." />
      ) : (
        data && (
          <Card as="section" aria-label="Requests" className="p-4">
            <ul className="divide-y divide-cx-line">
              {data.data.map((row) => (
                <li key={row.id} className="py-2.5">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-[14px] font-semibold text-cx-text [overflow-wrap:anywhere]">
                      {row.action} <span className="font-normal text-cx-text-2">· {row.target}</span>
                    </p>
                    <Pill tone={REQUEST_TONE[row.status] ?? 'slate'}>{row.direct ? 'Done by the owner' : humanize(row.status)}</Pill>
                  </div>
                  <p className="text-[13px] text-cx-text-2 [overflow-wrap:anywhere]">
                    {[row.requester ? `Asked by ${row.requester}` : null, shortDate(row.created_at), row.approver ? `decided by ${row.approver}` : null, row.decided_at ? shortDate(row.decided_at) : null]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                  <p className="text-[13px] text-cx-text [overflow-wrap:anywhere]">Reason: {row.reason}</p>
                  {row.last_note && row.last_note !== row.reason && <p className="text-[12.5px] text-cx-text-2 [overflow-wrap:anywhere]">{row.last_note}</p>}
                </li>
              ))}
            </ul>
            <Pager meta={data.meta} page={page} onPage={load} label="requests" />
          </Card>
        )
      )}
    </Frame>
  );
}
