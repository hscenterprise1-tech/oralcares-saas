import React, { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
} from '@/pages/Company/design';
import type { Deal, DealPackage } from '../deal';

interface LeadStartTrialModalProps {
  isOpen: boolean;
  onClose: () => void;
  lead: Deal;
  packages: DealPackage[];
}

export function LeadStartTrialModal({
  isOpen,
  onClose,
  lead,
  packages = [],
}: LeadStartTrialModalProps) {
  const trialPackages = packages.filter((p) => p.is_trial_available);

  const { data, setData, post, processing, errors, reset } = useForm({
    company_plan_id: '',
    duration_days: 14,
    notes: '',
  });

  useEffect(() => {
    if (isOpen && trialPackages.length > 0 && !data.company_plan_id) {
      const first = trialPackages[0];
      setData({
        company_plan_id: String(first.id),
        duration_days: first.trial_duration_days || 14,
        notes: '',
      });
    }
  }, [isOpen]);

  const handlePackageChange = (id: string) => {
    const pkg = trialPackages.find((p) => String(p.id) === id);
    setData({
      ...data,
      company_plan_id: id,
      duration_days: pkg?.trial_duration_days || data.duration_days,
    });
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('company.crm.leads.create-trial', lead.id), {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  };

  const selectedPackage = trialPackages.find((p) => String(p.id) === data.company_plan_id);

  return (
    <CrmModal
      open={isOpen}
      onClose={onClose}
      title={`Start Clinical Trial Sandbox — ${lead.company_name}`}
      description="Provision a live testing environment for the clinic's doctors."
    >
      <form onSubmit={handleSubmit} className="space-y-3.5">
        {trialPackages.length === 0 ? (
          <div className="rounded-xl border border-dashed border-ovs-crm-border bg-ovs-crm-surface-inset/50 p-4 text-center text-[12px] text-ovs-crm-text-muted">
            No trial-eligible packages configured yet. Default sandbox settings will be applied.
          </div>
        ) : (
          <CrmFormField label="Trial Plan Package" required>
            <CrmSelect
              value={data.company_plan_id}
              onValueChange={handlePackageChange}
              options={trialPackages.map((p) => ({
                value: String(p.id),
                label: `${p.name} — up to ${p.max_doctors} doctors`,
              }))}
            />
            {selectedPackage && (
              <p className="text-[11px] text-ovs-crm-text-muted mt-1">
                Includes {selectedPackage.max_doctors} doctors · {selectedPackage.max_users} staff users · {selectedPackage.max_branches} branch(es)
              </p>
            )}
          </CrmFormField>
        )}

        <CrmFormField label="Trial Duration (Days)" required>
          <CrmInput
            type="number"
            min={1}
            max={90}
            required
            value={data.duration_days}
            onChange={(e) => setData('duration_days', Number(e.target.value))}
          />
        </CrmFormField>

        <CrmFormField label="Sandbox Configuration Notes">
          <CrmTextarea
            rows={2}
            value={data.notes}
            onChange={(e) => setData('notes', e.target.value)}
            placeholder="Special trial requests, pre-loaded demo templates, required specialties..."
          />
        </CrmFormField>

        <div className="pt-3 border-t border-ovs-crm-border flex items-center justify-end gap-2">
          <CrmButton tone="ghost" size="sm" onClick={onClose}>
            Cancel
          </CrmButton>
          <CrmButton tone="primary" size="sm" type="submit" loading={processing}>
            Provision Trial
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
