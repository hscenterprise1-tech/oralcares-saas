import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmCard,
  CrmSection,
  CrmButton,
  CrmInput,
  CrmSelect,
  CrmFormField,
} from '@/pages/Company/design';
import { TRIGGER_GROUPS, TRIGGER_LABEL, TRIGGER_ICON, triggerDescription, STEP_ICON, stepLabel } from './labels';
import {
  Plus,
  Trash2,
  ArrowUp,
  ArrowDown,
  AlertCircle,
  Zap,
  Save,
  MousePointerClick,
} from 'lucide-react';

interface StepItem {
  id: string;
  type: string;
  duration?: number;
  unit?: string;
  template_id?: number | string;
  title?: string;
  description?: string;
  tag_name?: string;
  priority?: string;
  conditions?: {
    match: 'all' | 'any';
    rules: { field: string; operator: string; value: string }[];
  };
  [key: string]: any;
}

interface AutomationModel {
  id?: number;
  name: string;
  description: string | null;
  status: string;
  trigger_type: string;
  definition: {
    version: number;
    trigger: { type: string };
    steps: StepItem[];
  };
}

interface TemplateOption {
  id: number;
  name: string;
  language: string;
  body: string;
  variables: string[] | null;
  quick_reply_labels: string[];
  whatsapp_account_name: string;
}

interface BuilderProps {
  automation: AutomationModel | null;
  templates: TemplateOption[];
}

const CONDITION_FIELDS = [
  { value: 'contact.marketing_status', label: 'Contact: Marketing status' },
  { value: 'contact.organization', label: 'Contact: Organisation' },
  { value: 'contact.city', label: 'Contact: City' },
  { value: 'contact.tag', label: 'Contact: Has tag' },
  { value: 'lead.status', label: 'Lead: Status' },
  { value: 'lead.source', label: 'Lead: Source' },
  { value: 'conversation.status', label: 'Conversation: Status' },
  { value: 'conversation.unread_count', label: 'Conversation: Unread count' },
  { value: 'event.button_id', label: 'Reply: Button tapped (ID)' },
  { value: 'event.button_title', label: 'Reply: Button tapped (label)' },
];

const CONDITION_OPERATORS = [
  { value: 'is', label: 'is' },
  { value: 'is_not', label: 'is not' },
  { value: 'contains', label: 'contains' },
  { value: 'does_not_contain', label: 'does not contain' },
  { value: 'starts_with', label: 'starts with' },
  { value: 'exists', label: 'exists' },
  { value: 'does_not_exist', label: 'does not exist' },
  { value: 'greater_than', label: 'greater than' },
  { value: 'less_than', label: 'less than' },
];

const STEP_ADD_OPTIONS: { type: string; label: string }[] = [
  { type: 'delay', label: 'Delay' },
  { type: 'condition', label: 'Condition' },
  { type: 'send_template', label: 'Send WhatsApp template' },
  { type: 'create_task', label: 'Create CRM task' },
  { type: 'create_lead', label: 'Create CRM lead' },
  { type: 'handover_to_human', label: 'Hand to a person' },
  { type: 'add_tag', label: 'Attach tag' },
  { type: 'suppress_contact', label: 'Suppress contact' },
];

