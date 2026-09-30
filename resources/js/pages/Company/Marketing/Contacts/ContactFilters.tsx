import React, { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';

/*
 * The panel's chrome now lives in the design kit. It was worked out here first,
 * then lifted so the CRM could use the same filtering UX instead of inventing a
 * second one. Same markup, same behaviour — only the address changed.
 */
import {
  CrmFiltersToggle as FiltersToggle,
  CrmFilterGroup as Group,
  CrmFilterPill as Pill,
} from '@/pages/Company/design';

export { FiltersToggle };
import { CrmButton, CrmDatePicker, CrmInput, type CrmFilterChip } from '@/pages/Company/design';
import {
  Select,
  SelectTrigger,
  SelectContent,
  SelectItem,
  SelectValue,
} from '@/pages/Company/design/CrmSelectPrimitives';
import {
  MapPin,
  Send,
  FileSpreadsheet,
  Tag as TagIcon,
  Building2,
  SlidersHorizontal,
  ChevronUp,
} from 'lucide-react';
import { LIFECYCLE, stageByKey, batchLabel } from './lifecycle';
import type { ImportBatchForDropdown, MarketingTag } from './types';

/**
 * The advanced half of the contacts filter surface.
 *
 * The toolbar carries the five states people work by every day; everything
 * else lives behind this button, which reveals a panel in the page itself —
 * not an overlay of any kind. Two earlier versions tried a popover, then a
 * centred modal, and both fought their own positioning: a popover clipped
 * itself against the viewport it was supposed to fit inside, and a modal
 * covered the very list it was meant to be narrowing. A panel that pushes the
 * page down instead has no viewport to fight — it is just more of the page,
 * the same way the "More filters" row on every other CRM list already works.
 *
 * The dropdowns inside it are the CRM's own themed \`Select\`
 * (\`CrmSelectPrimitives\`, already used by CRM Leads and Pipeline), not the
 * plain HTML \`<select>\`. A bare \`<select>\` looks fine closed, but opening it
 * hands the list to the operating system — an unstyled system menu in the
 * middle of an otherwise designed page. The themed select opens its own
 * panel in the same rounded-white-sheet language as everything else here.
 */

export interface ContactFilterState {
  search: string;
  tags: string[];
  state: string;
  city: string;
  area: string;
  status: string;
  import_batch: string;
  organization: string;
  imported_from: string;
  imported_to: string;
  sort_by: string;
  sort_dir: string;
}

export interface ContactFilterOptions {
  states: string[];
  cities: string[];
  areas: string[];
  tags: MarketingTag[];
  importBatches: ImportBatchForDropdown[];
}

type Apply = (changes: Partial<Record<keyof ContactFilterState, string>>) => void;

const isSet = (value: string | undefined) => Boolean(value) && value !== 'all';

/**
 * The lifecycle states the toolbar already carries.
 *
 * The panel deliberately does not repeat them: two controls for one filter
 * makes the panel taller and says the same thing twice. Whichever of these is
 * active shows as a chip and lit on its toolbar button.
 */
export const TOOLBAR_STATES = ['never_contacted', 'replied', 'failed', 'opted_out'];

/**
 * Everything currently narrowing the list, in the order it reads: where you are
 * looking, what those people have done, where they came from, how they are
 * labelled.
 */
export function activeChips(
  filters: ContactFilterState,
  options: ContactFilterOptions,
  apply: Apply
): CrmFilterChip[] {
  const chips: CrmFilterChip[] = [];

  const place: Array<[keyof ContactFilterState, string]> = [
    ['state', 'State'],
    ['city', 'City'],
    ['area', 'Area'],
  ];

  for (const [key, group] of place) {
    if (isSet(filters[key] as string)) {
      chips.push({
        key,
        group,
        label: filters[key] as string,
        onRemove: () => apply({ [key]: 'all' }),
      });
    }
  }

  if (isSet(filters.status)) {
    chips.push({
      key: 'status',
      group: 'Status',
      label: stageByKey(filters.status).label,
      onRemove: () => apply({ status: 'all' }),
    });
  }

  if (isSet(filters.import_batch)) {
    const batch = options.importBatches.find((b) => String(b.id) === String(filters.import_batch));

    chips.push({
      key: 'import_batch',
      group: 'Batch',
      label: batch ? batchLabel(batch) : `Import #${filters.import_batch}`,
      onRemove: () => apply({ import_batch: 'all' }),
    });
  }

  if (filters.imported_from || filters.imported_to) {
    chips.push({
      key: 'imported',
      group: 'Added',
      label: `${filters.imported_from || 'the start'} – ${filters.imported_to || 'today'}`,
      onRemove: () => apply({ imported_from: '', imported_to: '' }),
    });
  }

  if (filters.organization) {
    chips.push({
      key: 'organization',
      group: 'Organisation',
      label: filters.organization,
      onRemove: () => apply({ organization: '' }),
    });
  }

  for (const tag of filters.tags ?? []) {
    if (tag && tag !== 'all') {
      chips.push({
        key: `tag-${tag}`,
        group: 'Tag',
        label: tag,
        onRemove: () =>
          apply({ tags: (filters.tags ?? []).filter((t) => t !== tag).join(',') || 'all' }),
      });
    }
  }

  return chips;
}

/**
 * The toggle button alone — placed in the toolbar's action slot.
 *
 * Split from the panel itself because the two live in different parts of the
 * page: this sits beside the search field, the panel it opens spans the full
 * width beneath the whole toolbar row. `open` is owned by the page, not by
 * this button, so both pieces agree about it.
 */

/**
 * One filter group: an icon, a name, a count of what it is doing, and its
 * controls on a sunken ground.
 *
 * Every group wears the same clothes so the panel reads as one thing rather
 * than as five unrelated widgets stacked on white.
 */


/**
 * The themed select, sized to sit inside a Group without its own extra chrome.
 *
 * Takes value/label pairs rather than plain strings — a batch's option reads
 * as its name but applies as its id, and forcing every caller through the
 * same shape means the two can never be quietly conflated the way a
 * string-only version invites.
 */
function GroupSelect({
  value,
  onChange,
  placeholder,
  ariaLabel,
  options,
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder: string;
  ariaLabel: string;
  options: Array<{ value: string; label: string }>;
}) {
  return (
    <Select value={value || 'all'} onValueChange={onChange}>
      <SelectTrigger aria-label={ariaLabel} className="h-10 bg-ovs-crm-surface-inset">
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">{placeholder}</SelectItem>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

/**
 * The panel itself — the full grouped set of controls, in normal page flow.
 *
 * Rendered by the page directly beneath the toolbar row, only while the
 * toggle says it is open. There is nothing to position or clip: it is a block
 * of content like any other, and the page simply grows to hold it.
 */
export function FiltersPanel({
  filters,
  options,
  onApply,
  onClearAll,
  activeCount,
}: {
  filters: ContactFilterState;
  options: ContactFilterOptions;
  onApply: Apply;
  onClearAll: () => void;
  activeCount: number;
}) {
  /*
   * Organisation is typed, so it is held locally and submitted rather than
   * fired per keystroke. Everything else applies on change, so the panel
   * behaves one way throughout.
   */
  const [organization, setOrganization] = useState(filters.organization ?? '');

  useEffect(() => setOrganization(filters.organization ?? ''), [filters.organization]);

  const selectedTags = filters.tags ?? [];

  const toggleTag = (name: string) => {
    const next = selectedTags.includes(name)
      ? selectedTags.filter((t) => t !== name)
      : [...selectedTags, name];

    onApply({ tags: next.join(',') || 'all' });
  };

  const locationCount = [filters.state, filters.city, filters.area].filter((v) => isSet(v)).length;
  const sourceCount =
    (isSet(filters.import_batch) ? 1 : 0) + (filters.imported_from || filters.imported_to ? 1 : 0);
  const detailActive = isSet(filters.status) && !TOOLBAR_STATES.includes(filters.status);

  return (
    <div className="crm-pop mb-4 rounded-[20px] bg-ovs-crm-surface-inset p-4">
      <div className="gap-x-6 sm:columns-2 lg:columns-3">
        <Group icon={MapPin} label="Location" active={locationCount}>
          {/*
            * No labels above these: each select already names its own level
            * in its resting state ("Any state", "Any city"), so a label would
            * only say it twice.
            */}
          <div className="space-y-2">
            <GroupSelect
              ariaLabel="State"
              placeholder="Any state"
              value={filters.state}
              onChange={(value) => onApply({ state: value })}
              options={options.states.map((s) => ({ value: s, label: s }))}
            />
            <GroupSelect
              ariaLabel="City"
              placeholder="Any city"
              value={filters.city}
              onChange={(value) => onApply({ city: value })}
              options={options.cities.map((c) => ({ value: c, label: c }))}
            />
            <GroupSelect
              ariaLabel="Area"
              placeholder="Any area"
              value={filters.area}
              onChange={(value) => onApply({ area: value })}
              options={options.areas.map((a) => ({ value: a, label: a }))}
            />
          </div>
        </Group>

        <Group icon={FileSpreadsheet} label="Source" active={sourceCount}>
          <div className="space-y-2.5">
            <GroupSelect
              ariaLabel="Import batch"
              placeholder="Any import batch"
              value={filters.import_batch}
              onChange={(value) => onApply({ import_batch: value })}
              options={options.importBatches.map((batch) => ({ value: String(batch.id), label: batchLabel(batch) }))}
            />

            <div>
              <span className="mb-1 block text-[10.5px] font-medium uppercase tracking-[0.08em] text-ovs-crm-text-muted">
                Added between
              </span>
              <div className="flex items-center gap-2">
                <CrmDatePicker
                  value={filters.imported_from ?? ''}
                  onChange={(e) => onApply({ imported_from: e.target.value })}
                  aria-label="Added from"
                  placeholder="From"
                  max={filters.imported_to || undefined}
                  className="min-w-0 flex-1 bg-white"
                />
                <span className="shrink-0 text-[11px] text-ovs-crm-text-muted">to</span>
                <CrmDatePicker
                  value={filters.imported_to ?? ''}
                  onChange={(e) => onApply({ imported_to: e.target.value })}
                  aria-label="Added until"
                  placeholder="To"
                  min={filters.imported_from || undefined}
                  className="min-w-0 flex-1 bg-white"
                />
              </div>
            </div>
          </div>
        </Group>

        {/*
          * Only the delivery detail lives here. All / Never contacted /
          * Replied / Failed / Opted out are on the toolbar.
          */}
        <Group icon={Send} label="Delivery detail" active={detailActive ? 1 : 0}>
          <div className="flex flex-wrap gap-1.5">
            {LIFECYCLE.filter((stage) => !TOOLBAR_STATES.includes(stage.key)).map((stage) => (
              <Pill
                key={stage.key}
                on={filters.status === stage.key}
                hue={stage.hue}
                onClick={() => onApply({ status: filters.status === stage.key ? 'all' : stage.key })}
              >
                {stage.label}
              </Pill>
            ))}
          </div>
          <p className="mt-2 text-[11px] leading-relaxed text-ovs-crm-text-muted">
            Everyday states sit on the toolbar.
          </p>
        </Group>

        {options.tags.length > 0 && (
          <Group icon={TagIcon} label="Tags" active={selectedTags.filter(Boolean).length}>
            <div className="flex flex-wrap gap-1.5">
              {options.tags.map((tag) => (
                <Pill
                  key={tag.id}
                  on={selectedTags.includes(tag.name)}
                  hue={tag.color || '#8C9BB5'}
                  onClick={() => toggleTag(tag.name)}
                >
                  {tag.name}
                </Pill>
              ))}
            </div>
            <p className="mt-2 text-[11px] leading-relaxed text-ovs-crm-text-muted">
              Matches every tag picked.
            </p>
          </Group>
        )}

        {/*
          * Organisation is typed, not picked. There can be thousands of them
          * and no select should try to hold that many.
          */}
        <Group icon={Building2} label="Organisation" active={filters.organization ? 1 : 0}>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              onApply({ organization });
            }}
          >
            <CrmInput
              value={organization}
              onChange={(e) => setOrganization(e.target.value)}
              placeholder="Part of the name is enough"
              aria-label="Organisation name"
              className="bg-white"
            />
            <p className="mt-2 text-[11px] text-ovs-crm-text-muted">Press Enter to apply.</p>
          </form>
        </Group>
      </div>

      {activeCount > 0 && (
        <div className="mt-1 flex items-center justify-end border-t border-ovs-crm-border pt-3">
          <button
            type="button"
            onClick={() => {
              setOrganization('');
              onClearAll();
            }}
            className="text-[11.5px] font-semibold text-ovs-crm-text-secondary transition-colors hover:text-ovs-crm-text-primary hover:underline"
          >
            Clear all filters
          </button>
        </div>
      )}
    </div>
  );
}
