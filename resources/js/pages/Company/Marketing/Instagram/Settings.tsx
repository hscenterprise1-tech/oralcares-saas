import React, { useState } from 'react';
import { Head, useForm, Link, router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmSection,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmFormField,
} from '@/pages/Company/design';
import {
  CheckCircle2,
  RefreshCw,
} from 'lucide-react';
import { InstagramIcon } from '@/components/icons/InstagramIcon';

interface InstagramAccount {
  id: number;
  instagram_business_account_id: string | null;
  facebook_page_id: string | null;
  username: string | null;
  masked_token: string;
  status: 'connected' | 'disconnected' | 'error';
  last_connected_at: string | null;
  logo_url: string | null;
  buffer_target: number;
  auto_fill_enabled: boolean;
}

interface AiSettings {
  id: number;
  image_model: string;
  text_model: string;
  masked_key: string;
  is_configured: boolean;
}

interface SettingsProps {
  account: InstagramAccount | null;
  aiSettings: AiSettings | null;
}

export default function InstagramSettings({ account, aiSettings }: SettingsProps) {
  const [isAccountFormOpen, setIsAccountFormOpen] = useState(!account);
  const [isAiFormOpen, setIsAiFormOpen] = useState(!aiSettings?.is_configured);

  const accountForm = useForm({
    instagram_business_account_id: account?.instagram_business_account_id || '',
    facebook_page_id: account?.facebook_page_id || '',
    username: account?.username || '',
    access_token: '',
  });

  const aiForm = useForm({
    api_key: '',
    image_model: aiSettings?.image_model || 'dall-e-3',
    text_model: aiSettings?.text_model || 'gpt-4o-mini',
  });

  const automationForm = useForm({
    buffer_target: account?.buffer_target ?? 10,
    auto_fill_enabled: account?.auto_fill_enabled ?? true,
  });

  const logoForm = useForm<{ logo: File | null }>({ logo: null });
  const [logoPreview, setLogoPreview] = useState<string | null>(account?.logo_url ?? null);

  const handleSaveAccount = (e: React.FormEvent) => {
    e.preventDefault();
    accountForm.post('/company/marketing/instagram/settings', {
      onSuccess: () => setIsAccountFormOpen(false),
    });
  };

  const handleSaveAi = (e: React.FormEvent) => {
    e.preventDefault();
    aiForm.post('/company/marketing/instagram/settings/ai', {
      onSuccess: () => setIsAiFormOpen(false),
    });
  };

  const handleTestConnection = () => {
    if (!account) return;
    router.post(`/company/marketing/instagram/settings/${account.id}/test`, {}, { preserveScroll: true });
  };

  const handleSaveAutomation = (e: React.FormEvent) => {
    e.preventDefault();
    automationForm.post('/company/marketing/instagram/settings/automation', { preserveScroll: true });
  };

  const handleLogoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] ?? null;
    logoForm.setData('logo', file);
    if (file) {
      setLogoPreview(URL.createObjectURL(file));
      logoForm.post('/company/marketing/instagram/settings/logo', { preserveScroll: true, forceFormData: true });
    }
  };

  const connected = account?.status === 'connected';

  return (
    <MarketingShell title="Instagram">
      <CrmPageHead
        eyebrow="Marketing"
        title="Instagram"
        actions={
          <>
            <CrmButton href="/company/marketing/instagram">Content queue</CrmButton>
            {account && (
              <CrmButton icon={RefreshCw} onClick={handleTestConnection}>
                Test connection
              </CrmButton>
            )}
          </>
        }
      />

      {/*
        * The three things this page configures are genuinely independent —
        * where posts go, who writes them, and how many are kept ready — so
        * each gets its own form rather than one long column of fields with a
        * single save button that hides which part failed.
        */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <CrmSection
          step={0}
          title="Where posts go"
          description="An Instagram Business account, connected through a Facebook Page. Meta requires both."
          className="lg:col-span-6"
          actions={
            <CrmStatusPill tone={connected ? 'good' : 'neutral'}>
              {connected ? 'Connected' : 'Not connected'}
            </CrmStatusPill>
          }
        >
          <form onSubmit={handleSaveAccount}>
            <CrmFormField
              label="Instagram Business account ID"
              error={accountForm.errors.instagram_business_account_id}
            >
              <CrmInput
                value={accountForm.data.instagram_business_account_id}
                onChange={(e) => accountForm.setData('instagram_business_account_id', e.target.value)}
                placeholder="17841400000000000"
              />
            </CrmFormField>

            <CrmFormField
              label="Facebook Page ID"
              hint="The Page the Instagram account is linked to."
              error={accountForm.errors.facebook_page_id}
            >
              <CrmInput
                value={accountForm.data.facebook_page_id}
                onChange={(e) => accountForm.setData('facebook_page_id', e.target.value)}
                placeholder="1234567890"
              />
            </CrmFormField>

            <CrmFormField label="Handle" hint="Shown here only — it does not affect posting.">
              <CrmInput
                value={accountForm.data.username}
                onChange={(e) => accountForm.setData('username', e.target.value)}
                placeholder="@oralcares"
              />
            </CrmFormField>

            <CrmFormField
              label="Access token"
              hint={
                account?.masked_token
                  ? `Already on file: ${account.masked_token}. Leave blank to keep it.`
                  : 'A long-lived Page access token with instagram_content_publish.'
              }
              error={accountForm.errors.access_token}
            >
              <CrmInput
                type="password"
                value={accountForm.data.access_token}
                onChange={(e) => accountForm.setData('access_token', e.target.value)}
                placeholder={account?.masked_token ? '••••••••••••••••' : 'EAAG…'}
              />
            </CrmFormField>

            <CrmButton
              tone="primary"
              type="submit"
              icon={CheckCircle2}
              loading={accountForm.processing}
              className="mt-2"
            >
              Save connection
            </CrmButton>
          </form>
        </CrmSection>

        <div className="grid grid-cols-1 gap-4 lg:col-span-6">
          <CrmSection
            step={1}
            title="Who writes the posts"
            description="Without a key the whole flow still works on placeholder content, so you can try it before spending anything."
            actions={
              <CrmStatusPill tone={aiSettings?.is_configured ? 'good' : 'neutral'}>
                {aiSettings?.is_configured ? 'Key on file' : 'Placeholder mode'}
              </CrmStatusPill>
            }
          >
            <form onSubmit={handleSaveAi}>
              <CrmFormField
                label="OpenAI API key"
                hint={
                  aiSettings?.masked_key
                    ? `Already on file: ${aiSettings.masked_key}. Leave blank to keep it.`
                    : 'Used for both the caption and the image. Charged to your own OpenAI account.'
                }
                error={aiForm.errors.api_key}
              >
                <CrmInput
                  type="password"
                  value={aiForm.data.api_key}
                  onChange={(e) => aiForm.setData('api_key', e.target.value)}
                  placeholder={aiSettings?.masked_key ? '••••••••••••••••' : 'sk-…'}
                />
              </CrmFormField>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <CrmFormField label="Image model">
                  <CrmInput
                    value={aiForm.data.image_model}
                    onChange={(e) => aiForm.setData('image_model', e.target.value)}
                  />
                </CrmFormField>
                <CrmFormField label="Caption model">
                  <CrmInput
                    value={aiForm.data.text_model}
                    onChange={(e) => aiForm.setData('text_model', e.target.value)}
                  />
                </CrmFormField>
              </div>

              <CrmButton
                tone="primary"
                type="submit"
                icon={CheckCircle2}
                loading={aiForm.processing}
                className="mt-2"
              >
                Save AI settings
              </CrmButton>
            </form>
          </CrmSection>

          <CrmSection
            step={2}
            title="How many drafts to keep ready"
            description="The AI picks its own topics to keep the buffer full. You still approve every post before it goes out."
          >
            <form onSubmit={handleSaveAutomation}>
              <div className="mb-3 rounded-[18px] bg-ovs-crm-surface-inset p-4">
                <label className="flex cursor-pointer items-start gap-3">
                  <input
                    type="checkbox"
                    checked={automationForm.data.auto_fill_enabled}
                    onChange={(e) => automationForm.setData('auto_fill_enabled', e.target.checked)}
                    className="mt-0.5 h-4 w-4 shrink-0 rounded-[4px] accent-ovs-crm-ink"
                  />
                  <span className="min-w-0">
                    <span className="block text-[12px] font-medium text-ovs-crm-text-primary">
                      Top the buffer up on its own
                    </span>
                    <span className="mt-0.5 block text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
                      New drafts are written as you approve or reject the ones waiting. Turn this off
                      and drafts only appear when you ask for a topic.
                    </span>
                  </span>
                </label>
              </div>

              <CrmFormField
                label="Drafts to keep waiting"
                hint="Higher means less chance of an empty queue, and more AI spend."
                error={automationForm.errors.buffer_target}
              >
                <CrmInput
                  type="number"
                  min={1}
                  max={50}
                  value={automationForm.data.buffer_target}
                  onChange={(e) => automationForm.setData('buffer_target', Number(e.target.value))}
                />
              </CrmFormField>

              <CrmButton
                tone="primary"
                type="submit"
                icon={CheckCircle2}
                loading={automationForm.processing}
                className="mt-2"
              >
                Save buffer settings
              </CrmButton>
            </form>

            {/*
              * The logo is composited onto every generated image rather than
              * described to the model — image models cannot reproduce an exact
              * existing logo, they can only approximate one.
              */}
            <div className="mt-4 border-t border-ovs-crm-border pt-4">
              <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Logo
              </p>

              {account?.logo_url && (
                <img
                  src={account.logo_url}
                  alt="Your logo"
                  className="mb-3 h-14 w-auto rounded-[14px] bg-ovs-crm-surface-inset object-contain p-2"
                />
              )}

              <input
                type="file"
                accept="image/png,image/jpeg,image/webp"
                onChange={handleLogoChange}
                aria-label="Upload your logo"
                className="block w-full text-[11.5px] text-ovs-crm-text-secondary file:mr-3 file:rounded-full file:border-0 file:bg-ovs-crm-ink file:px-4 file:py-2 file:text-[11.5px] file:font-medium file:text-white hover:file:bg-ovs-crm-ink-soft"
              />
              <p className="mt-2 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
                Placed onto every generated image, so posts stay recognisably yours. A transparent
                PNG works best.
              </p>
            </div>
          </CrmSection>
        </div>
      </div>
    </MarketingShell>
  );
}
