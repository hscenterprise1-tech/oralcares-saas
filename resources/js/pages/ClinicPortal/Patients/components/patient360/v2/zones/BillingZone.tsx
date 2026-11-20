import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { BadgeIndianRupee, CircleDollarSign, Printer, Receipt, RotateCcw, Undo2, Wallet } from 'lucide-react';
import { Button } from '@/clinic/ui/Button';
import { Modal } from '@/clinic/ui/Modal';
import { ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '@/clinic/http';
import type { Patient360Props } from '../../types';
import { useEndpoint } from '../store';
import { BTN_SM, Card, Empty, Figure, Pager, Pending, Pill, Row, day, money, type Paged, type Tone } from '../ui';

type BillRow = { id: number; number: string | null; status: string | null; date: string | null; total: number; paid: number | null; outstanding: number | null; detail_url: string | null; items?: { description: string | null; amount: number }[] };
type BillingPage = Paged<BillRow> & {
  scope?: 'own_fees';
  summary: { billed: number; paid: number; refunded: number; outstanding: number } | null;
  payments: { receipt: string | null; amount: number; method: string | null; date: string | null; bill: string | null; receipt_url?: string | null }[];
  refunds: { number: string | null; amount: number; reason: string | null; date: string | null; bill: string | null }[];
};
type BillDetail = {
  number: string | null;
  status: string;
  date: string | null;
  doctor: string | null;
  subtotal: number;
  discount: number;
  tax: number;
  total: number;
  paid: number;
  outstanding: number;
  items: { description: string; quantity: number; amount: number }[];
  payments: { id: number; receipt: string | null; amount: number; method: string | null; reference: string | null; date: string | null; receipt_url?: string | null }[];
  refunds: { number: string | null; amount: number; reason: string | null; date: string | null }[];
  actions: { print_url: string };
};

const STATUS: Record<string, [string, Tone]> = {
  paid: ['Paid', 'mint'],
  partially_paid: ['Part paid', 'amber'],
  pending: ['Unpaid', 'amber'],
  cancelled: ['Cancelled', 'slate'],
  refunded: ['Refunded', 'slate'],
};

function BillDialog({ url, onClose }: { url: string; onClose: () => void }) {
  const [bill, setBill] = useState<BillDetail | null>(null);
  const [error, setError] = useState<ClinicHttpErrorKind | null>(null);
  useEffect(() => {
    requestJson<BillDetail>('GET', url).then(setBill).catch((failure) => setError(errorKind(failure)));
  }, [url]);

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      title={bill ? `Bill ${bill.number ?? ''}` : 'Bill'}
      description={bill ? [day(bill.date), bill.doctor].filter(Boolean).join(' · ') : undefined}
      size="md"
      footer={
        <>
          {bill && (
            <a href={bill.actions.print_url} target="_blank" rel="noopener" className={BTN_SM}>
              <Printer className="h-3.5 w-3.5" aria-hidden="true" /> Print
            </a>
          )}
          <Button tone="primary" onClick={onClose}>Close</Button>
        </>
      }
    >
      {error ? (
        <p className="text-[13px] text-cx-rose-ink">{ERROR_COPY[error]}</p>
      ) : !bill ? (
        <p className="text-[13px] text-cx-text-3">Opening the bill…</p>
      ) : (
        <div className="space-y-4 text-[13px]">
          <ul className="divide-y divide-cx-line rounded-xl border border-cx-line">
            {bill.items.map((item, index) => (
              <li key={index} className="flex justify-between gap-3 px-3 py-2">
                <span className="[overflow-wrap:anywhere]">
                  {item.description}
                  {item.quantity > 1 && <span className="text-cx-text-3"> × {item.quantity}</span>}
                </span>
                <span className="whitespace-nowrap font-semibold tabular-nums">{money(item.amount)}</span>
              </li>
            ))}
          </ul>
          <dl className="ml-auto max-w-[280px] space-y-1 tabular-nums">
            {([['Subtotal', bill.subtotal], ['Discount', -bill.discount], ['Tax', bill.tax], ['Total', bill.total], ['Paid', bill.paid], ['Outstanding', bill.outstanding]] as const)
              .filter(([label, amount]) => !((label === 'Discount' || label === 'Tax') && amount === 0))
              .map(([label, amount]) => (
                <div key={label} className="flex justify-between gap-3">
                  <dt className="text-cx-text-2">{label}</dt>
                  <dd className="font-semibold text-cx-nav-ink">{money(amount)}</dd>
                </div>
              ))}
          </dl>
          {bill.payments.length > 0 && (
            <div>
              <p className="text-[12px] font-semibold uppercase tracking-wide text-cx-green-ink">Payments</p>
              <ul className="mt-1 space-y-1">
                {bill.payments.map((payment) => (
                  <li key={payment.id} className="flex justify-between gap-3">
                    <span className="text-cx-text-2">{[payment.receipt, payment.method, payment.reference ? `ref ${payment.reference}` : null, day(payment.date)].filter(Boolean).join(' · ')}</span>
                    <span className="flex items-center gap-2">
                      <span className="font-semibold tabular-nums text-cx-green-ink">{money(payment.amount)}</span>
                      {payment.receipt_url && (
                        <a href={payment.receipt_url} target="_blank" rel="noopener" className="text-[12px] font-semibold text-cx-primary hover:underline">
                          Receipt
                        </a>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {bill.refunds.length > 0 && (
            <div>
              <p className="text-[12px] font-semibold uppercase tracking-wide text-cx-text-2">Refunds</p>
              <ul className="mt-1 space-y-1">
                {bill.refunds.map((refund, index) => (
                  <li key={refund.number ?? index} className="flex justify-between gap-3">
                    <span className="text-cx-text-2">{[refund.number, refund.reason, day(refund.date)].filter(Boolean).join(' · ')}</span>
                    <span className="font-semibold tabular-nums">{money(refund.amount)}</span>
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

/** Billing (spec §6): the patient's bills, payments and refunds for the ledger's readers; a practitioner's own fees only for them. */
export function BillingZone({ props }: { props: Patient360Props }) {
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<string | null>(null);
  const state = useEndpoint<BillingPage>(props.endpoints.billing, page > 1 ? { page } : undefined);
  const data = state.data;
  const ownFees = data?.scope === 'own_fees';

  if (!data) {
    return (
      <Card title="Billing" icon={Wallet} tone="mint">
        <Pending error={state.error} onRetry={state.reload} />
      </Card>
    );
  }

  if (ownFees) {
    return (
      <Card title="Your fees" caption="Only the lines charged for your care of this patient" icon={BadgeIndianRupee} tone="mint">
        {data.data.length === 0 ? (
          <Empty>No fees of yours yet.</Empty>
        ) : (
          <ul className="space-y-2.5">
            {data.data.map((bill) => (
              <li key={bill.id} className="rounded-2xl bg-cx-green-bg/40 p-3.5">
                <p className="flex justify-between gap-2 text-[13px] font-semibold text-cx-nav-ink">
                  Bill {bill.number ?? bill.id} <span className="text-cx-text-2">{day(bill.date)}</span>
                </p>
                <ul className="mt-1.5 space-y-1 text-[12.5px]">
                  {(bill.items ?? []).map((item, index) => (
                    <li key={index} className="flex justify-between gap-2">
                      <span>{item.description ?? 'Fee'}</span>
                      <span className="font-semibold tabular-nums">{money(item.amount)}</span>
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        )}
        <Pager page={data.meta.page} last={data.meta.last_page} onPage={setPage} busy={state.loading} />
      </Card>
    );
  }

  const outstanding = data.data.filter((bill) => (bill.outstanding ?? 0) > 0);

  return (
    <div className="space-y-5">
      {data.summary && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Figure icon={Receipt} tone="blue" label="Billed" value={money(data.summary.billed)} detail={`${data.meta.total} bills`} />
          <Figure icon={CircleDollarSign} tone="mint" label="Paid" value={money(data.summary.paid)} />
          <Figure icon={Undo2} tone="slate" label="Refunded" value={money(data.summary.refunded)} />
          <Figure icon={Wallet} tone={data.summary.outstanding > 0 ? 'amber' : 'mint'} label="Outstanding" value={money(data.summary.outstanding)} detail={data.summary.outstanding > 0 ? 'Owed by the patient' : 'All settled'} />
        </div>
      )}
      <div className="space-y-5">
        <div>
          <Card title="Bills" icon={Receipt} tone="blue" action={props.links.bills ? <Link href={props.links.bills} className={BTN_SM}>Open in Billing</Link> : undefined}>
            {data.data.length === 0 ? (
              <Empty>No bills yet.</Empty>
            ) : (
              <ul className="grid auto-rows-fr gap-2 lg:grid-cols-2 2xl:grid-cols-3">
                {data.data.map((bill) => {
                  const [label, tone] = STATUS[bill.status ?? ''] ?? [bill.status ?? '', 'slate' as Tone];

                  return (
                    <li key={bill.id}>
                      <Row
                        icon={Receipt}
                        tone={tone === 'mint' ? 'mint' : tone === 'amber' ? 'amber' : 'slate'}
                        title={`Bill ${bill.number ?? bill.id}`}
                        meta={day(bill.date)}
                        aside={
                          <>
                            <Pill tone={tone}>{label}</Pill>
                            {bill.detail_url && (
                              <button type="button" className={BTN_SM} onClick={() => setOpen(bill.detail_url)}>
                                View
                              </button>
                            )}
                          </>
                        }
                      >
                        <dl className="grid grid-cols-3 gap-2 text-[12px] tabular-nums">
                          {(
                            [
                              ['Total', bill.total, 'text-cx-nav-ink'],
                              ['Paid', bill.paid, 'text-cx-green-ink'],
                              ['Due', bill.outstanding, (bill.outstanding ?? 0) > 0 ? 'text-cx-amber-ink' : 'text-cx-text-2'],
                            ] as const
                          ).map(([name, amount, ink]) => (
                            <div key={name}>
                              <dt className="text-cx-text-3">{name}</dt>
                              <dd className={`font-semibold ${ink}`}>{money(amount)}</dd>
                            </div>
                          ))}
                        </dl>
                      </Row>
                    </li>
                  );
                })}
              </ul>
            )}
            <Pager page={data.meta.page} last={data.meta.last_page} onPage={setPage} busy={state.loading} />
          </Card>
        </div>
        <div className="grid items-stretch gap-5 md:grid-cols-2 xl:grid-cols-3">
          {outstanding.length > 0 && (
            <Card title="Outstanding" caption="Bills on this page with money still due" icon={Wallet} tone="amber">
              <ul className="space-y-2">
                {outstanding.map((bill) => (
                  <li key={bill.id}>
                    <Row
                      title={`Bill ${bill.number ?? bill.id}`}
                      meta={`Total ${money(bill.total)} · paid ${money(bill.paid)}`}
                      aside={<span className="text-[13.5px] font-semibold tabular-nums text-cx-amber-ink">{money(bill.outstanding)}</span>}
                    />
                  </li>
                ))}
              </ul>
            </Card>
          )}
          <Card title="Payments & receipts" caption="Latest eight" icon={CircleDollarSign} tone="mint">
            {data.payments.length === 0 ? (
              <Empty>No payments yet.</Empty>
            ) : (
              <ul className="space-y-2">
                {data.payments.map((payment, index) => (
                  <li key={payment.receipt ?? index}>
                    <Row
                      title={money(payment.amount)}
                      meta={[day(payment.date), payment.method, payment.receipt, payment.bill ? `bill ${payment.bill}` : null].filter(Boolean).join(' · ')}
                      aside={
                        payment.receipt_url ? (
                          <a href={payment.receipt_url} target="_blank" rel="noopener" className={BTN_SM} aria-label={`Print receipt ${payment.receipt ?? ''}`}>
                            <Printer className="h-3.5 w-3.5" aria-hidden="true" /> Receipt
                          </a>
                        ) : (
                          <Pill tone="mint">Received</Pill>
                        )
                      }
                    />
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <Card title="Refunds" caption="Latest eight" icon={RotateCcw} tone="slate">
            {data.refunds.length === 0 ? (
              <Empty>No refunds.</Empty>
            ) : (
              <ul className="space-y-2">
                {data.refunds.map((refund, index) => (
                  <li key={refund.number ?? index}>
                    <Row title={money(refund.amount)} meta={[day(refund.date), refund.number, refund.bill ? `bill ${refund.bill}` : null].filter(Boolean).join(' · ')}>
                      {refund.reason && <p className="text-[12px] text-cx-text-2 [overflow-wrap:anywhere]">{refund.reason}</p>}
                    </Row>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
      {open && <BillDialog url={open} onClose={() => setOpen(null)} />}
    </div>
  );
}
