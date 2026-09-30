import React, { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import { SettingsWorkspaceHead } from '../Settings/SettingsWorkspace';
import {
  CrmCard,
  CrmSection,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmSelect,
  CrmFormField,
  useCrmConfirm,
} from '@/pages/Company/design';
import { CheckCircle2, Info, Pencil, Plus, Star, Unplug, Zap } from 'lucide-react';

interface SafeWhatsAppAccount {
  id: number;
  provider: string;
  business_account_id: string;
  phone_number_id: string;
  display_phone_number: string;
  verified_name: string;
  /** What this line is for — drives the badge in the inbox and the filter. */
  purpose: string | null;
  /** What to call it in front of people; falls back to the verified name. */
  label: string | null;
  display_name: string;
  webhook_verify_token: string;
  masked_token: string;
  status: 'connected' | 'disconnected' | 'error';
  connection_status: 'active' | 'inactive';
  last_connected_at: string | null;
  last_webhook_at: string | null;
}

interface IndexProps {
  accounts: SafeWhatsAppAccount[];
  /** Retained for compatibility; the list above is what this page renders. */
  account: SafeWhatsAppAccount | null;
  defaultAccountId: number | null;
}

const shortDate = (iso: string | null) => {
  if (!iso) return 'Never';
  const d = new Date(iso);

  return Number.isNaN(d.getTime())
    ? 'Never'
    : d.toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
};

/*
 * Mirrors `App\WhatsAppNumberPurpose`. The server validates against the enum,
 * so this list only decides what is offered — it can never widen what is
 * accepted.
 */
const PURPOSE_OPTIONS = [
  { value: 'marketing', label: 'Marketing' },
  { value: 'sales', label: 'Sales' },
  { value: 'onboarding', label: 'Onboarding' },
  { value: 'support', label: 'Support' },
  { value: 'clinic_pool', label: 'Clinic pool' },
];

const PURPOSE_HINT: Record<string, string> = {
  marketing: 'Campaigns and first replies from people who are not customers yet.',
  sales: 'A salesperson working a live deal.',
  onboarding: 'Setting a won customer up and getting them live.',
  support: 'Existing customers with a problem.',
  clinic_pool: 'Lent to clinics without their own number, for messaging their patients.',
};

const BLANK = {
  id: '',
  business_account_id: '',
  phone_number_id: '',
  display_phone_number: '',
  verified_name: 'OralCares WhatsApp',
  purpose: 'marketing',
  label: '',
  access_token: '',
  webhook_verify_token: 'oralcares_webhook_token',
};

export default function WhatsAppAccounts({ accounts = [], defaultAccountId }: IndexProps) {
  // null = not editing. '' = adding a new number. Otherwise the account's id.
  const [editingId, setEditingId] = useState<string | null>(null);
  const { confirm, dialog: confirmDialog } = useCrmConfirm();

  const form = useForm({ ...BLANK });

  /*
   * Editing carries the account's id, adding does not. That id is what tells
   * the server to update this exact connection rather than match on the
   * number — which is what lets a mistyped phone number ID be corrected
   * without leaving the old row behind as a second account.
   */
  const startEdit = (account: SafeWhatsAppAccount) => {
    form.setData({
      id: String(account.id),
      business_account_id: account.business_account_id || '',
      phone_number_id: account.phone_number_id || '',
      display_phone_number: account.display_phone_number || '',
      verified_name: account.verified_name || 'OralCares WhatsApp',
      purpose: account.purpose || 'marketing',
      label: account.label || '',
      access_token: '',
      webhook_verify_token: account.webhook_verify_token || 'oralcares_webhook_token',
    });
    setEditingId(String(account.id));
  };

  const startAdd = () => {
    form.setData({ ...BLANK });
    setEditingId('');
  };

  const handleSave = (e: React.FormEvent) => {
    e.preventDefault();
    form.post('/company/marketing/whatsapp', {
      onSuccess: () => {
        setEditingId(null);
        form.reset();
      },
    });
  };

  const handleTest = (account: SafeWhatsAppAccount) =>
    router.post(`/company/marketing/whatsapp/${account.id}/test`, {}, { preserveScroll: true });

  const handleDisconnect = (account: SafeWhatsAppAccount) =>
    confirm({
      title: 'Disconnect this WhatsApp number?',
      description:
        'Campaigns and automations stop sending from it, and replies stop arriving. Every conversation and message already recorded is kept — reconnecting the same number picks up where it left off.',
      confirmLabel: 'Disconnect',
      destructive: true,
      onConfirm: () => router.delete(`/company/marketing/whatsapp/${account.id}`),
    });

  const webhookUrl = `${window.location.origin}/api/marketing/whatsapp/webhook`;
  const isEditing = editingId !== null;
  const editingExisting = Boolean(editingId);

  return (
    <MarketingShell title="Settings">
      <SettingsWorkspaceHead
        title="WhatsApp Accounts"
        active="whatsapp"
        actions={
          <>
            {/* The way back to the rest of Settings, as on every other sub-page. */}
            <CrmButton href="/company/marketing/settings">Marketing settings</CrmButton>
            {!isEditing && (
              <CrmButton tone="primary" icon={Plus} onClick={startAdd}>
                Add a number
              </CrmButton>
            )}
          </>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <div className="space-y-4 lg:col-span-7">
          {isEditing ? (
            <CrmSection
              step={0}
              title={editingExisting ? 'Edit this connection' : 'Connect another number'}
              description="These come from your own Meta Business account — OralCares cannot create them for you."
              actions={<CrmButton onClick={() => setEditingId(null)}>Cancel</CrmButton>}
            >
              <form onSubmit={handleSave}>
                <CrmFormField
                  label="WhatsApp Business Account ID"
                  hint="In Meta Business Suite, under WhatsApp Accounts."
                  error={form.errors.business_account_id}
                >
                  <CrmInput
                    required
                    value={form.data.business_account_id}
                    onChange={(e) => form.setData('business_account_id', e.target.value)}
                    placeholder="1234567890"
                  />
                </CrmFormField>

                <CrmFormField
                  label="Phone number ID"
                  hint="The ID of the number messages are sent from — not the number itself. This is what identifies the connection."
                  error={form.errors.phone_number_id}
                >
                  <CrmInput
                    required
                    value={form.data.phone_number_id}
                    onChange={(e) => form.setData('phone_number_id', e.target.value)}
                    placeholder="0987654321"
                  />
                </CrmFormField>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {/*
                    * What the line is for comes before its credentials.
                    *
                    * With four or five numbers running, this is the field that
                    * decides what a reader sees in the inbox and what the line
                    * filter can narrow to — it is a business decision, not a
                    * technical detail, so it sits with the other ones.
                    */}
                  <CrmFormField
                    label="What this line is for"
                    error={form.errors.purpose}
                    hint={PURPOSE_HINT[form.data.purpose] ?? undefined}
                  >
                    <CrmSelect
                      value={form.data.purpose}
                      onValueChange={(value: string) => form.setData('purpose', value)}
                      className="w-full"
                      options={PURPOSE_OPTIONS}
                    />
                  </CrmFormField>

                  <CrmFormField
                    label="Call it something"
                    error={form.errors.label}
                    hint="Optional — shown instead of the number wherever this line appears"
                  >
                    <CrmInput
                      value={form.data.label}
                      onChange={(e) => form.setData('label', e.target.value)}
                      placeholder="Main sales line"
                      className="w-full"
                    />
                  </CrmFormField>

                  <CrmFormField label="The number, as people see it" error={form.errors.display_phone_number}>
                    <CrmInput
                      required
                      value={form.data.display_phone_number}
                      onChange={(e) => form.setData('display_phone_number', e.target.value)}
                      placeholder="+91 98765 43210"
                    />
                  </CrmFormField>

                  <CrmFormField label="Business name" error={form.errors.verified_name}>
                    <CrmInput
                      value={form.data.verified_name}
                      onChange={(e) => form.setData('verified_name', e.target.value)}
                    />
                  </CrmFormField>
                </div>

                <CrmFormField
                  label="Permanent access token"
                  hint={
                    editingExisting
                      ? 'Already on file. Leave blank to keep the existing token.'
                      : 'A permanent System User token, not a temporary one — temporary tokens expire in 24 hours.'
                  }
                  error={form.errors.access_token}
                >
                  <CrmInput
                    type="password"
                    value={form.data.access_token}
                    onChange={(e) => form.setData('access_token', e.target.value)}
                    placeholder={editingExisting ? '••••••••••••••••' : 'EAAG…'}
                  />
                </CrmFormField>

                <CrmFormField
                  label="Webhook verify token"
                  hint="You choose this. Paste the same value into Meta when you set the webhook up."
                  error={form.errors.webhook_verify_token}
                >
                  <CrmInput
                    required
                    value={form.data.webhook_verify_token}
                    onChange={(e) => form.setData('webhook_verify_token', e.target.value)}
                  />
                </CrmFormField>

                <CrmButton
                  tone="primary"
                  type="submit"
                  icon={CheckCircle2}
                  loading={form.processing}
                  className="mt-2"
                >
                  {editingExisting ? 'Save connection' : 'Connect'}
                </CrmButton>
              </form>
            </CrmSection>
          ) : accounts.length === 0 ? (
            <CrmSection step={0} title="No number connected" description="Campaigns need a connected WhatsApp number before they can send.">
              <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">
                Connect the first number to start sending.
              </p>
            </CrmSection>
          ) : (
            accounts.map((account, index) => {
              const connected = account.status === 'connected';
              const isDefault = defaultAccountId === account.id;

              return (
                <CrmCard key={account.id} step={index}>
                  <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <h2 className="truncate text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                          {account.verified_name || account.display_phone_number}
                        </h2>
                        {isDefault && (
                          <span className="inline-flex items-center gap-1 rounded-full bg-ovs-crm-surface-inset px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.08em] text-ovs-crm-text-secondary">
                            <Star className="h-3 w-3" aria-hidden="true" />
                            Default
                          </span>
                        )}
                      </div>
                      <p className="truncate text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                        {account.display_phone_number}
                      </p>
                    </div>
                    <CrmStatusPill tone={connected ? 'good' : 'bad'}>
                      {connected ? 'Connected' : 'Not connected'}
                    </CrmStatusPill>
                  </div>

                  <div className="space-y-2 text-[11.5px]">
                    {[
                      { label: 'Business account', value: account.business_account_id },
                      { label: 'Phone number ID', value: account.phone_number_id },
                      { label: 'Access token', value: account.masked_token || 'Not set' },
                      { label: 'Last connected', value: shortDate(account.last_connected_at) },
                      { label: 'Last webhook', value: shortDate(account.last_webhook_at) },
                    ].map((row) => (
                      <div key={row.label} className="flex items-center justify-between gap-4">
                        <span className="shrink-0 text-ovs-crm-text-muted">{row.label}</span>
                        <span className="min-w-0 truncate tabular-nums text-ovs-crm-text-primary">
                          {row.value}
                        </span>
                      </div>
                    ))}
                  </div>

                  <div className="mt-4 flex flex-wrap items-center gap-1.5 border-t border-ovs-crm-border pt-4">
                    <CrmButton size="sm" icon={Zap} onClick={() => handleTest(account)}>
                      Test
                    </CrmButton>
                    <CrmButton size="sm" icon={Pencil} onClick={() => startEdit(account)}>
                      Edit
                    </CrmButton>
                    <CrmButton size="sm" icon={Unplug} onClick={() => handleDisconnect(account)}>
                      Disconnect
                    </CrmButton>
                  </div>
                </CrmCard>
              );
            })
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 lg:col-span-5">
          <CrmSection step={1} title="Your webhook" description="Paste this into Meta so replies reach the inbox.">
            <div className="rounded-[18px] bg-ovs-crm-surface-inset p-3">
              <p className="mb-1 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Callback URL
              </p>
              <p className="break-all text-[13px] tabular-nums text-ovs-crm-text-primary">{webhookUrl}</p>
            </div>

            <p className="mt-3 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
              Subscribe to the <strong className="text-ovs-crm-text-secondary">messages</strong> field.
              Without the webhook, outbound messages still send but nothing anyone replies will ever
              arrive — no conversations, no automations, no leads.
            </p>
          </CrmSection>

          <CrmCard step={2} tone="sunken">
            <div className="flex items-start gap-3">
              <Info className="mt-0.5 h-4 w-4 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
              <p className="min-w-0 flex-1 text-[13px] leading-relaxed text-ovs-crm-text-secondary">
                Meta only lets you start a conversation with a template it has approved. Free text is
                allowed for 24 hours after someone messages you first — that is why the inbox reply
                box works but a campaign needs a template.
              </p>
            </div>
          </CrmCard>
        </div>
      </div>

      {confirmDialog}
    </MarketingShell>
  );
}
