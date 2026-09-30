import React, { useState } from 'react';
import { Head, useForm, router, Link } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import { SettingsWorkspaceHead } from '../Settings/SettingsWorkspace';
import {
  CrmPageStat,
  CrmCard,
  CrmSection,
  CrmStatusPill,
  CrmButton,
  CrmSelect,
  type CrmStatusTone,
} from '@/pages/Company/design';
import {
  CheckCircle2,
  FileText,
  Info,
  RefreshCw,
  Settings,
} from 'lucide-react';

interface WhatsAppAccount {
  id: number;
  display_phone_number: string;
  verified_name: string;
}

interface TemplateItem {
  id: number;
  name: string;
  language: string;
  category: string;
  status: 'draft' | 'pending' | 'approved' | 'rejected' | 'paused';
  body: string;
  header_type: string | null;
  footer: string | null;
  variables: string[] | null;
  updated_at: string;
  whatsapp_account?: WhatsAppAccount;
}

interface PaginatedData<T> {
  data: T[];
  current_page: number;
  total: number;
}

interface IndexProps {
  templates: PaginatedData<TemplateItem>;
  accounts: WhatsAppAccount[];
  selectedAccountId: number | null;
}

/** What Meta says about a template → the pill tone that means the same. */
const TEMPLATE_TONE: Record<string, CrmStatusTone> = {
  draft: 'neutral',
  pending: 'warn',
  approved: 'good',
  rejected: 'bad',
  paused: 'warn',
};

const TEMPLATE_LABEL: Record<string, string> = {
  draft: 'Draft',
  pending: 'With Meta',
  approved: 'Approved',
  rejected: 'Rejected by Meta',
  paused: 'Paused by Meta',
};

const humanize = (value: string) => value.replace(/_/g, ' ').replace(/w/g, (c) => c.toUpperCase());

export default function TemplatesDirectory({ templates, accounts, selectedAccountId }: IndexProps) {
  const [selectedAccount, setSelectedAccount] = useState<number | null>(selectedAccountId);

  const syncForm = useForm({
    whatsapp_account_id: selectedAccount || '',
  });

  const handleAccountChange = (accId: number) => {
    setSelectedAccount(accId);
    router.get('/company/marketing/templates', { account_id: accId }, { preserveState: true });
  };

  const handleSync = () => {
    if (!selectedAccount) return;
    syncForm.setData('whatsapp_account_id', selectedAccount);
    syncForm.post('/company/marketing/templates/sync', { preserveScroll: true });
  };

  /*
   * Templates are not authored here — Meta owns them, and only an approved one
   * can start a conversation. So the page's job is to show which of them are
   * actually usable, and to say plainly that approval is not ours to grant.
   */
  const approved = templates.data.filter((t) => t.status === 'approved').length;
  const waiting = templates.data.filter((t) => t.status === 'pending').length;

  return (
    <MarketingShell title="Settings">
      <SettingsWorkspaceHead
        title="Message Templates"
        active="templates"
        actions={
          <>
            {/* The way back to the rest of Settings, as on every other sub-page. */}
            <CrmButton href="/company/marketing/settings">Marketing settings</CrmButton>
            <CrmSelect
              aria-label="WhatsApp account"
              value={selectedAccount ? String(selectedAccount) : ''}
              onChange={(e) => handleAccountChange(Number(e.target.value))}
              className="w-auto"
            >
              {accounts.length === 0 && <option value="">No account connected</option>}
              {accounts.map((account) => (
                <option key={account.id} value={String(account.id)}>
                  {account.verified_name ?? account.display_phone_number}
                </option>
              ))}
            </CrmSelect>
            <CrmButton
              tone="primary"
              icon={RefreshCw}
              onClick={handleSync}
              loading={syncForm.processing}
              disabled={!selectedAccount}
            >
              Sync from Meta
            </CrmButton>
          </>
        }
      />

      {/*
        * The two figures that used to sit in the title block.
        *
        * They belong to this section rather than to the workspace, so they
        * live in its body — putting them in the head made this one section's
        * header taller than the other five and pushed the tab strip down.
        */}
      <div className="mb-4 flex flex-wrap items-center gap-x-8 gap-y-3">
        <CrmPageStat
          icon={CheckCircle2}
          value={approved}
          chip={waiting > 0 ? `${waiting} with Meta` : undefined}
          chipHue="#E0B21E"
          caption="Approved and ready to send"
        />
        <CrmPageStat icon={FileText} value={templates.total} caption="Templates in total" />
      </div>

      {/*
        * Worth saying once, at the top: a template's status is Meta's answer,
        * not a setting in this app. People otherwise hunt for the button that
        * approves it.
        */}
      <CrmCard step={0} className="mb-4" tone="sunken">
        <div className="flex flex-wrap items-start gap-3">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
          <p className="min-w-0 flex-1 text-[13px] leading-relaxed text-ovs-crm-text-secondary">
            Templates are written and approved inside Meta&rsquo;s WhatsApp Manager, not here — this
            page mirrors what Meta says about them. Only an approved template can open a conversation;
            free text is allowed only within 24 hours of someone messaging you first.
          </p>
        </div>
      </CrmCard>

      <CrmSection
        step={1}
        title="All templates"
        description={`${templates.total} template${templates.total === 1 ? '' : 's'}`}
      >
        {templates.data.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-[13px] text-ovs-crm-text-muted">
              {accounts.length === 0
                ? 'Connect a WhatsApp account first, then sync the templates Meta has approved for it.'
                : 'Nothing synced yet. Pull in whatever Meta has approved for this number.'}
            </p>
            <CrmButton
              tone="primary"
              icon={accounts.length === 0 ? Settings : RefreshCw}
              className="mt-4"
              href={accounts.length === 0 ? '/company/marketing/whatsapp' : undefined}
              onClick={accounts.length === 0 ? undefined : handleSync}
            >
              {accounts.length === 0 ? 'Connect WhatsApp' : 'Sync from Meta'}
            </CrmButton>
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            {templates.data.map((template) => (
              <div key={template.id} className="flex flex-col gap-3 rounded-[24px] bg-ovs-crm-surface-inset p-4">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                      {template.name}
                    </p>
                    <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                      {[template.language?.toUpperCase(), humanize(template.category)]
                        .filter(Boolean)
                        .join(' · ')}
                    </p>
                  </div>
                  <CrmStatusPill tone={TEMPLATE_TONE[template.status] ?? 'neutral'}>
                    {TEMPLATE_LABEL[template.status] ?? template.status}
                  </CrmStatusPill>
                </div>

                <p className="line-clamp-4 whitespace-pre-wrap rounded-[14px] bg-white p-3 text-[13px] leading-relaxed text-ovs-crm-text-secondary">
                  {template.body}
                </p>

                {template.variables && template.variables.length > 0 && (
                  <p className="text-[11.5px] text-ovs-crm-text-muted">
                    Fills in {template.variables.length} value
                    {template.variables.length === 1 ? '' : 's'} per recipient — that is what lets a
                    message greet someone by name.
                  </p>
                )}
              </div>
            ))}
          </div>
        )}
      </CrmSection>
    </MarketingShell>
  );
}
