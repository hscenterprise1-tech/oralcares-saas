import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetContainer, WidgetContainerProps } from '../framework';
import { Check, X, ArrowUpRight } from 'lucide-react';

export interface ApprovalItem {
  id: string;
  title: string;
  requester: string;
  amountOrType?: string;
  timestamp?: string;
}

export interface ApprovalWidgetProps extends Omit<WidgetContainerProps, 'children'> {
  pendingCount?: number;
  approvals?: ApprovalItem[];
  onApprove?: (id: string) => void;
  onReject?: (id: string) => void;
  onViewDetails?: (id: string) => void;
}

export function ApprovalWidget({
  pendingCount,
  approvals = [],
  onApprove,
  onReject,
  onViewDetails,
  ...containerProps
}: ApprovalWidgetProps) {
  const headerWithBadge = {
    ...containerProps.header,
    title: containerProps.header?.title || 'Pending Approvals',
    badge: pendingCount !== undefined ? { text: `${pendingCount} Pending`, variant: 'warning' as const } : containerProps.header?.badge,
  };

  return (
    <WidgetContainer {...containerProps} header={headerWithBadge}>
      {approvals.length > 0 && (
        <div className="divide-y divide-[#F4F6F9]">
          {approvals.map((item) => (
            <div key={item.id} className="p-4 flex items-center justify-between gap-3 hover:bg-[#F8FAFC] transition-colors">
              <div className="min-w-0">
                <p className="text-[12.5px] font-bold text-[#0F172A] truncate leading-tight">
                  {item.title}
                </p>
                <div className="flex items-center gap-2 mt-1 text-[11px] text-[#64748B] font-medium">
                  <span>Requested by: {item.requester}</span>
                  {item.amountOrType && (
                    <>
                      <span>•</span>
                      <span className="font-semibold text-[#0F172A]">{item.amountOrType}</span>
                    </>
                  )}
                  {item.timestamp && (
                    <>
                      <span>•</span>
                      <span className="font-mono text-[10px] text-[#94A3B8]">{item.timestamp}</span>
                    </>
                  )}
                </div>
              </div>

              <div className="flex items-center gap-1.5 flex-shrink-0">
                {onApprove && (
                  <button
                    type="button"
                    onClick={() => onApprove(item.id)}
                    aria-label={`Approve ${item.title}`}
                    className="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 flex items-center justify-center transition-colors"
                  >
                    <Check className="w-3.5 h-3.5" strokeWidth={2.25} />
                  </button>
                )}

                {onReject && (
                  <button
                    type="button"
                    onClick={() => onReject(item.id)}
                    aria-label={`Reject ${item.title}`}
                    className="w-7 h-7 rounded-lg bg-rose-50 text-rose-700 hover:bg-rose-100 flex items-center justify-center transition-colors"
                  >
                    <X className="w-3.5 h-3.5" strokeWidth={2.25} />
                  </button>
                )}

                {onViewDetails && (
                  <button
                    type="button"
                    onClick={() => onViewDetails(item.id)}
                    aria-label="View approval details"
                    className="w-7 h-7 rounded-lg bg-[#F1F5F9] text-[#64748B] hover:text-[#0F172A] hover:bg-[#E2E8F0] flex items-center justify-center transition-colors"
                  >
                    <ArrowUpRight className="w-3.5 h-3.5" strokeWidth={1.75} />
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </WidgetContainer>
  );
}
