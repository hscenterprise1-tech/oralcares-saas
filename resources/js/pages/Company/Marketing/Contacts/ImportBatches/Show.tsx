import React from 'react';
import { Link, router } from '@inertiajs/react';
import { MarketingShell } from '../../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmCardIcon,
  CrmSection,
  CrmValue,
  CrmHairlineBar,
  CrmStatusPill,
  CrmButton,
  CrmAvatar,
  CrmPagination,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { AlertTriangle, FileSpreadsheet, Users } from 'lucide-react';
import type {
  MarketingContact,
  MarketingContactImport,
  ImportBatchStatusSummary,
} from '../types';
import { LIFECYCLE, lifecycleOf, batchLabel } from '../lifecycle';

/**
 * One import batch, from upload to outcome.
 *
 * The page answers one question: "I uploaded a thousand contacts. What
 * happened to all of them?" So it is two ladders read top to bottom — what the
 * file did on the way in, then where those contacts stand now — and each of the
 * second ladder's rows opens the contacts list filtered to it.
 *
 * The two ladders deliberately do not add up to the same number, and the page
 * says why: only the rows that *became* contacts have a marketing state, so
 * duplicates and invalid rows are counted on the way in and never again.
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

interface ShowProps {
  import: MarketingContactImport;
  statusSummary: ImportBatchStatusSummary;
  contacts: PaginatedData<MarketingContact>;
}

const BATCH_TONE: Record<string, CrmStatusTone> = {
  pending: 'neutral',
  processing: 'warn',
  completed: 'good',
  failed: 'bad',
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

export default function ImportBatchShow({
  import: batch,
  statusSummary,
  contacts,
}: ShowProps) {
  /* What the file did on the way in. */
  const intake = [
    {
      label: 'Became contacts',
      count: batch.imported_count,
      hue: '#2FA96B',
      caption: 'New people added to the database',
    },
    {
      label: 'Already on the list',
      count: batch.duplicate_count,
      hue: '#E0B21E',
      caption: 'Matched an existing contact and were skipped',
    },
    {
      label: 'Could not be read',
      count: batch.invalid_count,
      hue: '#EF5B39',
      caption: 'Missing a name or a usable phone number',
    },
  ];

  /* Where those contacts stand now. */
  const lifecycleRows = LIFECYCLE.map((stage) => ({
    ...stage,
    count: statusSummary[stage.key as keyof ImportBatchStatusSummary] ?? 0,
  })).filter((row) => row.count > 0 || row.key === 'never_contacted');

  const contacted = statusSummary.total - statusSummary.never_contacted;

  return (
    <MarketingShell title={batchLabel(batch)}>
      <CrmPageHead
        eyebrow="Marketing"
        title={batchLabel(batch)}
        stats={
          <>
            <CrmPageStat
              icon={Users}
              value={batch.imported_count.toLocaleString('en-IN')}
              chip={
                batch.duplicate_count + batch.invalid_count > 0
                  ? `${batch.duplicate_count + batch.invalid_count} skipped`
                  : undefined
              }
              chipHue="#E0B21E"
              caption={`Of ${batch.total_rows.toLocaleString('en-IN')} rows in the file`}
            />
            <CrmPageStat
              icon={FileSpreadsheet}
              value={contacted.toLocaleString('en-IN')}
              caption="Have been messaged at least once"
            />
          </>
        }
        actions={
          <>
            <CrmButton href="/company/marketing/contacts/import">All batches</CrmButton>
            <CrmButton
              tone="primary"
              icon={Users}
              href={`/company/marketing/contacts?import_batch=${batch.id}`}
            >
              Open in contacts
            </CrmButton>
          </>
        }
      />

      {batch.status === 'failed' && batch.error_message && (
        <div className="crm-rise mb-4 flex items-start gap-3 rounded-[24px] bg-ovs-crm-danger-soft p-5">
          <AlertTriangle
            className="mt-0.5 h-5 w-5 shrink-0 text-ovs-crm-danger-text"
            aria-hidden="true"
          />
          <div className="min-w-0">
            <p className="text-[15px] font-semibold text-ovs-crm-danger-text">This import failed</p>
            <p className="mt-0.5 text-[13px] leading-relaxed text-ovs-crm-danger-text/80">
              {batch.error_message}
            </p>
          </div>
        </div>
      )}

      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        {/* ═══ What the file did ════════════════════════════════════════ */}
        <CrmCard step={0} className="lg:col-span-5">
          <div className="mb-6 flex items-start justify-between gap-4">
            <div className="min-w-0">
              <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Rows in the file
              </p>
              <CrmValue value={batch.total_rows.toLocaleString('en-IN')} size="hero" />
              <p className="mt-2 truncate text-[11.5px] text-ovs-crm-text-muted">
                {batch.original_filename || 'No file name recorded'}
              </p>
            </div>
            <CrmStatusPill tone={BATCH_TONE[batch.status] ?? 'neutral'}>
              {batch.status === 'completed' ? 'Done' : batch.status}
            </CrmStatusPill>
          </div>

          <div className="space-y-3.5">
            {intake.map((row) => (
              <div key={row.label}>
                <div className="mb-1.5 flex items-baseline justify-between gap-3">
                  <span className="flex items-center gap-2 text-[12px] font-medium text-ovs-crm-text-secondary">
                    <span
                      className="h-1.5 w-1.5 rounded-full"
                      style={{ backgroundColor: row.hue }}
                      aria-hidden="true"
                    />
                    {row.label}
                    <span className="text-[11.5px] text-ovs-crm-text-muted">{row.caption}</span>
                  </span>
                  <span className="text-[13px] tabular-nums text-ovs-crm-text-primary">
                    {row.count.toLocaleString('en-IN')}
                  </span>
                </div>
                <CrmHairlineBar
                  value={row.count}
                  max={Math.max(batch.total_rows, 1)}
                  color={row.hue}
                />
              </div>
            ))}
          </div>

          <div className="mt-4 space-y-2 border-t border-ovs-crm-border pt-4 text-[11.5px]">
            <div className="flex items-center justify-between gap-3">
              <span className="text-ovs-crm-text-muted">Uploaded</span>
              <span className="text-ovs-crm-text-primary">{fullDate(batch.created_at)}</span>
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-ovs-crm-text-muted">By</span>
              <span className="text-ovs-crm-text-primary">{batch.creator?.name ?? '—'}</span>
            </div>
            {batch.completed_at && (
              <div className="flex items-center justify-between gap-3">
                <span className="text-ovs-crm-text-muted">Finished</span>
                <span className="text-ovs-crm-text-primary">{fullDate(batch.completed_at)}</span>
              </div>
            )}
          </div>
        </CrmCard>

        {/* ═══ Where those contacts stand now ═══════════════════════════ */}
        <CrmCard step={1} className="lg:col-span-7">
          <div className="mb-6 flex items-start justify-between gap-4">
            <div>
              <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Still to reach
              </p>
              <CrmValue value={statusSummary.never_contacted.toLocaleString('en-IN')} size="hero" />
              <p className="mt-2 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
                {statusSummary.never_contacted === 0
                  ? 'Everyone this file brought in has been messaged'
                  : `Of the ${statusSummary.total.toLocaleString('en-IN')} contacts this file added`}
              </p>
            </div>
            <CrmCardIcon icon={Users} hue="#2F6BE4" />
          </div>

          <div className="space-y-3.5">
            {lifecycleRows.map((row) => (
              <Link
                key={row.key}
                href={`/company/marketing/contacts?import_batch=${batch.id}&status=${row.key}`}
                className="block rounded-[10px] transition-colors hover:bg-ovs-crm-surface-inset"
              >
                <div className="mb-1.5 flex items-baseline justify-between gap-3">
                  <span className="flex items-center gap-2 text-[12px] font-medium text-ovs-crm-text-secondary">
                    <span
                      className="h-1.5 w-1.5 rounded-full"
                      style={{ backgroundColor: row.hue }}
                      aria-hidden="true"
                    />
                    {row.label}
                    <span className="text-[11.5px] text-ovs-crm-text-muted">{row.caption}</span>
                  </span>
                  <span className="text-[13px] tabular-nums text-ovs-crm-text-primary">
                    {row.count.toLocaleString('en-IN')}
                  </span>
                </div>
                <CrmHairlineBar
                  value={row.count}
                  max={Math.max(statusSummary.total, 1)}
                  color={row.hue}
                />
              </Link>
            ))}
          </div>

          {/*
            * Said plainly, because the two figures above genuinely differ and
            * the difference looks like an error otherwise.
            */}
          {batch.imported_count !== batch.total_rows && (
            <p className="mt-4 border-t border-ovs-crm-border pt-4 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
              These {statusSummary.total.toLocaleString('en-IN')} are the contacts this file
              created. The {(batch.duplicate_count + batch.invalid_count).toLocaleString('en-IN')}{' '}
              rows it skipped have no marketing state of their own — a duplicate row was already
              somebody else&rsquo;s contact, and stays attributed to the batch that first added them.
            </p>
          )}
        </CrmCard>
      </div>

      {/* ═══ The contacts themselves ════════════════════════════════════ */}
      <CrmSection
        step={2}
        title="Contacts from this file"
        description={`${contacts.total.toLocaleString('en-IN')} contact${contacts.total === 1 ? '' : 's'}`}
      >
        {contacts.data.length === 0 ? (
          <p className="py-12 text-center text-[13px] text-ovs-crm-text-muted">
            This batch created no contacts — every row was a duplicate or could not be read.
          </p>
        ) : (
          <div className="-mx-2.5">
            {contacts.data.map((contact) => {
              const stage = lifecycleOf(contact);
              const place = [contact.area, contact.city, contact.state].filter(Boolean).join(', ');

              return (
                <div
                  key={contact.id}
                  role="button"
                  tabIndex={0}
                  onClick={() => router.visit(`/company/marketing/contacts/${contact.id}`)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      router.visit(`/company/marketing/contacts/${contact.id}`);
                    }
                  }}
                  className="flex cursor-pointer items-center gap-4 rounded-[14px] px-2.5 py-2.5 transition-colors hover:bg-ovs-crm-surface-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/20"
                >
                  <CrmAvatar name={contact.name} size="sm" />

                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                      {contact.name}
                    </p>
                    <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                      {[contact.organization, place].filter(Boolean).join(' · ') || contact.phone}
                    </p>
                  </div>

                  <span className="hidden w-32 shrink-0 text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted sm:block">
                    {contact.phone}
                  </span>

                  <span className="flex w-32 shrink-0 justify-end">
                    <CrmStatusPill tone={stage.tone}>{stage.label}</CrmStatusPill>
                  </span>
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
    </MarketingShell>
  );
}
