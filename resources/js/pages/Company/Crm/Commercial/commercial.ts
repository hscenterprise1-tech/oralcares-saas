/**
 * The Commercials contract.
 *
 * Mirrors `App\Services\CRM\CommercialPageService` and nothing else does. A
 * component that keeps its own copy of a payload shape typechecks perfectly and
 * renders blank the day the server changes — the server is the contract, and
 * when it moves this file moves with it.
 *
 * Money arrives as **numbers**, not formatted strings. The old quotations
 * payload shipped `'₹ 1,234.00'`, which cannot be sorted, totalled or
 * re-rendered; `inr()` in the design kit does the formatting.
 */

/** Where a proposal is in its life. */
export type QuotationStatusValue =
  | 'draft'
  | 'sent'
  | 'negotiation'
  | 'approved'
  | 'rejected'
  | 'expired'
  | 'converted';

/**
 * Whether the money owed and the money received agree.
 *
 * `mismatch` means the document's own arithmetic is broken — its subtotal, tax
 * and discount do not add up to its total — so the expected figure itself
 * cannot be trusted. It outranks the payment states for that reason.
 *
 * `overpaid` is never rendered as settled. More money arrived than was asked
 * for, and that is something somebody has to look at.
 */
export type ReconciliationState =
  | 'unpaid'
  | 'partially_paid'
  | 'paid'
  | 'overpaid'
  | 'mismatch';

export interface Reconciliation {
  state: ReconciliationState;
  /** What the document asks for. */
  expected: number;
  /** What has actually settled. Pending gateway attempts are not counted. */
  settled: number;
  /** `expected - settled`. Negative means overpaid. */
  balance: number;
  payments: number;
}

/**
 * How a quotation stands financially.
 *
 * Null until the quotation is approved: an unapproved proposal is not money
 * owed, and giving it a settlement state would invite somebody to chase a
 * payment nobody has agreed to make.
 *
 * There is no agreement step. The flow is Quotation → Approval → Payment →
 * Invoice → Won, and `number` is the invoice's — absent until settlement raises
 * one.
 */
export interface QuotationSettlement {
  id: number;
  number: string | null;
  type: 'invoice';
  /** `not issued` until the quotation is settled in full. */
  status: string;
  grandTotal: number;
  reconciliation: Reconciliation;
  /** True for `overpaid` or `mismatch` — the states someone must act on. */
  needsAttention: boolean;
}

export interface QuotationRowAbilities {
  update: boolean;
  approve: boolean;
  /** Stating that money arrived is its own authority, separate from pricing. */
  recordPayment: boolean;
}

export interface QuotationRow {
  id: number;
  number: string | null;
  company: string;
  leadId: number | null;
  status: QuotationStatusValue;
  statusLabel: string;
  /** Ordering weight, lower is more urgent. Already applied server-side. */
  urgency: number;
  owner: string | null;

  subtotal: number;
  /** The headline discount, as recorded — not re-derived from other totals. */
  discount: number;
  grandTotal: number;
  lineCount: number;
  currency: string;

  /**
   * True once the quotation has been settled and invoiced.
   *
   * Its commercial terms are then fixed — the customer holds an invoice, and
   * repricing behind it would mean their paper and ours disagree. Render the
   * discount control disabled rather than letting somebody fill in a form the
   * server will refuse. A different price means a new revision.
   */
  financiallyLocked: boolean;

  validUntil: string | null;
  expiringSoon: boolean;
  updatedAt: string | null;

  /** Null while the quotation is not approved. */
  settlement: QuotationSettlement | null;

  can: QuotationRowAbilities;
}

export interface CommercialCounts {
  total: number;
  draft: number;
  sent: number;
  approved: number;
  expiringSoon: number;
  /** Documents whose money does not reconcile. */
  needsAttention: number;
}

/**
 * Portfolio totals, in rupees.
 *
 * `settled` counts only money actually received, so it can be well below
 * `agreed` — which is the whole reason to show both.
 */
export interface CommercialMoney {
  proposed: number;
  approved: number;
  agreed: number;
  settled: number;
  outstanding: number;
}

export interface CommercialListPayload {
  rows: QuotationRow[];
  counts: CommercialCounts;
  money: CommercialMoney;
  filters: {
    search: string;
    status: QuotationStatusValue | null;
  };
  can: {
    create: boolean;
  };
  leads: PickableLead[];
  statuses: StatusOption[];
  packages: PickablePackage[];
  addons: PickableAddon[];
}

/**
 * A quotation package a rep can build from — mirrors `CompanyPlan`. Prices
 * shown here are the catalogue's *current* prices; once selected into a
 * quotation they are frozen onto that quotation's own line items and never
 * re-read from here again.
 */
