import React, { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import { ContactsWorkspaceHead } from '../Contacts/ContactsWorkspace';
import type { ContactWorkspaceCounts } from '../Contacts/ContactsTabs';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmSection,
  CrmValue,
  CrmHairlineBar,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  CrmFormField,
  CrmAvatar,
  CrmPagination,
  CrmModal,
} from '@/pages/Company/design';
import {
  Ban,
  FileText,
  RotateCcw,
  Search,
  ShieldAlert,
  UserCheck,
} from 'lucide-react';

interface ContactRow {
  id: number;
  name: string;
  phone: string;
  organization: string | null;
  city: string | null;
  marketing_status: 'active' | 'opted_out' | 'suppressed';
  suppression_reason: string | null;
  suppression_source: string | null;
  status_changed_at: string | null;
  updated_at: string;
}

interface SummaryData {
  total_contacts: number;
  active_contacts: number;
  opted_out_contacts: number;
  suppressed_contacts: number;
  lead_won_suppressions: number;
  manual_suppressions: number;
  invalid_blocked_contacts: number;
}

interface IndexProps {
  workspaceCounts?: ContactWorkspaceCounts;
  contacts: {
    data: ContactRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number;
    to: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
  summary: SummaryData;
  filters: {
    status: string;
    reason: string;
    source: string;
    search: string;
  };
}

const humanize = (value: string) => value.replace(/_/g, ' ').replace(/w/g, (c) => c.toUpperCase());

const shortDate = (iso: string | null) => {
  if (!iso) return '—';
  const d = new Date(iso);

  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

export default function SuppressionIndex({ contacts, summary, filters, workspaceCounts }: IndexProps) {
  const [searchTerm, setSearchTerm] = useState(filters.search || '');
  const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
  const [reasonFilter, setReasonFilter] = useState(filters.reason || 'all');

  const [activeModal, setActiveModal] = useState<{
    type: 'suppress' | 'unsuppress' | 'opt_out';
    contact: ContactRow;
  } | null>(null);

  const form = useForm({
    reason: 'manual_suppression',
    notes: '',
  });

  const applyFilters = (newFilters: { status?: string; reason?: string; search?: string }) => {
    router.get(
      '/company/marketing/suppression',
      {
        status: newFilters.status !== undefined ? newFilters.status : statusFilter,
        reason: newFilters.reason !== undefined ? newFilters.reason : reasonFilter,
        search: newFilters.search !== undefined ? newFilters.search : searchTerm,
      },
      { preserveState: true }
    );
  };

  const submitAction = (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeModal) return;

    if (activeModal.type === 'suppress') {
      form.post(`/company/marketing/suppression/${activeModal.contact.id}/suppress`, {
        onSuccess: () => setActiveModal(null),
      });
    } else if (activeModal.type === 'unsuppress') {
      form.post(`/company/marketing/suppression/${activeModal.contact.id}/unsuppress`, {
        onSuccess: () => setActiveModal(null),
      });
    } else if (activeModal.type === 'opt_out') {
      form.post(`/company/marketing/suppression/${activeModal.contact.id}/opt-out`, {
        onSuccess: () => setActiveModal(null),
      });
    }
  };

  /*
   * This page is a compliance record, so it is laid out as one: who may not be
   * messaged, and on whose authority.
   *
   * The distinction that matters is not "suppressed vs opted out" as two equal
   * counters — it is who decided. A contact who opted out decided for
   * themselves and that is not ours to reverse casually; a contact suppressed
   * by a rule or by hand was decided for, and can be let back in. The strip
   * groups them that way.
   */
  const offLimits = summary.opted_out_contacts + summary.suppressed_contacts;
  const reachableRate =
    summary.total_contacts > 0 ? (summary.active_contacts / summary.total_contacts) * 100 : 0;

  const authority = [
    {
      label: 'They opted out',
      count: summary.opted_out_contacts,
      hue: '#EF5B39',
      caption: 'Their decision — do not undo without a reason',
    },
    {
      label: 'Suppressed by hand',
      count: summary.manual_suppressions,
      hue: '#E0B21E',
      caption: 'Someone here decided',
    },
    {
      label: 'Won as a customer',
      count: summary.lead_won_suppressions,
      hue: '#2FA96B',
      caption: 'Stopped marketing once they bought',
    },
    {
      label: 'Bad number',
      count: summary.invalid_blocked_contacts,
      hue: '#C9CDD6',
      caption: 'Blocked after repeated failures',
    },
  ];

  return (
    <MarketingShell title="Contacts">
      <ContactsWorkspaceHead
        title="Compliance"
        active="compliance"
        counts={workspaceCounts}
        stats={
          <>
            <CrmPageStat
              icon={ShieldAlert}
              value={offLimits}
              caption="Contacts no message will reach"
            />
            <CrmPageStat
              icon={UserCheck}
              value={`${Math.round(reachableRate)}%`}
              caption="Of the list is still reachable"
            />
          </>
        }
      />

      {/* ═══ On whose authority ═════════════════════════════════════════ */}
      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {authority.map((band, index) => (
          <CrmCard key={band.label} step={index}>
            <div className="mb-3 flex items-start justify-between gap-2">
              <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                {band.label}
              </p>
              <span
                className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full"
                style={{ backgroundColor: band.hue }}
                aria-hidden="true"
              />
            </div>
            <CrmValue value={band.count} size="major" />
            <p className="mt-1.5 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
              {band.caption}
            </p>
            <CrmHairlineBar
              className="mt-3"
              value={band.count}
              max={Math.max(offLimits, 1)}
              color={band.hue}
            />
          </CrmCard>
        ))}
      </div>

      {/* ═══ The register ═══════════════════════════════════════════════ */}
      <CrmSection
        step={4}
        title="Register"
        description={`${contacts.total.toLocaleString('en-IN')} contact${contacts.total === 1 ? '' : 's'} matching`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <CrmSelect
              aria-label="Filter by status"
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                applyFilters({ status: e.target.value });
              }}
              className="w-auto"
            >
              <option value="all">Everyone</option>
              <option value="active">Reachable</option>
              <option value="opted_out">Opted out</option>
              <option value="suppressed">Suppressed</option>
            </CrmSelect>

            <div className="relative">
              <Search
                className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-ovs-crm-text-muted"
                aria-hidden="true"
              />
              <CrmInput
                type="search"
                value={searchTerm}
                onChange={(e) => {
                  setSearchTerm(e.target.value);
                  applyFilters({ search: e.target.value });
                }}
                placeholder="Search name or number"
                aria-label="Search the register"
                className="w-full pl-9 sm:w-64"
              />
            </div>
          </div>
        }
      >
        {contacts.data.length === 0 ? (
          <p className="py-16 text-center text-[13px] text-ovs-crm-text-muted">
            {searchTerm || statusFilter !== 'all'
              ? 'Nothing matches these filters.'
              : 'Nobody is suppressed. Every contact can be messaged.'}
          </p>
        ) : (
          <div className="-mx-2.5">
            {contacts.data.map((contact) => {
              const reachable = contact.marketing_status === 'active';

              return (
                <div
                  key={contact.id}
                  className="group flex items-center gap-4 rounded-[14px] px-2.5 py-3 transition-colors hover:bg-ovs-crm-surface-inset"
                >
                  <CrmAvatar name={contact.name} size="sm" />

                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                      {contact.name}
                    </p>
                    <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                      {[contact.phone, contact.organization].filter(Boolean).join(' · ')}
                    </p>
                  </div>

                  {/*
                    * Why, and when. A compliance register that records the fact
                    * but not the reason cannot answer the only question anyone
                    * ever asks of it.
                    */}
                  <div className="hidden min-w-0 flex-1 sm:block">
                    <p className="truncate text-[11.5px] text-ovs-crm-text-secondary">
                      {contact.suppression_reason
                        ? humanize(contact.suppression_reason)
                        : reachable
                          ? '—'
                          : 'No reason recorded'}
                    </p>
                    <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                      {[
                        contact.suppression_source ? humanize(contact.suppression_source) : null,
                        shortDate(contact.status_changed_at),
                      ]
                        .filter((v) => v && v !== '—')
                        .join(' · ') || '—'}
                    </p>
                  </div>

                  <span className="hidden w-28 shrink-0 justify-end md:flex">
                    <CrmStatusPill tone={reachable ? 'good' : contact.marketing_status === 'opted_out' ? 'bad' : 'warn'}>
                      {reachable ? 'Reachable' : contact.marketing_status === 'opted_out' ? 'Opted out' : 'Suppressed'}
                    </CrmStatusPill>
                  </span>

                  {/*
                    * Both actions are given a fixed width rather than sized to
                    * their own label. "Suppress" and "Let back in" are
                    * different lengths, so a register mixing the two had every
                    * row's buttons starting at a slightly different place and
                    * the whole column read as ragged. Fixing the widths keeps
                    * History and the action beside it in line down the list.
                    */}
                  <span className="flex shrink-0 items-center justify-end gap-1">
                    <CrmButton
                      size="sm"
                      icon={FileText}
                      href={`/company/marketing/suppression/${contact.id}`}
                      className="w-[104px] justify-center"
                    >
                      History
                    </CrmButton>
                    {reachable ? (
                      <CrmButton
                        size="sm"
                        icon={Ban}
                        className="w-[124px] justify-center"
                        onClick={() => {
                          form.setData({ reason: 'manual_suppression', notes: '' });
                          setActiveModal({ type: 'suppress', contact });
                        }}
                      >
                        Suppress
                      </CrmButton>
                    ) : (
                      <CrmButton
                        size="sm"
                        icon={RotateCcw}
                        className="w-[124px] justify-center"
                        onClick={() => {
                          form.setData({ reason: 'manual_suppression', notes: '' });
                          setActiveModal({ type: 'unsuppress', contact });
                        }}
                      >
                        Let back in
                      </CrmButton>
                    )}
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

      {/* ═══ Suppress, or let back in ═══════════════════════════════════ */}
      <CrmModal
        open={!!activeModal}
        onClose={() => setActiveModal(null)}
        size="sm"
        title={
          activeModal?.type === 'unsuppress'
            ? 'Let them be messaged again'
            : activeModal?.type === 'opt_out'
              ? 'Record an opt-out'
              : 'Stop messaging this contact'
        }
        description={activeModal ? `${activeModal.contact.name} · ${activeModal.contact.phone}` : undefined}
        footer={
          <>
            <CrmButton onClick={() => setActiveModal(null)}>Cancel</CrmButton>
            <CrmButton
              tone={activeModal?.type === 'unsuppress' ? 'primary' : 'danger'}
              type="submit"
              form="suppression-action"
              loading={form.processing}
            >
              {activeModal?.type === 'unsuppress' ? 'Let back in' : 'Suppress'}
            </CrmButton>
          </>
        }
      >
        <form id="suppression-action" onSubmit={submitAction}>
          {activeModal?.type === 'unsuppress' ? (
            <p className="mb-4 rounded-[18px] bg-ovs-crm-warning-soft p-3 text-[11.5px] leading-relaxed text-ovs-crm-warning-text">
              Only do this if you know they want to hear from you. If they opted out themselves,
              letting them back in without their say-so is the thing opt-out exists to prevent.
            </p>
          ) : (
            <p className="mb-4 rounded-[18px] bg-ovs-crm-surface-inset p-3 text-[11.5px] leading-relaxed text-ovs-crm-text-secondary">
              No campaign or automation will reach them again until this is lifted. Their history and
              their record stay exactly as they are.
            </p>
          )}

          <CrmFormField label="Why" hint="Recorded against the contact, and readable in their history.">
            <CrmSelect value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)}>
              <option value="manual_suppression">Decided here</option>
              <option value="customer_won">They became a customer</option>
              <option value="invalid_number">The number does not work</option>
              <option value="complaint">They complained</option>
            </CrmSelect>
          </CrmFormField>

          <CrmFormField label="Notes" hint="Optional — context for whoever reads this later.">
            <CrmTextarea
              rows={3}
              value={form.data.notes}
              onChange={(e) => form.setData('notes', e.target.value)}
              placeholder="Asked to be removed on the call"
            />
          </CrmFormField>
        </form>
      </CrmModal>
    </MarketingShell>
  );
}
