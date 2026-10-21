/** The clinic's support requests as `ClinicSupportController` sends them. */

export interface SupportStatus {
  value: 'open' | 'pending' | 'resolved' | 'closed';
  label: string;
}

export interface SupportTicketRow {
  id: number;
  reference: string;
  subject: string;
  status: SupportStatus | null;
  raised_by: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface SupportTicket {
  id: number;
  reference: string;
  subject: string;
  body: string | null;
  status: SupportStatus | null;
  raised_by: string | null;
  created_at: string | null;
  resolved_at: string | null;
}

export interface SupportHistoryEntry {
  id: number;
  kind: 'opened' | 'reply' | 'status';
  body: string | null;
  status: SupportStatus | null;
  at: string | null;
}