export interface PickablePackage {
  id: number;
  name: string;
  description: string | null;
  price: number;
  maxUsers: number;
  /** Null when this package does not sell additional seats. */
  additionalUserPrice: number | null;
  billingPeriod: string;
}

/** An optional add-on a package may attach — mirrors `CompanyPackageAddon`. */
export interface PickableAddon {
  id: number;
  name: string;
  type: string | null;
  price: number;
  pricingBasis: string;
}

/** What kind of line a quotation item is — metadata, never a live price source. */
export type QuotationItemType = 'package_base' | 'additional_users' | 'addon' | 'custom';

export interface QuotationLineItem {
  id: number;
  itemType?: QuotationItemType;
  title: string;
  description: string | null;
  quantity: number;
  unitPrice: number;
  discount: number;
  total: number;
}

export interface CommercialPaymentRecord {
  id: number;
  amount: number;
  status: string;
  gateway: string | null;
  reference: string | null;
  at: string | null;
  /** Null for a pending or failed payment — only a successful one gets one. */
  receiptNumber: string | null;
  receiptUrl: string | null;
}

/**
 * The GST breakdown, or null for a quotation that predates it — a legacy
 * quotation renders its old flat `tax` figure instead of a fabricated split.
 */
export interface QuotationGst {
  taxableAmount: number;
  rate: number;
  type: 'cgst_sgst' | 'igst';
  cgst: number;
  sgst: number;
  igst: number;
}

export interface QuotationDocument {
  number: string;
  url: string;
  pdfUrl: string;
  /** Present on the proforma only. */
  issuedAt?: string | null;
}

export interface QuotationDocuments {
  proforma: QuotationDocument | null;
  canIssueProforma: boolean;
  taxInvoice: QuotationDocument | null;
}

export interface CommercialDetailPayload {
  quotation: QuotationRow;
  terms: string | null;
  paymentTerms: string | null;
  implementationTerms: string | null;
  supportTerms: string | null;
  cancellationTerms: string | null;
  notes: string | null;
  rejectionReason: string | null;
  sentAt: string | null;
  approvedAt: string | null;
  versions: QuotationVersion[];
  history: CommercialHistoryEntry[];
  items: QuotationLineItem[];
  payments: CommercialPaymentRecord[];
  approval: ApprovalState;
  gst: QuotationGst | null;
  documents: QuotationDocuments;
  /**
   * Server-side Gate checks. Never re-derive these from a role or a status in
   * the browser — a client-side permission is decoration, and the server will
   * refuse regardless.
   */
  can: {
    update: boolean;
    approve: boolean;
    recordPayment: boolean;
    delete: boolean;
  };
}

/* ── List extras ─────────────────────────────────────────────────────────── */

/** A deal a new quotation can be raised against. Scoped like the list itself. */
export interface PickableLead {
  id: number;
  company: string;
}

export interface StatusOption {
  value: QuotationStatusValue;
  label: string;
}

/* ── Detail extras ───────────────────────────────────────────────────────── */

/**
 * One rung of the revision chain.
 *
 * A revised quotation is a new row pointing at its parent, so the chain is the
 * commercial history — what was offered first and what it became.
 */
export interface QuotationVersion {
  id: number;
  number: string | null;
  version: number;
  status: QuotationStatusValue;
  grandTotal: number;
  isCurrent: boolean;
  at: string | null;
}

export interface CommercialHistoryEntry {
  id: number;
  action: string | null;
  description: string | null;
  at: string | null;
  by: string | null;
}

/**
 * Commercial policy around the discount on this quotation.
 *
 * Comes from `QuotationApprovalService`, not from the payload builder — the
 * threshold and the reason a send is blocked are policy, and duplicating them
 * is how two answers to the same question appear.
 */
export type DiscountType = 'percentage' | 'fixed';

export interface ApprovalState {
  discount_percent: number;
  /** How the current discount was expressed, not just its resolved rupee amount. */
  discount_type: DiscountType;
  discount_value: number;
  /** The employee threshold — null when that gate is switched off entirely. */
  threshold_percent: number | null;
  /** The higher, no-approval-needed ceiling an admin-tier user gets. */
  admin_direct_limit_percent: number;
  requires_approval: boolean;
  is_approved: boolean;
  can_be_sent: boolean;
  can_approve: boolean;
  /** Would the *viewer* get the admin ceiling if they set a discount here. */
  viewer_is_admin_tier: boolean;
  blocked_reason: string | null;
  /** Who last set the discount, for display next to a pending approval. */
  requested_by: string | null;
}