export default function AutomationBuilder({ automation, templates = [] }: BuilderProps) {
  const isEdit = Boolean(automation?.id);

  const [name, setName] = useState(automation?.name || '');
  const [description, setDescription] = useState(automation?.description || '');
  const [triggerType, setTriggerType] = useState(automation?.trigger_type || 'whatsapp.reply_received');
  const [steps, setSteps] = useState<StepItem[]>(
    automation?.definition?.steps || [
      {
        id: 'step_1',
        type: 'condition',
        conditions: {
          match: 'all',
          rules: [{ field: 'lead.status', operator: 'is', value: 'new' }],
        },
      },
      {
        id: 'step_2',
        type: 'delay',
        duration: 30,
        unit: 'minutes',
      },
      {
        id: 'step_3',
        type: 'create_task',
        title: 'Follow up with WhatsApp lead',
      },
    ]
  );

  const [isSaving, setIsSaving] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);

  const handleAddStep = (type: string) => {
    // Date.now() collides when two steps are added inside the same
    // millisecond — confirmed live by clicking several "add step" buttons
    // in a row — and the backend rejects the resulting duplicate step ID.
    const newId = `step_${crypto.randomUUID()}`;
    const newStep: StepItem = { id: newId, type };

    if (type === 'condition') {
      newStep.conditions = {
        match: 'all',
        rules: [{ field: 'contact.marketing_status', operator: 'is', value: 'active' }],
      };
    } else if (type === 'delay') {
      newStep.duration = 1;
      newStep.unit = 'hours';
    } else if (type === 'create_task') {
      newStep.title = 'Review new marketing inquiry';
    } else if (type === 'add_tag') {
      newStep.tag_name = 'WhatsApp Qualified';
    } else if (type === 'send_template') {
      newStep.template_id = templates[0]?.id ?? '';
    } else if (type === 'create_lead') {
      newStep.priority = 'high';
    }

    // Functional update: assembling a longer automation means clicking
    // several "add step" buttons in quick succession, and a plain
    // `setSteps([...steps, newStep])` reading the outer `steps` closure
    // silently drops all but the last of those when React batches them —
    // confirmed live, not theoretical.
    setSteps((prev) => [...prev, newStep]);
  };

  const handleAddRule = (stepIdx: number) => {
    const updated = [...steps];
    const step = updated[stepIdx];
    const rules = step.conditions?.rules ?? [];
    step.conditions = {
      match: step.conditions?.match ?? 'all',
      rules: [...rules, { field: 'contact.marketing_status', operator: 'is', value: '' }],
    };
    setSteps(updated);
  };

  const handleUpdateRule = (stepIdx: number, ruleIdx: number, patch: Partial<{ field: string; operator: string; value: string }>) => {
    const updated = [...steps];
    const rules = [...(updated[stepIdx].conditions?.rules ?? [])];
    rules[ruleIdx] = { ...rules[ruleIdx], ...patch };
    updated[stepIdx].conditions = { match: updated[stepIdx].conditions?.match ?? 'all', rules };
    setSteps(updated);
  };

  const handleRemoveRule = (stepIdx: number, ruleIdx: number) => {
    const updated = [...steps];
    const rules = [...(updated[stepIdx].conditions?.rules ?? [])];
    rules.splice(ruleIdx, 1);
    updated[stepIdx].conditions = { match: updated[stepIdx].conditions?.match ?? 'all', rules };
    setSteps(updated);
  };

  const handleRemoveStep = (index: number) => {
    // Same functional-update reasoning as handleAddStep — trimming a long
    // automation down means removing several steps in a row.
    setSteps((prev) => {
      const updated = [...prev];
      updated.splice(index, 1);
      return updated;
    });
  };

  const handleMoveStep = (index: number, direction: 'up' | 'down') => {
    // Same functional-update reasoning again — reordering a long automation
    // means clicking the up/down arrows repeatedly in a row.
    setSteps((prev) => {
      if (
        (direction === 'up' && index === 0) ||
        (direction === 'down' && index === prev.length - 1)
      ) {
        return prev;
      }
      const updated = [...prev];
      const targetIdx = direction === 'up' ? index - 1 : index + 1;
      const temp = updated[index];
      updated[index] = updated[targetIdx];
      updated[targetIdx] = temp;
      return updated;
    });
  };

  const handleSave = (activate: boolean = false) => {
    if (!name.trim()) {
      setErrors(['Please enter an automation name.']);
      return;
    }
    setIsSaving(true);
    setErrors([]);

    const payload = {
      name,
      description,
      trigger_type: triggerType,
      definition: {
        version: 1,
        trigger: { type: triggerType },
        steps,
      },
      activate,
    };

    if (isEdit && automation?.id) {
      router.put(`/company/marketing/automations/${automation.id}`, payload, {
        onError: (err: any) => {
          setErrors(Object.values(err));
          setIsSaving(false);
        },
        onSuccess: () => setIsSaving(false),
      });
    } else {
      router.post('/company/marketing/automations', payload, {
        onError: (err: any) => {
          setErrors(Object.values(err));
          setIsSaving(false);
        },
        onSuccess: () => setIsSaving(false),
      });
    }
  };

  return (
    <MarketingShell title={isEdit ? 'Edit automation' : 'New automation'}>
      <CrmPageHead
        eyebrow="Marketing"
        title={isEdit ? 'Edit automation' : 'New automation'}
        actions={
          <>
            {/*
              * The way out. Building an automation is a long form, and it had
              * no route back to the list at all — the only exits were saving
              * it or the browser's own back button.
              */}
            <CrmButton href="/company/marketing/automations">All automations</CrmButton>
            <CrmButton icon={Save} onClick={() => handleSave(false)} disabled={isSaving}>
              Save as draft
            </CrmButton>
            <CrmButton tone="primary" icon={Zap} onClick={() => handleSave(true)} disabled={isSaving}>
              Save and switch on
            </CrmButton>
          </>
        }
      />

      {/*
        * Wider than the 4xl a plain form would use: the step rows carry a
        * field, an operator and a value side by side, and at 896px those were
        * crowding each other while the page around them sat empty.
        */}
      <div className="mx-auto max-w-5xl space-y-4 pb-12">
        {errors.length > 0 && (
          <div className="space-y-1 rounded-[18px] border border-ovs-crm-danger-border bg-ovs-crm-danger-soft p-4 text-[12px] text-ovs-crm-danger-text">
            {errors.map((e, i) => (
              <div key={i} className="flex items-center gap-2">
                <AlertCircle className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>{e}</span>
              </div>
            ))}
          </div>
        )}

        {/* ═══ Identity + trigger ═══════════════════════════════════════ */}
        <CrmSection step={0} title="Runs when" description="What starts this automation">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <CrmFormField label="Automation name" required>
              <CrmInput
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Reply follow-up"
              />
            </CrmFormField>

            <CrmFormField label="Trigger">
              <CrmSelect value={triggerType} onChange={(e) => setTriggerType(e.target.value)}>
                {TRIGGER_GROUPS.map((group) => (
                  <optgroup key={group.label} label={group.label}>
                    {group.triggers.map((value) => (
                      <option key={value} value={value}>
                        {TRIGGER_LABEL[value]}
                      </option>
                    ))}
                  </optgroup>
                ))}
              </CrmSelect>
              {triggerDescription(triggerType) && (
                <p className="mt-1.5 flex items-start gap-1.5 text-[11.5px] leading-tight text-ovs-crm-text-muted">
                  {(() => {
                    const TriggerIcon = TRIGGER_ICON[triggerType];
                    return TriggerIcon ? (
                      <TriggerIcon className="mt-px h-3 w-3 shrink-0" aria-hidden="true" />
                    ) : null;
                  })()}
                  {triggerDescription(triggerType)}
                </p>
              )}
            </CrmFormField>
          </div>

          <CrmFormField label="Description" className="mt-4">
            <CrmInput
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="e.g. Creates a follow-up task when a lead replies"
            />
          </CrmFormField>
        </CrmSection>

        {/* ═══ Steps ═══════════════════════════════════════════════════ */}
        <CrmSection
          step={1}
          title="Then, in order"
          description={`${steps.length} step${steps.length === 1 ? '' : 's'}`}
        >
          {steps.length === 0 ? (
            <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">
              Add a step below to build the automation.
            </p>
          ) : (
            <div className="space-y-3">
              {steps.map((step, idx) => (
                <div
                  key={step.id}
                  className="rounded-[18px] bg-ovs-crm-surface-inset p-4"
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-[11.5px] tabular-nums text-ovs-crm-text-secondary">
                        {idx + 1}
                      </span>
                      {(() => {
                        const StepIcon = STEP_ICON[step.type];
                        return StepIcon ? (
                          <StepIcon className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-secondary" aria-hidden="true" />
                        ) : null;
                      })()}
                      <p className="text-[13px] font-medium text-ovs-crm-text-primary">{stepLabel(step.type)}</p>
                    </div>

                    <div className="flex shrink-0 items-center gap-1">
                      <CrmButton
                        size="sm"
                        tone="ghost"
                        onClick={() => handleMoveStep(idx, 'up')}
                        disabled={idx === 0}
                        aria-label="Move step up"
                      >
                        <ArrowUp className="h-3.5 w-3.5" aria-hidden="true" />
                      </CrmButton>
                      <CrmButton
                        size="sm"
                        tone="ghost"
                        onClick={() => handleMoveStep(idx, 'down')}
                        disabled={idx === steps.length - 1}
                        aria-label="Move step down"
                      >
                        <ArrowDown className="h-3.5 w-3.5" aria-hidden="true" />
                      </CrmButton>
                      <CrmButton
                        size="sm"
                        tone="ghost"
                        onClick={() => handleRemoveStep(idx)}
                        aria-label="Remove step"
                        className="text-ovs-crm-danger-text"
                      >
                        <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                      </CrmButton>
                    </div>
                  </div>

                  {/* STEP DETAILS */}
                  {step.type === 'condition' && (
                    <div className="mt-3 space-y-2">
                      {(step.conditions?.rules ?? []).map((rule, ruleIdx) => (
                        <div key={ruleIdx} className="flex flex-wrap items-center gap-1.5">
                          <CrmSelect
                            value={rule.field}
                            onChange={(e) => handleUpdateRule(idx, ruleIdx, { field: e.target.value })}
                            className="h-9 w-52"
                          >
                            {CONDITION_FIELDS.map((f) => (
                              <option key={f.value} value={f.value}>{f.label}</option>
                            ))}
                          </CrmSelect>
                          <CrmSelect
                            value={rule.operator}
                            onChange={(e) => handleUpdateRule(idx, ruleIdx, { operator: e.target.value })}
                            className="h-9 w-36"
                          >
                            {CONDITION_OPERATORS.map((o) => (
                              <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                          </CrmSelect>
                          {!['exists', 'does_not_exist'].includes(rule.operator) && (
                            <CrmInput
                              value={rule.value}
                              onChange={(e) => handleUpdateRule(idx, ruleIdx, { value: e.target.value })}
                              placeholder="Value to match…"
                              className="h-9 w-40"
                            />
                          )}
                          <CrmButton
                            size="sm"
                            tone="ghost"
                            onClick={() => handleRemoveRule(idx, ruleIdx)}
                            aria-label="Remove rule"
                            className="text-ovs-crm-danger-text"
                          >
                            <Trash2 className="h-3 w-3" aria-hidden="true" />
                          </CrmButton>
                        </div>
                      ))}
                      <CrmButton size="sm" icon={Plus} onClick={() => handleAddRule(idx)}>
                        Add rule
                      </CrmButton>
                      <p className="text-[11px] text-ovs-crm-text-muted">
                        To branch on a tapped quick-reply button, use "Reply: Button tapped (ID)" with the button's ID (e.g. <span className="font-mono">interested</span>).
                      </p>
                    </div>
                  )}

                  {step.type === 'send_template' && (
                    <div className="mt-3 space-y-1.5">
                      <CrmSelect
                        value={String(step.template_id || '')}
                        onChange={(e) => {
                          const updated = [...steps];
                          updated[idx].template_id = Number(e.target.value);
                          setSteps(updated);
                        }}
                        className="h-9 w-full sm:w-96"
                      >
                        {templates.length === 0 && <option value="">No approved templates yet</option>}
                        {templates.map((t) => (
                          <option key={t.id} value={String(t.id)}>
                            {t.name} ({t.language}) — {t.whatsapp_account_name}
                          </option>
                        ))}
                      </CrmSelect>
                      {(() => {
                        const selected = templates.find((t) => t.id === Number(step.template_id));
                        if (!selected) return null;
                        return (
                          <div className="space-y-1 text-[11.5px] text-ovs-crm-text-muted">
                            <p className="italic">"{selected.body}"</p>
                            {selected.quick_reply_labels.length > 0 && (
                              <p className="flex items-center gap-1 font-medium text-ovs-crm-text-secondary">
                                <MousePointerClick className="h-3 w-3" aria-hidden="true" /> Buttons: {selected.quick_reply_labels.join(' · ')}
                              </p>
                            )}
                          </div>
                        );
                      })()}
                    </div>
                  )}

                  {step.type === 'create_lead' && (
                    <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
                      Creates a CRM lead from this conversation's contact, assigned to whoever the conversation is assigned to.
                    </p>
                  )}

                  {step.type === 'suppress_contact' && (
                    <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
                      Marks the contact as suppressed — excluded from all future marketing sends.
                    </p>
                  )}

                  {step.type === 'delay' && (
                    <div className="mt-3 flex items-center gap-2">
                      <span className="text-[12px] text-ovs-crm-text-secondary">Wait for</span>
                      <CrmInput
                        type="number"
                        min={1}
                        value={step.duration || 1}
                        onChange={(e) => {
                          const updated = [...steps];
                          updated[idx].duration = Number(e.target.value);
                          setSteps(updated);
                        }}
                        className="h-9 w-20"
                      />
                      <CrmSelect
                        value={step.unit || 'hours'}
                        onChange={(e) => {
                          const updated = [...steps];
                          updated[idx].unit = e.target.value;
                          setSteps(updated);
                        }}
                        className="h-9 w-28"
                      >
                        <option value="minutes">Minutes</option>
                        <option value="hours">Hours</option>
                        <option value="days">Days</option>
                      </CrmSelect>
                    </div>
                  )}

                  {step.type === 'create_task' && (
                    <CrmInput
                      value={step.title || ''}
                      onChange={(e) => {
                        const updated = [...steps];
                        updated[idx].title = e.target.value;
                        setSteps(updated);
                      }}
                      placeholder="Task title…"
                      className="mt-3 h-9 w-72"
                    />
                  )}

                  {step.type === 'add_tag' && (
                    <CrmInput
                      value={step.tag_name || ''}
                      onChange={(e) => {
                        const updated = [...steps];
                        updated[idx].tag_name = e.target.value;
                        setSteps(updated);
                      }}
                      placeholder="Tag name to attach…"
                      className="mt-3 h-9 w-60"
                    />
                  )}
                </div>
              ))}
            </div>
          )}

          {/* ADD STEP */}
          <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-ovs-crm-border pt-4">
            <span className="mr-1 text-[11.5px] font-medium text-ovs-crm-text-secondary">Add a step</span>
            {STEP_ADD_OPTIONS.map(({ type, label }) => (
              <CrmButton key={type} size="sm" icon={STEP_ICON[type]} onClick={() => handleAddStep(type)}>
                {label}
              </CrmButton>
            ))}
          </div>
        </CrmSection>
      </div>
    </MarketingShell>
  );
}
