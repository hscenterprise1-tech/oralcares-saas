import React from 'react';
import { Head } from '@inertiajs/react';
import { ArrowLeft, Boxes, CalendarClock, CreditCard, FileText, History, Landmark, Users } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmValue,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmHairlineBar,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { LifecycleShell } from './components/LifecycleShell';

/**
 * One subscription, end to end.
 *
 * Entitlements come from the shared projection, the access window from the
 * entitlement resolver, the history from the events the provisioning engine and
 * trial lifecycle service write. Nothing on this page recomputes any of it, and
 * nothing changes it.
 */

const HUE = { blue: '#2F6BE4', green: '#2FA96B', violet: '#7C6FE0', teal: '#3E93A3', orange: '#F59245' } as const;

interface Entitlements {
  purchased_seats: number;
  used_seats: number;
  seat_utilization_pct: number | null;
  purchased_doctors: number | null;
  purchased_branches: number;
  active_branches: number;
  branch_utilization_pct: number | null;
  has_subscription?: boolean;
}

interface Props {
  subscription: {
    id: number;
    organization_id: number | null;
    account: string | null;
    clinic: string | null;
    plan: string | null;
    billing_period: string | null;
    status: string;
    mrr: number | null;
    starts_at: string | null;
    ends_at: string | null;
    trial_ends_at: string | null;
    grace_ends_at: string | null;
    days_to_expiry: number | null;
    max_users: number | null;
    max_branches: number | null;
    max_doctors: number | null;
    entitlements: Entitlements | null;
    resolved: {
      has_subscription: boolean;
      grants_access: boolean;
      window: { state: string; days_remaining: number | null };
      plan: { id: number; key: string; name: string } | null;
      limits: Record<string, number | null>;
      modules: string[];
    };
    modules: string[];
    events: Array<{
      id: number;
      event: string;
      label: string;
      reason: string | null;
      plan: string | null;
      actor: string | null;
      at: string | null;
    }>;
    commercial: {
      invoices: Array<{ id: number; number: string; status: string; amount: number; due_date: string | null; paid_at: string | null }>;
      payments: Array<{ id: number; amount: number; method: string | null; paid_at: string | null; invoice_number: string | null }>;
      invoiced: number;
      collected: number;
    };
  };
  can: { viewRenewals: boolean; viewAccounts: boolean };
}

const WINDOW_TONE: Record<string, CrmStatusTone> = {
  paid: 'good',
  trial: 'info',
  grace: 'warn',
  lapsed: 'bad',
  none: 'neutral',
};

const inr = (value: number) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value ?? 0);

