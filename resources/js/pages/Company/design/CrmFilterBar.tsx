import React from 'react';
import { Loader2, Search, SlidersHorizontal, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CrmTabs } from './CrmControls';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  toSelectValue,
  fromSelectValue,
} from './CrmSelectPrimitives';

/**
 * The one filter pattern for every CRM list.
 *
 * Built to the reference language: a rounded translucent bar carrying a pill
 * search field, pill segment filters and circular controls. Active filters
 * surface as removable chips underneath so "what am I looking at" is never
 * hidden inside a dropdown.
 */

export interface CrmFilterOption {
  value: string;
  label: string;
  count?: number;
}

export interface CrmFilterDef {
  key: string;
  label: string;
  value: string;
  options: CrmFilterOption[];
  emptyValue?: string;
  advanced?: boolean;
}

/**
 * An active filter supplied by the caller rather than derived from a select.
 *
 * Most lists filter by a handful of dropdowns, and the bar can work their chips
 * out on its own. Some — a location hierarchy, a date range, a typed lookup —
 * cannot be expressed as a select, and those pages still deserve the same chip
 * row rather than a second one of their own invention.
 */
export interface CrmFilterChip {
  key: string;
  /** The muted prefix, e.g. "City". Omit where the value speaks for itself. */
  group?: string;
  label: string;
  onRemove: () => void;
}

export interface CrmFilterBarProps {
  searchValue?: string;
  onSearchChange?: (value: string) => void;
  searchPlaceholder?: string;
  /** Shows a spinner in place of the search icon while a server round-trip (search/filter) is in flight. */
  loading?: boolean;
  filters?: CrmFilterDef[];
  onFilterChange?: (key: string, value: string) => void;
  onClearAll?: () => void;
  /**
   * Quick segment filters rendered as a pill group before the search.
   *
   * `count` takes a node rather than only a number so a page whose counts
   * arrive after the first render can hand over a placeholder in their stead.
   */
  segments?: { key: string; label: string; count?: React.ReactNode }[];
  segmentValue?: string;
  onSegmentChange?: (key: string) => void;
  /** Active filters the caller renders itself, shown in the same chip row. */
  chips?: CrmFilterChip[];
  /**
   * Put the segment strip on the same line as the search field instead of on
   * its own line above it.
   *
   * Worth it for a bar carrying nothing but search and one button, where the
   * separate strip spends a whole line to say very little. A bar with several
   * inline selects should leave it off, or the row runs out of width.
   */
  inlineSegments?: boolean;
  actions?: React.ReactNode;
  resultCount?: number;
  resultNoun?: string;
  className?: string;
}

function isActive(filter: CrmFilterDef): boolean {
  const empty = filter.emptyValue ?? 'all';

  return filter.value !== '' && filter.value !== empty && filter.value != null;
}

/**
 * Same premium popover `CrmSelect` uses, in the bar's pill shape. `options`
 * here is already the structured `{value, label}[]` `CrmFilterDef` carries —
 * no `<option>`-children parsing needed, just the same empty-value sentinel
 * swap so a filter using `""` (or the default `"all"`) as its unset value
 * still renders through Radix, which rejects a literal `""` item value.
 * The public `CrmFilterDef`/`onFilterChange(key, value)` contract is
 * unchanged, so every existing `CrmFilterBar` caller inherits this without
 * touching its own code.
 */
