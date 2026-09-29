import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowLeft,
  ArrowUpRight,
  Check,
  Download,
  FileCheck,
  FileText,
  IndianRupee,
  Lock,
  Percent,
  Receipt as ReceiptIcon,
  Send,
  X,
} from 'lucide-react';
import {
  CrmShell,
  CrmCard,
  CrmStatusPill,
  CrmButton,
  CrmIconButton,
  CrmValue,
  inr,
} from '@/pages/Company/design';
import type { CommercialDetailPayload, QuotationItemType } from './commercial';
import {
  RECONCILIATION_HELP,
  RECONCILIATION_LABEL,
  RECONCILIATION_TONE,
  STATUS_TONE,
  settledPercent,
  writtenDate,
  writtenMoment,
} from './commercialPresentation';
import { QuotationActionModal, type QuotationAction } from './components/QuotationActionModal';
import { QuotationDiscountModal } from './components/QuotationDiscountModal';
import { RecordPaymentModal } from './components/RecordPaymentModal';

/** Labels for a package-derived line item — never shown for a custom one. */
const ITEM_TYPE_LABEL: Partial<Record<QuotationItemType, string>> = {
  package_base: 'Package',
  additional_users: 'Additional Users',
  addon: 'Add-on',
};

/**
 * One commercial record, in full.
 *
 * Six things a person comes here to find out: what was offered, on what terms,
 * whether it was agreed, whether it was paid, what changed along the way, and
 * what they may do about it now. The page it replaces answered the first two.
 *
 * Every action is gated on a server `can` flag. Nothing here decides a
 * permission in the browser.
 */
