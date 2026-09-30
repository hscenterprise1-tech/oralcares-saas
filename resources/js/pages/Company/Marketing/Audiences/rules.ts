import { stageByKey, batchLabel } from '../Contacts/lifecycle';
import type { ImportBatchForDropdown } from '../Contacts/types';
import type { SegmentRules } from './types';

/**
 * A Segment's rules read as plain-language chips — "State: Gujarat",
 * "Never contacted", "Tag: Clinic" — the same vocabulary Contacts' own
 * filter chips use, via the same `stageByKey`/`batchLabel` helpers, so a
 * status or a batch is never described two different ways depending on
 * which screen it is read from.
 */
export interface RuleChip {
  key: string;
  label: string;
}

export function describeRules(
  rules: SegmentRules,
  importBatches: ImportBatchForDropdown[] = []
): RuleChip[] {
  const chips: RuleChip[] = [];

  if (rules.state) chips.push({ key: 'state', label: `State: ${rules.state}` });
  if (rules.city) chips.push({ key: 'city', label: `City: ${rules.city}` });
  if (rules.area) chips.push({ key: 'area', label: `Area: ${rules.area}` });

  if (rules.status) {
    chips.push({ key: 'status', label: stageByKey(rules.status).label });
  }

  if (rules.import_batch) {
    const batch = importBatches.find((b) => String(b.id) === String(rules.import_batch));
    chips.push({
      key: 'import_batch',
      label: `Batch: ${batch ? batchLabel(batch) : `Import #${rules.import_batch}`}`,
    });
  }

  if (rules.imported_from || rules.imported_to) {
    chips.push({
      key: 'imported',
      label: `Added ${rules.imported_from || 'the start'} – ${rules.imported_to || 'today'}`,
    });
  }

  if (rules.organization) {
    chips.push({ key: 'organization', label: `Org: ${rules.organization}` });
  }

  for (const tag of rules.tags ?? []) {
    if (tag) chips.push({ key: `tag-${tag}`, label: `Tag: ${tag}` });
  }

  return chips;
}

/**
 * The Contacts URL a Segment's rules describe.
 *
 * A Segment's "full list" is never rendered inside Segments itself — it is
 * this: Contacts, with these same rules applied as its ordinary filters. The
 * mapping is direct because the shapes are the same thing; there is no
 * translation step to keep in sync as either side changes.
 */
export function segmentContactsUrl(rules: SegmentRules): string {
  const params = new URLSearchParams();

  if (rules.state) params.set('state', rules.state);
  if (rules.city) params.set('city', rules.city);
  if (rules.area) params.set('area', rules.area);
  if (rules.organization) params.set('organization', rules.organization);
  if (rules.tags && rules.tags.length > 0) params.set('tags', rules.tags.join(','));
  if (rules.import_batch) params.set('import_batch', rules.import_batch);
  if (rules.imported_from) params.set('imported_from', rules.imported_from);
  if (rules.imported_to) params.set('imported_to', rules.imported_to);
  if (rules.status) params.set('status', rules.status);

  const qs = params.toString();

  return `/company/marketing/contacts${qs ? `?${qs}` : ''}`;
}
