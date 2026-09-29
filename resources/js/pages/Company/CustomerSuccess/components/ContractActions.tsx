import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowUpRight, CalendarPlus, Ban, Users } from 'lucide-react';
import {
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmModal,
  CrmSelect,
  CrmTextarea,
} from '@/pages/Company/design';

export interface ContractPlan {
  id: number;
  name: string;
}

export interface ContractSummary {
  id: number;
  account?: string | null;
  planId?: number | null;
  planName?: string | null;
  mrr?: number | null;
  maxUsers?: number | null;
  maxBranches?: number | null;
  maxDoctors?: number | null;
  endsAt?: string | null;
}

export type ContractAction = 'renew' | 'change-plan' | 'entitlements' | 'cancel';

/**
 * The four things anybody does to a customer's contract, in one place.
 *
 * These used to be a single button that opened a CRM *deal* — the pipeline
 * built for winning a stranger. Once a deal is won the account belongs to
 * Customer Success: another year is a renewal, more seats is an entitlement
 * change, a bigger plan is an upgrade. None of them is a sale, and none of them
 * needs a second module's permission to happen.
 *
 * One component rather than a copy per page, because the Renewals queue, the
 * contract book, the capacity worklist and Account 360 all offer the same four
 * verbs — and four copies of a money form is four places for them to drift.
 *
 * Money is never prefilled from a plan. The field starts at what this customer
 * currently pays, and an empty field leaves it untouched: adopting a plan's
 * list price silently would rewrite what was actually agreed.
 */
