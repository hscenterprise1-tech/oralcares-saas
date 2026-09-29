import React, { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { CrmModal } from '@/pages/Company/design/CrmOverlay';
import { CrmButton } from '@/pages/Company/design/CrmControls';
import { Input } from '@/pages/Company/design/CrmFormPrimitives';
import { Switch } from '@/pages/Company/design/CrmFormPrimitives';
import { CompanyFeatureOption, Package } from '../types';

interface PackageFormModalProps {
  isOpen: boolean;
  onClose: () => void;
  pkg?: Package | null;
  features: CompanyFeatureOption[];
}

export const PackageFormModal: React.FC<PackageFormModalProps> = ({ isOpen, onClose, pkg, features }) => {
  const isEditing = !!pkg;

  const { data, setData, post, put, processing, errors, reset } = useForm({
    name: '',
    description: '',
    price: 0,
    billing_period: 'monthly' as 'monthly' | 'yearly',
    max_users: 5,
    additional_user_price: null as number | null,
    max_doctors: 2,
    max_staff: null as number | null,
    max_branches: 1,
    storage_limit_gb: 10,
    is_active: true,
    is_trial_available: true,
    trial_duration_days: 15,
    module_keys: [] as string[],
  });

  useEffect(() => {
    if (!isOpen) return;
    if (pkg) {
      setData({
        name: pkg.name,
        description: pkg.description ?? '',
        price: pkg.price,
        billing_period: pkg.billing_period,
        max_users: pkg.max_users,
        additional_user_price: pkg.additional_user_price,
        max_doctors: pkg.max_doctors,
        max_staff: pkg.max_staff,
        max_branches: pkg.max_branches,
        storage_limit_gb: pkg.storage_limit_gb,
        is_active: pkg.is_active,
        is_trial_available: pkg.is_trial_available,
        trial_duration_days: pkg.trial_duration_days,
        module_keys: pkg.module_keys,
      });
    } else {
      reset();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, pkg]);

  const toggleModule = (key: string) => {
    setData('module_keys', data.module_keys.includes(key)
      ? data.module_keys.filter((k) => k !== key)
      : [...data.module_keys, key]);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
    if (isEditing && pkg) {
      put(route('company.admin.packages.update', pkg.id), options);
    } else {
      post(route('company.admin.packages.store'), options);
    }
  };

  return (
    <CrmModal
      open={isOpen}
      onClose={onClose}
      title={isEditing ? 'Edit package' : 'Create package'}
      description="The plan catalog trials and quotations are built against."
      footer={
        <>
          <CrmButton tone="ghost" onClick={onClose}>Cancel</CrmButton>
          <CrmButton
            tone="primary"
            type="submit"
            form="package-form"
            disabled={processing || !data.name.trim()}
          >
            {processing && <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />}
            {isEditing ? 'Save changes' : 'Create package'}
          </CrmButton>
        </>
      }
    >
        <form id="package-form" onSubmit={handleSubmit} className="space-y-5">
          <div className="space-y-1.5">
            <label className="block text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">
              Package Name <span className="text-ovs-crm-danger">*</span>
            </label>
            <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Growth" />
            {errors.name && <p className="text-2xs text-ovs-crm-danger mt-1">{errors.name}</p>}
          </div>

          <div className="space-y-1.5">
            <label className="block text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">Description</label>
            <Input value={data.description} onChange={(e) => setData('description', e.target.value)} placeholder="Optional" />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <label className="block text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">Price (₹)</label>
              <Input type="number" min={0} value={data.price} onChange={(e) => setData('price', parseFloat(e.target.value) || 0)} />
              {errors.price && <p className="text-2xs text-ovs-crm-danger mt-1">{errors.price}</p>}
            </div>
            <div className="space-y-1.5">
              <label className="block text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">Billing Period</label>
              <select
                value={data.billing_period}
                onChange={(e) => setData('billing_period', e.target.value as 'monthly' | 'yearly')}
                className="w-full h-9 px-2.5 text-[11.5px] rounded-[16px] bg-ovs-crm-surface-inset text-ovs-crm-text-primary focus:ring-1 focus:ring-ovs-crm-text-primary focus:outline-none cursor-pointer"
              >
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
              </select>
            </div>
          </div>

          <div className="p-3 rounded-[16px] bg-ovs-crm-surface-inset border border-ovs-crm-border space-y-3">
            <span className="text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">Capacity Limits</span>
            <div className="grid grid-cols-3 gap-2.5">
              <div className="space-y-1">
                <label className="block text-2xs text-ovs-crm-text-muted">Max Doctors</label>
                <Input type="number" min={1} value={data.max_doctors} onChange={(e) => setData('max_doctors', parseInt(e.target.value, 10) || 1)} />
              </div>
              <div className="space-y-1">
                <label className="block text-2xs text-ovs-crm-text-muted">Max Staff</label>
                <Input
                  type="number"
                  min={0}
                  value={data.max_staff ?? ''}
                  placeholder="No sub-cap"
                  onChange={(e) => setData('max_staff', e.target.value === '' ? null : parseInt(e.target.value, 10) || 0)}
                />
              </div>
              <div className="space-y-1">
                <label className="block text-2xs text-ovs-crm-text-muted">Max Branches</label>
                <Input type="number" min={1} value={data.max_branches} onChange={(e) => setData('max_branches', parseInt(e.target.value, 10) || 1)} />
              </div>
            </div>
            <p className="text-2xs text-ovs-crm-text-muted">
              Max Staff is a non-doctor sub-cap under Max Users — leave it blank if this plan doesn&apos;t distinguish staff from the seat total.
            </p>
            <div className="grid grid-cols-2 gap-2.5">
              <div className="space-y-1">
                <label className="block text-2xs text-ovs-crm-text-muted">Max Users (total seats)</label>
                <Input type="number" min={1} value={data.max_users} onChange={(e) => setData('max_users', parseInt(e.target.value, 10) || 1)} />
              </div>
              <div className="space-y-1">
                <label className="block text-2xs text-ovs-crm-text-muted">Additional User Price (₹)</label>
                <Input
                  type="number"
                  min={0}
                  value={data.additional_user_price ?? ''}
                  placeholder="Not sold"
                  onChange={(e) => setData('additional_user_price', e.target.value === '' ? null : parseFloat(e.target.value) || 0)}
                />
              </div>
            </div>
            <div className="space-y-1">
              <label className="block text-2xs text-ovs-crm-text-muted">Storage (GB)</label>
              <Input type="number" min={1} value={data.storage_limit_gb} onChange={(e) => setData('storage_limit_gb', parseInt(e.target.value, 10) || 1)} />
            </div>
          </div>

          <div className="p-3 rounded-[16px] bg-ovs-crm-surface-inset border border-ovs-crm-border space-y-2.5">
            <span className="text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">Modules</span>
            <p className="text-2xs text-ovs-crm-text-muted">
              What this package grants access to. Unchecked means the starter set (appointments, patients, billing) applies.
            </p>
            <div className="grid grid-cols-2 gap-1.5">
              {features.map((feature) => (
                <label
                  key={feature.key}
                  className="flex items-center gap-2 rounded-[12px] px-2 py-1.5 text-2xs text-ovs-crm-text-primary hover:bg-white cursor-pointer"
                  title={feature.description ?? undefined}
                >
                  <input
                    type="checkbox"
                    checked={data.module_keys.includes(feature.key)}
                    onChange={() => toggleModule(feature.key)}
                    className="h-3.5 w-3.5 rounded border-ovs-crm-border text-ovs-crm-text-primary focus:ring-1 focus:ring-ovs-crm-text-primary"
                  />
                  {feature.name}
                </label>
              ))}
            </div>
            {errors.module_keys && <p className="text-2xs text-ovs-crm-danger">{errors.module_keys}</p>}
          </div>

          <div className="space-y-3">
            <div className="flex items-center justify-between py-1">
              <span className="text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">Active (selectable for new deals)</span>
              <Switch checked={data.is_active} onCheckedChange={(v) => setData('is_active', v)} />
            </div>
            <div className="flex items-center justify-between py-1">
              <span className="text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">Offer as a Trial</span>
              <Switch checked={data.is_trial_available} onCheckedChange={(v) => setData('is_trial_available', v)} />
            </div>
            {data.is_trial_available && (
              <div className="space-y-1">
                <label className="block text-2xs text-ovs-crm-text-muted">Default Trial Duration (days)</label>
                <Input
                  type="number"
                  min={1}
                  max={90}
                  value={data.trial_duration_days}
                  onChange={(e) => setData('trial_duration_days', parseInt(e.target.value, 10) || 15)}
                />
                <p className="text-2xs text-ovs-crm-text-muted">Reps can still enter a custom duration per trial — this is just the default.</p>
              </div>
            )}
          </div>

        </form>
    </CrmModal>
  );
};

export default PackageFormModal;