export default function CommercialShow({
  quotation,
  terms,
  paymentTerms,
  implementationTerms,
  supportTerms,
  cancellationTerms,
  notes,
  rejectionReason,
  sentAt,
  approvedAt,
  versions,
  history,
  items,
  payments,
  approval,
  gst,
  documents,
  can,
}: CommercialDetailPayload) {
  const [action, setAction] = useState<QuotationAction | null>(null);
  const [editingDiscount, setEditingDiscount] = useState(false);
  const [recordingPayment, setRecordingPayment] = useState(false);
  const [issuingProforma, setIssuingProforma] = useState(false);

  const settlement = quotation.settlement;
  const reconciliation = settlement?.reconciliation;
  const attention = Boolean(settlement?.needsAttention);

  /*
   * `quotation.discount` as recorded, not re-derived from subtotal and grand
   * total. It used to be `subtotal - grandTotal`, which only worked back when
   * tax was always ₹0 — real GST now sits on top of the subtotal, so a
   * quotation with a real discount AND real tax can have `grandTotal` exceed
   * `subtotal`, and that arithmetic silently reported no discount at all.
   */
  const headerDiscount = quotation.discount;
  const lineDiscounts = items.reduce((sum, item) => sum + item.discount, 0);
  /**
   * Total tax, however it was reached: `grandTotal = (subtotal - discount) +
   * tax`. Valid whether or not the GST split could be resolved — used as the
   * flat fallback row below, and as the pre-discount-change preview the
   * discount modal shows.
   */
  const flatTax = Math.max(0, quotation.grandTotal - quotation.subtotal + headerDiscount);

  return (
    <CrmShell title={`Quotation — ${quotation.company}`}>
      <div className="mx-auto max-w-[1480px] pb-16">
        <div className="space-y-5">
          {/* ── Header ─────────────────────────────────────────────────── */}
          <CrmCard as="header" aria-label="Quotation summary" step={0} className="p-5 sm:p-6">
            <CrmIconButton
              icon={ArrowLeft}
              label="Back to commercials"
              tone="ghost"
              href={route('company.crm.quotations.index')}
              className="mb-2"
            />

            <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                  <h1 className="truncate text-xl font-semibold text-ovs-crm-text-primary sm:text-2xl">
                    {quotation.company}
                  </h1>
                  <CrmStatusPill tone={STATUS_TONE[quotation.status]}>{quotation.statusLabel}</CrmStatusPill>
                </div>

                <p className="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-ovs-crm-text-secondary">
                  <span className="tabular-nums text-xs">{quotation.number ?? '—'}</span>
                  {quotation.validUntil && <span>· valid to {writtenDate(quotation.validUntil)}</span>}
                  {sentAt && <span>· sent {writtenDate(sentAt)}</span>}
                  {approvedAt && <span>· approved {writtenDate(approvedAt)}</span>}
                </p>

                <div className="mt-3">
                  <CrmValue value={inr(quotation.grandTotal)} size="minor" />
                </div>
              </div>

              <div className="flex shrink-0 flex-col gap-2 lg:items-end">
                <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                  <span className="text-xs text-ovs-crm-text-secondary">
                    {quotation.owner ?? 'Unassigned'}
                  </span>
                  {quotation.leadId && (
                    <Link
                      href={route('company.crm.leads.show', quotation.leadId)}
                      className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-sm font-medium text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
                    >
                      Deal
                      <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
                    </Link>
                  )}
                </div>

                <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                  {/*
                    * Disabled rather than hidden once the terms are locked: a
                    * control that vanishes leaves somebody wondering where it
                    * went, and the reason is the useful part.
                    */}
                  {can.update && !['rejected', 'expired', 'converted'].includes(quotation.status) && (
                    <CrmButton
                      tone="ghost"
                      size="sm"
                      onClick={() => setEditingDiscount(true)}
                      disabled={quotation.financiallyLocked}
                      title={quotation.financiallyLocked
                        ? 'Settled and invoiced — revise the quotation to change the price.'
                        : undefined}
                    >
                      <Percent className="h-3.5 w-3.5" aria-hidden="true" />
                      Discount
                    </CrmButton>
                  )}

                  {/*
                    * Money is only recorded against an approved quotation, and
                    * only by someone holding `record-payment`. Both conditions
                    * are the server's — this renders what it already decided.
                    */}
                  {can.recordPayment && quotation.status === 'approved' && (
                    <CrmButton tone="ghost" size="sm" onClick={() => setRecordingPayment(true)}>
                      <IndianRupee className="h-3.5 w-3.5" aria-hidden="true" />
                      Record payment
                    </CrmButton>
                  )}

                  {can.update && quotation.status === 'draft' && (
                    <CrmButton tone="ghost" size="sm" onClick={() => setAction('send')}>
                      <Send className="h-3.5 w-3.5" aria-hidden="true" />
                      Send
                    </CrmButton>
                  )}

                  {can.approve && ['sent', 'negotiation'].includes(quotation.status) && (
                    <>
                      <CrmButton tone="ghost" size="sm" onClick={() => setAction('approve')}>
                        <Check className="h-3.5 w-3.5" aria-hidden="true" />
                        Approve
                      </CrmButton>
                      <CrmButton tone="ghost" size="sm" onClick={() => setAction('reject')}>
                        <X className="h-3.5 w-3.5" aria-hidden="true" />
                        Reject
                      </CrmButton>
                    </>
                  )}

                  {/*
                    * A proforma only makes sense between Approved and
                    * settled — the server's own guard, mirrored here so the
                    * button never offers something it will refuse.
                    */}
                  {documents.canIssueProforma && (
                    <CrmButton
                      tone="ghost"
                      size="sm"
                      loading={issuingProforma}
                      disabled={issuingProforma}
                      onClick={() => {
                        setIssuingProforma(true);
                        router.post(
                          route('company.crm.quotations.proforma.issue', quotation.id),
                          {},
                          { preserveScroll: true, onFinish: () => setIssuingProforma(false) },
                        );
                      }}
                    >
                      <FileCheck className="h-3.5 w-3.5" aria-hidden="true" />
                      Issue proforma
                    </CrmButton>
                  )}

                  {documents.proforma && (
                    <a
                      href={documents.proforma.pdfUrl}
                      className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-xs font-medium text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
                    >
                      <Download className="h-3.5 w-3.5" aria-hidden="true" />
                      Proforma
                    </a>
                  )}

                  {documents.taxInvoice && (
                    <a
                      href={documents.taxInvoice.pdfUrl}
                      className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-xs font-medium text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
                    >
                      <Download className="h-3.5 w-3.5" aria-hidden="true" />
                      Tax Invoice
                    </a>
                  )}

                  <a
                    href={route('company.crm.quotations.pdf', quotation.id)}
                    className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-xs font-medium text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
                  >
                    <Download className="h-3.5 w-3.5" aria-hidden="true" />
                    PDF
                  </a>
                </div>
              </div>
            </div>

            {/*
              * Why a send is blocked, said before somebody presses the button.
              * The threshold is commercial policy and comes from the approval
              * service, not from anything computed here.
              */}
            {approval?.blocked_reason && (
              <p className="mt-4 flex items-start gap-2 rounded-2xl bg-ovs-crm-accent-yellow-soft px-4 py-3 text-sm text-ovs-crm-accent-yellow-text">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <span>
                  {approval.blocked_reason}
                  {approval.requires_approval && (
                    <> Discount is {approval.discount_percent}%, above the {approval.threshold_percent}% a rep may give unaided.</>
                  )}
                </span>
              </p>
            )}

            {quotation.financiallyLocked && (
              <p className="mt-4 flex items-start gap-2 rounded-2xl bg-ovs-crm-surface-inset px-4 py-3 text-sm text-ovs-crm-text-secondary">
                <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <span>
                  Settled and invoiced, so the price and discount are fixed. The customer holds
                  that invoice — to charge something different, revise this quotation and the new
                  version gets its own terms.
                </span>
              </p>
            )}

            {rejectionReason && (
              <p className="mt-4 rounded-2xl bg-ovs-crm-accent-coral-soft px-4 py-3 text-sm text-ovs-crm-accent-coral-text">
                Rejected — {rejectionReason}
              </p>
            )}
          </CrmCard>

          {/* ── Money ──────────────────────────────────────────────────── */}
          <Zone title="Payment &amp; invoice" step={1}>
            {settlement && reconciliation ? (
              <>
                <div className="flex flex-wrap items-center gap-3">
                  <CrmStatusPill tone={RECONCILIATION_TONE[reconciliation.state]}>
                    {RECONCILIATION_LABEL[reconciliation.state]}
                  </CrmStatusPill>

                  {/*
                    * The invoice follows the money. Saying so stops an absent
                    * number reading as a document somebody forgot to attach.
                    */}
                  {settlement.number ? (
                    <span className="tabular-nums text-xs text-ovs-crm-text-secondary">{settlement.number}</span>
                  ) : (
                    <span className="text-sm text-ovs-crm-text-secondary">
                      Invoice is raised once this quotation is settled in full
                    </span>
                  )}
                </div>

                {/*
                  * The two states where the record itself is wrong get an
                  * explanation, not just a colour — a pill alone does not say
                  * what somebody should do next.
                  */}
                {attention && (
                  <p className="mt-3 flex items-start gap-2 rounded-2xl bg-ovs-crm-accent-coral-soft px-4 py-3 text-sm text-ovs-crm-accent-coral-text">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{RECONCILIATION_HELP[reconciliation.state]}</span>
                  </p>
                )}

                <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                  <Stat label="Agreed" value={inr(reconciliation.expected)} />
                  <Stat label="Settled" value={inr(reconciliation.settled)} />
                  <Stat
                    label={reconciliation.balance < 0 ? 'Overpaid by' : 'Outstanding'}
                    value={inr(Math.abs(reconciliation.balance))}
                    tone={reconciliation.balance < 0 ? 'bad' : undefined}
                  />
                  <Stat label="Payments" value={String(reconciliation.payments)} />
                </dl>

                {reconciliation.state !== 'mismatch' && (
                  <div
                    className="mt-4 h-1.5 w-full overflow-hidden rounded-full bg-ovs-crm-surface-inset"
                    role="progressbar"
                    aria-valuenow={settledPercent(reconciliation.settled, reconciliation.expected)}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label="Settled against agreed"
                  >
                    <div
                      className={`h-full rounded-full ${attention ? 'bg-ovs-crm-accent-coral' : 'bg-ovs-crm-accent-green'}`}
                      style={{ width: `${settledPercent(reconciliation.settled, reconciliation.expected)}%` }}
                    />
                  </div>
                )}

                {payments.length > 0 && (
                  <ul className="mt-5 space-y-2">
                    {payments.map((p) => (
                      <li key={p.id} className="flex flex-wrap items-baseline justify-between gap-2 rounded-2xl bg-ovs-crm-surface-inset px-4 py-3">
                        <span className="text-sm font-semibold tabular-nums text-ovs-crm-text-primary">
                          {inr(p.amount)}
                        </span>
                        <span className="flex flex-wrap items-baseline gap-x-2 text-xs text-ovs-crm-text-secondary">
                          {p.status}
                          {p.gateway && <> · {p.gateway}</>}
                          {p.reference && <> · {p.reference}</>}
                          <> · {writtenMoment(p.at)}</>
                          {/* Never present for a pending or failed payment. */}
                          {p.receiptUrl && (
                            <a
                              href={p.receiptUrl}
                              className="inline-flex items-center gap-1 font-medium text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
                            >
                              <ReceiptIcon className="h-3 w-3" aria-hidden="true" />
                              Receipt
                            </a>
                          )}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </>
            ) : (
              <p className="text-sm text-ovs-crm-text-secondary">
                Not approved yet — money is only recorded against an approved quotation.
              </p>
            )}
          </Zone>

          {/* ── Line items ─────────────────────────────────────────────── */}
          <Zone title="What was quoted" step={2} aside={`${items.length} ${items.length === 1 ? 'line' : 'lines'}`}>
            {items.length === 0 ? (
              <p className="text-sm text-ovs-crm-text-secondary">No line items on this quotation.</p>
            ) : (
              /* Its own scroller, so a wide table never scrolls the page sideways. */
              <div className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-sm">
                  <thead>
                    <tr className="border-b border-ovs-crm-border text-left">
                      <th scope="col" className="pb-2 pr-4 text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">Item</th>
                      <th scope="col" className="pb-2 pr-4 text-right text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">Qty</th>
                      <th scope="col" className="pb-2 pr-4 text-right text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">Unit</th>
                      <th scope="col" className="pb-2 pr-4 text-right text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">Discount</th>
                      <th scope="col" className="pb-2 text-right text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item) => (
                      <tr key={item.id} className="border-b border-ovs-crm-border/60 last:border-0">
                        <td className="py-2.5 pr-4">
                          <p className="font-medium text-ovs-crm-text-primary">
                            {item.title}
                            {item.itemType && item.itemType !== 'custom' && (
                              <span className="ml-2 inline-flex items-center rounded-full bg-ovs-crm-brand-soft px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ovs-crm-brand-text">
                                {ITEM_TYPE_LABEL[item.itemType]}
                              </span>
                            )}
                          </p>
                          {item.description && (
                            <p className="text-xs text-ovs-crm-text-secondary">{item.description}</p>
                          )}
                        </td>
                        <td className="py-2.5 pr-4 text-right tabular-nums text-ovs-crm-text-secondary">{item.quantity}</td>
                        <td className="py-2.5 pr-4 text-right tabular-nums text-ovs-crm-text-secondary">{inr(item.unitPrice)}</td>
                        <td className="py-2.5 pr-4 text-right tabular-nums text-ovs-crm-text-secondary">{item.discount ? inr(item.discount) : '—'}</td>
                        <td className="py-2.5 text-right font-semibold tabular-nums text-ovs-crm-text-primary">{inr(item.total)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <dl className="mt-4 space-y-1 border-t border-ovs-crm-border pt-4 text-sm">
              <TotalRow label="Subtotal" value={inr(quotation.subtotal)} />
              {lineDiscounts > 0 && <TotalRow label="Line discounts" value={`− ${inr(lineDiscounts)}`} />}
              {headerDiscount > 0 && (
                <TotalRow
                  label={`Discount${approval.discount_type === 'percentage' ? ` · ${approval.discount_value}%` : ''}`}
                  value={`− ${inr(headerDiscount)}`}
                />
              )}
              {/*
                * Null for a quotation that predates the GST breakdown — it
                * keeps showing only the flat total below rather than a split
                * fabricated for money nobody recorded the state data for.
                */}
              {gst && gst.type === 'igst' && (
                <TotalRow label={`IGST @ ${trimRate(gst.rate)}%`} value={`+ ${inr(gst.igst)}`} />
              )}
              {gst && gst.type === 'cgst_sgst' && (
                <>
                  <TotalRow label={`CGST @ ${trimRate(gst.rate / 2)}%`} value={`+ ${inr(gst.cgst)}`} />
                  <TotalRow label={`SGST @ ${trimRate(gst.rate / 2)}%`} value={`+ ${inr(gst.sgst)}`} />
                </>
              )}
              {/*
                * A rate still applies even where the intra-/inter-state split
                * could not be resolved (seller or lead state missing) — see
                * `GstCalculationService`. Shown as a flat figure rather than
                * silently absorbed into the jump from Subtotal to Total.
                */}
              {!gst && flatTax > 0 && <TotalRow label="Tax" value={`+ ${inr(flatTax)}`} />}
              <TotalRow label="Total" value={inr(quotation.grandTotal)} strong />
            </dl>

            {(terms || paymentTerms || implementationTerms || supportTerms || cancellationTerms || notes) && (
              <dl className="mt-5 space-y-3">
                <TermRow label="Payment terms" value={paymentTerms} />
                <TermRow label="Implementation & setup" value={implementationTerms} />
                <TermRow label="Support / AMC" value={supportTerms} />
                <TermRow label="Cancellation & refund" value={cancellationTerms} />
                <TermRow label="Terms & conditions" value={terms} />
                {notes && (
                  <div>
                    <dt className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">Notes · internal</dt>
                    <dd className="mt-1 whitespace-pre-line text-sm text-ovs-crm-text-secondary">{notes}</dd>
                  </div>
                )}
              </dl>
            )}
          </Zone>

          {/* ── Revisions ──────────────────────────────────────────────── */}
          {versions.length > 1 && (
            <Zone title="Revisions" step={3} aside={`${versions.length} versions`}>
              <ol className="space-y-2">
                {versions.map((v) => (
                  <li
                    key={v.id}
                    className={`flex flex-wrap items-center justify-between gap-2 rounded-2xl px-4 py-3 ${
                      v.isCurrent ? 'bg-ovs-crm-surface-inset ring-1 ring-ovs-crm-border' : 'bg-ovs-crm-surface-inset'
                    }`}
                  >
                    <span className="flex items-center gap-2 text-sm">
                      <FileText className="h-3.5 w-3.5 text-ovs-crm-text-secondary" aria-hidden="true" />
                      <span className="font-medium text-ovs-crm-text-primary">v{v.version}</span>
                      <span className="tabular-nums text-xs text-ovs-crm-text-secondary">{v.number ?? '—'}</span>
                      {v.isCurrent && <CrmStatusPill tone="info">Viewing</CrmStatusPill>}
                    </span>

                    <span className="flex items-center gap-3 text-sm">
                      <span className="tabular-nums text-ovs-crm-text-secondary">{inr(v.grandTotal)}</span>
                      {!v.isCurrent && (
                        <Link
                          href={route('company.crm.quotations.show', v.id)}
                          className="text-xs font-medium text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
                        >
                          Open
                        </Link>
                      )}
                    </span>
                  </li>
                ))}
              </ol>
            </Zone>
          )}

          {/* ── History ────────────────────────────────────────────────── */}
          <Zone title="History" step={4}>
            {history.length === 0 ? (
              <p className="text-sm text-ovs-crm-text-secondary">Nothing recorded yet.</p>
            ) : (
              <ol className="space-y-3">
                {history.map((entry) => (
                  <li key={entry.id} className="flex gap-3">
                    <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-ovs-crm-text-secondary" aria-hidden="true" />
                    <div className="min-w-0">
                      <p className="text-sm text-ovs-crm-text-primary">
                        {entry.description ?? entry.action}
                      </p>
                      <p className="text-xs text-ovs-crm-text-secondary">
                        {writtenMoment(entry.at)}
                        {entry.by && <> · {entry.by}</>}
                      </p>
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </Zone>
        </div>
      </div>

      <QuotationDiscountModal
        isOpen={editingDiscount}
        onClose={() => setEditingDiscount(false)}
        quotationId={quotation.id}
        company={quotation.company}
        subtotal={quotation.subtotal}
        tax={flatTax}
        currentDiscount={headerDiscount}
        isApproved={quotation.status === 'approved'}
        approval={approval}
      />

      {reconciliation && (
        <RecordPaymentModal
          isOpen={recordingPayment}
          onClose={() => setRecordingPayment(false)}
          quotationId={quotation.id}
          company={quotation.company}
          reconciliation={reconciliation}
        />
      )}

      {action && (
        <QuotationActionModal
          isOpen
          kind={action}
          quotationId={quotation.id}
          company={quotation.company}
          onClose={() => setAction(null)}
        />
      )}
    </CrmShell>
  );
}

function Zone({
  title,
  step,
  aside,
  children,
}: {
  title: string;
  step: number;
  aside?: string;
  children: React.ReactNode;
}) {
  return (
    <CrmCard as="section" aria-label={title} step={step} className="p-5 sm:p-6">
      <div className="mb-4 flex items-baseline justify-between gap-3">
        <h2 className="text-sm font-semibold uppercase tracking-wider text-ovs-crm-text-secondary">{title}</h2>
        {aside && <span className="text-xs text-ovs-crm-text-secondary">{aside}</span>}
      </div>
      {children}
    </CrmCard>
  );
}

function TermRow({ label, value }: { label: string; value: string | null }) {
  if (!value) return null;

  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">{label}</dt>
      <dd className="mt-1 whitespace-pre-line text-sm text-ovs-crm-text-primary">{value}</dd>
    </div>
  );
}

function TotalRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className={strong ? 'font-medium text-ovs-crm-text-primary' : 'text-ovs-crm-text-secondary'}>{label}</dt>
      <dd className={`tabular-nums ${strong ? 'text-base font-semibold text-ovs-crm-text-primary' : 'text-ovs-crm-text-secondary'}`}>
        {value}
      </dd>
    </div>
  );
}

/** "18" rather than "18.00"; "9" rather than "9.00" for a halved rate. */
function trimRate(rate: number): string {
  return Number(rate.toFixed(2)).toString();
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: 'bad' }) {
  return (
    <div className="rounded-2xl bg-ovs-crm-surface-inset p-4">
      <dt className="text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">{label}</dt>
      <dd className={`mt-1 text-lg font-semibold tabular-nums ${
        tone === 'bad' ? 'text-ovs-crm-accent-coral-text' : 'text-ovs-crm-text-primary'
      }`}
      >
        {value}
      </dd>
    </div>
  );
}
