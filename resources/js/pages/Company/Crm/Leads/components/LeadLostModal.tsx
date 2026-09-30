import React from 'react';
import { useForm } from '@inertiajs/react';
import { CircleSlash, AlertTriangle } from 'lucide-react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmSelect,
  CrmTextarea,
  CrmInput,
} from '@/pages/Company/design';

interface LeadLostModalProps {
  isOpen: boolean;
  onClose: () => void;
  leadId: number;
  companyName: string;
  lostReasons: { value: string; label: string }[];
}

export function LeadLostModal({
  isOpen,
  onClose,
  leadId,
  companyName,
  lostReasons = [],
}: LeadLostModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({
    stage: 'lost',
    reason: lostReasons[0]?.value || 'price_too_high',
    notes: '',
    competitor: '',
    winback_scheduled_at: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('company.crm.leads.transition', leadId), {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  };

  return (
    <CrmModal
      open={isOpen}
      onClose={onClose}
      title={`Close Deal as Lost — ${companyName}`}
      description="Record loss intelligence, competitor choices, and future win-back review dates."
    >
      <form onSubmit={handleSubmit} className="space-y-3.5">
        <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-[12px] text-rose-800 flex items-start gap-2">
          <AlertTriangle className="h-4 w-4 text-rose-600 shrink-0 mt-0.5" />
          <span>
            Closing this opportunity as lost will remove it from the active pipeline directory and catalog it in the Lost deals radar for future win-back analysis.
          </span>
        </div>

        <CrmFormField label="Primary Loss Reason" required>
          <CrmSelect
            value={data.reason}
            onValueChange={(val) => setData('reason', val)}
            options={
              lostReasons.length > 0
                ? lostReasons
                : [
                    { value: 'price_too_high', label: 'Price / Budgetary Constraint' },
                    { value: 'competitor_chosen', label: 'Competitor Software Chosen' },
                    { value: 'feature_gap', label: 'Missing Required Clinical Feature' },
                    { value: 'no_decision', label: 'Project Postponed / No Decision' },
                    { value: 'unresponsive', label: 'Prospect Unresponsive' },
                    { value: 'bad_timing', label: 'Bad Timing / Not Ready' },
                  ]
            }
          />
        </CrmFormField>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <CrmFormField label="Competitor (If Chosen)">
            <CrmInput
              value={data.competitor}
              onChange={(e) => setData('competitor', e.target.value)}
              placeholder="e.g. Practo, Dentrix"
            />
          </CrmFormField>

          <CrmFormField label="Scheduled Win-Back Date">
            <CrmInput
              type="date"
              value={data.winback_scheduled_at}
              onChange={(e) => setData('winback_scheduled_at', e.target.value)}
            />
          </CrmFormField>
        </div>

        <CrmFormField label="Detailed Loss Notes &amp; Context">
          <CrmTextarea
            rows={2}
            value={data.notes}
            onChange={(e) => setData('notes', e.target.value)}
            placeholder="Why did the prospect decline? What would make them reconsider in the future?"
          />
        </CrmFormField>

        <div className="pt-3 border-t border-ovs-crm-border flex items-center justify-end gap-2">
          <CrmButton tone="ghost" size="sm" onClick={onClose}>
            Cancel
          </CrmButton>
          <CrmButton tone="danger" size="sm" type="submit" loading={processing}>
            Confirm Closed-Lost
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