function FilterSelect({
  filter,
  onChange,
}: {
  filter: CrmFilterDef;
  onChange?: (key: string, value: string) => void;
}) {
  const active = isActive(filter);
  const emptyValue = filter.emptyValue ?? 'all';

  /*
   * The trigger paints its own label — `SelectValue` is deliberately not
   * used here.
   *
   * Radix's value part decides what to show from its own internal state:
   * it teleports the chosen item's text out of the item collection, which
   * sits in a detached fragment whenever the popover is closed. A filter
   * change swaps `value` and rebuilds `options` in one update (what an
   * Inertia visit does on every selection), and that left the control
   * rendering empty while the active-filter chip above it proved a value
   * was set. Passing the label into `SelectValue` as children still left
   * Radix deciding whether to paint them, so the label is simply rendered
   * directly instead: whatever string is resolved here is what appears,
   * with no portal and no internal state in between.
   */
  const selectedOption = filter.options.find((option) => String(option.value) === String(filter.value));
  const triggerLabel = active ? (selectedOption?.label ?? filter.label) : filter.label;

  return (
    <Select
      value={toSelectValue(String(filter.value))}
      onValueChange={(next) => onChange?.(filter.key, fromSelectValue(next))}
    >
      <SelectTrigger
        aria-label={filter.label}
        className={cn(
          'h-9 w-auto gap-1.5 rounded-full border px-3.5 py-0 text-[13px] font-medium transition-colors',
          active
            /*
             * `focus:bg-ovs-crm-ink` is belt and braces now.
             *
             * It was load-bearing when the shared trigger changed its
             * background on focus: after choosing an option the trigger keeps
             * focus, the white focus background won while `text-white` stayed,
             * and the pill read as empty until focus moved away. The trigger
             * no longer paints on focus — it draws a ring instead — so this
             * cannot recur, but holding the ink background explicitly costs
             * nothing and states the intent.
             */
            ? 'border-transparent bg-ovs-crm-ink text-white focus:bg-ovs-crm-ink'
            : 'border-transparent bg-white text-ovs-crm-text-secondary shadow-ovs-crmPanel hover:text-ovs-crm-text-primary'
        )}
      >
        <span className="truncate">{triggerLabel}</span>
      </SelectTrigger>
      <SelectContent>
        {filter.options.map((option) => (
          <SelectItem key={option.value} value={toSelectValue(option.value)}>
            {option.value === emptyValue ? filter.label : option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

export function CrmFilterBar({
  searchValue = '',
  onSearchChange,
  searchPlaceholder = 'Search…',
  loading = false,
  filters = [],
  onFilterChange,
  onClearAll,
  segments,
  segmentValue,
  onSegmentChange,
  chips = [],
  inlineSegments = false,
  actions,
  resultCount,
  resultNoun = 'result',
  className,
}: CrmFilterBarProps) {
  const [showAdvanced, setShowAdvanced] = React.useState(false);

  const primaryFilters = filters.filter((f) => !f.advanced);
  const advancedFilters = filters.filter((f) => f.advanced);
  const activeFilters = filters.filter(isActive);
  const hasAnythingApplied =
    activeFilters.length > 0 || chips.length > 0 || searchValue.trim() !== '';

  /*
   * Quick segments render through `CrmTabs` — the same component the Contacts
   * and Deals tab bars use.
   *
   * This was a second, hand-built copy of that control living inside the filter
   * bar. Same container, same black active pill, same count badge, but drifted
   * in five places: font-bold against font-semibold, a different muted token for
   * the inactive badge, white/20 against white/25, gap-1 against gap-0.5, and a
   * hover fill only one of them had. None of those were decisions — they were
   * two builds of one control aging apart, and side by side the pages looked
   * like they came from different systems. One implementation, so they cannot
   * drift again.
   */
  const segmentStrip = segments && segments.length > 0 && (
    <CrmTabs
      value={segmentValue ?? ''}
      onChange={(key) => onSegmentChange?.(key)}
      tabs={segments}
    />
  );

  return (
    <div className={cn('space-y-3', className)}>
      {segmentStrip && !inlineSegments && segmentStrip}

      <div className="flex flex-wrap items-center gap-2">
        {segmentStrip && inlineSegments && segmentStrip}

        {/*
          * The search field shrinks to 180px before anything on this bar wraps.
          *
          * At 220 a bar carrying five segment tabs, two filters and an action
          * ran out of room on a 1120px content column and pushed the last
          * control onto a second line — a stacked toolbar over a table, which
          * is the thing these bars exist to avoid. A search field narrower than
          * its placeholder is a smaller cost than a toolbar that wraps.
          */}
        {onSearchChange && (
          <div className="relative min-w-[180px] flex-1 sm:max-w-sm">
            {loading ? (
              <Loader2
                className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-ovs-crm-text-muted"
                aria-hidden="true"
              />
            ) : (
              <Search
                className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-ovs-crm-text-muted"
                aria-hidden="true"
              />
            )}
            <input
              type="search"
              value={searchValue}
              onChange={(event) => onSearchChange(event.target.value)}
              placeholder={searchPlaceholder}
              aria-label={searchPlaceholder}
              /*
               * Hand-rolled rather than CrmInput because it is a pill, not a
               * 16px field — but it follows the same rule as every other
               * control: white ground with a real edge at rest, a ring on
               * focus. It kept the old borderless grey through the control
               * rework because nothing shared reached it.
               */
              className="h-10 w-full rounded-full border border-ovs-crm-border bg-white pl-11 pr-4 text-[13px] text-ovs-crm-text-primary transition-[box-shadow,border-color] placeholder:text-ovs-crm-text-muted hover:border-ovs-crm-border-strong focus:border-ovs-crm-ink/30 focus:outline-none focus:ring-2 focus:ring-ovs-crm-ink/15"
            />
          </div>
        )}

        {primaryFilters.map((filter) => (
          <FilterSelect key={filter.key} filter={filter} onChange={onFilterChange} />
        ))}

        {advancedFilters.length > 0 && (
          <button
            type="button"
            onClick={() => setShowAdvanced((open) => !open)}
            aria-expanded={showAdvanced}
            className="crm-lift inline-flex h-9 items-center gap-1.5 rounded-full bg-white px-3.5 text-[13px] font-medium text-ovs-crm-text-secondary shadow-ovs-crmPanel hover:text-ovs-crm-text-primary"
          >
            <SlidersHorizontal className="h-3.5 w-3.5" aria-hidden="true" />
            More
          </button>
        )}

        <div className="ml-auto flex items-center gap-2">
          {typeof resultCount === 'number' && (
            <span className="text-[11.5px] font-medium tabular-nums text-ovs-crm-text-muted">
              {resultCount} {resultNoun}
              {resultCount === 1 ? '' : 's'}
            </span>
          )}
          {actions}
        </div>
      </div>

      {showAdvanced && advancedFilters.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-[18px] bg-ovs-crm-surface-inset p-3">
          {advancedFilters.map((filter) => (
            <FilterSelect key={filter.key} filter={filter} onChange={onFilterChange} />
          ))}
        </div>
      )}

      {hasAnythingApplied && (
        <div className="flex flex-wrap items-center gap-1.5">
          {activeFilters.map((filter) => {
            const option = filter.options.find((o) => o.value === filter.value);

            return (
              <button
                key={filter.key}
                type="button"
                onClick={() => onFilterChange?.(filter.key, filter.emptyValue ?? 'all')}
                className="inline-flex items-center gap-1.5 rounded-full bg-white py-1 pl-3 pr-2 text-[11.5px] font-medium text-ovs-crm-text-primary shadow-ovs-crmPanel transition-colors hover:bg-ovs-crm-surface-inset"
              >
                <span className="font-normal text-ovs-crm-text-muted">{filter.label}:</span>
                {option?.label ?? filter.value}
                <X className="h-3 w-3 text-ovs-crm-text-muted" aria-hidden="true" />
                <span className="sr-only">Remove {filter.label} filter</span>
              </button>
            );
          })}

          {/* Caller-supplied chips, in the same clothes as the derived ones. */}
          {chips.map((chip) => (
            <button
              key={chip.key}
              type="button"
              onClick={chip.onRemove}
              className="inline-flex items-center gap-1.5 rounded-full bg-white py-1 pl-3 pr-2 text-[11.5px] font-medium text-ovs-crm-text-primary shadow-ovs-crmPanel transition-colors hover:bg-ovs-crm-surface-inset"
            >
              {chip.group && (
                <span className="font-normal text-ovs-crm-text-muted">{chip.group}:</span>
              )}
              {chip.label}
              <X className="h-3 w-3 text-ovs-crm-text-muted" aria-hidden="true" />
              <span className="sr-only">
                Remove {chip.group ?? ''} {chip.label} filter
              </span>
            </button>
          ))}

          {onClearAll && (
            <button
              type="button"
              onClick={onClearAll}
              className="ml-1 text-[11.5px] font-semibold text-ovs-crm-text-secondary underline-offset-2 hover:text-ovs-crm-text-primary hover:underline"
            >
              Clear all
            </button>
          )}
        </div>
      )}
    </div>
  );
}
