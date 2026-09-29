import React from 'react';
import { useForm } from '@inertiajs/react';
import { Phone, Calendar } from 'lucide-react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
} from '@/pages/Company/design';

interface LeadCallModalProps {
  isOpen: boolean;
  onClose: () => void;
  leadId: number;
  companyName: string;
}

export function LeadCallModal({
  isOpen,
  onClose,
  leadId,
  companyName,
}: LeadCallModalProps) {
  const { data, setData, post, processing, errors, reset } = useForm({
    disposition: 'follow_up_required',
    duration_seconds: 180,
    notes: '',
    next_followup_at: '',
    followup_notes: '',
  });

  /*
   * A call that ends "they want a follow-up" or "line was busy" and gets
   * logged with no date is the exact gap this cadence closes — the call
   * happened and nothing was promised. Both dispositions require a date
   * before the form will submit; the other two describe outcomes with
   * nothing left to schedule.
   */
  const followupRequired = data.disposition === 'follow_up_required' || data.disposition === 'busy';
  const canSubmit = !followupRequired || data.next_followup_at.trim() !== '';

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!canSubmit) return;

    post(route('company.crm.leads.call-disposition', leadId), {
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
      title={`Log Phone Call — ${companyName}`}
      description="Record call outcome, duration, discussion points, and follow-up commitments."
    >
      <form onSubmit={handleSubmit} className="space-y-3.5">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <CrmFormField label="Call Disposition Outcome" required error={errors.disposition}>
            <CrmSelect
              value={data.disposition}
              onValueChange={(val) => setData('disposition', val)}
              options={[
                { value: 'follow_up_required', label: 'Connected — Follow-up Requested' },
                { value: 'demo_requested', label: 'Connected — Demo Agreed' },
                { value: 'busy', label: 'Busy / No Answer' },
                { value: 'not_interested', label: 'Not Interested' },
              ]}
            />
          </CrmFormField>

          <CrmFormField label="Call Duration (Seconds)">
            <CrmInput
              type="number"
              min={0}
              value={data.duration_seconds}
              onChange={(e) => setData('duration_seconds', Number(e.target.value))}
            />
          </CrmFormField>
        </div>

        <CrmFormField label="Discussion Summary &amp; Notes">
          <CrmTextarea
            rows={3}
            value={data.notes}
            onChange={(e) => setData('notes', e.target.value)}
            placeholder="What was discussed? Any specific pricing, feature or timing requirements?"
          />
        </CrmFormField>

        <div className="pt-2 border-t border-ovs-crm-border space-y-2">
          <CrmFormField
            label="Schedule Next Follow-Up (Creates Pending Task)"
            required={followupRequired}
            error={errors.next_followup_at}
            hint={followupRequired ? 'Mandatory for this outcome — the call is not logged without a promised date.' : undefined}
          >
            <CrmInput
              type="datetime-local"
              value={data.next_followup_at}
              onChange={(e) => setData('next_followup_at', e.target.value)}
            />
          </CrmFormField>
        </div>

        <div className="pt-3 border-t border-ovs-crm-border flex items-center justify-end gap-2">
          <CrmButton tone="ghost" size="sm" onClick={onClose}>
            Cancel
          </CrmButton>
          <CrmButton tone="primary" size="sm" type="submit" loading={processing} disabled={!canSubmit}>
            Log Call &amp; Save
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
