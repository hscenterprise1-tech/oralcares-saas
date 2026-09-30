import React, { useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import {
  Select,
  SelectTrigger,
  SelectContent,
  SelectItem,
  SelectValue,
} from '@/pages/Company/design/CrmSelectPrimitives';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmCard,
  CrmButton,
  CrmInput,
  CrmTextarea,
  CrmFormField,
  CrmValue,
  CrmAvatar,
  CrmStatusPill,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { CrmConfirm } from '@/pages/Company/design/CrmOverlay';
import { cn } from '@/lib/utils';
import {
  Target,
  Smartphone,
  FileText,
  CheckCircle2,
  ChevronRight,
  ChevronLeft,
  AlertTriangle,
  Save,
  Clock,
  Send,
  Wifi,
  WifiOff,
} from 'lucide-react';

/**
 * The Campaign builder — four steps, each answering one question: who is
 * this, who gets it, what does it say, is it ready. Nothing here duplicates
 * Segments' own targeting UI (Step 2 only ever asks "which Segment", never
 * "which rules") and nothing here invents a send capability the backend
 * doesn't have — the Content step's fields exist because
 * MarketingWhatsAppTemplate already carries them, not because a campaign
 * builder is expected to have them.
 */

interface SegmentPreviewContact {
  id: number;
  name: string;
  organization: string | null;
  city: string | null;
}

interface SegmentRules {
  state?: string;
  city?: string;
  area?: string;
  organization?: string;
  tags?: string[];
  import_batch?: string;
  imported_from?: string;
  imported_to?: string;
  status?: string;
}

interface Segment {
  id: number;
  name: string;
  description: string | null;
  rules: SegmentRules;
  eligible_contacts_count: number;
  preview_contact: SegmentPreviewContact | null;
}

interface WhatsAppSender {
  id: number;
  display_phone_number: string;
  verified_name: string;
  status: string;
  connection_status: string | null;
  last_webhook_at: string | null;
  sent_today: number;
  hourly_dispatch_rate: number | null;
  max_daily_messages: number | null;
}

interface TemplateButton {
  type: string;
  text: string;
}

interface Template {
  id: number;
  whatsapp_account_id: number;
  name: string;
  language: string;
  category: string;
  status: string;
  body: string;
  header_type: string | null;
  header_content: string | null;
  footer: string | null;
  buttons: TemplateButton[] | null;
  variables: string[] | null;
}

interface BuilderProps {
  audiences: Segment[];
  accounts: WhatsAppSender[];
  templates: Template[];
  quiet_hours_active?: boolean;
}

const VARIABLE_FIELD_OPTIONS = [
  { value: 'name', label: 'Contact name' },
  { value: 'organization', label: 'Organization' },
  { value: 'city', label: 'City' },
  { value: 'phone', label: 'Phone number' },
];

/** The same fallback logic CampaignPreparationService uses server-side — kept in lockstep so this preview is never a guess. */
function resolveVariableValue(field: string, contact: SegmentPreviewContact | null): string | null {
  if (!contact) return null;
  switch (field) {
    case 'name':
      return contact.name;
    case 'organization':
      return contact.organization ?? 'Clinic';
    case 'city':
      return contact.city ?? '';
    case 'phone':
      return null; // the sample doesn't carry a phone number
    default:
      return null;
  }
}

function renderBody(template: Template | undefined, mapping: Record<string, string>, contact: SegmentPreviewContact | null): string {
  if (!template) return '';
  let text = template.body;
  (template.variables ?? []).forEach((v) => {
    const field = mapping[v] || 'name';
    const value = resolveVariableValue(field, contact);
    const token = value && value.length > 0 ? value : `[${VARIABLE_FIELD_OPTIONS.find((o) => o.value === field)?.label ?? field}]`;
    text = text.replace(new RegExp(`\\{\\{${v}\\}\\}`, 'g'), token);
  });
  return text;
}

const relativeTime = (iso: string | null): string => {
  if (!iso) return 'Never';
  const diffMs = Date.now() - new Date(iso).getTime();
  const mins = Math.round(diffMs / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hours = Math.round(mins / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.round(hours / 24)}d ago`;
};

/** A WhatsApp-shaped preview of the actual outgoing message — header, body, footer, buttons — never a fake mockup disconnected from the real template. */
function MessagePreview({
  template,
  mapping,
  contact,
}: {
  template: Template | undefined;
  mapping: Record<string, string>;
  contact: SegmentPreviewContact | null;
}) {
  if (!template) {
    return (
      <div className="rounded-[16px] bg-ovs-crm-surface-inset p-6 text-center text-[12.5px] text-ovs-crm-text-muted">
        Select a template to see what recipients will receive.
      </div>
    );
  }

  return (
    <div className="rounded-[18px] bg-[#E5DDD3] p-4">
      <div className="max-w-[280px] rounded-[12px] bg-white p-3 shadow-sm">
        {template.header_type === 'text' && template.header_content && (
          <p className="mb-1.5 text-[12.5px] font-bold text-ovs-crm-text-primary">{template.header_content}</p>
        )}
        {template.header_type && template.header_type !== 'text' && (
          <div className="mb-1.5 flex h-24 items-center justify-center rounded-[8px] bg-ovs-crm-surface-inset text-[11px] text-ovs-crm-text-muted">
            {template.header_type.charAt(0).toUpperCase() + template.header_type.slice(1)} header
          </div>
        )}
        <p className="whitespace-pre-wrap text-[12.5px] leading-relaxed text-ovs-crm-text-primary">
          {renderBody(template, mapping, contact)}
        </p>
        {template.footer && <p className="mt-1.5 text-[11px] text-ovs-crm-text-muted">{template.footer}</p>}
        {contact ? (
          <p className="mt-2 text-[10px] italic text-ovs-crm-text-muted">Sample: {contact.name}</p>
        ) : (
          <p className="mt-2 text-[10px] italic text-ovs-crm-text-muted">No matching contact to sample yet</p>
        )}
      </div>
      {template.buttons && template.buttons.length > 0 && (
        <div className="mt-2 max-w-[280px] space-y-1.5">
          {template.buttons.map((btn, i) => (
            <div
              key={i}
              className="rounded-[10px] bg-white py-2 text-center text-[12px] font-medium text-ovs-crm-accent-blue shadow-sm"
            >
              {btn.text}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

const SENDER_TONE: Record<string, CrmStatusTone> = {
  connected: 'good',
  disconnected: 'bad',
  degraded: 'warn',
};

const STEPS = [
  { key: 1, label: 'Details' },
  { key: 2, label: 'Segment & Sender' },
  { key: 3, label: 'Content' },
  { key: 4, label: 'Review & Launch' },
] as const;

const DRAFT_KEY = 'campaign-builder-draft';

interface DraftShape {
  step: number;
  name: string;
  description: string;
  whatsapp_account_id: string;
  audience_id: string;
  template_id: string;
  variable_mapping: Record<string, string>;
}

/**
 * There is no backend draft to persist into while a campaign is still being
 * built — the campaign row itself doesn't exist until the final submit — so
 * this in-progress form state has nowhere else to live. sessionStorage
 * (not localStorage) matches that: it survives a refresh but clears itself
 * with the tab, which is the right lifetime for a form nobody has saved yet.
 * Nothing sensitive is stored — a name, a Segment/sender/template choice.
 */
function loadDraft(segments: Segment[], accounts: WhatsAppSender[], templates: Template[]): DraftShape | null {
  try {
    const raw = sessionStorage.getItem(DRAFT_KEY);
    if (!raw) return null;
    const draft = JSON.parse(raw) as DraftShape;

    // A Segment, sender, or template referenced by a stale draft may no
    // longer exist (or no longer be approved/connected) — never restore a
    // dangling reference the current props can't back up.
    if (draft.audience_id && !segments.some((s) => String(s.id) === draft.audience_id)) draft.audience_id = '';
    if (draft.whatsapp_account_id && !accounts.some((a) => String(a.id) === draft.whatsapp_account_id)) draft.whatsapp_account_id = '';
    if (draft.template_id && !templates.some((t) => String(t.id) === draft.template_id)) draft.template_id = '';

    return draft;
  } catch {
    return null;
  }
}

export default function CampaignBuilder({ audiences: segments, accounts, templates, quiet_hours_active }: BuilderProps) {
  const restored = useRef(loadDraft(segments, accounts, templates)).current;

  const [currentStep, setCurrentStep] = useState(restored?.step ?? 1);
  const [isConfirmModalOpen, setIsConfirmModalOpen] = useState(false);

  const form = useForm({
    name: restored?.name ?? '',
    description: restored?.description ?? '',
    whatsapp_account_id: restored?.whatsapp_account_id ?? '',
    audience_id: restored?.audience_id ?? '',
    template_id: restored?.template_id ?? '',
    variable_mapping: restored?.variable_mapping ?? ({} as Record<string, string>),
    action: 'save_draft' as 'save_draft' | 'launch',
  });

  useEffect(() => {
    const draft: DraftShape = {
      step: currentStep,
      name: form.data.name,
      description: form.data.description,
      whatsapp_account_id: form.data.whatsapp_account_id,
      audience_id: form.data.audience_id,
      template_id: form.data.template_id,
      variable_mapping: form.data.variable_mapping,
    };
    sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    currentStep,
    form.data.name,
    form.data.description,
    form.data.whatsapp_account_id,
    form.data.audience_id,
    form.data.template_id,
    form.data.variable_mapping,
  ]);

  const clearDraft = () => sessionStorage.removeItem(DRAFT_KEY);

  const selectedSegment = segments.find((s) => s.id === Number(form.data.audience_id));
  const selectedSender = accounts.find((a) => a.id === Number(form.data.whatsapp_account_id));
  const availableTemplates = templates.filter(
    (t) => t.whatsapp_account_id === Number(form.data.whatsapp_account_id) && t.status === 'approved'
  );
  const selectedTemplate = availableTemplates.find((t) => t.id === Number(form.data.template_id));

  const handleTemplateSelect = (tmpl: Template) => {
    const mapping: Record<string, string> = {};
    (tmpl.variables ?? []).forEach((v) => {
      mapping[v] = 'name';
    });
    form.setData({ ...form.data, template_id: tmpl.id.toString(), variable_mapping: mapping });
  };

  const handleFinalSubmit = (actionType: 'save_draft' | 'launch') => {
    form.setData('action', actionType);
    form.post('/company/marketing/campaigns', {
      onSuccess: () => {
        clearDraft();
        setIsConfirmModalOpen(false);
      },
    });
  };

  const goTo = (step: number) => setCurrentStep(step);

  const warnings: string[] = [];
  if (selectedSegment && selectedSegment.eligible_contacts_count === 0) {
    warnings.push(`"${selectedSegment.name}" currently matches zero eligible contacts — launching now would reach nobody.`);
  }
  if (selectedSender && selectedSender.connection_status && selectedSender.connection_status !== 'connected') {
    warnings.push(`${selectedSender.verified_name} is showing as ${selectedSender.connection_status} — messages may not send until it reconnects.`);
  }
  if (quiet_hours_active) {
    warnings.push('Organization quiet hours are active — messages will queue and go out once quiet hours end.');
  }

  return (
    <MarketingShell title="New campaign">
      <CrmPageHead eyebrow="Marketing" title="New campaign" />

      {/* Matches the Automations builder's width, so the two read as one pattern. */}
      <div className="mx-auto max-w-5xl space-y-4 pb-16">
        {/* ── Stepper ─────────────────────────────────────────────────── */}
        <div className="rail-scroll -mx-1 max-w-full overflow-x-auto px-1">
          <div className="inline-flex w-full items-center gap-1 rounded-full bg-white p-1 shadow-ovs-crmPanel">
            {STEPS.map((step) => {
              const active = step.key === currentStep;
              const done = step.key < currentStep;
              const reachable = step.key <= currentStep;

              return (
                <button
                  key={step.key}
                  type="button"
                  disabled={!reachable}
                  onClick={() => reachable && goTo(step.key)}
                  className={cn(
                    'flex flex-1 items-center justify-center gap-1.5 whitespace-nowrap rounded-full px-3 py-2 text-[12px] font-medium transition-colors',
                    active && 'bg-ovs-crm-ink text-white',
                    !active && done && 'text-ovs-crm-accent-teal hover:bg-ovs-crm-surface-inset',
                    !active && !done && 'text-ovs-crm-text-muted',
                    !reachable && 'cursor-not-allowed opacity-50'
                  )}
                >
                  {done && !active ? <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" /> : <span className="tabular-nums">{step.key}</span>}
                  {step.label}
                </button>
              );
            })}
          </div>
        </div>

        {/* ── Step 1: Details ─────────────────────────────────────────── */}
        {currentStep === 1 && (
          <CrmCard step={0}>
            <h3 className="mb-4 text-[13px] font-semibold text-ovs-crm-text-primary">Campaign details</h3>

            <CrmFormField label="Campaign name" required error={form.errors.name} className="mb-4">
              <CrmInput
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                placeholder="e.g. Q3 Dental Outreach — Gujarat"
                autoFocus
              />
            </CrmFormField>

            <CrmFormField label="Internal description" hint="Optional — only you and your team see this">
              <CrmTextarea
                value={form.data.description}
                onChange={(e) => form.setData('description', e.target.value)}
                placeholder="What this campaign is for"
                rows={2}
              />
            </CrmFormField>

            <div className="mt-5 flex justify-end border-t border-ovs-crm-border pt-4">
              <CrmButton tone="primary" icon={ChevronRight} disabled={!form.data.name.trim()} onClick={() => goTo(2)}>
                Next
              </CrmButton>
            </div>
          </CrmCard>
        )}

        {/* ── Step 2: Segment & Sender ────────────────────────────────── */}
        {currentStep === 2 && (
          <div className="space-y-4">
            <CrmCard step={0}>
              <h3 className="mb-4 flex items-center gap-2 text-[13px] font-semibold text-ovs-crm-text-primary">
                <Target className="h-4 w-4 text-ovs-crm-text-muted" aria-hidden="true" />
                Segment
              </h3>

              <Select value={form.data.audience_id} onValueChange={(v) => form.setData('audience_id', v)}>
                <SelectTrigger aria-label="Segment" className="h-11 bg-ovs-crm-surface-inset">
                  <SelectValue placeholder="Choose a Segment" />
                </SelectTrigger>
                <SelectContent>
                  {segments.map((seg) => (
                    <SelectItem key={seg.id} value={seg.id.toString()}>
                      {seg.name} ({seg.eligible_contacts_count.toLocaleString('en-IN')} eligible)
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              {selectedSegment && (
                <div className="mt-4 rounded-[16px] bg-ovs-crm-surface-inset p-4">
                  <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                      <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">{selectedSegment.name}</p>
                      {selectedSegment.description && (
                        <p className="mt-0.5 truncate text-[11.5px] text-ovs-crm-text-muted">{selectedSegment.description}</p>
                      )}
                    </div>
                    <CrmValue value={selectedSegment.eligible_contacts_count.toLocaleString('en-IN')} size="major" />
                  </div>

                  {segmentRuleSummary(selectedSegment.rules).length > 0 && (
                    <div className="mt-3 flex flex-wrap gap-1.5">
                      {segmentRuleSummary(selectedSegment.rules).map((chip) => (
                        <span
                          key={chip}
                          className="rounded-full bg-white px-2.5 py-1 text-[11px] font-medium text-ovs-crm-text-secondary"
                        >
                          {chip}
                        </span>
                      ))}
                    </div>
                  )}

                  {selectedSegment.preview_contact && (
                    <div className="mt-3 flex items-center gap-2 border-t border-ovs-crm-border pt-3">
                      <CrmAvatar name={selectedSegment.preview_contact.name} size="sm" />
                      <p className="min-w-0 truncate text-[11.5px] text-ovs-crm-text-muted">
                        e.g. <span className="font-medium text-ovs-crm-text-secondary">{selectedSegment.preview_contact.name}</span>
                      </p>
                    </div>
                  )}

                  {selectedSegment.eligible_contacts_count === 0 && (
                    <p className="mt-3 flex items-center gap-1.5 text-[11.5px] font-medium text-ovs-semantic-danger">
                      <AlertTriangle className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                      Nobody currently matches this Segment.
                    </p>
                  )}
                </div>
              )}
            </CrmCard>

            <CrmCard step={1}>
              <h3 className="mb-4 flex items-center gap-2 text-[13px] font-semibold text-ovs-crm-text-primary">
                <Smartphone className="h-4 w-4 text-ovs-crm-text-muted" aria-hidden="true" />
                WhatsApp sender
              </h3>

              {accounts.length === 0 ? (
                <p className="py-6 text-center text-[12.5px] text-ovs-crm-text-muted">
                  No connected WhatsApp number yet — connect one in WhatsApp settings first.
                </p>
              ) : (
                <div className="space-y-2">
                  {accounts.map((account) => {
                    const on = form.data.whatsapp_account_id === account.id.toString();
                    const capacityKnown = account.max_daily_messages != null;
                    const capacityPct = capacityKnown
                      ? Math.min(100, Math.round((account.sent_today / (account.max_daily_messages as number)) * 100))
                      : null;

                    return (
                      <button
                        key={account.id}
                        type="button"
                        aria-pressed={on}
                        onClick={() => form.setData('whatsapp_account_id', account.id.toString())}
                        className={cn(
                          'flex w-full items-center gap-3 rounded-[16px] border p-3.5 text-left transition-colors',
                          on ? 'border-ovs-crm-ink bg-ovs-crm-surface-inset' : 'border-transparent bg-ovs-crm-surface-inset hover:border-ovs-crm-border'
                        )}
                      >
                        <span className="shrink-0">
                          {account.connection_status === 'disconnected' ? (
                            <WifiOff className="h-4 w-4 text-ovs-semantic-danger" aria-hidden="true" />
                          ) : (
                            <Wifi className="h-4 w-4 text-ovs-semantic-success" aria-hidden="true" />
                          )}
                        </span>

                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-2">
                            <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">{account.verified_name}</p>
                            <CrmStatusPill tone={SENDER_TONE[account.connection_status ?? 'connected'] ?? 'good'}>
                              {account.connection_status ?? 'connected'}
                            </CrmStatusPill>
                          </div>
                          <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                            {account.display_phone_number} · Active {relativeTime(account.last_webhook_at)}
                          </p>
                          {capacityKnown && (
                            <div className="mt-2 max-w-[220px]">
                              <div className="mb-1 flex items-center justify-between text-[10.5px] text-ovs-crm-text-muted">
                                <span>Today</span>
                                <span className="tabular-nums">
                                  {account.sent_today.toLocaleString('en-IN')} / {(account.max_daily_messages as number).toLocaleString('en-IN')}
                                </span>
                              </div>
                              <div className="h-1 overflow-hidden rounded-full bg-ovs-crm-border">
                                <div
                                  className="h-full rounded-full bg-ovs-crm-accent-teal"
                                  style={{ width: `${capacityPct}%` }}
                                />
                              </div>
                            </div>
                          )}
                        </div>
                      </button>
                    );
                  })}
                </div>
              )}
            </CrmCard>

            <div className="flex justify-between">
              <CrmButton icon={ChevronLeft} onClick={() => goTo(1)}>
                Back
              </CrmButton>
              <CrmButton
                tone="primary"
                icon={ChevronRight}
                disabled={!form.data.audience_id || !form.data.whatsapp_account_id}
                onClick={() => goTo(3)}
              >
                Next
              </CrmButton>
            </div>
          </div>
        )}

        {/* ── Step 3: Content ─────────────────────────────────────────── */}
        {currentStep === 3 && (
          <div className="space-y-4">
            <CrmCard step={0}>
              <h3 className="mb-4 flex items-center gap-2 text-[13px] font-semibold text-ovs-crm-text-primary">
                <FileText className="h-4 w-4 text-ovs-crm-text-muted" aria-hidden="true" />
                Template
              </h3>

              {availableTemplates.length === 0 ? (
                <p className="py-6 text-center text-[12.5px] text-ovs-crm-text-muted">
                  No approved template on this number yet — approve one in Templates first.
                </p>
              ) : (
                <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                  {availableTemplates.map((tmpl) => {
                    const on = form.data.template_id === tmpl.id.toString();
                    return (
                      <button
                        key={tmpl.id}
                        type="button"
                        aria-pressed={on}
                        onClick={() => handleTemplateSelect(tmpl)}
                        className={cn(
                          'rounded-[16px] border p-3.5 text-left transition-colors',
                          on ? 'border-ovs-crm-ink bg-ovs-crm-surface-inset' : 'border-transparent bg-ovs-crm-surface-inset hover:border-ovs-crm-border'
                        )}
                      >
                        <div className="flex items-center justify-between gap-2">
                          <span className="truncate font-mono text-[12px] font-semibold text-ovs-crm-text-primary">{tmpl.name}</span>
                          <CrmStatusPill tone="good">Approved</CrmStatusPill>
                        </div>
                        <p className="mt-1.5 line-clamp-2 text-[11.5px] text-ovs-crm-text-muted">{tmpl.body}</p>
                      </button>
                    );
                  })}
                </div>
              )}

              {selectedTemplate && selectedTemplate.variables && selectedTemplate.variables.length > 0 && (
                <div className="mt-4 border-t border-ovs-crm-border pt-4">
                  <p className="mb-2.5 text-[11px] font-semibold uppercase tracking-[0.1em] text-ovs-crm-text-secondary">
                    Personalization
                  </p>
                  <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                    {selectedTemplate.variables.map((v) => (
                      <CrmFormField key={v} label={`{{${v}}}`}>
                        <Select
                          value={form.data.variable_mapping[v] || 'name'}
                          onValueChange={(val) => form.setData('variable_mapping', { ...form.data.variable_mapping, [v]: val })}
                        >
                          <SelectTrigger className="h-9 bg-white text-[12.5px]">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {VARIABLE_FIELD_OPTIONS.map((opt) => (
                              <SelectItem key={opt.value} value={opt.value}>
                                {opt.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </CrmFormField>
                    ))}
                  </div>
                </div>
              )}
            </CrmCard>

            <CrmCard step={1}>
              <h3 className="mb-4 text-[13px] font-semibold text-ovs-crm-text-primary">Preview</h3>
              <MessagePreview
                template={selectedTemplate}
                mapping={form.data.variable_mapping}
                contact={selectedSegment?.preview_contact ?? null}
              />
            </CrmCard>

            <div className="flex justify-between">
              <CrmButton icon={ChevronLeft} onClick={() => goTo(2)}>
                Back
              </CrmButton>
              <CrmButton tone="primary" icon={ChevronRight} disabled={!form.data.template_id} onClick={() => goTo(4)}>
                Review
              </CrmButton>
            </div>
          </div>
        )}

        {/* ── Step 4: Review & Launch ─────────────────────────────────── */}
        {currentStep === 4 && (
          <div className="space-y-4">
            <CrmCard step={0}>
              <h3 className="mb-4 text-[13px] font-semibold text-ovs-crm-text-primary">Ready to review</h3>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <SummaryTile label="Campaign" value={form.data.name} />
                <SummaryTile
                  label="Segment"
                  value={selectedSegment ? `${selectedSegment.name} · ${selectedSegment.eligible_contacts_count.toLocaleString('en-IN')} eligible` : '—'}
                />
                <SummaryTile
                  label="Sender"
                  value={selectedSender ? `${selectedSender.verified_name} · ${selectedSender.display_phone_number}` : '—'}
                />
                <SummaryTile label="Template" value={selectedTemplate?.name ?? '—'} mono />
              </div>

              {warnings.length > 0 && (
                <div className="mt-4 space-y-2">
                  {warnings.map((w) => (
                    <div
                      key={w}
                      className="flex items-start gap-2.5 rounded-[14px] bg-ovs-semantic-warning-bg p-3 text-[12px] leading-relaxed text-ovs-semantic-warning-text"
                    >
                      <Clock className="h-4 w-4 shrink-0" aria-hidden="true" />
                      {w}
                    </div>
                  ))}
                </div>
              )}
            </CrmCard>

            <CrmCard step={1}>
              <h3 className="mb-4 text-[13px] font-semibold text-ovs-crm-text-primary">What recipients will see</h3>
              <MessagePreview
                template={selectedTemplate}
                mapping={form.data.variable_mapping}
                contact={selectedSegment?.preview_contact ?? null}
              />
            </CrmCard>

            <div className="flex items-center justify-between">
              <CrmButton icon={ChevronLeft} onClick={() => goTo(3)}>
                Back
              </CrmButton>

              <div className="flex items-center gap-2">
                <CrmButton icon={Save} disabled={form.processing} onClick={() => handleFinalSubmit('save_draft')}>
                  Save draft
                </CrmButton>
                <CrmButton tone="primary" icon={Send} disabled={form.processing} onClick={() => setIsConfirmModalOpen(true)}>
                  Launch
                </CrmButton>
              </div>
            </div>
          </div>
        )}
      </div>

      {/*
       * Launching sends real messages that cannot be recalled, so the
       * confirmation says exactly that rather than asking "are you sure".
       * The lifecycle from here is Draft → Preparing → Sending, unchanged
       * from Phase 1 — this dialog only ever calls the same store endpoint.
       */}
      <CrmConfirm
        open={isConfirmModalOpen}
        onClose={() => setIsConfirmModalOpen(false)}
        onConfirm={() => handleFinalSubmit('launch')}
        tone="primary"
        title={`Send this campaign to ${(selectedSegment?.eligible_contacts_count ?? 0).toLocaleString('en-IN')} contact${selectedSegment?.eligible_contacts_count === 1 ? '' : 's'}?`}
        description="Messages start going out immediately and cannot be recalled once WhatsApp has them. Opted-out and suppressed contacts are skipped automatically."
        confirmLabel="Send it"
      />
    </MarketingShell>
  );
}

function SummaryTile({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="rounded-[14px] bg-ovs-crm-surface-inset p-3.5">
      <p className="text-[10px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted">{label}</p>
      <p className={cn('mt-1 truncate text-[13px] font-medium text-ovs-crm-text-primary', mono && 'font-mono')}>{value || '—'}</p>
    </div>
  );
}

/** A short plain-language read of a Segment's rules — the same vocabulary Segments' own detail page uses, kept minimal here since this is a summary, not the full picture. */
function segmentRuleSummary(rules: SegmentRules): string[] {
  const chips: string[] = [];
  if (rules.state) chips.push(`State: ${rules.state}`);
  if (rules.city) chips.push(`City: ${rules.city}`);
  if (rules.area) chips.push(`Area: ${rules.area}`);
  if (rules.organization) chips.push(`Org: ${rules.organization}`);
  if (rules.status) chips.push(`Status: ${rules.status}`);
  if (rules.import_batch) chips.push('From an import batch');
  if (rules.imported_from || rules.imported_to) chips.push('Added in a date range');
  for (const tag of rules.tags ?? []) {
    if (tag) chips.push(`Tag: ${tag}`);
  }
  return chips;
}
