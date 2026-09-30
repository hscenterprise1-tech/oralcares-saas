/**
 * Shapes the contacts pages read.
 *
 * The MarketingContact type now includes batch traceability fields (import_id,
 * import_batch_name), geographic hierarchy (state, area), and a campaign-derived
 * last_campaign_status that replaces the simple active/suppressed pill in lists.
 */

export interface MarketingTag {
  id: number;
  name: string;
  slug: string;
  color?: string;
}

export type MarketingStatusValue =
  | 'active'
  | 'subscribed'
  | 'opted_out'
  | 'suppressed';

/**
 * The raw status of the contact's most recent `marketing_campaign_recipients`
 * row, or `never_contacted` when there is no such row.
 *
 * These are the values the database actually holds, `pending` and `skipped`
 * included. The UI folds `pending` into "Queued" when it displays them — both
 * mean the message has not gone out — but the column itself is not narrowed on
 * the way out of the server.
 */
export type CampaignDeliveryStatus =
  | 'never_contacted'
  | 'pending'
  | 'queued'
  | 'sent'
  | 'delivered'
  | 'read'
  | 'replied'
  | 'failed'
  | 'skipped';

export interface MarketingContact {
  id: number;
  name: string;
  phone: string;
  normalized_phone?: string;
  organization: string | null;
  city: string | null;
  state: string | null;
  area: string | null;
  /** Compliance status — opted_out / suppressed take priority over campaign status. */
  marketing_status: MarketingStatusValue;
  /** Derived from the latest campaign_recipients row. NULL if never sent a campaign. */
  last_campaign_status: CampaignDeliveryStatus | null;
  /** When that most recent campaign row last moved. NULL if never on one. */
  last_campaign_at?: string | null;
  created_at: string;
  tags: MarketingTag[];
  suppression_reason?: string | null;
  /** Set once this contact has been handed to the CRM as a lead. */
  crm_lead_id?: number | null;
  /** The import batch that created this contact, or null for manually-added contacts. */
  import_id?: number | null;
  import_batch?: MarketingContactImport | null;
}

export interface MarketingContactImport {
  id: number;
  batch_name: string | null;
  original_filename: string | null;
  /** Human-readable label: batch_name if set, else original_filename, else "Import #{id}" */
  display_name?: string;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  total_rows: number;
  imported_count: number;
  duplicate_count: number;
  invalid_count: number;
  error_message?: string | null;
  created_at: string;
  completed_at?: string | null;
  creator?: { id: number; name: string } | null;
}

/** Aggregated marketing status for all contacts in a single import batch. */
export interface ImportBatchStatusSummary {
  total: number;
  never_contacted: number;
  queued: number;
  sent: number;
  delivered: number;
  read: number;
  replied: number;
  failed: number;
  opted_out: number;
  suppressed: number;
}

export interface ImportBatchForDropdown {
  id: number;
  batch_name: string | null;
  original_filename: string | null;
  imported_count: number;
  created_at: string;
}
