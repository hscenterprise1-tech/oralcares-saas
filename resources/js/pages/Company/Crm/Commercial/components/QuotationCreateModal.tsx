import React, { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  inr,
} from '@/pages/Company/design';
import type { PickableAddon, PickableLead, PickablePackage } from '../commercial';

/**
 * Raise a quotation against a deal.
 *
 * A salesperson works from a package where one applies — pick it, choose
 * additional seats and add-ons, and the base pricing is there without
 * retyping a plan's own numbers every time. Freeform line items sit
 * alongside it, unchanged, for whatever a package genuinely does not cover.
 * The package/add-on prices shown here are the catalogue's *current* ones;
 * once submitted, the server snapshots them onto the quotation's own line
 * items — a later catalogue price change never rewrites a quotation already
 * raised.
 *
 * The running total is shown as everything is chosen, because a total that
 * comes as a surprise should be visible before the thing is saved rather
 * than after it has been sent.
 */
/*
 * A `type` rather than an `interface` on purpose: only a type alias gets the
 * implicit index signature that lets TypeScript accept it as Inertia's
 * `FormDataConvertible`. An interface here would compile only behind a cast,
 * and a cast at the payload boundary is exactly where a shape mismatch would
 * stop being caught.
 */
type DraftLine = {
  title: string;
  description: string;
  quantity: number;
  unit_price: number;
  discount: number;
};

const emptyLine = (): DraftLine => ({ title: '', description: '', quantity: 1, unit_price: 0, discount: 0 });

const lineTotal = (line: DraftLine): number =>
  Math.max(0, line.quantity * line.unit_price - line.discount);

