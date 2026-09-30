/**
 * A Segment's rules are the same shape Contacts' own filter state already
 * is — state/city/area/organization/tags/import_batch/date-range/status —
 * because a Segment *is* a saved Contacts filter, not a parallel query
 * language. See `AudienceQueryService` on the backend for where this shape
 * actually gets turned into a query.
 */
export interface SegmentRules {
  state?: string;
  city?: string;
  area?: string;
  organization?: string;
  tags?: string[];
  import_batch?: string;
  imported_from?: string;
  imported_to?: string;
  status?: string;
}

/** A campaign that has used a Segment for targeting — from `campaigns()`. */
export interface CampaignUsage {
  id: number;
  name: string;
  status: string;
  created_at: string;
}

export interface SegmentListItem {
  id: number;
  name: string;
  description: string | null;
  status: 'active' | 'paused';
  rules: SegmentRules;
  updated_at: string;
  /** How many contacts these rules match right now — never cached. */
  match_count: number;
  campaigns_count: number;
  last_used_at: string | null;
}

export interface SegmentDetail {
  id: number;
  name: string;
  description: string | null;
  status: 'active' | 'paused';
  rules: SegmentRules;
  created_at: string;
  updated_at: string;
  match_count: number;
  campaigns_count: number;
}

/** A contact as the small builder/detail preview shows it — a name to recognise, nothing more. */
export interface SegmentPreviewContact {
  id: number;
  name: string;
  organization: string | null;
  city: string | null;
}
