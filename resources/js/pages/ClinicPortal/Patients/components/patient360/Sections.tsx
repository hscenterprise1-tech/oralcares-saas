import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download, Eye, FileText, FlaskConical, MessageSquare, Pill, Printer, Receipt } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { useOwnerModeStepUp } from '@/clinic/ownerModeStepUp';
import { Modal } from '@/clinic/ui/Modal';
import { Skeleton } from '@/clinic/ui/Spinner';
import { EmptyState, ErrorState, PermissionState } from '@/clinic/ui/states';
import { formatMoney, humanize } from '@/clinic/ui/visuals';
import { ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '@/clinic/http';
import { shortDate } from '../format';

/*
 * Patient 360's record sections, each read from its own endpoint
 * (`Patient360Sections`), a page at a time. The server decides who may open
 * a section and what each row carries (results only to clinical roles,
 * clinical documents only to clinical roles, money only to billing roles);
 * this file only draws it. A link is drawn only when the server sends one,
 * which it does only for built pages.
 */

type Paged<T> = {
  data: T[];
  meta: { page: number; last_page: number; total: number };
};

/** Load one endpoint a page at a time; a newer request cancels an older one. */
function usePaged<T extends Paged<unknown>>(endpoint: string, query: Record<string, string> = {}) {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<ClinicHttpErrorKind | null>(null);
  const [loading, setLoading] = useState(true);
  const controller = useRef<AbortController | null>(null);
  const key = JSON.stringify(query);

  const load = useCallback(
    (target: number) => {
      controller.current?.abort();
      const abort = new AbortController();
      controller.current = abort;
      setLoading(true);
      setError(null);
      requestJson<T>('GET', endpoint, {
        query: { ...JSON.parse(key), page: target },
        signal: abort.signal,
      })
        .then((result) => {
          setData(result);
          setPage(target);
        })
        .catch((failure) => errorKind(failure) !== 'aborted' && setError(errorKind(failure)))
        .finally(() => controller.current === abort && setLoading(false));
    },
    [endpoint, key],
  );

  useEffect(() => {
    load(1);

    return () => controller.current?.abort();
  }, [load]);

  return { data, error, loading, page, load };
}

/** The shared frame: loading, permission, error and empty states, heading, list and pager. */
function SectionFrame<T>({
  id,
  title,
  state,
  empty,
  aside,
  children,
}: {
  id: string;
  title: string;
  state: ReturnType<typeof usePaged<Paged<T>>>;
  empty: { title: string; description: string };
  aside?: ReactNode;
  children: (rows: T[]) => ReactNode;
}) {
  const { data, error, loading, page, load } = state;

  if (error === 'forbidden') {
    return <PermissionState />;
  }
  if (error) {
    return <ErrorState title={`${title} could not be loaded`} description={ERROR_COPY[error]} onRetry={() => load(page)} />;
  }
  if (!data) {
    return (
      <div role="status" aria-label={`Loading ${title.toLowerCase()}`} className="grid gap-2.5">
        {[0, 1, 2].map((row) => (
          <Card key={row} as="div" className="space-y-2 p-4">
            <Skeleton className="h-4 w-1/3" />
            <Skeleton className="h-3.5 w-2/3" />
          </Card>
        ))}
      </div>
    );
  }

  return (
    <section aria-labelledby={`${id}-heading`} aria-busy={loading || undefined}>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 id={`${id}-heading`} className="text-[15px] font-semibold leading-[22px] text-cx-text">
          {title}
          {data.meta.total > 0 && <span className="ml-2 text-[13px] font-medium text-cx-text-2">{data.meta.total}</span>}
        </h2>
        {aside}
      </div>
      {data.meta.total === 0 ? (
        <EmptyState title={empty.title} description={empty.description} />
      ) : (
        <div className={cn(loading && 'pointer-events-none opacity-60 motion-safe:transition-opacity')}>{children(data.data)}</div>
      )}
      {data.meta.last_page > 1 && (
        <nav aria-label={`Pages of ${title.toLowerCase()}`} className="mt-3.5 grid grid-cols-2 items-center gap-3 md:grid-cols-[auto_1fr_auto]">
          <p className="col-span-2 text-center text-[13px] text-cx-text-2 md:col-span-1 md:col-start-2 md:row-start-1">
            Page {data.meta.page} of {data.meta.last_page}
          </p>
          <Button icon={ChevronLeft} disabled={page <= 1 || loading} onClick={() => load(page - 1)} className="md:col-start-1 md:row-start-1">
            Newer
          </Button>
          <Button disabled={page >= data.meta.last_page || loading} onClick={() => load(page + 1)} className="md:col-start-3 md:row-start-1">
            <span className="inline-flex items-center gap-1.5">
              Older <ChevronRight className="h-4 w-4" aria-hidden="true" />
            </span>
          </Button>
        </nav>
      )}
    </section>
  );
}

type Tone = 'teal' | 'blue' | 'amber' | 'slate' | 'rose' | 'green';
const TONES: Record<Tone, string> = {
  teal: 'bg-cx-m-teal-bg text-cx-teal-ink',
  blue: 'bg-cx-m-blue-bg text-cx-blue-ink',
  amber: 'bg-cx-amber-bg text-cx-amber-ink',
  slate: 'bg-cx-slate-bg text-cx-slate-ink',
  rose: 'bg-cx-rose-bg text-cx-rose-ink',
  green: 'bg-cx-green-bg text-cx-green-ink',
};

function Status({ tone, children }: { tone: Tone; children: ReactNode }) {
  return <span className={cn('inline-flex h-6 shrink-0 items-center whitespace-nowrap rounded-full px-2.5 text-[12px] font-semibold', TONES[tone])}>{children}</span>;
}

/** One row: icon, title line (with status), detail, and actions on the right (below on phones). */
function Row({ icon: Icon, tone, title, status, meta, children, actions }: { icon: typeof Pill; tone: Tone; title: ReactNode; status?: ReactNode; meta?: ReactNode; children?: ReactNode; actions?: ReactNode }) {
  return (
    <li>
      <Card as="div" className="flex h-full flex-col gap-3 p-4 sm:flex-row sm:items-start">
        <span className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-[11px]', TONES[tone])} aria-hidden="true">
          <Icon className="h-[18px] w-[18px]" strokeWidth={1.9} />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <p className="min-w-0 text-[14.5px] font-semibold leading-5 text-cx-text [overflow-wrap:anywhere]">{title}</p>
            {status}
          </div>
          {meta && <p className="mt-0.5 text-[12.5px] leading-[18px] text-cx-text-2">{meta}</p>}
          {children && <div className="mt-2 text-[13px] leading-5 text-cx-text-2">{children}</div>}
        </div>
        {actions && <div className="flex shrink-0 flex-wrap gap-1.5 sm:justify-end">{actions}</div>}
      </Card>
    </li>
  );
}

const actionClass =
  'inline-flex h-9 items-center gap-1.5 rounded-[10px] border border-cx-line bg-white px-3 text-[13px] font-semibold text-cx-blue-ink hover:bg-cx-m-blue-bg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan';

function ExternalAction({ href, icon: Icon, children, download = false }: { href: string; icon: typeof Pill; children: ReactNode; download?: boolean }) {
  return (
    <a href={href} target={download ? undefined : '_blank'} rel="noopener" className={actionClass}>
      <Icon className="h-4 w-4" aria-hidden="true" />
      {children}
    </a>
  );
}

/* ------------------------------------------------------------ prescriptions */

type PrescriptionRow = {
  id: number;
  number: string | null;
  status: string;
  date: string | null;
  practitioner: string | null;
  notes: string | null;
  cancellation_reason: string | null;
  consultation: string | null;
  items: {
    name: string;
    strength: string | null;
    form: string | null;
    dosage: string | null;
    frequency: string | null;
    duration: string | null;
    timing: string | null;
    instructions: string | null;
  }[];
  print_url: string | null;
  pdf_url: string | null;
};

export function PrescriptionsSection({ endpoint }: { endpoint: string }) {
  const state = usePaged<Paged<PrescriptionRow>>(endpoint);

  return (
    <SectionFrame
      id="prescriptions"
      title="Prescriptions"
      state={state}
      empty={{
        title: 'No prescriptions yet',
        description: 'Prescriptions appear here once a clinician finalises one in a consultation.',
      }}
    >
      {(rows) => (
        <ul className="grid gap-2.5">
          {rows.map((rx) => (
            <Row
              key={rx.id}
              icon={Pill}
              tone={rx.status === 'cancelled' ? 'slate' : 'blue'}
              title={`Prescription ${rx.number ?? ''}`}
              status={rx.status === 'cancelled' ? <Status tone="slate">Cancelled</Status> : <Status tone="green">Finalised</Status>}
              meta={[shortDate(rx.date), rx.practitioner, rx.consultation ? `Consultation ${rx.consultation}` : null].filter(Boolean).join(' · ')}
              actions={
                rx.print_url && (
                  <>
                    <ExternalAction href={rx.print_url} icon={Printer}>
                      Print
                    </ExternalAction>
                    {rx.pdf_url && (
                      <ExternalAction href={rx.pdf_url} icon={Download} download>
                        PDF
                      </ExternalAction>
                    )}
                  </>
                )
              }
            >
              <ul className="space-y-1">
                {rx.items.map((line, index) => (
                  <li key={index} className="[overflow-wrap:anywhere]">
                    <span className="font-semibold text-cx-text">{[line.name, line.strength].filter(Boolean).join(' ')}</span>
                    {' — '}
                    {[line.dosage, line.frequency, line.duration, line.timing].filter(Boolean).join(' · ')}
                    {line.instructions && <span className="block text-[12.5px]">{line.instructions}</span>}
                  </li>
                ))}
              </ul>
              {rx.notes && <p className="mt-1.5 [overflow-wrap:anywhere]">Advice: {rx.notes}</p>}
              {rx.cancellation_reason && <p className="mt-1.5 text-cx-rose-ink">Cancelled: {rx.cancellation_reason}</p>}
            </Row>
          ))}
        </ul>
      )}
    </SectionFrame>
  );
}

/* ------------------------------------------------------------ investigations */

type InvestigationRow = {
  id: number;
  name: string;
  category: string;
  body_site?: string | null;
  status: string;
  priority: string | null;
  order_number: string | null;
  date: string | null;
  practitioner?: string | null;
  result?: {
    summary: string | null;
    values: { name: string; value: string; unit?: string; flag?: string }[];
    is_abnormal: boolean;
    final: boolean;
    review_note: string | null;
  } | null;
  attachments?: { name: string; url: string }[];
};

/** Where an investigation is; "Ordered" is never presented as a result. */
const ORDER_STATUS: Record<string, [string, Tone]> = {
  ordered: ['Ordered', 'slate'],
  collected: ['Sample collected', 'blue'],
  resulted: ['Result in, not reviewed', 'amber'],
  reviewed: ['Reviewed', 'green'],
  result_in: ['Result in', 'teal'],
  cancelled: ['Cancelled', 'slate'],
};

export function InvestigationsSection({ endpoint }: { endpoint: string }) {
  const [filter, setFilter] = useState<'all' | 'pending'>('all');
  const state = usePaged<Paged<InvestigationRow>>(endpoint, filter === 'pending' ? { status: 'pending' } : {});

  return (
    <SectionFrame
      id="investigations"
      title="Investigations"
      state={state}
      empty={{
        title: filter === 'pending' ? 'Nothing awaited' : 'No investigations yet',
        description: filter === 'pending' ? 'Every investigation ordered has a result or was cancelled.' : 'Tests and imaging ordered in a consultation are listed here.',
      }}
      aside={
        <div role="group" aria-label="Show" className="flex gap-1 rounded-[10px] bg-cx-slate-bg p-1">
          {(['all', 'pending'] as const).map((value) => (
            <button
              key={value}
              type="button"
              aria-pressed={filter === value}
              onClick={() => setFilter(value)}
              className={cn(
                'h-8 rounded-[8px] px-3 text-[13px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan',
                filter === value ? 'bg-white text-cx-text shadow-sm' : 'text-cx-text-2',
              )}
            >
              {value === 'all' ? 'All' : 'Awaited'}
            </button>
          ))}
        </div>
      }
    >
      {(rows) => (
        <ul className="grid gap-2.5">
          {rows.map((item) => {
            const [label, tone] = ORDER_STATUS[item.status] ?? [humanize(item.status), 'slate'];

            return (
              <Row
                key={item.id}
                icon={FlaskConical}
                tone={item.result?.is_abnormal ? 'rose' : 'teal'}
                title={item.name}
                status={
                  <>
                    <Status tone={tone}>{label}</Status>
                    {item.result?.is_abnormal && <Status tone="rose">Abnormal</Status>}
                    {item.priority && item.priority !== 'routine' && <Status tone="amber">{humanize(item.priority)}</Status>}
                  </>
                }
                meta={[item.category, item.body_site, shortDate(item.date), item.practitioner, item.order_number].filter(Boolean).join(' · ')}
                actions={
                  item.attachments && item.attachments.length > 0
                    ? item.attachments.map((file) => (
                        <ExternalAction key={file.url} href={file.url} icon={FileText}>
                          {file.name}
                        </ExternalAction>
                      ))
                    : undefined
                }
              >
                {item.result && (
                  <div className="space-y-1">
                    {item.result.summary && <p className="text-cx-text [overflow-wrap:anywhere]">{item.result.summary}</p>}
                    {item.result.values.length > 0 && (
                      <ul className="flex flex-wrap gap-x-4 gap-y-0.5">
                        {item.result.values.map((value) => (
                          <li key={value.name} className={cn(value.flag && value.flag !== 'normal' && 'font-semibold text-cx-rose-ink')}>
                            {value.name}: {value.value}
                            {value.unit ? ` ${value.unit}` : ''}
                          </li>
                        ))}
                      </ul>
                    )}
                    {!item.result.final && <p className="text-cx-amber-ink">Preliminary result.</p>}
                    {item.result.review_note && <p>Review: {item.result.review_note}</p>}
                  </div>
                )}
              </Row>
            );
          })}
        </ul>
      )}
    </SectionFrame>
  );
}

/* ------------------------------------------------------------ billing */

type BillRow = {
  id: number;
  number: string | null;
  status: string;
  date: string | null;
  total: number;
  paid: number;
  outstanding: number;
  detail_url: string;
};
type BillingPage = Paged<BillRow> & {
  summary: {
    billed: number;
    paid: number;
    refunded: number;
    outstanding: number;
  };
  payments: {
    receipt: string | null;
    amount: number;
    method: string | null;
    date: string | null;
    bill: string | null;
  }[];
  refunds: {
    number: string | null;
    amount: number;
    reason: string | null;
    date: string | null;
    bill: string | null;
  }[];
};
type BillDetail = BillRow & {
  doctor: string | null;
  subtotal: number;
  discount: number;
  tax: number;
  items: {
    description: string;
    quantity: number;
    unit_price: number;
    discount: number;
    tax: number;
    amount: number;
  }[];
  payments: {
    id: number;
    receipt: string | null;
    amount: number;
    method: string | null;
    reference: string | null;
    date: string | null;
  }[];
  refunds: {
    number: string | null;
    amount: number;
    reason: string | null;
    date: string | null;
  }[];
  actions: { print_url: string };
};

const BILL_STATUS: Record<string, [string, Tone]> = {
  paid: ['Paid', 'green'],
  partially_paid: ['Part paid', 'amber'],
  pending: ['Unpaid', 'amber'],
  cancelled: ['Cancelled', 'slate'],
  refunded: ['Refunded', 'slate'],
};

/**
 * Bills, payments and refunds for this patient. Collecting, refunding and
 * adjusting stay on the Billing page (one place for money); the link opens it
 * filtered to this patient.
 */
export function BillingSection({ endpoint, billsUrl }: { endpoint: string; billsUrl: string | null }) {
  const state = usePaged<BillingPage>(endpoint);
  const [open, setOpen] = useState<string | null>(null);
  const summary = state.data?.summary;

  return (
    <>
      {summary && (
        <dl className="mb-4 grid grid-cols-2 gap-2.5 md:grid-cols-4">
          {[
            ['Total billed', summary.billed, 'text-cx-text'],
            ['Paid', summary.paid, 'text-cx-green-ink'],
            ['Refunded', summary.refunded, 'text-cx-text'],
            ['Outstanding', summary.outstanding, summary.outstanding > 0 ? 'text-cx-rose-ink' : 'text-cx-text'],
          ].map(([label, amount, tone]) => (
            <Card key={label as string} as="div" className="p-3.5">
              <dt className="text-[12.5px] text-cx-text-2">{label as string}</dt>
              <dd className={cn('whitespace-nowrap text-[18px] font-bold tabular-nums', tone as string)}>{formatMoney(amount as number)}</dd>
            </Card>
          ))}
        </dl>
      )}
      <SectionFrame
        id="billing"
        title="Bills"
        state={state}
        empty={{
          title: 'No bills yet',
          description: 'Bills raised for this patient at checkout appear here.',
        }}
        aside={
          billsUrl ? (
            <Link href={billsUrl} className={actionClass}>
              Open in Billing
            </Link>
          ) : undefined
        }
      >
        {(rows) => (
          <ul className="grid gap-2.5">
            {rows.map((bill) => {
              const [label, tone] = BILL_STATUS[bill.status] ?? [humanize(bill.status), 'slate'];

              return (
                <Row
                  key={bill.id}
                  icon={Receipt}
                  tone={bill.outstanding > 0 ? 'amber' : 'green'}
                  title={`Bill ${bill.number ?? bill.id}`}
                  status={<Status tone={tone}>{label}</Status>}
                  meta={shortDate(bill.date) ?? undefined}
                  actions={
                    <Button onClick={() => setOpen(bill.detail_url)} aria-label={`View bill ${bill.number ?? bill.id}`}>
                      View
                    </Button>
                  }
                >
                  <p className="tabular-nums">
                    Total <span className="font-semibold text-cx-text">{formatMoney(bill.total)}</span> · Paid {formatMoney(bill.paid)}
                    {bill.outstanding > 0 && (
                      <>
                        {' · '}
                        <span className="font-semibold text-cx-rose-ink">Due {formatMoney(bill.outstanding)}</span>
                      </>
                    )}
                  </p>
                </Row>
              );
            })}
          </ul>
        )}
      </SectionFrame>
      {state.data && state.data.payments.length > 0 && (
        <Card as="section" aria-labelledby="payments-heading" className="mt-4 p-4">
          <h3 id="payments-heading" className="text-[14px] font-semibold text-cx-text">
            Latest payments
          </h3>
          <ul className="mt-2 divide-y divide-cx-line text-[13px]">
            {state.data.payments.map((payment, index) => (
              <li key={payment.receipt ?? index} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span className="text-cx-text-2">{[payment.receipt, payment.method, shortDate(payment.date), payment.bill ? `Bill ${payment.bill}` : null].filter(Boolean).join(' · ')}</span>
                <span className="font-semibold tabular-nums text-cx-green-ink">{formatMoney(payment.amount)}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}
      {open && <BillDialog url={open} onClose={() => setOpen(null)} />}
    </>
  );
}

function BillDialog({ url, onClose }: { url: string; onClose: () => void }) {
  const [bill, setBill] = useState<BillDetail | null>(null);
  const [error, setError] = useState<ClinicHttpErrorKind | null>(null);

  useEffect(() => {
    const abort = new AbortController();
    requestJson<BillDetail>('GET', url, { signal: abort.signal })
      .then(setBill)
      .catch((failure) => errorKind(failure) !== 'aborted' && setError(errorKind(failure)));

    return () => abort.abort();
  }, [url]);

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title={bill ? `Bill ${bill.number ?? bill.id}` : 'Bill'}
      description={bill ? [shortDate(bill.date), bill.doctor].filter(Boolean).join(' · ') : undefined}
      size="lg"
      footer={
        <>
          {bill && (
            <a href={bill.actions.print_url} target="_blank" rel="noopener" className={actionClass}>
              <Printer className="h-4 w-4" aria-hidden="true" />
              Print
            </a>
          )}
          <Button tone="primary" onClick={onClose}>
            Close
          </Button>
        </>
      }
    >
      {error ? (
        error === 'forbidden' ? (
          <PermissionState compact />
        ) : (
          <ErrorState compact description={ERROR_COPY[error]} />
        )
      ) : !bill ? (
        <div role="status" aria-label="Loading the bill" className="space-y-2">
          <Skeleton className="h-4 w-2/3" />
          <Skeleton className="h-4 w-1/2" />
        </div>
      ) : (
        <div className="space-y-4 text-[13.5px]">
          <table className="w-full">
            <thead>
              <tr className="border-b border-cx-line text-left text-[12px] text-cx-text-2">
                <th className="py-1.5 font-medium">Item</th>
                <th className="py-1.5 text-right font-medium">Qty</th>
                <th className="py-1.5 text-right font-medium">Amount</th>
              </tr>
            </thead>
            <tbody>
              {bill.items.map((item, index) => (
                <tr key={index} className="border-b border-cx-line/60 align-top">
                  <td className="py-1.5 pr-3 [overflow-wrap:anywhere]">{item.description}</td>
                  <td className="py-1.5 text-right tabular-nums">{item.quantity}</td>
                  <td className="whitespace-nowrap py-1.5 text-right tabular-nums">{formatMoney(item.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <dl className="ml-auto max-w-[300px] space-y-1 tabular-nums">
            {[
              ['Subtotal', bill.subtotal],
              ['Discount', -bill.discount],
              ['Tax', bill.tax],
              ['Total', bill.total],
              ['Paid', bill.paid],
              ['Outstanding', bill.outstanding],
            ]
              .filter(([label, amount]) => !(label === 'Discount' && amount === 0) && !(label === 'Tax' && amount === 0))
              .map(([label, amount]) => (
                <div key={label as string} className={cn('flex justify-between gap-3', (label === 'Total' || label === 'Outstanding') && 'font-semibold text-cx-text')}>
                  <dt>{label as string}</dt>
                  <dd className="whitespace-nowrap">{formatMoney(amount as number)}</dd>
                </div>
              ))}
          </dl>
          {bill.payments.length > 0 && (
            <div>
              <p className="font-semibold text-cx-text">Payments</p>
              <ul className="mt-1 space-y-0.5 text-cx-text-2">
                {bill.payments.map((payment) => (
                  <li key={payment.id} className="flex justify-between gap-3">
                    <span>{[payment.receipt, payment.method, shortDate(payment.date)].filter(Boolean).join(' · ')}</span>
                    <span className="whitespace-nowrap tabular-nums text-cx-green-ink">{formatMoney(payment.amount)}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {bill.refunds.length > 0 && (
            <div>
              <p className="font-semibold text-cx-text">Refunds</p>
              <ul className="mt-1 space-y-0.5 text-cx-text-2">
                {bill.refunds.map((refund, index) => (
                  <li key={refund.number ?? index} className="flex justify-between gap-3">
                    <span className="[overflow-wrap:anywhere]">{[refund.number, refund.reason, shortDate(refund.date)].filter(Boolean).join(' · ')}</span>
                    <span className="whitespace-nowrap tabular-nums">{formatMoney(refund.amount)}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}

/* ------------------------------------------------------------ documents */

type DocumentRow = {
  id: number;
  name: string;
  category: string;
  date: string | null;
  uploaded_by: string | null;
  source: string;
  file_type: string;
  size: number | null;
  status: string;
  preview_url: string | null;
  download_url: string | null;
  remove_mode: 'direct' | 'request' | null;
};
type DocumentsPage = Paged<DocumentRow> & {
  upload: {
    url: string;
    types: { value: string; label: string }[];
    max_mb: number;
    accept: string;
  } | null;
};

function fileSize(bytes: number | null): string | null {
  if (!bytes) {
    return null;
  }

  return bytes >= 1_048_576 ? `${(bytes / 1_048_576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

export function DocumentsSection({ endpoint, governance }: { endpoint: string; governance: { request: string; direct: string } | null }) {
  const state = usePaged<DocumentsPage>(endpoint);
  const [removing, setRemoving] = useState<DocumentRow | null>(null);
  const upload = state.data?.upload ?? null;

  return (
    <>
      {upload && <UploadPanel upload={upload} onUploaded={() => state.load(1)} />}
      <SectionFrame
        id="documents"
        title="Documents"
        state={state}
        empty={{
          title: 'No documents yet',
          description: 'Reports, X-rays, consent forms and other files filed for this patient appear here.',
        }}
      >
        {(rows) => (
          <ul className="grid gap-2.5">
            {rows.map((doc) => (
              <Row
                key={doc.id}
                icon={FileText}
                tone="green"
                title={doc.name}
                status={doc.status !== 'clean' ? <Status tone="amber">{doc.status === 'pending' ? 'Being checked' : humanize(doc.status)}</Status> : undefined}
                meta={[doc.category, doc.file_type, fileSize(doc.size), shortDate(doc.date), doc.uploaded_by ? `by ${doc.uploaded_by}` : null].filter(Boolean).join(' · ')}
                actions={
                  doc.status === 'clean' && (
                    <>
                      {doc.preview_url && (
                        <ExternalAction href={doc.preview_url} icon={Eye}>
                          Open
                        </ExternalAction>
                      )}
                      {doc.download_url && (
                        <ExternalAction href={doc.download_url} icon={Download} download>
                          Download
                        </ExternalAction>
                      )}
                      {doc.remove_mode && governance && (
                        <Button tone="ghost" size="sm" onClick={() => setRemoving(doc)}>
                          {doc.remove_mode === 'direct' ? 'Remove' : 'Ask to remove'}
                        </Button>
                      )}
                    </>
                  )
                }
              >
                <p>{doc.source}</p>
              </Row>
            ))}
          </ul>
        )}
      </SectionFrame>
      {removing && governance && removing.remove_mode && (
        <RemoveDocumentDialog
          doc={removing}
          mode={removing.remove_mode}
          governance={governance}
          onClose={() => setRemoving(null)}
          onDone={() => {
            setRemoving(null);
            state.load(1);
          }}
        />
      )}
    </>
  );
}

/** Upload one file to the patient's record. It is scanned for malware before anyone can open it (`ScannedUploadIntake`). */
function UploadPanel({ upload, onUploaded }: { upload: NonNullable<DocumentsPage['upload']>; onUploaded: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [type, setType] = useState(upload.types[0]?.value ?? 'other');
  const [title, setTitle] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement | null>(null);

  const submit = () => {
    if (!file) {
      return;
    }
    if (file.size > upload.max_mb * 1024 * 1024) {
      setError(`The file is larger than ${upload.max_mb} MB.`);

      return;
    }
    router.post(
      upload.url,
      {
        document: file,
        document_type: type,
        title: title.trim() || null,
      } as never,
      {
        preserveScroll: true,
        forceFormData: true,
        onStart: () => {
          setBusy(true);
          setError(null);
        },
        onFinish: () => setBusy(false),
        onError: (errors) => setError(Object.values(errors as Record<string, string>)[0] ?? 'The file was not uploaded.'),
        onSuccess: () => {
          setFile(null);
          setTitle('');
          if (input.current) {
            input.current.value = '';
          }
          onUploaded();
        },
      },
    );
  };

  return (
    <Card as="section" aria-labelledby="upload-heading" className="mb-4 p-4">
      <h3 id="upload-heading" className="text-[14px] font-semibold text-cx-text">
        Add a document
      </h3>
      <p className="text-[12.5px] text-cx-text-2">PDF or image, up to {upload.max_mb} MB. It can be opened once the malware check passes.</p>
      {error && (
        <p role="alert" className="mt-2 text-[13px] font-semibold text-cx-rose-ink">
          {error}
        </p>
      )}
      <div className="mt-3 grid gap-2 md:grid-cols-[minmax(0,1fr)_180px_minmax(0,1fr)_auto] md:items-end">
        <Field label="File" htmlFor="doc-file">
          <input
            id="doc-file"
            ref={input}
            type="file"
            accept={upload.accept}
            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            className="block w-full text-[13px] text-cx-text file:mr-3 file:rounded-[9px] file:border-0 file:bg-cx-m-blue-bg file:px-3 file:py-2 file:text-[13px] file:font-semibold file:text-cx-blue-ink"
          />
        </Field>
        <Field label="Type" htmlFor="doc-type">
          <Select id="doc-type" value={type} onChange={(event) => setType(event.target.value)} options={upload.types} />
        </Field>
        <Field label="Title (optional)" htmlFor="doc-title">
          <Input id="doc-title" value={title} maxLength={255} onChange={(event) => setTitle(event.target.value)} />
        </Field>
        <Button tone="primary" onClick={submit} disabled={!file || busy} loading={busy} loadingLabel="Uploading…">
          Upload
        </Button>
      </div>
    </Card>
  );
}

/** Removing a document is governed: the owner removes it (the file is kept for audit), anyone else asks. */
function RemoveDocumentDialog({ doc, mode, governance, onClose, onDone }: { doc: DocumentRow; mode: 'direct' | 'request'; governance: { request: string; direct: string }; onClose: () => void; onDone: () => void }) {
  const stepUp = useOwnerModeStepUp();
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  return (
    <>
      <Modal
        open
        onOpenChange={(next) => !next && onClose()}
        title={mode === 'direct' ? 'Remove document' : 'Ask to remove document'}
        description={`${doc.name}. It leaves the patient's record; the removal and its reason are kept.`}
        size="sm"
        footer={
          <>
            <Button tone="ghost" onClick={onClose}>
              Cancel
            </Button>
            <Button
              tone="danger"
              disabled={reason.trim().length < 10 || stepUp.busy}
              loading={stepUp.busy}
              onClick={() =>
                stepUp.submit({
                  method: 'post',
                  url: mode === 'direct' ? governance.direct : governance.request,
                  data: {
                    action: 'document.remove',
                    target_id: doc.id,
                    reason: reason.trim(),
                    payload: {},
                  },
                  onSuccess: onDone,
                  onError: (errors) => setError(Object.values(errors as Record<string, string>)[0] ?? 'Not done.'),
                })
              }
            >
              {mode === 'direct' ? 'Remove' : 'Send to the owner'}
            </Button>
          </>
        }
      >
        {error && (
          <p role="alert" className="mb-2 text-[13px] font-semibold text-cx-rose-ink">
            {error}
          </p>
        )}
        <Field label="Reason" htmlFor="doc-remove-reason" required hint="At least 10 characters.">
          <Input id="doc-remove-reason" value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} />
        </Field>
      </Modal>
      {stepUp.dialog}
    </>
  );
}

/* ------------------------------------------------------------ messages */

type ShareOption = { kind: string; id: number; label: string; detail: string; preview: string; blocked: string | null };
type MessagesPage = Paged<MessageRow> & { provider_connected: boolean; share?: { url: string; options: ShareOption[] } };

/**
 * Send on WhatsApp by hand: what this member may send now, the exact text
 * (the clinic's approved template), and why it cannot go where it cannot.
 * A confirmation step before anything is queued; the server checks it all again.
 */
function SharePanel({ share, onSent }: { share: NonNullable<MessagesPage['share']>; onSent: () => void }) {
  const [confirming, setConfirming] = useState<ShareOption | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const send = (option: ShareOption) =>
    router.post(
      share.url,
      { kind: option.kind, id: option.id },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'Not sent.'),
        onSuccess: () => {
          setProblem(null);
          setConfirming(null);
          onSent();
        },
      },
    );

  return (
    <Card as="section" aria-labelledby="share-heading" className="mb-4 p-4">
      <h3 id="share-heading" className="text-[14px] font-semibold text-cx-text">
        Send on WhatsApp
      </h3>
      <p className="text-[12.5px] text-cx-text-2">The clinic's approved message for each, to the patient's mobile number. Files are not sent.</p>
      {problem && (
        <p role="alert" className="mt-2 text-[13px] font-semibold text-cx-rose-ink">
          {problem}
        </p>
      )}
      <ul className="mt-2 divide-y divide-cx-line">
        {share.options.map((option) => (
          <li key={`${option.kind}-${option.id}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
            <div className="min-w-0">
              <p className="text-[13.5px] font-semibold text-cx-text [overflow-wrap:anywhere]">
                {option.label} <span className="font-normal text-cx-text-2">· {option.detail}</span>
              </p>
              {option.blocked && <p className="text-[12.5px] text-cx-amber-ink">{option.blocked}</p>}
            </div>
            <Button size="sm" disabled={option.blocked !== null || busy} onClick={() => setConfirming(option)}>
              Send
            </Button>
          </li>
        ))}
      </ul>
      {confirming && (
        <Modal
          open
          onOpenChange={(next) => !next && setConfirming(null)}
          title={`Send: ${confirming.label}`}
          description="This message goes to the patient's mobile number on WhatsApp."
          size="sm"
          footer={
            <>
              <Button tone="ghost" onClick={() => setConfirming(null)}>
                Cancel
              </Button>
              <Button tone="primary" loading={busy} loadingLabel="Sending…" onClick={() => send(confirming)}>
                Send
              </Button>
            </>
          }
        >
          <p className="whitespace-pre-line rounded-[10px] bg-cx-slate-bg px-3 py-2 text-[14px] text-cx-text [overflow-wrap:anywhere]">{confirming.preview}</p>
        </Modal>
      )}
    </Card>
  );
}

type MessageRow = {
  id: number;
  kind: string;
  channel: string;
  status: string;
  scheduled_for: string | null;
  sent_at: string | null;
  created_at: string | null;
  failed_reason: string | null;
  text: string | null;
};

/** "Sent" is what the provider accepted — never read as delivered or read. */
const MESSAGE_STATUS: Record<string, [string, Tone]> = {
  scheduled: ['Scheduled', 'blue'],
  queued: ['Queued', 'blue'],
  sending: ['Sending', 'blue'],
  sent: ['Sent', 'green'],
  simulated: ['Not sent (no provider)', 'slate'],
  failed: ['Failed', 'rose'],
  withdrawn: ['Withdrawn', 'slate'],
  skipped: ['Not sent', 'slate'],
};

export function MessagesSection({ endpoint }: { endpoint: string }) {
  const state = usePaged<MessagesPage>(endpoint);

  return (
    <>
      {state.data?.share && state.data.share.options.length > 0 && <SharePanel share={state.data.share} onSent={() => state.load(1)} />}
      <SectionFrame
        id="messages"
        title="Messages"
        state={state}
        empty={{
          title: 'No messages yet',
          description: 'Appointment, payment and follow-up messages sent to the patient are listed here with what happened to each.',
        }}
      >
        {(rows) => (
          <ul className="grid gap-2.5">
            {rows.map((message) => {
              const [label, tone] = MESSAGE_STATUS[message.status] ?? [humanize(message.status), 'slate'];

              return (
                <Row
                  key={message.id}
                  icon={MessageSquare}
                  tone="teal"
                  title={message.kind}
                  status={<Status tone={tone}>{label}</Status>}
                  meta={[humanize(message.channel), message.scheduled_for ? `for ${message.scheduled_for}` : shortDate(message.sent_at ?? message.created_at)].filter(Boolean).join(' · ')}
                >
                  {message.text && <p className="line-clamp-3 whitespace-pre-line [overflow-wrap:anywhere]">{message.text}</p>}
                  {message.failed_reason && <p className="mt-1 text-cx-rose-ink [overflow-wrap:anywhere]">{message.failed_reason}</p>}
                </Row>
              );
            })}
          </ul>
        )}
      </SectionFrame>
    </>
  );
}

/* ------------------------------------------------------------ follow-ups */

type FollowUpRow = {
  id: number;
  due_on: string;
  reason: string | null;
  practitioner: string | null;
  status: string;
  overdue: boolean;
  source: string | null;
  source_url: string | null;
  booked_for: string | null;
  actions: {
    reschedule_url: string;
    complete_url: string;
    cancel_url: string;
  } | null;
};

const FOLLOW_UP_STATUS: Record<string, [string, Tone]> = {
  due: ['Due', 'blue'],
  booked: ['Booked', 'teal'],
  completed: ['Done', 'green'],
  cancelled: ['Cancelled', 'slate'],
};

/**
 * Follow-up tasks — reminders to see the patient again, never appointments
 * themselves (a booked one names its appointment). Members who manage
 * follow-ups add, move, complete and cancel them here (`PatientFollowUpController`).
 */
export function FollowUpsSection({ endpoint, practitioners }: { endpoint: string; practitioners: { id: number; name: string }[] }) {
  const [state, setState] = useState<{
    data: FollowUpRow[] | null;
    addUrl: string | null;
    error: ClinicHttpErrorKind | null;
  }>({ data: null, addUrl: null, error: null });
  const [problem, setProblem] = useState<string | null>(null);
  const [moving, setMoving] = useState<FollowUpRow | null>(null);
  const load = useCallback(() => {
    requestJson<{ data: FollowUpRow[]; add_url: string | null }>('GET', endpoint)
      .then((result) =>
        setState({
          data: result.data,
          addUrl: result.add_url,
          error: null,
        }),
      )
      .catch((failure) =>
        setState({
          data: null,
          addUrl: null,
          error: errorKind(failure),
        }),
      );
  }, [endpoint]);
  useEffect(load, [load]);

  const act = (url: string, data: Record<string, unknown> = {}) =>
    router.post(url, data as never, {
      preserveScroll: true,
      onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'Not done.'),
      onSuccess: () => {
        setProblem(null);
        setMoving(null);
        load();
      },
    });

  const paged = {
    data: state.data
      ? {
          data: state.data,
          meta: { page: 1, last_page: 1, total: state.data.length },
        }
      : null,
    error: state.error,
    loading: state.data === null && state.error === null,
    page: 1,
    load: () => load(),
  };

  return (
    <>
      {problem && (
        <p role="alert" className="mb-3 rounded-[10px] bg-cx-rose-bg px-3 py-2 text-[13px] font-semibold text-cx-rose-ink">
          {problem}
        </p>
      )}
      {state.addUrl && <AddFollowUp url={state.addUrl} practitioners={practitioners} onAdd={act} />}
      <SectionFrame
        id="follow-ups"
        title="Follow-ups"
        state={paged}
        empty={{
          title: 'No follow-ups',
          description: 'Follow-ups set in a consultation or added here appear in this list.',
        }}
      >
        {(rows) => (
          <ul className="grid gap-2.5">
            {rows.map((row) => {
              const [label, tone] = FOLLOW_UP_STATUS[row.status] ?? [humanize(row.status), 'slate'];

              return (
                <Row
                  key={row.id}
                  icon={FileText}
                  tone="blue"
                  title={`Due ${shortDate(row.due_on)}`}
                  status={
                    <>
                      <Status tone={tone}>{label}</Status>
                      {row.overdue && <Status tone="amber">Overdue</Status>}
                    </>
                  }
                  meta={[row.practitioner, row.booked_for ? `Booked for ${row.booked_for}` : null].filter(Boolean).join(' · ') || undefined}
                  actions={
                    <>
                      {row.source_url && (
                        <Link href={row.source_url} className={actionClass}>
                          {row.source ?? 'Consultation'}
                        </Link>
                      )}
                      {row.actions && (
                        <>
                          <Button size="sm" onClick={() => setMoving(row)}>
                            Move
                          </Button>
                          <Button size="sm" onClick={() => act(row.actions!.complete_url)}>
                            Mark done
                          </Button>
                          <Button size="sm" tone="ghost" onClick={() => act(row.actions!.cancel_url)}>
                            Cancel
                          </Button>
                        </>
                      )}
                    </>
                  }
                >
                  {row.reason && <p className="[overflow-wrap:anywhere]">{row.reason}</p>}
                  {!row.source_url && row.source && <p>From consultation {row.source}</p>}
                </Row>
              );
            })}
          </ul>
        )}
      </SectionFrame>
      {moving?.actions && <MoveFollowUp row={moving} onClose={() => setMoving(null)} onMove={(date) => act(moving.actions!.reschedule_url, { due_on: date })} />}
    </>
  );
}

function AddFollowUp({ url, practitioners, onAdd }: { url: string; practitioners: { id: number; name: string }[]; onAdd: (url: string, data: Record<string, unknown>) => void }) {
  const [due, setDue] = useState('');
  const [reason, setReason] = useState('');
  const [doctor, setDoctor] = useState('');

  return (
    <Card as="section" aria-labelledby="add-follow-up" className="mb-4 p-4">
      <h3 id="add-follow-up" className="text-[14px] font-semibold text-cx-text">
        Add a follow-up
      </h3>
      <form
        className="mt-2 grid gap-2 md:grid-cols-[170px_minmax(0,1fr)_200px_auto] md:items-end"
        onSubmit={(event) => {
          event.preventDefault();
          onAdd(url, {
            due_on: due,
            reason: reason.trim() || null,
            practitioner_id: doctor ? Number(doctor) : null,
          });
          setReason('');
        }}
      >
        <Field label="Due on" htmlFor="fu-due">
          <Input id="fu-due" type="date" value={due} onChange={(event) => setDue(event.target.value)} />
        </Field>
        <Field label="Reason" htmlFor="fu-reason">
          <Input id="fu-reason" value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} />
        </Field>
        <Field label="Doctor" htmlFor="fu-doctor">
          <Select
            id="fu-doctor"
            value={doctor}
            onChange={(event) => setDoctor(event.target.value)}
            placeholder="Any doctor"
            options={practitioners.map((item) => ({
              value: String(item.id),
              label: item.name,
            }))}
          />
        </Field>
        <Button type="submit" disabled={due === ''}>
          Add
        </Button>
      </form>
    </Card>
  );
}

function MoveFollowUp({ row, onClose, onMove }: { row: FollowUpRow; onClose: () => void; onMove: (date: string) => void }) {
  const [date, setDate] = useState(row.due_on);

  return (
    <Modal
      open
      onOpenChange={(next) => !next && onClose()}
      title="Move follow-up"
      description={row.reason ?? undefined}
      size="sm"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button tone="primary" disabled={date === '' || date === row.due_on} onClick={() => onMove(date)}>
            Move
          </Button>
        </>
      }
    >
      <Field label="New date" htmlFor="fu-move">
        <Input id="fu-move" type="date" value={date} onChange={(event) => setDate(event.target.value)} />
      </Field>
    </Modal>
  );
}

/* ------------------------------------------------------------ treatments */

type TreatmentItem = {
  id: number;
  title: string;
  specialty: string | null;
  status: string;
  practitioner: { id: number; name: string } | null;
  progress: { done: number; total: number | null; unit: string };
  next_stage: { id: number; name: string; status: string } | null;
  started_at: string | null;
};
type ProcedureRow = {
  id: number;
  name: string;
  quantity: number;
  tooth: number | string | null;
  notes: string | null;
  date: string | null;
  practitioner: string | null;
  consultation: string | null;
  consultation_url: string | null;
  billed: boolean;
};
type TreatmentPage = Paged<ProcedureRow> & { treatments: TreatmentItem[] };

const TREATMENT_STATUS: Record<string, [string, Tone]> = {
  planned: ['Planned', 'slate'],
  active: ['In progress', 'blue'],
  in_progress: ['In progress', 'blue'],
  paused: ['Paused', 'amber'],
  completed: ['Completed', 'green'],
  cancelled: ['Cancelled', 'slate'],
  declined: ['Declined', 'slate'],
};

/**
 * Every treatment (dental lines and physio plans alike, from the treatment
 * engine) with real progress only where it counts sessions or stages, then
 * the procedures done, newest first, with whether each was billed.
 */
export function TreatmentSection({ endpoint }: { endpoint: string }) {
  const state = usePaged<TreatmentPage>(endpoint);
  const treatments = state.data?.treatments ?? [];

  return (
    <>
      {state.data && (
        <Card as="section" aria-labelledby="treatments-heading" className="mb-4 p-4">
          <h2 id="treatments-heading" className="text-[15px] font-semibold text-cx-text">
            Treatments {treatments.length > 0 && <span className="ml-1 text-[13px] font-medium text-cx-text-2">{treatments.length}</span>}
          </h2>
          {treatments.length === 0 ? (
            <p className="mt-1 text-[13.5px] text-cx-text-2">No treatment started for this patient.</p>
          ) : (
            <ul className="mt-2 divide-y divide-cx-line">
              {treatments.map((item) => {
                const [label, tone] = TREATMENT_STATUS[item.status] ?? [humanize(item.status), 'slate'];
                const counted = item.progress.total !== null && item.progress.total > 1;

                return (
                  <li key={item.id} className="py-2.5">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="min-w-0 text-[14px] font-semibold text-cx-text [overflow-wrap:anywhere]">{item.title}</p>
                      <Status tone={tone}>{label}</Status>
                    </div>
                    <p className="text-[12.5px] text-cx-text-2">
                      {[
                        item.specialty ? humanize(item.specialty) : null,
                        counted ? `${item.progress.done} of ${item.progress.total} ${item.progress.unit}` : null,
                        item.next_stage ? `Next: ${item.next_stage.name}` : null,
                        item.practitioner?.name,
                        item.started_at ? `since ${shortDate(item.started_at)}` : null,
                      ]
                        .filter(Boolean)
                        .join(' · ')}
                    </p>
                    {counted && (
                      <div
                        className="mt-1.5 h-1.5 max-w-md overflow-hidden rounded-full bg-cx-slate-bg"
                        role="progressbar"
                        aria-valuenow={item.progress.done}
                        aria-valuemin={0}
                        aria-valuemax={item.progress.total ?? undefined}
                        aria-label={`${item.title}: ${item.progress.done} of ${item.progress.total} ${item.progress.unit}`}
                      >
                        <div
                          className="h-full rounded-full bg-gradient-to-r from-[#14B8A6] to-[#2B86E8]"
                          style={{
                            width: `${Math.min(100, Math.round((item.progress.done / (item.progress.total as number)) * 100))}%`,
                          }}
                        />
                      </div>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </Card>
      )}
      <SectionFrame
        id="procedures"
        title="Procedures done"
        state={state}
        empty={{
          title: 'No procedures yet',
          description: 'Procedures recorded in consultations appear here, with whether each was billed.',
        }}
      >
        {(rows) => (
          <ul className="grid gap-2.5">
            {rows.map((row) => (
              <Row
                key={row.id}
                icon={FileText}
                tone="teal"
                title={row.name}
                status={row.billed ? <Status tone="green">Billed</Status> : <Status tone="slate">Not billed</Status>}
                meta={[row.tooth ? `Tooth ${row.tooth}` : null, row.quantity > 1 ? `× ${row.quantity}` : null, shortDate(row.date), row.practitioner].filter(Boolean).join(' · ')}
                actions={
                  row.consultation_url ? (
                    <Link href={row.consultation_url} className={actionClass}>
                      {row.consultation ?? 'Consultation'}
                    </Link>
                  ) : undefined
                }
              >
                {row.notes && <p className="[overflow-wrap:anywhere]">{row.notes}</p>}
              </Row>
            ))}
          </ul>
        )}
      </SectionFrame>
    </>
  );
}