export function ContractActionModal({
  action,
  contract,
  plans = [],
  onClose,
}: {
  action: ContractAction | null;
  contract: ContractSummary | null;
  plans?: ContractPlan[];
  onClose: () => void;
}) {
  const [months, setMonths] = useState('12');
  const [planId, setPlanId] = useState('');
  const [mrr, setMrr] = useState('');
  const [maxUsers, setMaxUsers] = useState('');
  const [maxBranches, setMaxBranches] = useState('');
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const open = action !== null && contract !== null;

  /* Fresh fields each time the modal opens, seeded from the contract itself. */
  React.useEffect(() => {
    if (! open || ! contract) return;
    setMonths('12');
    setPlanId(contract.planId ? String(contract.planId) : '');
    setMrr(contract.mrr !== null && contract.mrr !== undefined ? String(contract.mrr) : '');
    setMaxUsers(contract.maxUsers !== null && contract.maxUsers !== undefined ? String(contract.maxUsers) : '');
    setMaxBranches(contract.maxBranches !== null && contract.maxBranches !== undefined ? String(contract.maxBranches) : '');
    setReason('');
  }, [open, contract?.id, action]);

  if (! open || ! contract) return null;

  const submit = () => {
    const routes: Record<ContractAction, string> = {
      renew: 'company.customer-success.subscriptions.renew',
      'change-plan': 'company.customer-success.subscriptions.change-plan',
      entitlements: 'company.customer-success.subscriptions.entitlements',
      cancel: 'company.customer-success.subscriptions.cancel',
    };

    const payloads: Record<ContractAction, Record<string, unknown>> = {
      renew: {
        months: Number(months) || 12,
        ...(planId && Number(planId) !== contract.planId ? { company_plan_id: Number(planId) } : {}),
        ...(mrr !== '' ? { mrr: Number(mrr) } : {}),
      },
      'change-plan': {
        company_plan_id: Number(planId),
        ...(mrr !== '' ? { mrr: Number(mrr) } : {}),
        ...(maxUsers !== '' ? { max_users: Number(maxUsers) } : {}),
        ...(maxBranches !== '' ? { max_branches: Number(maxBranches) } : {}),
      },
      entitlements: {
        ...(maxUsers !== '' ? { max_users: Number(maxUsers) } : {}),
        ...(maxBranches !== '' ? { max_branches: Number(maxBranches) } : {}),
        ...(mrr !== '' ? { mrr: Number(mrr) } : {}),
      },
      cancel: {},
    };

    setSubmitting(true);
    router.post(
      route(routes[action], contract.id),
      { ...payloads[action], ...(reason ? { reason } : {}) },
      {
        preserveScroll: true,
        onFinish: () => setSubmitting(false),
        onSuccess: onClose,
      },
    );
  };

  const copy: Record<ContractAction, { title: string; description: string; cta: string; icon: typeof CalendarPlus }> = {
    renew: {
      title: `Renew — ${contract.account ?? 'contract'}`,
      description: contract.endsAt
        ? `The new term starts where the current one ends (${contract.endsAt}), so a renewal processed late does not give days away.`
        : 'This contract has no end date, so the new term starts today.',
      cta: 'Renew',
      icon: CalendarPlus,
    },
    'change-plan': {
      title: `Change plan — ${contract.account ?? 'contract'}`,
      description: 'Moving plans does not change what they pay unless you say so. Leave the amount alone to keep it.',
      cta: 'Change plan',
      icon: ArrowUpRight,
    },
    entitlements: {
      title: `Adjust entitlements — ${contract.account ?? 'contract'}`,
      description: 'More seats or branches on the plan they already hold.',
      cta: 'Apply',
      icon: Users,
    },
    cancel: {
      title: `Cancel — ${contract.account ?? 'contract'}`,
      description: 'They keep their access until the date they have paid through. The reason is recorded against the contract.',
      cta: 'Cancel contract',
      icon: Ban,
    },
  };

  const { title, description, cta } = copy[action];

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title={title}
      description={description}
      footer={
        <div className="flex justify-end gap-2">
          <CrmButton tone="secondary" size="sm" onClick={onClose}>
            Close
          </CrmButton>
          <CrmButton
            tone={action === 'cancel' ? 'danger' : 'primary'}
            size="sm"
            onClick={submit}
            disabled={submitting || (action === 'cancel' && reason.trim().length < 3)}
          >
            {submitting ? 'Working…' : cta}
          </CrmButton>
        </div>
      }
    >
      <div className="space-y-4">
        {action === 'renew' && (
          <CrmFormField label="Term" hint="How many months to add.">
            <CrmSelect value={months} onChange={(e) => setMonths(e.target.value)}>
              <option value="1">1 month</option>
              <option value="3">3 months</option>
              <option value="6">6 months</option>
              <option value="12">12 months</option>
              <option value="24">24 months</option>
              <option value="36">36 months</option>
            </CrmSelect>
          </CrmFormField>
        )}

        {(action === 'renew' || action === 'change-plan') && plans.length > 0 && (
          <CrmFormField
            label="Plan"
            hint={action === 'renew' ? 'Leave as-is to renew onto the same plan.' : undefined}
          >
            <CrmSelect value={planId} onChange={(e) => setPlanId(e.target.value)}>
              {action === 'renew' && <option value="">Same plan ({contract.planName ?? 'current'})</option>}
              {plans.map((plan) => (
                <option key={plan.id} value={String(plan.id)}>
                  {plan.name}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        )}

        {(action === 'change-plan' || action === 'entitlements') && (
          <div className="grid grid-cols-2 gap-3">
            <CrmFormField label="Seats">
              <CrmInput
                type="number"
                min={0}
                value={maxUsers}
                onChange={(e) => setMaxUsers(e.target.value)}
                placeholder="Unchanged"
              />
            </CrmFormField>
            <CrmFormField label="Branches">
              <CrmInput
                type="number"
                min={0}
                value={maxBranches}
                onChange={(e) => setMaxBranches(e.target.value)}
                placeholder="Unchanged"
              />
            </CrmFormField>
          </div>
        )}

        {action !== 'cancel' && (
          <CrmFormField
            label="Monthly value (₹)"
            hint="What they will actually pay. Left empty, the current figure stands — a plan's list price is never adopted on its own."
          >
            <CrmInput
              type="number"
              min={0}
              step="0.01"
              value={mrr}
              onChange={(e) => setMrr(e.target.value)}
              placeholder="Unchanged"
            />
          </CrmFormField>
        )}

        <CrmFormField
          label={action === 'cancel' ? 'Why are they leaving?' : 'Note'}
          hint={action === 'cancel' ? 'Required. This is what the next person reads.' : 'Optional. Kept on the contract history.'}
        >
          <CrmTextarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
            placeholder={action === 'cancel' ? 'Practice sold, merging into another group…' : 'Agreed at the quarterly review…'}
          />
        </CrmFormField>
      </div>
    </CrmModal>
  );
}
