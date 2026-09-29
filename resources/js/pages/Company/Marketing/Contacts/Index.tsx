import React, { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmAvatar,
  CrmPagination,
  CrmTabs,
  CrmFilterBar,
  useCrmConfirm,
} from '@/pages/Company/design';
import {
  Check,
  ExternalLink,
  FileSpreadsheet,
  Plus,
  Search,
  Tag as TagIcon,
  Upload,
  UserCheck,
  Users,
} from 'lucide-react';
import type {
  MarketingContact,
  MarketingTag,
  ImportBatchForDropdown,
} from './types';
import { lifecycleOf, batchLabel } from './lifecycle';
import { ContactsWorkspaceHead } from './ContactsWorkspace';
import type { ContactWorkspaceCounts } from './ContactsTabs';
import { FiltersToggle, FiltersPanel, activeChips } from './ContactFilters';
import { ContactFormModal } from './Partials/ContactFormModal';
import { ContactImportModal } from './Partials/ContactImportModal';
import { SaveAsSegmentButton } from './Partials/SaveAsSegmentModal';

/**
 * The marketing contact database.
 *
 * Built to be worked, not admired: at five to ten thousand contacts the useful
 * questions are "who is in Vadodara that I have never written to", "what came
 * in on that file last month", and "who replied" — so the lifecycle strip, the
 * location filters and the batch filter are the page, and the summary cards
 * that used to sit on top are gone. Every figure in the strip is a filter, and
 * the count on it is produced by the same query the filter runs, so clicking
 * one can never show a different number than the one you clicked.
 *
 * Import Batches lives here as a tab rather than as its own sidebar item —
 * a batch is a view of these same contacts, not a separate thing to manage.
 */

interface PaginatedData<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface IndexProps {
  contacts: PaginatedData<MarketingContact>;
  workspaceCounts?: ContactWorkspaceCounts;
  filters: {
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
  };
  states: string[];
  cities: string[];
  areas: string[];
  tags: MarketingTag[];
  importBatches: ImportBatchForDropdown[];
  metrics: {
    total_contacts: number;
    active_contacts: number;
    opted_out_contacts: number;
    suppressed_contacts: number;
  };
  /**
   * One count per lifecycle state, each produced by the same expression the
   * filter uses. Deferred, so it is absent on the first render and arrives
   * a moment later.
   */
  lifecycle?: Record<string, number>;
  /** The zone both the "Added between" filter and the row dates are read in. */
  timezone: string;
}

/**
 * A stored instant as the day the business saw it.
 *
 * The timezone comes from the server rather than from the browser, because the
 * "Added between" filter resolves its dates in that same zone. Formatting in
 * whatever zone the laptop happens to be in would stamp rows with a day the
 * filter does not agree is theirs — every row reading "24 Aug" while a filter
 * for the 24th returns nothing.
 */
const shortDate = (iso: string | null | undefined, timeZone: string) => {
  if (!iso) return '—';
  const d = new Date(iso);

  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: '2-digit', timeZone });
};

/**
 * The states worth a permanent button.
 *
 * Chosen for the daily job rather than for completeness: who is left to reach,
 * who answered, what bounced, and who must never be written to. Everything else
 * is one click away in the panel.
 *
 * `all` is here so clearing the status filter is a click, not a hunt.
 */
const QUICK_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'never_contacted', label: 'Never contacted' },
  { key: 'replied', label: 'Replied' },
  { key: 'failed', label: 'Failed' },
  { key: 'opted_out', label: 'Opted out' },
] as const;