export function QuotationCreateModal({
  isOpen,
  onClose,
  leads,
  packages = [],
  addons = [],
}: {
  isOpen: boolean;
  onClose: () => void;
  leads: PickableLead[];
  packages?: PickablePackage[];
  addons?: PickableAddon[];
}) {
  const [leadId, setLeadId] = useState('');
  const [packageId, setPackageId] = useState('');
  const [additionalUsers, setAdditionalUsers] = useState(0);
  const [addonIds, setAddonIds] = useState<number[]>([]);
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState('');
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()]);
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const selectedPackage = useMemo(
    () => packages.find((p) => String(p.id) === packageId) ?? null,
    [packages, packageId],
  );

  const packageBaseTotal = selectedPackage?.price ?? 0;
  const additionalUsersTotal = selectedPackage?.additionalUserPrice
    ? additionalUsers * selectedPackage.additionalUserPrice
    : 0;
  const addonsTotal = addons
    .filter((a) => addonIds.includes(a.id))
    .reduce((sum, a) => sum + a.price, 0);
  const customLinesTotal = lines.reduce((sum, line) => sum + lineTotal(line), 0);
  const subtotal = packageBaseTotal + additionalUsersTotal + addonsTotal + customLinesTotal;

  const close = () => {
    setLeadId('');
    setPackageId('');
    setAdditionalUsers(0);
    setAddonIds([]);
    setNotes('');
    setTerms('');
    setLines([emptyLine()]);
    setErrors({});
    onClose();
  };

  const patch = (index: number, changes: Partial<DraftLine>) =>
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...changes } : line)));

  const toggleAddon = (id: number) =>
    setAddonIds((current) => (current.includes(id) ? current.filter((a) => a !== id) : [...current, id]));

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!leadId) return;

    setProcessing(true);

    router.post(
      route('company.crm.quotations.store'),
      {
        lead_id: leadId,
        company_plan_id: packageId || undefined,
        additional_users: selectedPackage?.additionalUserPrice ? additionalUsers : undefined,
        addon_ids: addonIds.length > 0 ? addonIds : undefined,
        notes,
        terms,
        items: lines.filter((l) => l.title.trim() !== ''),
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
      title="New quotation"
      description="Build from a package, then add anything custom on top."
      size="lg"
    >
      <form onSubmit={submit} className="space-y-5">
        <CrmFormField label="Deal" error={errors.lead_id} required>
          <CrmSelect value={leadId} onChange={(e) => setLeadId(e.target.value)} required>
            <option value="">Choose a deal…</option>
            {leads.map((lead) => (
              <option key={lead.id} value={lead.id}>{lead.company}</option>
            ))}
          </CrmSelect>
        </CrmFormField>

        {packages.length > 0 && (
          <div className="rounded-2xl bg-ovs-crm-surface-inset p-3">
            <CrmFormField label="Package" error={errors.company_plan_id} hint="Optional — leave unset for a fully custom quotation.">
              <CrmSelect value={packageId} onChange={(e) => { setPackageId(e.target.value); setAdditionalUsers(0); }}>
                <option value="">No package — custom only</option>
                {packages.map((pkg) => (
                  <option key={pkg.id} value={pkg.id}>{pkg.name} — {inr(pkg.price)} ({pkg.billingPeriod})</option>
                ))}
              </CrmSelect>
            </CrmFormField>

            {selectedPackage && (
              <div className="mt-3 space-y-3 border-t border-ovs-crm-border pt-3">
                {selectedPackage.description && (
                  <p className="text-xs text-ovs-crm-text-secondary">{selectedPackage.description}</p>
                )}
                <p className="text-xs text-ovs-crm-text-secondary">
                  Includes {selectedPackage.maxUsers} {selectedPackage.maxUsers === 1 ? 'user' : 'users'}.
                </p>

                {selectedPackage.additionalUserPrice !== null && (
                  <CrmFormField label="Additional users" hint={`${inr(selectedPackage.additionalUserPrice)} per extra user, beyond the ${selectedPackage.maxUsers} included.`}>
                    <CrmInput
                      type="number"
                      min={0}
                      step="1"
                      value={additionalUsers}
                      onChange={(e) => setAdditionalUsers(Math.max(0, parseInt(e.target.value, 10) || 0))}
                      className="w-32"
                    />
                  </CrmFormField>
                )}

                {addons.length > 0 && (
                  <div>
                    <p className="mb-1.5 text-xs font-medium uppercase tracking-wide text-ovs-crm-text-secondary">Add-ons</p>
                    <div className="space-y-1.5">
                      {addons.map((addon) => (
                        <label key={addon.id} className="flex items-center justify-between gap-2 rounded-xl bg-ovs-crm-surface px-3 py-2 text-sm">
                          <span className="flex items-center gap-2">
                            <input
                              type="checkbox"
                              checked={addonIds.includes(addon.id)}
                              onChange={() => toggleAddon(addon.id)}
                              className="h-4 w-4 rounded border-ovs-crm-border-strong"
                            />
                            {addon.name}
                          </span>
                          <span className="tabular-nums text-ovs-crm-text-secondary">{inr(addon.price)}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        )}

        <div>
          <div className="mb-2 flex items-center justify-between">
            <span className="text-xs font-semibold uppercase tracking-wide text-ovs-crm-text-secondary">
              Custom line items
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
                    <label className="sr-only" htmlFor={`line-title-${index}`}>Line {index + 1} description</label>
                    <CrmInput
                      id={`line-title-${index}`}
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
                    id={`line-qty-${index}`}
                    label="Qty"
                    value={line.quantity}
                    min={1}
                    onChange={(v) => patch(index, { quantity: Math.max(1, v) })}
                  />
                  <NumberCell
                    id={`line-price-${index}`}
                    label="Unit price"
                    value={line.unit_price}
                    onChange={(v) => patch(index, { unit_price: Math.max(0, v) })}
                  />
                  <NumberCell
                    id={`line-discount-${index}`}
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

          <div className="mt-3 space-y-1 border-t border-ovs-crm-border pt-3 text-sm">
            {selectedPackage && (
              <SummaryRow label={selectedPackage.name} value={inr(packageBaseTotal)} />
            )}
            {selectedPackage && additionalUsersTotal > 0 && (
              <SummaryRow label={`Additional users (+${additionalUsers})`} value={inr(additionalUsersTotal)} />
            )}
            {addonsTotal > 0 && <SummaryRow label="Add-ons" value={inr(addonsTotal)} />}
            {customLinesTotal > 0 && <SummaryRow label="Custom items" value={inr(customLinesTotal)} />}
            <div className="flex items-baseline justify-between pt-1.5">
              <span className="text-xs font-medium text-ovs-crm-text-secondary">Subtotal · before tax</span>
              <span className="text-base font-semibold tabular-nums text-ovs-crm-text-primary">{inr(subtotal)}</span>
            </div>
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
          <CrmButton type="submit" tone="primary" disabled={processing || !leadId}>
            {processing ? 'Saving…' : 'Create draft'}
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}

function SummaryRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between text-xs text-ovs-crm-text-secondary">
      <span>{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
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
