import React from 'react';
import { ExternalLink } from 'lucide-react';
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
 * One person's journey.
 *
 * Everything the module did to this contact and everything they did back, in
 * order. The page is deliberately a single column: a journey is a sequence, and
 * splitting it into panels asks the reader to reassemble the order themselves.
 */

interface TimelineEvent {
  type: string;
  title: string;
  description: string;
  timestamp: string;
  icon: string;
}

interface ContactModel {
  id: number;
  name: string;
  phone: string;
  organization: string | null;
  city: string | null;
  marketing_status: string;
  tags?: { id: number; name: string }[];
}

interface JourneyProps {
  contact: ContactModel;
  timeline: TimelineEvent[];
}

const STATUS_TONE: Record<string, CrmStatusTone> = {
  active: 'good',
  subscribed: 'good',
  opted_out: 'bad',
  suppressed: 'warn',
};

/*
 * Which side an event came from. An outbound message and an inbound reply are
 * not the same kind of fact, and a timeline that paints them identically hides
 * the only rhythm worth reading — who spoke last.
 *
 * These are the exact event `type` strings MarketingJourneyService::buildContactTimeline()
 * emits for something the customer did (app/Services/Marketing/Analytics/MarketingJourneyService.php) —
 * not the generic names ('reply', 'inbound') the set previously held, which
 * never matched anything the backend actually produces.
 */
const INBOUND_TYPES = new Set(['whatsapp_msg_inbound', 'campaign_replied']);

const humanize = (value: string) => value.replace(/[_.]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const fullDate = (iso: string) => {
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

export default function CustomerJourneyView({ contact, timeline }: JourneyProps) {
  const reachable = contact.marketing_status === 'active' || contact.marketing_status === 'subscribed';

  return (
    <MarketingShell title={contact.name}>
      <CrmPageHead
        eyebrow="Marketing"
        title={contact.name}
        actions={
          <>
            <CrmButton href="/company/marketing/analytics">Analytics</CrmButton>
            <CrmButton
              tone="primary"
              icon={ExternalLink}
              href={`/company/marketing/contacts/${contact.id}`}
            >
              Open the contact
            </CrmButton>
          </>
        }
      />

      <CrmCard step={0} className="mb-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <CrmAvatar name={contact.name} size="md" />
            <div className="min-w-0">
              <h2 className="truncate text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                {contact.name}
              </h2>
              <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                {[contact.phone, contact.organization, contact.city].filter(Boolean).join(' · ')}
              </p>
            </div>
          </div>
          <CrmStatusPill tone={STATUS_TONE[contact.marketing_status] ?? 'neutral'}>
            {reachable ? 'Reachable' : humanize(contact.marketing_status)}
          </CrmStatusPill>
        </div>
      </CrmCard>

      <CrmSection
        step={1}
        title="Everything that happened"
        description={
          timeline.length === 0
            ? 'Nothing recorded for this contact yet'
            : `${timeline.length} event${timeline.length === 1 ? '' : 's'}, newest first`
        }
      >
        {timeline.length === 0 ? (
          <p className="py-16 text-center text-[13px] text-ovs-crm-text-muted">
            This contact has not been messaged and has not written in.
          </p>
        ) : (
          <div className="space-y-3">
            {timeline.map((event, index) => {
              const inbound = INBOUND_TYPES.has(event.type);

              return (
                <div key={index} className="flex gap-3">
                  {/*
                    * A hairline spine with a dot per event, so the sequence
                    * reads as one thread rather than as a stack of cards.
                    */}
                  <div className="flex shrink-0 flex-col items-center">
                    <span
                      className={
                        inbound
                          ? 'mt-1.5 h-2 w-2 rounded-full bg-ovs-crm-success'
                          : 'mt-1.5 h-2 w-2 rounded-full bg-ovs-crm-border-strong'
                      }
                      aria-hidden="true"
                    />
                    {index < timeline.length - 1 && (
                      <span className="mt-1 w-px flex-1 bg-ovs-crm-border" aria-hidden="true" />
                    )}
                  </div>

                  <div className="min-w-0 flex-1 pb-3">
                    <div className="flex flex-wrap items-baseline gap-2">
                      <p className="text-[13px] font-medium text-ovs-crm-text-primary">
                        {event.title}
                      </p>
                      <span className="text-[11.5px] text-ovs-crm-text-muted">
                        {inbound ? 'they wrote' : 'we sent'}
                      </span>
                    </div>

                    {event.description && (
                      <p className="mt-0.5 whitespace-pre-wrap text-[13px] leading-relaxed text-ovs-crm-text-secondary">
                        {event.description}
                      </p>
                    )}

                    <p className="mt-1 text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                      {fullDate(event.timestamp)}
                    </p>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </CrmSection>
    </MarketingShell>
  );
}
