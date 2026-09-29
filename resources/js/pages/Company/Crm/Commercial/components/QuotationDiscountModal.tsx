import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock } from 'lucide-react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  inr,
} from '@/pages/Company/design';
import type { ApprovalState, DiscountType } from '../commercial';

/**
 * Set the headline discount on a quotation, as either a percentage or a
 * fixed rupee amount.
 *
 * The running total updates as the number is typed — computed the same way
 * the server does (base → discount → taxable amount → GST → grand total) —
 * because a discount that produces an unexpected total should be visible
 * before it is saved rather than after it has gone to a customer.
 *
 * Three things surface as you type, and all three are the point of the form:
 * whether *this* user's discount would cross their own approval ceiling
 * (admin: 20% direct; anyone else: the configured threshold) before saving
 * rather than discovered when the send button refuses; whether changing the
 * discount on an already-approved quotation revokes that approval; and,
 * separately, the *current* approval state already on record — not required,
 * pending, or approved — so an admin never sees an approval flow they don't
 * need, and an employee whose discount is pending sees exactly that.
 */
export function QuotationDiscountModal({
  isOpen,
  onClose,
  quotationId,
  company,
  subtotal,
  tax,
  currentDiscount,
  isApproved,
  approval,
}: {
  isOpen: boolean;
  onClose: () => void;
  quotationId: number;
  company: string;
  subtotal: number;
  tax: number;
  currentDiscount: number;
  isApproved: boolean;
  approval: ApprovalState;
}) {
  const [discountType, setDiscountType] = useState<DiscountType>(approval.discount_type ?? 'fixed');
  const [discountValue, setDiscountValue] = useState(approval.discount_value ?? currentDiscount);
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const rawAmount = discountType === 'percentage'
    ? (subtotal * (discountValue || 0)) / 100
    : (discountValue || 0);
  const clamped = Math.max(0, Math.min(Math.round(rawAmount * 100) / 100, subtotal));
  const taxableAmount = Math.max(0, subtotal - clamped);

  /*
   * `tax` arrives as the flat amount the quotation is currently stored
   * with — a snapshot of GST on the *current* taxable amount, not a rate.
   * Typing a new discount changes the taxable amount, so the tax owed on it
   * changes too; the effective rate it implies is derived from the current
   * figures rather than recomputing CGST/SGST/IGST here, which would need
   * the provider's and lead's state on top of what this modal is given.
   */
  const currentTaxableAmount = Math.max(0, subtotal - currentDiscount);
  const effectiveGstRate = currentTaxableAmount > 0 ? tax / currentTaxableAmount : 0;
  const projectedTax = Math.round(taxableAmount * effectiveGstRate * 100) / 100;
  const total = Math.max(0, taxableAmount + projectedTax);
  const percent = subtotal > 0 ? Math.round((clamped / subtotal) * 1000) / 10 : 0;

  /*
   * Which ceiling applies to *this* viewer, mirroring
   * `QuotationApprovalService::requiresApprovalForUser()`: an admin-tier
   * viewer's own boundary is still fine (inclusive), an employee's threshold
   * is already the exception at the boundary (also inclusive on that side,
   * but a different number) — either way the UI never shows a preview that
   * disagrees with what the server will decide.
   */
  const applicableLimit = approval.viewer_is_admin_tier ? approval.admin_direct_limit_percent : approval.threshold_percent;
  const willNeedApproval = applicableLimit !== null && percent >= applicableLimit;
  const willRevoke = isApproved && Math.abs(clamped - currentDiscount) > 0.001;

  const currentStatus: 'approved' | 'pending' | 'none' = approval.is_approved
    ? 'approved'
    : approval.requires_approval
      ? 'pending'
      : 'none';

  const close = () => {
    setDiscountType(approval.discount_type ?? 'fixed');
    setDiscountValue(approval.discount_value ?? currentDiscount);
    setErrors({});
    onClose();
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    setProcessing(true);

    router.put(
      route('company.crm.quotations.discount', quotationId),
      { discount_type: discountType, discount_value: discountValue },
      {
        preserveScroll: true,
        onSuccess: close,
        onError: (e) => setErrors(e as Record<string, string>),
        onFinish: () => setProcessing(false),
      },
    );
  };

  return (
    <CrmModal
      open={isOpen}
      onClose={close}
      title={`Discount — ${company}`}
      description="Applies to the quotation as a whole, on top of anything set on the individual lines."
    >
      <form onSubmit={submit} className="space-y-5">
        <ApprovalStatusBadge status={currentStatus} requestedBy={approval.requested_by} />

        <CrmFormField label="Discount" error={errors.discount} required>
          <div className="flex gap-2">
            <CrmSelect
              value={discountType}
              onChange={(e) => setDiscountType(e.target.value as DiscountType)}
              className="w-40 shrink-0"
              aria-label="Discount type"
            >
              <option value="percentage">Percentage</option>
              <option value="fixed">Fixed Amount</option>
            </CrmSelect>
            <CrmInput
              type="number"
              min={0}
              max={discountType === 'percentage' ? 100 : subtotal}
              step="0.01"
              value={discountValue}
              onChange={(e) => setDiscountValue(parseFloat(e.target.value) || 0)}
              required
              aria-label={discountType === 'percentage' ? 'Discount percentage' : 'Discount amount'}
            />
          </div>
          <p className="mt-1.5 text-xs text-ovs-crm-text-secondary">
            {discountType === 'percentage'
              ? `${discountValue || 0}% of ${inr(subtotal)} = ${inr(clamped)}.`
              : `Cannot exceed the subtotal of ${inr(subtotal)}.`}
          </p>
        </CrmFormField>

        {/* The live total, computed the same way the server does: base → discount → taxable amount → GST → grand total. */}
        <dl className="rounded-2xl bg-ovs-crm-surface-inset p-4 text-sm">
          <Row label="Subtotal" value={inr(subtotal)} />
          <Row label={`Discount${percent > 0 ? ` · ${percent}%` : ''}`} value={`− ${inr(clamped)}`} />
          <Row label="Taxable amount" value={inr(taxableAmount)} />
          <Row label="GST" value={`+ ${inr(projectedTax)}`} />
          <div className="mt-2 border-t border-ovs-crm-border pt-2">
            <Row label="Grand total" value={inr(total)} strong />
          </div>
        </dl>

        {willNeedApproval && (
          <p className="flex items-start gap-2 rounded-2xl bg-ovs-crm-accent-yellow-soft px-4 py-3 text-sm text-ovs-crm-accent-yellow-text">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>
              <strong>Approval required.</strong> {percent}% is at or above the {applicableLimit}%
              {approval.viewer_is_admin_tier ? ' an admin' : ' a rep'} may give unaided, so this
              quotation will need signing off before it can be sent.
            </span>
          </p>
        )}

        {!willNeedApproval && approval.viewer_is_admin_tier && (
          <p className="flex items-start gap-2 rounded-2xl bg-ovs-crm-accent-green-soft px-4 py-3 text-sm text-ovs-crm-accent-green-text">
            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>Within your direct-discount authority — no approval needed.</span>
          </p>
        )}

        {willRevoke && (
          <p className="flex items-start gap-2 rounded-2xl bg-ovs-crm-accent-coral-soft px-4 py-3 text-sm text-ovs-crm-accent-coral-text">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>
              This quotation is already approved. Changing the discount revokes that
              approval, and it will have to be signed off again.
            </span>
          </p>
        )}

        <div className="flex justify-end gap-2">
          <CrmButton type="button" tone="ghost" onClick={close}>Cancel</CrmButton>
          <CrmButton type="submit" tone="primary" disabled={processing}>
            {processing ? 'Saving…' : 'Set discount'}
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}

function ApprovalStatusBadge({
  status,
  requestedBy,
}: {
  status: 'approved' | 'pending' | 'none';
  requestedBy: string | null;
}) {
  if (status === 'none') return null;

  const isPending = status === 'pending';

  return (
    <p className={`flex items-center gap-2 rounded-2xl px-4 py-2.5 text-sm ${
      isPending
        ? 'bg-ovs-crm-accent-yellow-soft text-ovs-crm-accent-yellow-text'
        : 'bg-ovs-crm-accent-green-soft text-ovs-crm-accent-green-text'
    }`}
    >
      {isPending ? (
        <Clock className="h-4 w-4 shrink-0" aria-hidden="true" />
      ) : (
        <CheckCircle2 className="h-4 w-4 shrink-0" aria-hidden="true" />
      )}
      <span>
        Current discount is <strong>{isPending ? 'pending approval' : 'approved'}</strong>
        {isPending && requestedBy ? ` — requested by ${requestedBy}` : ''}.
      </span>
    </p>
  );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="flex items-baseline justify-between gap-3 py-0.5">
      <dt className={strong ? 'font-medium text-ovs-crm-text-primary' : 'text-ovs-crm-text-secondary'}>
        {label}
      </dt>
      <dd className={`tabular-nums ${strong ? 'text-base font-semibold text-ovs-crm-text-primary' : 'text-ovs-crm-text-secondary'}`}>
        {value}
      </dd>
    </div>
  );
}
