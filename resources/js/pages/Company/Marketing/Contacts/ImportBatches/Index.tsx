import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { MarketingShell } from '../../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmHairlineBar,
  CrmPagination,
  CrmTabs,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { FileSpreadsheet, Search, Upload, Users } from 'lucide-react';
import type { MarketingContactImport } from '../types';
import { ContactsWorkspaceHead } from '../ContactsWorkspace';
import type { ContactWorkspaceCounts } from '../ContactsTabs';
import { batchLabel } from '../lifecycle';
import { ContactImportModal } from '../Partials/ContactImportModal';

/**
 * Every file that was ever uploaded.
 *
 * This is a history, so it reads as one: newest first, each row saying what
 * came in and what happened to it. The bar on each row is the split — imported
 * against duplicate and invalid — because "1,000 rows" means nothing without
 * knowing that 58 of them never became contacts.
 *
 * It is a tab of Contacts rather than its own sidebar item on purpose: a batch
 * is a slice of the contact database, not a separate thing to administer.
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
  batches: PaginatedData<MarketingContactImport>;
  /** All three workspace figures, so the tab strip reads the same on every view. */
  workspaceCounts?: ContactWorkspaceCounts;
}

const BATCH_TONE: Record<string, CrmStatusTone> = {
  pending: 'neutral',
  processing: 'warn',
  completed: 'good',
  failed: 'bad',
};

const BATCH_LABEL: Record<string, string> = {
  pending: 'Waiting',
  processing: 'Importing',
  completed: 'Done',
  failed: 'Failed',
};

const fullDate = (iso: string | null | undefined) => {
  if (!iso) return '—';
  const d = new Date(iso);

  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });
};

export default function ImportBatchesIndex({ batches, workspaceCounts }: IndexProps) {
  const [search, setSearch] = useState('');
  const [isImportModalOpen, setIsImportModalOpen] = useState(false);

  /*
   * Searched in the browser deliberately. This list is paginated at twenty and
   * a company has tens of batches, not thousands — a round trip per keystroke
   * would be slower than the filter it replaces. The contacts list, which can
   * hold ten thousand rows, does the opposite for exactly the same reason.
   */
  const needle = search.trim().toLowerCase();
  const visible = needle
    ? batches.data.filter((batch) =>
        [batch.batch_name, batch.original_filename, batch.creator?.name]
          .filter(Boolean)
          .some((value) => String(value).toLowerCase().includes(needle))
      )
    : batches.data;

  const importedTotal = batches.data.reduce((sum, b) => sum + b.imported_count, 0);

  return (
    <MarketingShell title="Import batches">
      <ContactsWorkspaceHead
        title="Contacts"
        active="batches"
        counts={workspaceCounts}
        stats={
          <>
            <CrmPageStat
              icon={FileSpreadsheet}
              value={batches.total}
              caption="Files uploaded so far"
            />
            <CrmPageStat
              icon={Users}
              value={importedTotal.toLocaleString('en-IN')}
              caption="Contacts they brought in"
            />
          </>
        }
        actions={
          <CrmButton tone="primary" icon={Upload} onClick={() => setIsImportModalOpen(true)}>
            Import contacts
          </CrmButton>
        }
      />

      <CrmSection
        title="Upload history"
        description={`${batches.total} batch${batches.total === 1 ? '' : 'es'}, newest first`}
        actions={
          <div className="relative">
            <Search
              className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-ovs-crm-text-muted"
              aria-hidden="true"
            />
            <CrmInput
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search batch name, file or who uploaded it"
              aria-label="Search import batches"
              className="w-full pl-9 sm:w-80"
            />
          </div>
        }
      >
        {visible.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-[13px] text-ovs-crm-text-muted">
              {needle
                ? 'No batch matches that search.'
                : 'Nothing has been imported yet. Every upload is kept here so you can always see what a file brought in.'}
            </p>
            {!needle && (
              <CrmButton
                tone="primary"
                icon={Upload}
                className="mt-4"
                onClick={() => setIsImportModalOpen(true)}
              >
                Import your first file
              </CrmButton>
            )}
          </div>
        ) : (
          <div className="-mx-2.5">
            {visible.map((batch) => {
              const rejected = batch.duplicate_count + batch.invalid_count;
              const open = () => router.visit(`/company/marketing/contacts/import/${batch.id}`);

              return (
                <div
                  key={batch.id}
                  role="button"
                  tabIndex={0}
                  onClick={open}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      open();
                    }
                  }}
                  className="group flex cursor-pointer items-center gap-4 rounded-[14px] px-2.5 py-3 transition-colors hover:bg-ovs-crm-surface-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/20"
                >
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-ovs-crm-surface-inset text-ovs-crm-text-muted">
                    <FileSpreadsheet className="h-4 w-4" aria-hidden="true" />
                  </span>

                  <div className="min-w-0 flex-[2]">
                    <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                      {batchLabel(batch)}
                    </p>
                    <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                      {[batch.original_filename, batch.creator?.name].filter(Boolean).join(' · ') ||
                        'No file recorded'}
                    </p>
                  </div>

                  <span className="hidden w-40 shrink-0 text-[11.5px] tabular-nums text-ovs-crm-text-muted lg:block">
                    {fullDate(batch.created_at)}
                  </span>

                  {/*
                    * What actually landed. The bar is imported against the full
                    * file, so a batch that mostly bounced is visible without
                    * reading the numbers.
                    */}
                  <div className="hidden w-36 shrink-0 sm:block">
                    <CrmHairlineBar
                      value={batch.imported_count}
                      max={Math.max(batch.total_rows, 1)}
                      color={rejected > 0 ? '#E0B21E' : '#2FA96B'}
                      tooltip={`${batch.imported_count} of ${batch.total_rows} rows became contacts`}
                    />
                    <p className="mt-1 text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                      {rejected > 0
                        ? `${batch.imported_count.toLocaleString('en-IN')} in · ${rejected} skipped`
                        : `${batch.imported_count.toLocaleString('en-IN')} in`}
                    </p>
                  </div>

                  <span className="hidden w-24 shrink-0 justify-end md:flex">
                    <CrmStatusPill tone={BATCH_TONE[batch.status] ?? 'neutral'}>
                      {BATCH_LABEL[batch.status] ?? batch.status}
                    </CrmStatusPill>
                  </span>
                </div>
              );
            })}
          </div>
        )}

        <CrmPagination
          links={batches.links}
          from={batches.from}
          to={batches.to}
          total={batches.total}
          className="mt-4"
        />
      </CrmSection>

      <ContactImportModal open={isImportModalOpen} onClose={() => setIsImportModalOpen(false)} />
    </MarketingShell>
  );
}