export default function ContactsIndex({
  contacts,
  filters,
  states = [],
  cities = [],
  areas = [],
  tags = [],
  importBatches = [],
  metrics,
  workspaceCounts,
  lifecycle,
  timezone = 'Asia/Kolkata',
}: IndexProps) {
  const [search, setSearch] = useState(filters.search || '');
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [isImportModalOpen, setIsImportModalOpen] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [selectedContactIds, setSelectedContactIds] = useState<number[]>([]);
  const [bulkTag, setBulkTag] = useState('');
  const { confirm, dialog: confirmDialog } = useCrmConfirm();

  /*
   * The counts stay on screen while the next set is being fetched.
   *
   * They are deferred, so every filter change hands this component `undefined`
   * for a moment before the real figures arrive. Rendering a placeholder in
   * that gap meant the whole strip blinked out and back on every single click
   * — the numbers were never wrong, but the page looked like it was reloading
   * itself constantly. Holding the last known set keeps the strip still: the
   * figures simply update in place when the new ones land, and the skeleton is
   * only ever seen once, on a cold load when there is nothing to hold.
   */
  const lastLifecycle = useRef<Record<string, number> | undefined>(undefined);

  if (lifecycle) {
    lastLifecycle.current = lifecycle;
  }

  const counts = lifecycle ?? lastLifecycle.current;
  const countsAreStale = !lifecycle && Boolean(lastLifecycle.current);

  /*
   * Deep-linked from the header create menu, which has nowhere else to send
   * someone: a contact is created through this modal, not on a page of its own.
   */
  useEffect(() => {
    if (new URLSearchParams(window.location.search).get('create') === '1') {
      setIsAddModalOpen(true);
    }
  }, []);

  /**
   * Apply a change to the query.
   *
   * Everything is server-side because the list is paginated: filtering in the
   * browser would only ever search the page you happen to be standing on.
   * Selecting a wider location clears the narrower one, so you can never be
   * left looking at "Gujarat → Surat → Alkapuri" where Alkapuri is in Vadodara
   * and the result is silently empty.
   */
  const apply = (changes: Record<string, string | undefined>) => {
    const next: Record<string, string | undefined> = {
      ...(filters as unknown as Record<string, string>),
      ...changes,
    };

    if (changes.state !== undefined) {
      next.city = 'all';
      next.area = 'all';
    }
    if (changes.city !== undefined) {
      next.area = 'all';
    }

    for (const key of Object.keys(next)) {
      if (!next[key] || next[key] === 'all') delete next[key];
    }
    delete next.page;

    /*
     * `preserveScroll` matters as much as `preserveState` here: without it
     * every filter click threw the page back to the top, which is what made
     * changing a filter feel like a full reload rather than a refinement.
     */
    router.get('/company/marketing/contacts', next, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  /*
   * Typing waits for a pause before it asks the server anything.
   *
   * Firing on every keystroke meant "Vadodara" was eight round trips, seven of
   * them for a list nobody wanted to see. The field itself stays instant — only
   * the request is held back.
   */
  const searchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  const onSearchChange = (value: string) => {
    setSearch(value);
    clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => apply({ search: value }), 300);
  };

  useEffect(() => () => clearTimeout(searchTimer.current), []);

  /** Drop every filter but keep the search term — they are different intents. */
  const clearAll = () =>
    apply({
      state: 'all', city: 'all', area: 'all', status: 'all',
      import_batch: 'all', tags: 'all', organization: '',
      imported_from: '', imported_to: '',
    });

  const openContact = (contact: MarketingContact) =>
    router.visit(`/company/marketing/contacts/${contact.id}`);

  const toggleSelectRow = (e: React.MouseEvent, id: number) => {
    e.stopPropagation();
    setSelectedContactIds((current) =>
      current.includes(id) ? current.filter((cid) => cid !== id) : [...current, id]
    );
  };

  const toggleSelectAll = () =>
    setSelectedContactIds((current) =>
      current.length === contacts.data.length ? [] : contacts.data.map((c) => c.id)
    );

  const handleBulkTag = (tagName: string) => {
    if (!tagName.trim() || selectedContactIds.length === 0) return;
    router.post(
      '/company/marketing/contacts/bulk-tag',
      { contact_ids: selectedContactIds, tag_name: tagName.trim(), action: 'add' },
      { onSuccess: () => setSelectedContactIds([]) }
    );
  };

  const allOnPageSelected =
    contacts.data.length > 0 && selectedContactIds.length === contacts.data.length;

  const unreachable = metrics.opted_out_contacts + metrics.suppressed_contacts;
  /*
   * The chips are built once and both counted and rendered, so the "3 filters
   * on" in the heading, the "Filters (3)" on the button and the row of chips
   * can never disagree about how many filters are on.
   */
  const chips = activeChips(filters, { states, cities, areas, tags, importBatches }, apply);
  const activeFilters = chips.length;

  return (
    <MarketingShell title="Contacts">
      <ContactsWorkspaceHead
        title="Contacts"
        active="contacts"
        counts={workspaceCounts}
        stats={
          <>
            <CrmPageStat
              icon={UserCheck}
              value={metrics.active_contacts.toLocaleString('en-IN')}
              chip={unreachable > 0 ? `${unreachable} off limits` : undefined}
              chipHue="#EF5B39"
              caption="People you can still message"
            />
            <CrmPageStat
              icon={Users}
              value={metrics.total_contacts.toLocaleString('en-IN')}
              caption="On the database altogether"
            />
          </>
        }
        actions={
          <>
            <CrmButton icon={Upload} onClick={() => setIsImportModalOpen(true)}>
              Import contacts
            </CrmButton>
            <CrmButton tone="primary" icon={Plus} onClick={() => setIsAddModalOpen(true)}>
              New contact
            </CrmButton>
          </>
        }
      />

      {/* ═══ The database ═══════════════════════════════════════════════ */}
      <CrmSection
        title="Contact database"
        description={
          activeFilters > 0
            ? `${contacts.total.toLocaleString('en-IN')} matching · ${activeFilters} filter${activeFilters === 1 ? '' : 's'} on`
            : `${contacts.total.toLocaleString('en-IN')} contact${contacts.total === 1 ? '' : 's'}`
        }
        /*
         * Search and the filter controls sit on the section's heading line,
         * the same place every other list in the module puts them. Below the
         * heading the strip then carries only the quick states and the active
         * filter chips, so the row does not have to hold five different kinds
         * of control at once.
         */
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative">
              <Search
                className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-ovs-crm-text-muted"
                aria-hidden="true"
              />
              <CrmInput
                type="search"
                value={search}
                onChange={(e) => onSearchChange(e.target.value)}
                placeholder="Name, organisation, phone, city or area"
                aria-label="Search contacts"
                className="w-full pl-9 sm:w-72"
              />
            </div>
            <SaveAsSegmentButton filters={filters} activeCount={activeFilters} />
            <FiltersToggle
              open={filtersOpen}
              onToggle={() => setFiltersOpen((o) => !o)}
              activeCount={activeFilters}
            />
          </div>
        }
      >
        {/*
          * The same filter bar every CRM list uses: a segment strip for the
          * states people work by daily, one search field, and the active
          * filters as removable chips on their own line underneath.
          *
          * The chips used to share a row with the segments, and with five
          * filters on that row wrapped to six lines and pushed the table
          * further down than the filters were worth. Giving the summary its
          * own line is what the shared bar already does everywhere else.
          *
          * The segments write the same `status` parameter the panel writes —
          * one filter reached two ways, so they cannot disagree.
          */}
        <CrmFilterBar
          className="mb-4"
          segments={QUICK_FILTERS.map((quick) => ({
            key: quick.key,
            label: quick.label,
            /*
             * Every number here — "All" included — comes from the deferred
             * counts, which are this same list with every filter applied
             * *except* status. While the next set is in flight the previous
             * one stays put, dimmed a little, so the strip updates rather than
             * blinking. The pulse is only for a genuinely cold load.
             */
            count: counts ? (
              <span className={cn('transition-opacity duration-200', countsAreStale && 'opacity-50')}>
                {(counts[quick.key] ?? 0).toLocaleString('en-IN')}
              </span>
            ) : (
              <span className="inline-block h-2 w-5 animate-pulse rounded-full bg-current opacity-30" aria-hidden="true" />
            ),
          }))}
          segmentValue={filters.status || 'all'}
          onSegmentChange={(key) => apply({ status: key })}
          inlineSegments
          chips={chips}
          onClearAll={activeFilters > 0 ? clearAll : undefined}
        />

        {/*
          * The panel itself, in the page's own flow — not floating over
          * anything. Clicking the toggle grows the page by exactly the height
          * of this block and clicking it again shrinks it back; nothing here
          * is ever positioned, flipped, or clipped against a viewport.
          */}
        {filtersOpen && (
          <FiltersPanel
            filters={filters}
            options={{ states, cities, areas, tags, importBatches }}
            onApply={apply}
            onClearAll={clearAll}
            activeCount={activeFilters}
          />
        )}

        {contacts.data.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-[13px] text-ovs-crm-text-muted">
              {search || activeFilters > 0
                ? 'Nothing matches these filters.'
                : 'No contacts yet. Import a file to get started — every upload is kept as a batch you can come back to.'}
            </p>
            <CrmButton
              tone="primary"
              icon={Upload}
              className="mt-4"
              onClick={() => setIsImportModalOpen(true)}
            >
              Import contacts
            </CrmButton>
          </div>
        ) : (
          <div className="-mx-2.5">
            {contacts.data.map((contact) => {
              const selected = selectedContactIds.includes(contact.id);
              const stage = lifecycleOf(contact);
              const place = [contact.area, contact.city, contact.state].filter(Boolean).join(', ');

              return (
                <div
                  key={contact.id}
                  role="button"
                  tabIndex={0}
                  onClick={() => openContact(contact)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      openContact(contact);
                    }
                  }}
                  className="group flex cursor-pointer items-center gap-3 rounded-[14px] px-2.5 py-2.5 transition-colors hover:bg-ovs-crm-surface-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/20"
                >
                  <button
                    type="button"
                    onClick={(e) => toggleSelectRow(e, contact.id)}
                    aria-label={selected ? `Deselect ${contact.name}` : `Select ${contact.name}`}
                    aria-pressed={selected}
                    className={cn(
                      'flex h-5 w-5 shrink-0 items-center justify-center rounded-[6px] border transition-colors',
                      selected
                        ? 'border-ovs-crm-ink bg-ovs-crm-ink text-white'
                        : 'border-ovs-crm-border bg-white text-transparent hover:border-ovs-crm-text-muted'
                    )}
                  >
                    <Check className="h-3 w-3" aria-hidden="true" />
                  </button>

                  <CrmAvatar name={contact.name} size="sm" />

                  {/* Who, and where they work */}
                  <div className="min-w-0 flex-[2]">
                    <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                      {contact.name}
                    </p>
                    <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                      {contact.organization || 'No organisation'}
                    </p>
                  </div>

                  <div className="hidden min-w-0 flex-1 lg:block">
                    <p className="truncate text-[11.5px] tabular-nums text-ovs-crm-text-secondary">
                      {contact.phone}
                    </p>
                    <p className="truncate text-[11.5px] text-ovs-crm-text-muted">{place || '—'}</p>
                  </div>

                  {/* How they got here */}
                  <div className="hidden min-w-0 w-40 shrink-0 xl:block">
                    {contact.import_batch ? (
                      <Link
                        href={`/company/marketing/contacts/import/${contact.import_batch.id}`}
                        onClick={(e) => e.stopPropagation()}
                        className="truncate text-[11.5px] text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
                      >
                        {batchLabel(contact.import_batch)}
                      </Link>
                    ) : (
                      <span className="text-[11.5px] text-ovs-crm-text-muted">Added by hand</span>
                    )}
                    <p className="truncate text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                      {shortDate(contact.created_at, timezone)}
                    </p>
                  </div>

                  <span className="hidden w-20 shrink-0 text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted sm:block">
                    {shortDate(contact.last_campaign_at, timezone)}
                  </span>

                  <span className="hidden w-32 shrink-0 justify-end md:flex">
                    <CrmStatusPill tone={stage.tone}>{stage.label}</CrmStatusPill>
                  </span>

                  {/*
                    * No per-row Edit here. The whole row already opens the
                    * contact, and editing lives on that page — a button on
                    * every row that does the same thing as clicking the row
                    * was a second way to do one thing, not a shortcut.
                    */}
                  {contact.crm_lead_id && (
                    <span className="flex shrink-0 items-center">
                      <CrmButton size="sm" icon={ExternalLink} href={`/company/crm/leads/${contact.crm_lead_id}`}>
                        Lead
                      </CrmButton>
                    </span>
                  )}
                </div>
              );
            })}
          </div>
        )}

        <CrmPagination
          links={contacts.links}
          from={contacts.from}
          to={contacts.to}
          total={contacts.total}
          className="mt-4"
        />
      </CrmSection>

      {/*
        * Bulk actions dock to the bottom rather than pushing the list down —
        * a bar that moves the rows makes you lose your place the moment you
        * tick a box. Deliberately minimal: tagging is the one contact-level
        * action that belongs here, and building an audience or a campaign is
        * the campaign module's job, not this page's.
        */}
      {selectedContactIds.length > 0 && (
        <div className="crm-pop fixed inset-x-0 bottom-6 z-30 mx-auto flex w-[min(92vw,640px)] flex-wrap items-center gap-3 rounded-[24px] bg-ovs-crm-ink p-4 shadow-ovs-crmInk">
          <span className="text-[13px] font-medium text-white">
            {selectedContactIds.length} selected
          </span>

          <form
            className="flex flex-1 items-center gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              handleBulkTag(bulkTag);
              setBulkTag('');
            }}
          >
            <CrmInput
              value={bulkTag}
              onChange={(e) => setBulkTag(e.target.value)}
              placeholder="Tag all of them"
              aria-label="Tag to apply to the selected contacts"
              className="min-w-0 flex-1"
            />
            <CrmButton tone="primary" type="submit" icon={TagIcon} disabled={!bulkTag.trim()}>
              Tag
            </CrmButton>
          </form>

          <CrmButton onClick={() => setSelectedContactIds([])}>Cancel</CrmButton>
        </div>
      )}

      {/* Always a fresh contact — editing an existing one happens on its own page. */}
      <ContactFormModal
        open={isAddModalOpen}
        onClose={() => setIsAddModalOpen(false)}
        contact={null}
        tags={tags}
      />

      <ContactImportModal open={isImportModalOpen} onClose={() => setIsImportModalOpen(false)} />

      {confirmDialog}
    </MarketingShell>
  );
}
