import React from 'react';
import { FileCheck, ShieldAlert, CheckCircle, Clock, Building2, User, Landmark } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Card } from '@/ovs/primitives/card';
import { Badge } from '@/ovs/primitives/badge';
import { ActivityFeed, ActivityFeedItem } from '@/ovs/patterns/activity-feed';

export type ClaimStatus =
  | 'draft'
  | 'submitted'
  | 'under-review'
  | 'approved'
  | 'partially-approved'
  | 'rejected'
  | 'paid';

export interface ClaimHistoryItem {
  status: ClaimStatus;
  date: string;
  note?: string;
  actorName?: string;
}

export interface ClaimData {
  id: string;
  patientName: string;
  insurerName: string;
  policyNumber?: string;
  claimAmount: string | number;
  approvedAmount?: string | number;
  submittedDate: string;
  status: ClaimStatus;
  statusHistory: ClaimHistoryItem[];
}

export interface ClaimTrackerProps {
  claim: ClaimData;
  className?: string;
}

const CLAIM_STATUS_MAP: Record<
  ClaimStatus,
  { label: string; variant: 'neutral' | 'success' | 'warning' | 'danger' | 'info'; stepIndex: number }
> = {
  draft: { label: 'Draft', variant: 'neutral', stepIndex: 0 },
  submitted: { label: 'Claim Submitted', variant: 'info', stepIndex: 1 },
  'under-review': { label: 'Under Review', variant: 'warning', stepIndex: 2 },
  'partially-approved': { label: 'Partially Approved', variant: 'warning', stepIndex: 3 },
  approved: { label: 'Approved', variant: 'success', stepIndex: 3 },
  rejected: { label: 'Claim Rejected', variant: 'danger', stepIndex: 3 },
  paid: { label: 'Settled & Paid', variant: 'success', stepIndex: 4 },
};

const STEPPER_STAGES = [
  { label: 'Draft', desc: 'Prepared' },
  { label: 'Submitted', desc: 'Sent to Insurer' },
  { label: 'Under Review', desc: 'TPA Verification' },
  { label: 'Decision', desc: 'Approved / Rejected' },
  { label: 'Paid', desc: 'Amount Disbursed' },
];

