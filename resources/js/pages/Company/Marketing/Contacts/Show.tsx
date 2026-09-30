import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
  Building2,
  Clock,
  Edit3,
  ExternalLink,
  MapPin,
  MessageSquare,
  Phone,
  Route,
  ShieldAlert,
} from 'lucide-react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmCard,
  CrmCardIcon,
  CrmSection,
  CrmStatusPill,
  CrmButton,
  CrmAvatar,
} from '@/pages/Company/design';
import { ContactFormModal } from './Partials/ContactFormModal';
import type { MarketingContact, MarketingTag } from './types';

/**
 * A marketing contact.
 *
 * This page replaces the slide-over the contacts list used to open, which
 * summarised the record and offered "open the full page" as its main action —
 * a detour on the way somewhere the reader was already going. It has a URL
 * now, so it can be sent to someone.
 *
 * The page leads with whether this person can be messaged at all, because
 * every other fact about them is moot if they cannot.
 */

interface ActivityItem {
  id: string;
  title: string;
  description: string;
  user: string;
  timestamp: string;
  type: string;
}

interface ShowProps {
  contact: MarketingContact;
  activities: ActivityItem[];
  /** For the edit modal's tag picker — the organisation's full tag list. */
  tags: MarketingTag[];
}

const formatDate = (iso: string) => {
  const d = new Date(iso);

  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

export default function ContactShow({ contact, activities = [], tags = [] }: ShowProps) {
  const reachable = contact.marketing_status === 'active' || contact.marketing_status === 'subscribed';
  const [isEditOpen, setIsEditOpen] = useState(false);

  const facts = [
    { icon: Phone, label: 'Phone', value: contact.phone },
    { icon: Building2, label: 'Organisation', value: contact.organization },
    { icon: MapPin, label: 'City', value: contact.city },
    { icon: Clock, label: 'First added', value: formatDate(contact.created_at) },
  ].filter((f) => f.value);

  return (
    <MarketingShell title={contact.name}>
      <CrmPageHead
        eyebrow="Marketing"
        title={contact.name}
        actions={
          <>
            <CrmButton href="/company/marketing/contacts">All contacts</CrmButton>
            {/*
              * The one place this record can be edited.
              *
              * The list this page is opened from used to carry its own Edit
              * button on every row — a second way to reach the same form the
              * row's own click already led to. Now the row just opens the
              * contact, and editing lives here, on the record itself.
              */}
            <CrmButton icon={Edit3} onClick={() => setIsEditOpen(true)}>
              Edit
            </CrmButton>
            {/*
              * Everything the module ever did to this person and everything
              * they did back, in order. Built and complete, but until now
              * unreachable — nothing anywhere linked to it.
              */}
            <CrmButton icon={Route} href={`/company/marketing/analytics/journey/${contact.id}`}>
              Journey
            </CrmButton>
            {contact.crm_lead_id && (
              <CrmButton
                tone="primary"
                icon={ExternalLink}
                href={`/company/crm/leads/${contact.crm_lead_id}`}
              >
                Open the lead
              </CrmButton>
            )}
          </>
        }
      />

      <ContactFormModal open={isEditOpen} onClose={() => setIsEditOpen(false)} contact={contact} tags={tags} />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <CrmCard step={0} className="lg:col-span-7">
          <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
              <CrmAvatar name={contact.name} size="md" />
              <div className="min-w-0">
                <h2 className="truncate text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  {contact.name}
                </h2>
                <p className="truncate text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                  {contact.phone}
                </p>
              </div>
            </div>
            <CrmStatusPill tone={reachable ? 'good' : 'bad'}>
              {reachable
                ? 'Reachable'
                : contact.marketing_status === 'opted_out'
                  ? 'Opted out'
                  : 'Suppressed'}
            </CrmStatusPill>
          </div>

          {/*
            * Why they cannot be messaged, when that is the case. A status pill
            * on its own tells you the what and leaves the reader to go hunting
            * for the why in another screen.
            */}
          {!reachable && (
            <div className="mb-5 flex items-start gap-3 rounded-[18px] bg-ovs-crm-danger-soft p-3.5">
              <ShieldAlert
                className="mt-0.5 h-4 w-4 shrink-0 text-ovs-crm-danger-text"
                aria-hidden="true"
              />
              <div className="min-w-0">
                <p className="text-[12px] font-medium text-ovs-crm-danger-text">
                  No campaign or automation will reach this contact
                </p>
                <p className="mt-0.5 text-[11.5px] leading-relaxed text-ovs-crm-danger-text/80">
                  {contact.suppression_reason
                    ? contact.suppression_reason
                    : contact.marketing_status === 'opted_out'
                      ? 'They asked not to be contacted.'
                      : 'No reason was recorded.'}
                </p>
              </div>
            </div>
          )}

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {facts.map((fact) => (
              <div key={fact.label} className="rounded-[18px] bg-ovs-crm-surface-inset p-3">
                <p className="mb-1 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                  <fact.icon className="h-3 w-3" aria-hidden="true" />
                  {fact.label}
                </p>
                <p className="truncate text-[13px] text-ovs-crm-text-primary">{fact.value}</p>
              </div>
            ))}
          </div>

          {contact.tags.length > 0 && (
            <div className="mt-4 border-t border-ovs-crm-border pt-4">
              <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Tags
              </p>
              <div className="flex flex-wrap gap-1.5">
                {contact.tags.map((tag) => (
                  <span
                    key={tag.id}
                    className="rounded-full bg-ovs-crm-surface-inset px-2.5 py-1 text-[11.5px] text-ovs-crm-text-secondary"
                  >
                    {tag.name}
                  </span>
                ))}
              </div>
            </div>
          )}
        </CrmCard>

        <div className="grid grid-cols-1 gap-4 lg:col-span-5">
          <CrmCard step={1} tone={contact.crm_lead_id ? 'ink' : 'white'}>
            <div className="mb-4 flex items-start justify-between gap-4">
              <div>
                <p
                  className={
                    contact.crm_lead_id
                      ? 'mb-1 text-[10px] font-bold uppercase tracking-[0.12em] text-white/45'
                      : 'mb-1 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted'
                  }
                >
                  In the CRM
                </p>
                <h2
                  className={
                    contact.crm_lead_id
                      ? 'text-[15px] font-semibold tracking-[-0.01em] text-white'
                      : 'text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary'
                  }
                >
                  {contact.crm_lead_id ? 'Already a lead' : 'Not yet a lead'}
                </h2>
              </div>
              <CrmCardIcon
                icon={ExternalLink}
                hue={contact.crm_lead_id ? '#2FA96B' : '#C9CDD6'}
                onDark={!!contact.crm_lead_id}
              />
            </div>

            <p
              className={
                contact.crm_lead_id
                  ? 'text-[13px] leading-relaxed text-white/60'
                  : 'text-[13px] leading-relaxed text-ovs-crm-text-muted'
              }
            >
              {contact.crm_lead_id
                ? 'Marketing has handed this person over — a rep owns them now.'
                : 'A lead is created automatically when this contact replies with interest, or by hand from their conversation.'}
            </p>

            {!contact.crm_lead_id && (
              <CrmButton
                icon={MessageSquare}
                className="mt-4"
                href="/company/marketing/conversations"
              >
                Open conversations
              </CrmButton>
            )}
          </CrmCard>

          <CrmSection step={2} title="History" description="What has happened to this record">
            {activities.length === 0 ? (
              <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">
                Nothing logged yet.
              </p>
            ) : (
              <div className="-mx-2.5">
                {activities.map((activity) => (
                  <div key={activity.id} className="rounded-[14px] px-2.5 py-2.5">
                    <p className="text-[13px] text-ovs-crm-text-primary">{activity.title}</p>
                    {activity.description && (
                      <p className="mt-0.5 text-[11.5px] leading-relaxed text-ovs-crm-text-secondary">
                        {activity.description}
                      </p>
                    )}
                    <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                      {[activity.user, formatDate(activity.timestamp)].filter(Boolean).join(' · ')}
                    </p>
                  </div>
                ))}
              </div>
            )}
          </CrmSection>
        </div>
      </div>
    </MarketingShell>
  );
}
