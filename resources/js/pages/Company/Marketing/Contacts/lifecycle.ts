import type { CrmStatusTone } from '@/pages/Company/design';
import type { MarketingContact, MarketingContactImport } from './types';

/**
 * One definition of where a contact stands, shared by every screen that says it.
 *
 * The contacts list, the batch summary and the contact detail page all display
 * this. Written once here so they cannot drift into three slightly different
 * vocabularies for the same eight states — which is exactly how a list ends up
 * saying "Delivered" where the batch behind it says "Sent".
 *
 * The precedence mirrors the server (`MarketingContact::scopeFilterStatus` and
 * `MarketingContactImport::marketingStatusSummary`): compliance beats campaign,
 * because a person who opted out after a message reached them is opted out.
 */

export interface LifecycleStage {
  key: string;
  label: string;
  hue: string;
  tone: CrmStatusTone;
  /** What the state actually means, for anywhere with room to say it. */
  caption: string;
}

export const LIFECYCLE: LifecycleStage[] = [
  {
    key: 'never_contacted',
    label: 'Never contacted',
    hue: '#C9CDD6',
    tone: 'neutral',
    caption: 'Has never been on a campaign',
  },
  {
    key: 'queued',
    label: 'Queued',
    hue: '#7C6FE0',
    tone: 'warn',
    caption: 'Waiting to go out',
  },
  {
    key: 'sent',
    label: 'Sent',
    hue: '#2F6BE4',
    tone: 'warn',
    caption: 'Handed to WhatsApp',
  },
  {
    key: 'delivered',
    label: 'Delivered',
    hue: '#3E93A3',
    tone: 'good',
    caption: 'Reached their phone',
  },
  {
    key: 'read',
    label: 'Read',
    hue: '#2FA96B',
    tone: 'good',
    caption: 'They opened it',
  },
  {
    key: 'replied',
    label: 'Replied',
    hue: '#F59245',
    tone: 'good',
    caption: 'They wrote back',
  },
  {
    key: 'failed',
    label: 'Failed',
    hue: '#EF5B39',
    tone: 'bad',
    caption: 'WhatsApp rejected it',
  },
  {
    key: 'opted_out',
    label: 'Opted out',
    hue: '#EF5B39',
    tone: 'bad',
    caption: 'Asked not to be contacted',
  },
  {
    key: 'suppressed',
    label: 'Suppressed',
    hue: '#E0B21E',
    tone: 'warn',
    caption: 'Blocked by a rule or by hand',
  },
];

const BY_KEY = new Map(LIFECYCLE.map((stage) => [stage.key, stage]));

const NEVER_CONTACTED = BY_KEY.get('never_contacted') as LifecycleStage;

/**
 * Where one contact currently stands.
 *
 * Compliance first, then the status of their most recent campaign recipient
 * row, then — if they have never been on a campaign at all — never contacted.
 */
export function lifecycleOf(contact: MarketingContact): LifecycleStage {
  if (contact.marketing_status === 'opted_out' || contact.marketing_status === 'suppressed') {
    return BY_KEY.get(contact.marketing_status) ?? NEVER_CONTACTED;
  }

  if (!contact.last_campaign_status || contact.last_campaign_status === 'never_contacted') {
    return NEVER_CONTACTED;
  }

  // `pending` and `queued` are the same thing to a reader: it has not gone yet.
  const key = contact.last_campaign_status === 'pending' ? 'queued' : contact.last_campaign_status;

  return BY_KEY.get(key) ?? NEVER_CONTACTED;
}

export function stageByKey(key: string): LifecycleStage {
  return BY_KEY.get(key) ?? NEVER_CONTACTED;
}

/**
 * What to call an import batch.
 *
 * A batch is named when it is created, but older ones may only have the file
 * they came from, and a batch created some other way may have neither — so
 * there is always something to show rather than a blank cell.
 */
export function batchLabel(
  batch: Pick<MarketingContactImport, 'id' | 'batch_name' | 'original_filename'>
): string {
  return batch.batch_name || batch.original_filename || `Import #${batch.id}`;
}
