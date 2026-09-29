import React from 'react';
import { ArrowRight, ShieldAlert, ShieldCheck } from 'lucide-react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmCard,
  CrmSection,
  CrmStatusPill,
  CrmButton,
  CrmAvatar,
  type CrmStatusTone,
} from '@/pages/Company/design';

/**
 * One contact's consent history.
 *
 * This is the page that answers "why can we not message them, and who decided
 * that" — the only question a suppression record exists to answer. So the
 * audit trail is the page, not a panel at the bottom of it, and each entry is
 * written as a transition (from → to) rather than as a row of columns.
 */

interface AuditLogItem {
  id: number;
  old_status: string | null;
  new_status: string;
  reason: string;
  source: string;
  notes: string | null;
  created_at: string;
  changed_by?: { name: string; email: string } | null;
}

interface ContactModel {
  id: number;
  name: string;
  phone: string;
  organization: string | null;
  city: string | null;
  marketing_status: string;
  suppression_reason: string | null;
  suppression_source: string | null;
  status_changed_at: string | null;
  created_at: string;
  tags?: { id: number; name: string }[];
}

interface ShowProps {
  contact: ContactModel;
  logs: AuditLogItem[];
}

const STATUS_TONE: Record<string, CrmStatusTone> = {
  active: 'good',
  subscribed: 'good',
  opted_out: 'bad',
  suppressed: 'warn',
};

const STATUS_LABEL: Record<string, string> = {
  active: 'Reachable',
  subscribed: 'Reachable',
  opted_out: 'Opted out',
  suppressed: 'Suppressed',
};

const humanize = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const fullDate = (iso: string | null) => {
  if (!iso) return '—';
  const d = new Date(iso);

  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });
};

export default function ContactSuppressionDetail({ contact, logs }: ShowProps) {
  const reachable = contact.marketing_status === 'active' || contact.marketing_status === 'subscribed';

  return (
    <MarketingShell title={contact.name}>
      <CrmPageHead
        eyebrow="Marketing"
        title={contact.name}
        actions={<CrmButton href="/company/marketing/suppression">Back to the register</CrmButton>}
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <CrmCard step={0} className="lg:col-span-5">
          <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
              <CrmAvatar name={contact.name} size="md" />
              <div className="min-w-0">
                <h2 className="truncate text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  {contact.name}
                </h2>
                <p className="truncate text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                  {[contact.phone, contact.organization, contact.city].filter(Boolean).join(' · ')}
                </p>
              </div>
            </div>
          </div>

          <div
            className={
              reachable
                ? 'flex items-start gap-3 rounded-[18px] bg-ovs-crm-success-soft p-3.5'
                : 'flex items-start gap-3 rounded-[18px] bg-ovs-crm-danger-soft p-3.5'
            }
          >
            {reachable ? (
              <ShieldCheck
                className="mt-0.5 h-4 w-4 shrink-0 text-ovs-crm-success-text"
                aria-hidden="true"
              />
            ) : (
              <ShieldAlert
                className="mt-0.5 h-4 w-4 shrink-0 text-ovs-crm-danger-text"
                aria-hidden="true"
              />
            )}
            <div className="min-w-0">
              <p
                className={
                  reachable
                    ? 'text-[12px] font-medium text-ovs-crm-success-text'
                    : 'text-[12px] font-medium text-ovs-crm-danger-text'
                }
              >
                {reachable
                  ? 'Campaigns and automations may reach this contact'
                  : 'No campaign or automation will reach this contact'}
              </p>
              <p
                className={
                  reachable
                    ? 'mt-0.5 text-[11.5px] leading-relaxed text-ovs-crm-success-text/80'
                    : 'mt-0.5 text-[11.5px] leading-relaxed text-ovs-crm-danger-text/80'
                }
              >
                {contact.suppression_reason
                  ? `${humanize(contact.suppression_reason)}${contact.suppression_source ? ` · ${humanize(contact.suppression_source)}` : ''}`
                  : reachable
                    ? 'Nothing is holding messages back.'
                    : 'No reason was recorded.'}
              </p>
            </div>
          </div>

          <div className="mt-4 space-y-2 border-t border-ovs-crm-border pt-4 text-[11.5px]">
            <div className="flex items-center justify-between gap-3">
              <span className="text-ovs-crm-text-muted">Current status</span>
              <CrmStatusPill tone={STATUS_TONE[contact.marketing_status] ?? 'neutral'}>
                {STATUS_LABEL[contact.marketing_status] ?? humanize(contact.marketing_status)}
              </CrmStatusPill>
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-ovs-crm-text-muted">Last changed</span>
              <span className="text-ovs-crm-text-primary">{fullDate(contact.status_changed_at)}</span>
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-ovs-crm-text-muted">First added</span>
              <span className="text-ovs-crm-text-primary">{fullDate(contact.created_at)}</span>
            </div>
          </div>
        </CrmCard>

        <CrmSection
          step={1}
          title="Consent history"
          description={
            logs.length === 0
              ? 'Nothing has changed since this contact was added'
              : `${logs.length} change${logs.length === 1 ? '' : 's'}, newest first`
          }
          className="lg:col-span-7"
        >
          {logs.length === 0 ? (
            <p className="py-12 text-center text-[13px] text-ovs-crm-text-muted">
              This contact&rsquo;s status has never changed.
            </p>
          ) : (
            <div className="-mx-2.5">
              {logs.map((log) => (
                <div key={log.id} className="rounded-[14px] px-2.5 py-3">
                  {/*
                    * Written as a move from one state to another. A table of
                    * old/new columns makes the reader assemble the sentence
                    * themselves every time.
                    */}
                  <div className="mb-1 flex flex-wrap items-center gap-2">
                    {log.old_status && (
                      <>
                        <CrmStatusPill tone={STATUS_TONE[log.old_status] ?? 'neutral'}>
                          {STATUS_LABEL[log.old_status] ?? humanize(log.old_status)}
                        </CrmStatusPill>
                        <ArrowRight
                          className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted"
                          aria-hidden="true"
                        />
                      </>
                    )}
                    <CrmStatusPill tone={STATUS_TONE[log.new_status] ?? 'neutral'}>
                      {STATUS_LABEL[log.new_status] ?? humanize(log.new_status)}
                    </CrmStatusPill>
                  </div>

                  <p className="text-[13px] text-ovs-crm-text-primary">{humanize(log.reason)}</p>

                  {log.notes && (
                    <p className="mt-0.5 text-[11.5px] leading-relaxed text-ovs-crm-text-secondary">
                      {log.notes}
                    </p>
                  )}

                  <p className="mt-1 text-[11.5px] text-ovs-crm-text-muted">
                    {[
                      log.changed_by?.name ?? humanize(log.source),
                      fullDate(log.created_at),
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                </div>
              ))}
            </div>
          )}
        </CrmSection>
      </div>
    </MarketingShell>
  );
}
