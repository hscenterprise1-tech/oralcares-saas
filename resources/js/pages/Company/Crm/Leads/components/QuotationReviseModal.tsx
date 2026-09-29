import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { CrmModal, CrmButton, CrmFormField, CrmInput, CrmTextarea, inr } from '@/pages/Company/design';

/*
 * Revise a settled quotation — see `QuotationController::revise()`, which was
 * fully built (a new version, the old one preserved in history) with no form
 * on this page ever calling it. "Revise Quote" navigated to the read-only
 * quotation view instead, so a financially locked quotation's price could
 * never actually change from here.
 *
 * Deliberately just the line items, not the full package/add-on builder
 * `QuotationCreateModal` offers for a brand-new quotation — a revision is
 * re-pricing an existing deal, not re-running discovery, and the package
 * shape this page already has (`commercial.packages`) is narrower than what
 * that picker needs.
 */
type DraftLine = {
  title: string;
  description: string;
  quantity: number;
  unit_price: number;
  discount: number;
};

const emptyLine = (): DraftLine => ({ title: '', description: '', quantity: 1, unit_price: 0, discount: 0 });

const lineTotal = (line: DraftLine): number => Math.max(0, line.quantity * line.unit_price - line.discount);

export function QuotationReviseModal({
  isOpen,
  onClose,
  quotationId,
  quotationNumber,
}: {
  isOpen: boolean;
  onClose: () => void;
  quotationId: number;
  quotationNumber: string;
}) {
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()]);
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState('');
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const subtotal = lines.reduce((sum, line) => sum + lineTotal(line), 0);
  const complete = lines.some((l) => l.title.trim() !== '' && l.unit_price > 0);

  const close = () => {
    setLines([emptyLine()]);
    setNotes('');
    setTerms('');
    setErrors({});
    onClose();
  };

  const patch = (index: number, changes: Partial<DraftLine>) =>
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...changes } : line)));

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!complete) return;

    setProcessing(true);

    router.post(
      route('company.crm.quotations.revise', quotationId),
      {
        items: lines.filter((l) => l.title.trim() !== ''),
        notes,
        terms,
      },
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
      title={`Revise ${quotationNumber}`}
      description="Creates a new version priced from scratch. The current version is kept in history, unchanged."
      size="lg"
    >
      <form onSubmit={submit} className="space-y-5">
        {errors.quotation && (
          <p className="rounded-xl bg-ovs-crm-accent-coral-soft px-3 py-2 text-xs text-ovs-crm-accent-coral-text">
            {errors.quotation}
          </p>
        )}

        <div>
          <div className="mb-2 flex items-center justify-between">
            <span className="text-xs font-semibold uppercase tracking-wide text-ovs-crm-text-secondary">
              Line items
            </span>
            <CrmButton type="button" tone="ghost" size="sm" onClick={() => setLines([...lines, emptyLine()])}>
              <Plus className="h-3.5 w-3.5" aria-hidden="true" />
              Add line
            </CrmButton>
          </div>

          <div className="space-y-3">
            {lines.map((line, index) => (
              <div key={index} className="rounded-2xl bg-ovs-crm-surface-inset p-3">
                <div className="flex items-start gap-2">
                  <div className="min-w-0 flex-1">
                    <label className="sr-only" htmlFor={`revise-line-title-${index}`}>Line {index + 1} description</label>
                    <CrmInput
                      id={`revise-line-title-${index}`}
                      value={line.title}
                      onChange={(e) => patch(index, { title: e.target.value })}
                      placeholder="What is being sold"
                    />
                  </div>

                  {lines.length > 1 && (
                    <button
                      type="button"
                      onClick={() => setLines(lines.filter((_, i) => i !== index))}
                      className="mt-1.5 shrink-0 rounded-full p-1.5 text-ovs-crm-text-secondary hover:bg-ovs-crm-surface hover:text-ovs-crm-accent-coral-text"
                      aria-label={`Remove line ${index + 1}`}
                    >
                      <Trash2 className="h-4 w-4" aria-hidden="true" />
                    </button>
                  )}
                </div>

                <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                  <NumberCell
                    id={`revise-line-qty-${index}`}
                    label="Qty"
                    value={line.quantity}
                    min={1}
                    onChange={(v) => patch(index, { quantity: Math.max(1, v) })}
                  />
                  <NumberCell
                    id={`revise-line-price-${index}`}
                    label="Unit price"
                    value={line.unit_price}
                    onChange={(v) => patch(index, { unit_price: Math.max(0, v) })}
                  />
                  <NumberCell
                    id={`revise-line-discount-${index}`}
                    label="Discount"
                    value={line.discount}
                    onChange={(v) => patch(index, { discount: Math.max(0, v) })}
                  />
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wide text-ovs-crm-text-secondary">Total</p>
                    <p className="mt-1.5 text-sm font-semibold tabular-nums text-ovs-crm-text-primary">
                      {inr(lineTotal(line))}
                    </p>
                  </div>
                </div>
              </div>
            ))}
          </div>

          <div className="mt-3 flex items-baseline justify-between border-t border-ovs-crm-border pt-3">
            <span className="text-xs font-medium text-ovs-crm-text-secondary">Subtotal · before tax</span>
            <span className="text-base font-semibold tabular-nums text-ovs-crm-text-primary">{inr(subtotal)}</span>
          </div>
        </div>

        <CrmFormField label="Notes" error={errors.notes} hint="Internal. Not shown to the customer.">
          <CrmTextarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </CrmFormField>

        <CrmFormField label="Terms" error={errors.terms} hint="Appears on the quotation the customer receives.">
          <CrmTextarea rows={2} value={terms} onChange={(e) => setTerms(e.target.value)} />
        </CrmFormField>

        <div className="flex justify-end gap-2">
          <CrmButton type="button" tone="ghost" onClick={close}>Cancel</CrmButton>
          <CrmButton type="submit" tone="primary" loading={processing} disabled={!complete}>
            Save as new version
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}

function NumberCell({
  id,
  label,
  value,
  min = 0,
  onChange,
}: {
  id: string;
  label: string;
  value: number;
  min?: number;
  onChange: (value: number) => void;
}) {
  return (
    <div>
      <label htmlFor={id} className="text-[11px] font-medium uppercase tracking-wide text-ovs-crm-text-secondary">
        {label}
      </label>
      <CrmInput
        id={id}
        type="number"
        min={min}
        step="0.01"
        value={value}
        onChange={(e) => onChange(parseFloat(e.target.value) || 0)}
        className="mt-1"
      />
    </div>
  );
}