export function ClaimTracker({ claim, className }: ClaimTrackerProps) {
  const currentStatusConfig = CLAIM_STATUS_MAP[claim.status] || CLAIM_STATUS_MAP.draft;
  const currentStep = currentStatusConfig.stepIndex;

  // Convert statusHistory to ActivityFeed items
  const feedItems: ActivityFeedItem[] = claim.statusHistory.map((h, i) => {
    const conf = CLAIM_STATUS_MAP[h.status] || CLAIM_STATUS_MAP.draft;
    return {
      id: `history-${i}`,
      actor: {
        name: h.actorName || 'Insurance Desk',
        fallback: 'TPA',
      },
      action: `Status updated to ${conf.label}`,
      timestamp: h.date,
      details: h.note,
      icon: h.status === 'approved' || h.status === 'paid' ? CheckCircle : h.status === 'rejected' ? ShieldAlert : Clock,
      iconColor: conf.variant === 'success' ? 'bg-ovs-semantic-success-bg text-ovs-semantic-success-text' : conf.variant === 'danger' ? 'bg-ovs-semantic-danger-bg text-ovs-semantic-danger-text' : 'bg-ovs-semantic-warning-bg text-ovs-semantic-warning-text',
    };
  });

  return (
    <div className={cn('w-full bg-white border border-ovs-border-subtle rounded-2xl p-5 shadow-2xs space-y-6', className)}>
      {/* Top Claim Metadata Header */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-ovs-border-subtle pb-4">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-ovs-accent/15 text-ovs-accent flex items-center justify-center font-bold">
            <Landmark className="w-5 h-5" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="text-sm font-bold text-ovs-primary font-display">
                Claim #{claim.id}
              </h3>
              <Badge variant={currentStatusConfig.variant} className="text-[11px] py-0.5 px-2.5">
                {currentStatusConfig.label}
              </Badge>
            </div>
            <p className="text-xs text-ovs-secondary mt-0.5 flex items-center gap-2">
              <span className="flex items-center gap-1"><User className="w-3 h-3 text-ovs-tertiary" /> {claim.patientName}</span>
              <span>·</span>
              <span className="flex items-center gap-1"><Building2 className="w-3 h-3 text-ovs-tertiary" /> {claim.insurerName}</span>
            </p>
          </div>
        </div>

        {/* Claim Financial Summary */}
        <div className="text-right bg-ovs-background px-3.5 py-2 rounded-xl border border-ovs-border-subtle">
          <span className="text-[10px] text-ovs-secondary font-mono uppercase tracking-wider block">
            Submitted Claim Amount
          </span>
          <span className="text-sm font-extrabold text-ovs-primary font-mono">
            {typeof claim.claimAmount === 'number' ? `₹${claim.claimAmount.toLocaleString('en-IN')}` : claim.claimAmount}
          </span>
          {claim.approvedAmount && (
            <span className="text-[11px] text-ovs-semantic-success-text font-bold font-mono block">
              Approved: {typeof claim.approvedAmount === 'number' ? `₹${claim.approvedAmount.toLocaleString('en-IN')}` : claim.approvedAmount}
            </span>
          )}
        </div>
      </div>

      {/* Horizontal Status Progress Stepper */}
      <div className="p-4 bg-ovs-background/60 border border-ovs-border-subtle/80 rounded-2xl">
        <span className="text-[11px] font-mono uppercase font-bold text-ovs-secondary block mb-3">
          Claim Lifecycle Lifecycle Journey:
        </span>
        <div className="flex items-center justify-between relative">
          {/* Connector Line behind steps */}
          <div className="absolute top-4 left-4 right-4 h-0.5 bg-ovs-border-subtle -z-0" />
          <div
            className="absolute top-4 left-4 h-0.5 bg-ovs-accent transition-all duration-300 -z-0"
            style={{ width: `${(currentStep / (STEPPER_STAGES.length - 1)) * 100}%` }}
          />

          {STEPPER_STAGES.map((stage, idx) => {
            const isCompleted = idx < currentStep;
            const isCurrent = idx === currentStep;
            const isRejected = claim.status === 'rejected' && idx === 3;

            return (
              <div key={idx} className="relative z-10 flex flex-col items-center text-center group">
                <div
                  className={cn(
                    'w-8 h-8 rounded-full flex items-center justify-center font-mono font-bold text-xs border-2 transition-all',
                    isCompleted && 'bg-ovs-accent border-ovs-accent text-white',
                    isCurrent && !isRejected && 'bg-white border-ovs-accent text-ovs-accent ring-4 ring-ovs-accent/15',
                    isCurrent && isRejected && 'bg-ovs-semantic-danger-bg border-ovs-semantic-danger-text text-ovs-semantic-danger-text ring-4 ring-ovs-semantic-danger-bg',
                    !isCompleted && !isCurrent && 'bg-white border-ovs-border-subtle text-ovs-tertiary'
                  )}
                >
                  {isCompleted ? '✓' : idx + 1}
                </div>
                <span className={cn('text-[11px] font-bold mt-1.5', isCurrent ? 'text-ovs-primary' : 'text-ovs-secondary')}>
                  {stage.label}
                </span>
                <span className="text-[9px] text-ovs-tertiary hidden sm:block font-mono">
                  {stage.desc}
                </span>
              </div>
            );
          })}
        </div>
      </div>

      {/* Activity Timeline History */}
      <div className="space-y-2 pt-2">
        <h4 className="text-xs font-bold text-ovs-primary font-mono uppercase tracking-wider">
          Claim Audit Trail & Status History:
        </h4>
        <ActivityFeed items={feedItems} />
      </div>
    </div>
  );
}
