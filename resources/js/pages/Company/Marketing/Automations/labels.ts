import {
  UserPlus2,
  Tag as TagIcon,
  Repeat,
  MessageCircle,
  MessagesSquare,
  Reply,
  MousePointerClick,
  Send,
  CheckCheck,
  Eye,
  Megaphone,
  Flag,
  UserCheck,
  UserPlus,
  ArrowRightLeft,
  Trophy,
  XCircle,
  GitBranch,
  Clock,
  MessageSquare,
  CheckSquare,
  FileText,
  Ban,
  type LucideIcon,
} from 'lucide-react';

/**
 * What actually sets a rule off, and what each step does — in words.
 *
 * The stored values are the event/step names the engine reads
 * (`whatsapp.button_reply`, `create_lead`); showing those to a person tells
 * them nothing about when their automation runs or what it does. One table
 * per concept, shared by the index, detail, and builder pages, so a label
 * can't read differently depending on which page you're looking at.
 */

export const TRIGGER_LABEL: Record<string, string> = {
  'contact.created': 'A new contact is added',
  'contact.tag_added': 'A tag is added to a contact',
  'contact.tag_removed': 'A tag is removed from a contact',
  'contact.status_changed': "A contact's marketing status changes",
  'whatsapp.conversation_started': 'A new WhatsApp conversation opens',
  'whatsapp.message_received': 'Any WhatsApp message arrives',
  'whatsapp.reply_received': 'Someone writes back on WhatsApp',
  'whatsapp.button_reply': 'Someone taps a quick-reply button',
  'campaign.sent': 'A campaign message is sent',
  'campaign.delivered': 'A campaign message is delivered',
  'campaign.read': 'A campaign message is read',
  'campaign.replied': 'Someone replies to a campaign message',
  'campaign.completed': 'A campaign finishes sending',
  'lead.created': 'A new CRM lead is created',
  'lead.status_changed': "A lead's status changes",
  'lead.won': 'A lead is won',
  'lead.lost': 'A lead is lost',
};

/**
 * One longer sentence per trigger, for the "what exactly does this mean"
 * question a short label can't answer on its own — shown as a hint under
 * whichever trigger is currently selected, not all 17 at once.
 */
export const TRIGGER_DESCRIPTION: Record<string, string> = {
  'contact.created': 'Fires once, right when the contact is added — from an import, a form, or manual entry.',
  'contact.tag_added': 'Fires the moment a tag is attached to a contact, by a rep or by another automation.',
  'contact.tag_removed': 'Fires the moment a tag is taken off a contact.',
  'contact.status_changed': "Fires when a contact's status changes — for example when they opt out or get suppressed.",
  'whatsapp.conversation_started': 'Fires the first time this contact messages you on WhatsApp.',
  'whatsapp.message_received': 'Fires on every inbound WhatsApp message, in any conversation.',
  'whatsapp.reply_received': 'Fires when a contact replies on WhatsApp — any reply, whether or not it followed a campaign.',
  'whatsapp.button_reply': 'Fires when a contact taps a quick-reply button on a template you sent.',
  'campaign.sent': 'Fires the moment a campaign message goes out to this recipient.',
  'campaign.delivered': 'Fires when WhatsApp confirms delivery of a campaign message.',
  'campaign.read': 'Fires when a campaign message is marked read.',
  'campaign.replied': "Fires only when a reply can be tied back to a specific campaign send — unlike \"Someone writes back on WhatsApp\", which fires for any reply.",
  'campaign.completed': 'Fires once, when every recipient in a campaign has been processed.',
  'lead.created': 'Fires the moment a new lead is created in the CRM.',
  'lead.status_changed': 'Fires whenever a lead moves to a different pipeline status.',
  'lead.won': "Fires the moment a lead's status becomes Won.",
  'lead.lost': "Fires the moment a lead's status becomes Lost.",
};

/**
 * The trigger picker groups by where the event comes from — Contact,
 * WhatsApp, Campaign, or Lead activity — so choosing one is "which kind of
 * thing happened" rather than scanning 17 flat, unrelated sentences.
 */
export const TRIGGER_GROUPS: { label: string; triggers: string[] }[] = [
  {
    label: 'Contact activity',
    triggers: ['contact.created', 'contact.tag_added', 'contact.tag_removed', 'contact.status_changed'],
  },
  {
    label: 'WhatsApp activity',
    triggers: [
      'whatsapp.conversation_started',
      'whatsapp.message_received',
      'whatsapp.reply_received',
      'whatsapp.button_reply',
    ],
  },
  {
    label: 'Campaign activity',
    triggers: ['campaign.sent', 'campaign.delivered', 'campaign.read', 'campaign.replied', 'campaign.completed'],
  },
  {
    label: 'Lead activity',
    triggers: ['lead.created', 'lead.status_changed', 'lead.won', 'lead.lost'],
  },
];

export const TRIGGER_ICON: Record<string, LucideIcon> = {
  'contact.created': UserPlus2,
  'contact.tag_added': TagIcon,
  'contact.tag_removed': TagIcon,
  'contact.status_changed': Repeat,
  'whatsapp.conversation_started': MessageCircle,
  'whatsapp.message_received': MessagesSquare,
  'whatsapp.reply_received': Reply,
  'whatsapp.button_reply': MousePointerClick,
  'campaign.sent': Send,
  'campaign.delivered': CheckCheck,
  'campaign.read': Eye,
  'campaign.replied': Reply,
  'campaign.completed': Megaphone,
  'lead.created': UserPlus,
  'lead.status_changed': ArrowRightLeft,
  'lead.won': Trophy,
  'lead.lost': XCircle,
};

export const STEP_LABEL: Record<string, string> = {
  condition: 'Check a condition before going on',
  delay: 'Wait before the next step',
  wait: 'Wait before the next step',
  send_template: 'Send an approved WhatsApp template',
  create_task: 'Create a task for a rep',
  create_lead: 'Create a lead in the CRM',
  handover_to_human: 'Hand the conversation to a person',
  add_lead_activity: 'Log an activity on the lead',
  add_tag: 'Tag the contact',
  remove_tag: 'Remove a tag from the contact',
  suppress_contact: 'Stop messaging this contact',
  stop_automation: 'Stop the automation here',
};

/** Same icon whether a step is being added, already sitting in the builder, or shown read-only on the detail page. */
export const STEP_ICON: Record<string, LucideIcon> = {
  condition: GitBranch,
  delay: Clock,
  wait: Clock,
  send_template: MessageSquare,
  create_task: CheckSquare,
  create_lead: UserPlus,
  handover_to_human: UserCheck,
  add_lead_activity: FileText,
  add_tag: TagIcon,
  remove_tag: TagIcon,
  suppress_contact: Ban,
  stop_automation: Flag,
};

/** Falls back to this only for a value with no label above — keeps "foo.bar_baz" readable rather than a raw enum string. */
export const humanize = (value: string): string =>
  value
    .replace(/[_.]/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());

export const triggerLabel = (triggerType: string): string => TRIGGER_LABEL[triggerType] ?? humanize(triggerType);

export const triggerDescription = (triggerType: string): string | undefined => TRIGGER_DESCRIPTION[triggerType];

export const stepLabel = (stepType: string): string => STEP_LABEL[stepType] ?? humanize(stepType);
