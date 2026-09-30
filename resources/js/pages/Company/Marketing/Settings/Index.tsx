import React, { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import { SettingsWorkspaceHead } from './SettingsWorkspace';
import type { SettingsTabKey } from './SettingsTabs';
import {
  CrmSection,
  CrmCard,
  CrmButton,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  CrmFormField,
  CrmStatusPill,
  CrmConfirm,
} from '@/pages/Company/design';
import { CheckCircle2, PlayCircle, Snowflake } from 'lucide-react';

interface SettingsPayload {
  id: number;
  organization_id: number;
  default_whatsapp_account_id: number | null;
  max_daily_messages: number;
  hourly_dispatch_rate: number;
  quiet_hours_enabled: boolean;
  quiet_hours_start: string;
  quiet_hours_end: string;
  emergency_freeze_enabled: boolean;
  opt_out_keywords: string[];
  auto_suppress_on_failures: boolean;
  max_consecutive_failures: number;
}

interface AccountItem {
  id: number;
  verified_name: string | null;
  display_phone_number: string;
}

interface ConnectionsSummary {
  whatsapp_connected: number;
  whatsapp_total: number;
  templates_approved: number;
  suppressed_total: number;
}

interface IndexProps {
  settings: SettingsPayload;
  accounts: AccountItem[];
  connectionsSummary: ConnectionsSummary;
  /** Business timezone quiet hours are evaluated in — shown, never guessed at. */
  timezone?: string;
  tab?: SettingsTabKey;
}

/** Only the tabs this page owns; WhatsApp and Templates are their own routes. */
const LOCAL_TABS: SettingsTabKey[] = ['general', 'sending', 'compliance', 'safety'];

export default function MarketingSettingsIndex({
  settings,
  accounts,
  connectionsSummary,
  timezone,
  tab,
}: IndexProps) {
  const [activeTab, setActiveTab] = useState<SettingsTabKey>(
    tab && LOCAL_TABS.includes(tab) ? tab : 'general'
  );
  const [showFreezeModal, setShowFreezeModal] = useState(false);

  const { data, setData, put, processing, errors } = useForm({
    default_whatsapp_account_id: settings.default_whatsapp_account_id
      ? String(settings.default_whatsapp_account_id)
      : '',
    max_daily_messages: settings.max_daily_messages || 5000,
    hourly_dispatch_rate: settings.hourly_dispatch_rate || 500,
    quiet_hours_enabled: settings.quiet_hours_enabled || false,
    quiet_hours_start: settings.quiet_hours_start || '21:00',
    quiet_hours_end: settings.quiet_hours_end || '09:00',
    opt_out_keywords: (settings.opt_out_keywords || ['STOP', 'UNSUBSCRIBE', 'REMOVE', 'CANCEL']).join(', '),
    auto_suppress_on_failures: settings.auto_suppress_on_failures !== false,
    max_consecutive_failures: settings.max_consecutive_failures || 3,
  });

  const handleFormSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    put('/company/marketing/settings');
  };

  const handleToggleFreeze = () => {
    router.post(
      '/company/marketing/settings/emergency-freeze',
      { freeze: !settings.emergency_freeze_enabled },
      { onSuccess: () => setShowFreezeModal(false) }
    );
  };

  const frozen = settings.emergency_freeze_enabled;
  // Safety is a state to act on, not a form to save — it saves on its own.
  const showSaveButton = activeTab !== 'safety';

  return (
    <MarketingShell title="Settings">
      {/*
        * When sending is frozen nothing else on this page has any effect, so
        * it is said first and in full, on every tab, rather than left as one
        * switch among eleven.
        */}
      {frozen && (
        <div className="crm-rise mb-5 flex flex-wrap items-center gap-4 rounded-[24px] bg-ovs-crm-danger-soft p-5">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-ovs-crm-danger-text text-white">
            <Snowflake className="h-5 w-5" aria-hidden="true" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-[15px] font-semibold text-ovs-crm-danger-text">Sending is frozen</p>
            <p className="mt-0.5 text-[13px] leading-relaxed text-ovs-crm-danger-text/80">
              Every campaign and automation is held. Nothing is lost — sends resume from where they
              stopped the moment you lift it.
            </p>
          </div>
          <CrmButton tone="primary" icon={PlayCircle} onClick={() => setShowFreezeModal(true)}>
            Lift the freeze
          </CrmButton>
        </div>
      )}

      <SettingsWorkspaceHead
        title="Settings"
        active={activeTab}
        onSelectLocal={setActiveTab}
        actions={
          showSaveButton ? (
            <CrmButton
              tone="primary"
              type="submit"
              form="marketing-settings"
              icon={CheckCircle2}
              loading={processing}
            >
              Save settings
            </CrmButton>
          ) : undefined
        }
      />

      <form id="marketing-settings" onSubmit={handleFormSubmit}>
        {/* ═══ General ═══════════════════════════════════════════════════ */}
        {activeTab === 'general' && (
          <CrmSection
            step={0}
            title="Default sender"
            description="Which connected number a new campaign starts out set to."
          >
            <CrmFormField
              label="Default WhatsApp number"
              hint="Preselected in the campaign builder. Every campaign can still pick a different number, and a campaign already created keeps the number it was built with. If this number is later disconnected, new campaigns simply open with nothing selected rather than being set up to send through it."
              error={errors.default_whatsapp_account_id}
            >
              <CrmSelect
                value={data.default_whatsapp_account_id}
                onChange={(e) => setData('default_whatsapp_account_id', e.target.value)}
              >
                <option value="">No default — pick per campaign</option>
                {accounts.map((account) => (
                  <option key={account.id} value={String(account.id)}>
                    {account.verified_name ?? account.display_phone_number}
                  </option>
                ))}
              </CrmSelect>
            </CrmFormField>

            <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
              {[
                { label: 'Numbers connected', value: `${connectionsSummary.whatsapp_connected} of ${connectionsSummary.whatsapp_total}` },
                { label: 'Approved templates', value: connectionsSummary.templates_approved },
                { label: 'On the suppression register', value: connectionsSummary.suppressed_total },
              ].map((stat) => (
                <div key={stat.label} className="rounded-[18px] bg-ovs-crm-surface-inset p-4">
                  <p className="text-[15px] font-semibold tabular-nums text-ovs-crm-text-primary">
                    {stat.value}
                  </p>
                  <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">{stat.label}</p>
                </div>
              ))}
            </div>
          </CrmSection>
        )}

        {/* ═══ Sending & Delivery ════════════════════════════════════════ */}
        {activeTab === 'sending' && (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
            <CrmSection
              step={0}
              title="Pace and volume"
              description="Meta throttles bursts. These two caps keep a large send from tripping that."
              className="lg:col-span-7"
            >
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <CrmFormField
                  label="Most in an hour, per number"
                  hint="Applied as each message leaves the queue. A lower number spreads a large send out over more hours."
                  error={errors.hourly_dispatch_rate}
                >
                  <CrmInput
                    type="number"
                    min={1}
                    value={data.hourly_dispatch_rate}
                    onChange={(e) => setData('hourly_dispatch_rate', Number(e.target.value))}
                  />
                </CrmFormField>

                <CrmFormField
                  label="Most in a day, across the organisation"
                  hint="Counted from messages actually sent today. Once reached, anything still waiting is held until tomorrow rather than dropped."
                  error={errors.max_daily_messages}
                >
                  <CrmInput
                    type="number"
                    min={1}
                    value={data.max_daily_messages}
                    onChange={(e) => setData('max_daily_messages', Number(e.target.value))}
                  />
                </CrmFormField>
              </div>

              <p className="mt-3 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
                The two work together: the hourly limit paces each sending number, the daily limit
                caps total volume. A message has to satisfy both before it goes out.
              </p>
            </CrmSection>

            <CrmSection
              step={1}
              title="Quiet hours"
              description="Nothing goes out inside this window."
              className="lg:col-span-5"
            >
              <div className="rounded-[18px] bg-ovs-crm-surface-inset p-4">
                <label className="flex cursor-pointer items-start gap-3">
                  <input
                    type="checkbox"
                    checked={data.quiet_hours_enabled}
                    onChange={(e) => setData('quiet_hours_enabled', e.target.checked)}
                    className="mt-0.5 h-4 w-4 shrink-0 rounded-[4px] accent-ovs-crm-ink"
                  />
                  <span className="min-w-0">
                    <span className="block text-[12px] font-medium text-ovs-crm-text-primary">
                      Hold messages overnight
                    </span>
                    <span className="mt-0.5 block text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
                      Anything due inside the window waits until it closes, then goes out. A clinic
                      owner woken at 2am is a complaint, not a lead.
                    </span>
                  </span>
                </label>

                {data.quiet_hours_enabled && (
                  <>
                    <div className="mt-3 grid grid-cols-2 gap-3">
                      <CrmFormField label="Quiet from">
                        <CrmInput
                          type="time"
                          value={data.quiet_hours_start}
                          onChange={(e) => setData('quiet_hours_start', e.target.value)}
                        />
                      </CrmFormField>
                      <CrmFormField label="Until">
                        <CrmInput
                          type="time"
                          value={data.quiet_hours_end}
                          onChange={(e) => setData('quiet_hours_end', e.target.value)}
                        />
                      </CrmFormField>
                    </div>

                    {/*
                      * Which clock these hours are read against is the whole
                      * meaning of the setting, so it is stated rather than
                      * assumed.
                      */}
                    <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
                      Read in {timezone ?? 'your organisation’s timezone'} — your own local hours,
                      not the server’s.
                    </p>
                  </>
                )}
              </div>
            </CrmSection>
          </div>
        )}

        {/* ═══ Compliance ════════════════════════════════════════════════ */}
        {activeTab === 'compliance' && (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
            <CrmSection
              step={0}
              title="How someone tells you to stop"
              description="Checked against every inbound message as it arrives."
              className="lg:col-span-6"
            >
              <CrmFormField
                label="Words that mean stop"
                hint="Comma separated. A reply matching any of these opts the contact out immediately, whatever else the message says — and an opted-out contact is excluded from every campaign and automation from that moment."
                error={errors.opt_out_keywords}
              >
                <CrmTextarea
                  rows={3}
                  value={data.opt_out_keywords}
                  onChange={(e) => setData('opt_out_keywords', e.target.value)}
                  placeholder="STOP, UNSUBSCRIBE, REMOVE"
                />
              </CrmFormField>
            </CrmSection>

            <CrmSection
              step={1}
              title="Numbers that keep failing"
              description="Protects the reputation of the number you send from."
              className="lg:col-span-6"
            >
              <div className="rounded-[18px] bg-ovs-crm-surface-inset p-4">
                <label className="flex cursor-pointer items-start gap-3">
                  <input
                    type="checkbox"
                    checked={data.auto_suppress_on_failures}
                    onChange={(e) => setData('auto_suppress_on_failures', e.target.checked)}
                    className="mt-0.5 h-4 w-4 shrink-0 rounded-[4px] accent-ovs-crm-ink"
                  />
                  <span className="min-w-0">
                    <span className="block text-[12px] font-medium text-ovs-crm-text-primary">
                      Give up on numbers that keep failing
                    </span>
                    <span className="mt-0.5 block text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
                      Meta counts repeated failures against your sending number. A contact suppressed
                      this way is recorded with a reason and stays on the register until someone
                      restores them.
                    </span>
                  </span>
                </label>

                {data.auto_suppress_on_failures && (
                  <div className="mt-3">
                    <CrmFormField label="After how many failures" error={errors.max_consecutive_failures}>
                      <CrmInput
                        type="number"
                        min={1}
                        value={data.max_consecutive_failures}
                        onChange={(e) => setData('max_consecutive_failures', Number(e.target.value))}
                      />
                    </CrmFormField>
                  </div>
                )}
              </div>
            </CrmSection>
          </div>
        )}
      </form>

      {/* ═══ Safety ══════════════════════════════════════════════════════
        * Deliberately outside the settings form: this is not a preference
        * that waits for a Save, it is a switch that takes effect at once.
        */}
      {activeTab === 'safety' && (
        <CrmCard step={0}>
          <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                Emergency freeze
              </h2>
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                The one control that stops everything at once.
              </p>
            </div>
            <CrmStatusPill tone={frozen ? 'bad' : 'good'}>
              {frozen ? 'Sending frozen' : 'Sending normally'}
            </CrmStatusPill>
          </div>

          <div className="rounded-[18px] bg-ovs-crm-surface-inset p-4">
            <p className="text-[13px] leading-relaxed text-ovs-crm-text-secondary">
              While frozen, campaign messages are held and automations stop starting new runs.
              Nothing is cancelled and nothing is lost — every held message goes out when you lift
              it, subject to your pace limits and quiet hours.
            </p>
            <p className="mt-2 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
              Takes effect immediately, without saving. Use it when something is going out wrong and
              you need it stopped now.
            </p>
          </div>

          <div className="mt-4">
            {frozen ? (
              <CrmButton tone="primary" icon={PlayCircle} onClick={() => setShowFreezeModal(true)}>
                Lift the freeze
              </CrmButton>
            ) : (
              <CrmButton tone="danger" icon={Snowflake} onClick={() => setShowFreezeModal(true)}>
                Freeze all sending
              </CrmButton>
            )}
          </div>
        </CrmCard>
      )}

      <CrmConfirm
        open={showFreezeModal}
        onClose={() => setShowFreezeModal(false)}
        onConfirm={handleToggleFreeze}
        tone={frozen ? 'primary' : 'danger'}
        title={frozen ? 'Lift the freeze?' : 'Freeze all sending?'}
        description={
          frozen
            ? 'Campaigns and automations resume from where they stopped. Anything that queued up while frozen goes out, subject to your pace limits.'
            : 'Every campaign and automation stops sending immediately. Nothing is cancelled or lost — it waits until you lift this.'
        }
        confirmLabel={frozen ? 'Resume sending' : 'Freeze everything'}
      />
    </MarketingShell>
  );
}