export default function SubscriptionDetail({ subscription: sub, can }: Props) {
  const ent = sub.entitlements;
  const state = sub.resolved?.window?.state ?? 'none';

  return (
    <LifecycleShell title={sub.account ?? 'Subscription'}>
      <Head title={`${sub.account ?? 'Subscription'} | Customer Success`} />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Subscription · SUB-{String(sub.id).padStart(4, '0')}
          </p>
          <h1 className="text-[32px] font-bold leading-[1.06] tracking-[-0.03em] text-ovs-crm-text-primary sm:text-[38px]">
            {sub.account ?? 'Unattributed account'}
          </h1>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <CrmStatusPill tone={WINDOW_TONE[state] ?? 'neutral'}>{state}</CrmStatusPill>
            <CrmStatusPill tone={sub.resolved?.grants_access ? 'good' : 'bad'}>
              {sub.resolved?.grants_access ? 'Access granted' : 'Access closed'}
            </CrmStatusPill>
            {sub.plan && <CrmStatusPill tone="neutral">{sub.plan}</CrmStatusPill>}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat
            icon={Landmark}
            value={sub.mrr === null ? 'Not recorded' : inr(sub.mrr)}
            caption="Recurring monthly"
          />
          <CrmPageStat icon={Users} value={`${ent?.used_seats ?? 0} / ${ent?.purchased_seats ?? 0}`} caption="Seats" />
          <CrmPageStat
            icon={CalendarClock}
            value={sub.days_to_expiry === null ? 'Open-ended' : `${sub.days_to_expiry}d`}
            caption="To expiry"
          />
        </div>
      </div>

      <div className="mb-4 flex flex-wrap gap-2">
        <CrmButton size="sm" icon={ArrowLeft} href="/company/customer-success/subscriptions">
          All subscriptions
        </CrmButton>
        {can.viewAccounts && sub.organization_id && (
          <CrmButton size="sm" href={`/company/customer-success/accounts/${sub.organization_id}`}>
            Account 360
          </CrmButton>
        )}
        {sub.organization_id && (
          <CrmButton size="sm" href={`/company/customer-success/accounts/${sub.organization_id}?tab=lifecycle`}>
            Lifecycle
          </CrmButton>
        )}
        {can.viewRenewals && (
          <CrmButton size="sm" href={`/company/customer-success/renewals?search=${encodeURIComponent(sub.account || '')}`}>
            Renewals
          </CrmButton>
        )}
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <CrmCard className="p-6">
          <div className="mb-4 flex items-start gap-3">
            <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              Term
            </h2>
            <CrmCardIcon icon={CalendarClock} hue={HUE.blue} />
          </div>

          <dl className="space-y-2.5">
            {[
              ['Started', sub.starts_at],
              ['Trial ends', sub.trial_ends_at],
              ['Grace ends', sub.grace_ends_at],
              ['Ends', sub.ends_at],
              ['Billing', sub.billing_period],
            ].map(([label, value]) => (
              <div key={label as string} className="flex items-baseline justify-between gap-3">
                <dt className="text-[12.5px] text-ovs-crm-text-secondary">{label as string}</dt>
                <dd className="text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                  {(value as string | null) ?? <span className="text-ovs-crm-text-muted">—</span>}
                </dd>
              </div>
            ))}
          </dl>
        </CrmCard>

        <CrmCard className="p-6">
          <div className="mb-4 flex items-start gap-3">
            <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              Entitlements
            </h2>
            <CrmCardIcon icon={Users} hue={HUE.teal} />
          </div>

          {ent ? (
            <ul className="space-y-3">
              <li>
                <div className="mb-1 flex items-baseline justify-between gap-3">
                  <span className="text-[12.5px] text-ovs-crm-text-secondary">Seats</span>
                  <span className="text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                    {ent.used_seats} / {ent.purchased_seats}
                  </span>
                </div>
                <CrmHairlineBar
                  value={ent.used_seats}
                  max={Math.max(ent.purchased_seats, ent.used_seats, 1)}
                  color={ent.used_seats > ent.purchased_seats ? '#EF5B39' : HUE.teal}
                />
              </li>
              <li>
                <div className="mb-1 flex items-baseline justify-between gap-3">
                  <span className="text-[12.5px] text-ovs-crm-text-secondary">Branches</span>
                  <span className="text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                    {ent.active_branches} / {ent.purchased_branches}
                  </span>
                </div>
                <CrmHairlineBar
                  value={ent.active_branches}
                  max={Math.max(ent.purchased_branches, ent.active_branches, 1)}
                  color={HUE.blue}
                />
              </li>
              <li className="flex items-baseline justify-between gap-3 border-t border-ovs-crm-border pt-2.5">
                <span className="text-[12.5px] text-ovs-crm-text-secondary">Doctors purchased</span>
                <span className="text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                  {ent.purchased_doctors ?? <span className="text-ovs-crm-text-muted">not capped</span>}
                </span>
              </li>
            </ul>
          ) : (
            <p className="text-[12.5px] text-ovs-crm-text-muted">No account is linked to this subscription.</p>
          )}
        </CrmCard>

        <CrmCard className="p-6">
          <div className="mb-4 flex items-start gap-3">
            <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              Modules
            </h2>
            <CrmCardIcon icon={Boxes} hue={HUE.violet} />
          </div>

          {sub.modules.length === 0 && (sub.resolved?.modules ?? []).length === 0 ? (
            <p className="text-[12.5px] text-ovs-crm-text-muted">No modules enabled on this account's licence.</p>
          ) : (
            <>
              <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                Enabled on the licence
              </p>
              <div className="mb-4 flex flex-wrap gap-1.5">
                {sub.modules.length === 0 ? (
                  <span className="text-[12px] text-ovs-crm-text-muted">None</span>
                ) : (
                  sub.modules.map((module) => (
                    <CrmStatusPill key={module} tone="good">
                      {module}
                    </CrmStatusPill>
                  ))
                )}
              </div>

              <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                Granted by the plan
              </p>
              <div className="flex flex-wrap gap-1.5">
                {(sub.resolved?.modules ?? []).map((module) => (
                  <CrmStatusPill key={module} tone="neutral">
                    {module}
                  </CrmStatusPill>
                ))}
              </div>
            </>
          )}
        </CrmCard>
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <CrmCard className="p-6">
          <div className="mb-4 flex items-start gap-3">
            <div className="min-w-0 flex-1">
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">History</h2>
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                Written by provisioning and the trial lifecycle — read here, never edited
              </p>
            </div>
            <CrmCardIcon icon={History} hue={HUE.green} />
          </div>

          {sub.events.length === 0 ? (
            <p className="py-6 text-center text-[12.5px] text-ovs-crm-text-muted">
              Nothing recorded against this subscription yet.
            </p>
          ) : (
            <ol className="space-y-3">
              {sub.events.map((event) => (
                <li key={event.id} className="flex gap-3">
                  <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-ovs-crm-ink" aria-hidden />
                  <div className="min-w-0 flex-1">
                    <p className="text-[12.5px] font-medium text-ovs-crm-text-primary">{event.label}</p>
                    {event.reason && (
                      <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">{event.reason}</p>
                    )}
                    <p className="mt-0.5 text-[11px] text-ovs-crm-text-muted">
                      {event.at}
                      {event.plan && <> · {event.plan}</>}
                      {event.actor && <> · {event.actor}</>}
                    </p>
                  </div>
                </li>
              ))}
            </ol>
          )}
        </CrmCard>

        <CrmCard className="p-6">
          <div className="mb-4 flex items-start gap-3">
            <div className="min-w-0 flex-1">
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                Invoices and payments
              </h2>
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                {inr(sub.commercial.invoiced)} invoiced · {inr(sub.commercial.collected)} collected
              </p>
            </div>
            <CrmCardIcon icon={FileText} hue={HUE.orange} />
          </div>

          {sub.commercial.invoices.length === 0 && sub.commercial.payments.length === 0 ? (
            <p className="py-6 text-center text-[12.5px] text-ovs-crm-text-muted">
              Nothing has been invoiced or collected against this account.
            </p>
          ) : (
            <div className="space-y-4">
              {sub.commercial.invoices.length > 0 && (
                <div>
                  <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                    Invoices
                  </p>
                  <ul className="space-y-1.5">
                    {sub.commercial.invoices.slice(0, 6).map((invoice) => (
                      <li key={invoice.id} className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="min-w-[120px] flex-1 text-[12px] tabular-nums text-ovs-crm-text-primary">
                          {invoice.number}
                        </span>
                        <CrmStatusPill tone={invoice.status === 'paid' ? 'good' : 'warn'}>
                          {invoice.status}
                        </CrmStatusPill>
                        <span className="text-[12px] tabular-nums text-ovs-crm-text-secondary">
                          {inr(invoice.amount)}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {sub.commercial.payments.length > 0 && (
                <div className="border-t border-ovs-crm-border pt-3">
                  <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                    Payments
                  </p>
                  <ul className="space-y-1.5">
                    {sub.commercial.payments.slice(0, 6).map((payment) => (
                      <li key={payment.id} className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span className="min-w-[120px] flex-1 text-[12px] text-ovs-crm-text-muted">
                          {/* Money raised against a commercial document rather than an invoice. */}
                          {payment.invoice_number ?? 'not against an invoice'}
                        </span>
                        <span className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">{payment.paid_at}</span>
                        <span className="text-[12px] font-medium tabular-nums text-ovs-crm-text-primary">
                          {inr(payment.amount)}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          )}
        </CrmCard>
      </div>
    </LifecycleShell>
  );
}
