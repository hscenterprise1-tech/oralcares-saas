import React from 'react';
import { Activity, FileText, Receipt, Users } from 'lucide-react';
import { CrmCard, CrmStatusPill } from '@/pages/Company/design';
import type { TrialTelemetry } from '../trial';

/**
 * What this customer is actually doing with the product, right now.
 *
 * Four counters and a health verdict. The counters answer "are they using it",
 * the verdict answers "should I call them today", and a rep opening a trial
 * wants the second question answered first — so the health chip leads and the
 * numbers explain it.
 *
 * Absence is rendered as absence. A tenant that has not been measured shows a
 * sentence saying so, never a row of zeros: zeros tell a salesperson the
 * customer did nothing, which is a very different and far more damaging claim
 * than "we have not looked yet". The same rule governs the nightly snapshot
 * elsewhere on the page.
 */

const METRICS: { key: keyof TrialTelemetry; label: string; icon: typeof Users }[] = [
  { key: 'patients', label: 'Patients created', icon: Users },
  { key: 'prescriptions', label: 'Prescriptions', icon: FileText },
  { key: 'invoices', label: 'Invoices issued', icon: Receipt },
  { key: 'appointments', label: 'Appointments', icon: Activity },
];

function health(telemetry: TrialTelemetry): { tone: 'good' | 'warn' | 'neutral'; label: string } {
  if (telemetry.is_active_today) {
    return { tone: 'good', label: 'Active today' };
  }

  if (telemetry.is_inactive) {
    const hours = telemetry.hours_since_login;

    return {
      tone: 'warn',
      // "Never signed in" and "quiet for three days" need different calls, so
      // they are not collapsed into one phrase.
      label: hours === null ? 'Never signed in' : `Inactive ${Math.floor(hours / 24)}d`,
    };
  }

  return { tone: 'neutral', label: 'Seen recently' };
}

export function TrialTelemetryMeter({
  telemetry,
  onRefresh,
  refreshing = false,
  canRefresh = false,
}: {
  telemetry: TrialTelemetry | null;
  onRefresh?: () => void;
  refreshing?: boolean;
  canRefresh?: boolean;
}) {
  if (!telemetry) {
    return (
      <CrmCard className="p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-[13.5px] font-medium text-ovs-crm-text-primary">Live activity</h3>
            <p className="mt-1 text-[12.5px] text-ovs-crm-text-secondary">
              This tenant has not been measured yet.
            </p>
          </div>
          {canRefresh && onRefresh && (
            <button
              type="button"
              onClick={onRefresh}
              disabled={refreshing}
              className="rounded-full border border-ovs-crm-border px-3 py-1.5 text-[12px] text-ovs-crm-text-primary hover:bg-ovs-crm-surface-inset disabled:opacity-60"
            >
              {refreshing ? 'Measuring…' : 'Measure now'}
            </button>
          )}
        </div>
      </CrmCard>
    );
  }

  const verdict = health(telemetry);

  return (
    <CrmCard className="p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2.5">
          <h3 className="text-[13.5px] font-medium text-ovs-crm-text-primary">Live activity</h3>
          <CrmStatusPill tone={verdict.tone}>{verdict.label}</CrmStatusPill>
        </div>

        {canRefresh && onRefresh && (
          <button
            type="button"
            onClick={onRefresh}
            disabled={refreshing}
            className="rounded-full border border-ovs-crm-border px-3 py-1.5 text-[12px] text-ovs-crm-text-primary hover:bg-ovs-crm-surface-inset disabled:opacity-60"
          >
            {refreshing ? 'Measuring…' : 'Measure now'}
          </button>
        )}
      </div>

      <div className="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        {METRICS.map(({ key, label, icon: Icon }) => (
          <div
            key={key}
            className="rounded-[16px] border border-ovs-crm-border px-3.5 py-3"
          >
            <span className="flex items-center gap-1.5 text-[11.5px] text-ovs-crm-text-muted">
              <Icon className="h-3.5 w-3.5" aria-hidden="true" />
              {label}
            </span>
            <span className="mt-1.5 block text-[20px] font-semibold tabular-nums text-ovs-crm-text-primary">
              {Number(telemetry[key] ?? 0).toLocaleString()}
            </span>
          </div>
        ))}
      </div>

      <p className="mt-3 text-[11.5px] text-ovs-crm-text-muted">
        {telemetry.users_total} {telemetry.users_total === 1 ? 'user' : 'users'} ·{' '}
        {telemetry.logins_7d} {telemetry.logins_7d === 1 ? 'sign-in' : 'sign-ins'} in the last 7 days
        {telemetry.measured_at
          ? ` · measured ${new Date(telemetry.measured_at).toLocaleString()}`
          : ''}
      </p>
    </CrmCard>
  );
}
