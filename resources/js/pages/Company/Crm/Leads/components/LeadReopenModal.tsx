import React from 'react';
import { useForm } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { CrmModal, CrmButton, CrmFormField, CrmSelect, CrmTextarea } from '@/pages/Company/design';

interface LeadReopenModalProps {
  isOpen: boolean;
  onClose: () => void;
  leadId: number;
  companyName: string;
}

/*
 * The one way out of Lost — see `LeadController::reopen()`, which was fully
 * built (a justified re-entry into a working stage, never back into Won or
 * Lost itself) with nothing on this page ever calling it. Once a deal was
 * marked lost it stayed that way permanently, mistaken closures included.
 */
const REOPEN_STAGES = [
  { value: 'new', label: 'Inflow' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'demo_scheduled', label: 'Demo' },
  { value: 'trial_active', label: 'Trial' },
  { value: 'negotiation', label: 'Closing' },
];

export function LeadReopenModal({ isOpen, onClose, leadId, companyName }: LeadReopenModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({
    stage: 'contacted',
    justification: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('company.crm.leads.reopen', leadId), {
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
      title={`Reopen — ${companyName}`}
      description="Brings this deal back into the working pipeline. It cannot re-enter as Won or Lost directly."
    >
      <form onSubmit={handleSubmit} className="space-y-3.5">
        <CrmFormField label="Reopen into" required error={errors.stage}>
          <CrmSelect value={data.stage} onValueChange={(val) => setData('stage', val)} options={REOPEN_STAGES} />
        </CrmFormField>

        <CrmFormField
          label="Why this deal is being reopened"
          required
          error={errors.justification}
          hint="At least 10 characters — this becomes part of the deal's history."
        >
          <CrmTextarea
            rows={3}
            value={data.justification}
            onChange={(e) => setData('justification', e.target.value)}
            placeholder="Closed in error — the prospect never actually declined."
          />
        </CrmFormField>

        <div className="pt-3 border-t border-ovs-crm-border flex items-center justify-end gap-2">
          <CrmButton tone="ghost" size="sm" onClick={onClose}>
            Cancel
          </CrmButton>
          <CrmButton
            tone="primary"
            size="sm"
            type="submit"
            icon={RotateCcw}
            loading={processing}
            disabled={data.justification.trim().length < 10}
          >
            Reopen deal
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
