import React from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, Hand, Inbox } from 'lucide-react';
import { CrmCard, CrmButton, CrmStageBadge, inr } from '@/pages/Company/design';

/**
 * The work nobody has picked up.
 *
 * A personal queue is only useful once work has been assigned to you. Before a
 * team exists — or on any day the routing rules matched nothing — this page had
 * nothing to say and said it seven times: "Follow-ups 0 · Demos 0 · Trials 0 ·
 * Quotations 0 · Needs an action 0 · Win-backs 0 · My leads 0". Seven zeros
 * reads as a broken page, not an empty one.
 *
 * Unclaimed leads *are* the work in that situation, so the page answers with
 * them instead. Claiming one is a single click, because the gap between seeing
 * a lead nobody owns and owning it should not be a detour through a list.
 */

export interface UnclaimedLead {
  id: number;
  company_name: string;
  contact_name: string | null;
  stage: string;
  stage_key: string;
  city: string | null;
  expected_mrr: number;
  waiting_days: number;
}

export function MyWorkUnclaimed({
  unclaimed,
  currentUserId,
}: {
  unclaimed: { count: number; items: UnclaimedLead[] };
  currentUserId: number | null;
}) {
  const [claiming, setClaiming] = React.useState<number | null>(null);

  if (unclaimed.count === 0) return null;

  const claim = (leadId: number) => {
    if (! currentUserId) return;

    setClaiming(leadId);
    router.post(
      `/company/crm/leads/${leadId}/assign`,
      { assigned_user_id: currentUserId },
      { preserveScroll: true, onFinish: () => setClaiming(null) }
    );
  };

  return (
    <CrmCard as="section" aria-label="Unclaimed leads" className="p-5">
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <div className="flex items-center gap-2">
          <Inbox className="h-4 w-4 text-ovs-crm-text-muted" aria-hidden="true" />
          <h2 className="text-[15px] font-semibold text-ovs-crm-text-primary">Nobody is on these</h2>
        </div>
        <p className="text-[11.5px] text-ovs-crm-text-muted">
          <span className="tabular-nums text-ovs-crm-text-secondary">{unclaimed.count}</span> waiting
        </p>
      </div>

      <div className="-mx-2">
        {unclaimed.items.map((lead) => (
          <div
            key={lead.id}
            className="flex flex-wrap items-center gap-3 rounded-[16px] px-2 py-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
          >
            <div className="min-w-0 flex-1">
              <Link
                href={`/company/crm/leads/${lead.id}`}
                className="block truncate text-[13px] font-medium text-ovs-crm-text-primary underline-offset-2 hover:underline"
              >
                {lead.company_name}
              </Link>
              <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                {[lead.contact_name, lead.city].filter(Boolean).join(' · ') || 'No contact on file'}
                {/*
                  * How long it has sat there is the part that decides whether
                  * this matters. A lead unowned for an hour is fine; one
                  * unowned for a fortnight is the reason deals go cold.
                  */}
                {lead.waiting_days > 0 && ` · waiting ${lead.waiting_days}d`}
              </p>
            </div>

            <CrmStageBadge stage={lead.stage_key} label={lead.stage} />

            {lead.expected_mrr > 0 && (
              <span className="shrink-0 text-[12px] tabular-nums text-ovs-crm-text-secondary">
                {inr(lead.expected_mrr)}/mo
              </span>
            )}

            <CrmButton
              size="sm"
              icon={claiming === lead.id ? undefined : Hand}
              onClick={() => claim(lead.id)}
              disabled={claiming === lead.id}
            >
              {claiming === lead.id ? 'Taking' : 'Take it'}
            </CrmButton>
          </div>
        ))}
      </div>

      {unclaimed.count > unclaimed.items.length && (
        <Link
          href="/company/crm/deals?unassigned_only=1"
          className="mt-3 inline-flex items-center gap-1.5 text-[12.5px] text-ovs-crm-text-secondary underline-offset-2 hover:underline"
        >
          See all {unclaimed.count}
          <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
        </Link>
      )}
    </CrmCard>
  );
}
