import {
  AdministrationShell,
  CrmPageHead,
  CrmPageStat,
  inr,
} from '@/pages/Company/design';
import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Card, CardTitle } from '@/pages/Company/design/CrmCardPrimitives';
import { Badge } from '@/pages/Company/design/CrmBadgePrimitives';
import { Button } from '@/pages/Company/design/CrmFormPrimitives';
import { IconChip } from '@/pages/Company/design/CrmFormPrimitives';
import { cn } from '@/lib/utils';
import { CreditCard, Plus, Users, GitBranch, HardDrive, Clock, Pencil, Trash2 } from 'lucide-react';
import { PackageFormModal } from './components/PackageFormModal';
import { Package, PackagesIndexProps } from './types';
import { useCrmConfirm } from '@/pages/Company/design';

const PACKAGE_ACCENTS = [
  { card: 'bg-gradient-to-br from-ovs-crm-surface-inset to-white', border: 'border-ovs-crm-border hover:border-ovs-crm-border', dot: 'bg-ovs-crm-text-secondary' },
  { card: 'bg-gradient-to-br from-ovs-crm-accent-teal-soft to-white', border: 'border-ovs-crm-accent-teal-border hover:border-ovs-crm-accent-teal-border', dot: 'bg-ovs-crm-accent-teal' },
  { card: 'bg-gradient-to-br from-ovs-crm-accent-violet-soft to-white', border: 'border-ovs-crm-accent-violet-border hover:border-ovs-crm-accent-violet-border', dot: 'bg-ovs-crm-accent-violet' },
] as const;

function formatINR(amount: number): string {
  return '₹' + amount.toLocaleString('en-IN');
}

export default function PackagesIndex({ packages = [], features = [] }: PackagesIndexProps) {
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingPackage, setEditingPackage] = useState<Package | null>(null);
  const { confirm, dialog } = useCrmConfirm();

  const openCreate = () => {
    setEditingPackage(null);
    setIsModalOpen(true);
  };

  const openEdit = (pkg: Package) => {
    setEditingPackage(pkg);
    setIsModalOpen(true);
  };

  const handleDelete = (pkg: Package) =>
    confirm({
      title: `Remove ${pkg.name}?`,
      description:
        'Leads, trials and quotations that already reference this plan keep it. New records will no longer be able to select it. This cannot be undone.',
      confirmLabel: 'Remove plan',
      destructive: true,
      onConfirm: () =>
        router.delete(route('company.admin.packages.destroy', pkg.id), { preserveScroll: true }),
    });

  const trialAvailableCount = packages.filter((p) => p.is_trial_available).length;
  const activeCount = packages.filter((p) => p.is_active).length;

  return (
    <AdministrationShell title="Packages">
      <CrmPageHead
        eyebrow="Admin"
        title="Packages"
        stats={
          <>
            <CrmPageStat
              icon={CreditCard}
              value={packages.length}
              caption={`Package${packages.length === 1 ? '' : 's'} on offer`}
            />
            {activeCount !== packages.length && (
              <CrmPageStat icon={Users} value={activeCount} caption="Available to sell" />
            )}
            {trialAvailableCount !== packages.length && (
              <CrmPageStat icon={Clock} value={trialAvailableCount} caption="Offer a free trial" />
            )}
          </>
        }
        actions={
        <Button size="sm" onClick={openCreate} className="gap-1.5">
          <Plus className="w-4 h-4" /> Create Package
        </Button>
      }
      />


      <div className="space-y-6 pb-12">
        {packages.length === 0 ? (
          <Card elevation="surface" className="border border-ovs-crm-border p-10 text-center">
            <div className="w-12 h-12 rounded-[24px] bg-ovs-crm-border/80 border border-ovs-crm-border flex items-center justify-center mb-4 mx-auto text-ovs-crm-text-muted">
              <CreditCard className="w-6 h-6" />
            </div>
            <CardTitle className="text-[15px] font-extrabold text-ovs-crm-text-primary uppercase tracking-wide mb-2">No Packages Yet</CardTitle>
            <p className="text-[11.5px] text-ovs-crm-text-secondary max-w-md mx-auto mb-5">
              Create a package to start offering trials and sending quotations against it — set doctor/user caps, storage, and default trial length once, and reuse everywhere.
            </p>
            <Button size="sm" onClick={openCreate} className="gap-1.5 mx-auto">
              <Plus className="w-4 h-4" /> Create Your First Package
            </Button>
          </Card>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
            {packages.map((pkg, idx) => {
              const accent = PACKAGE_ACCENTS[idx % PACKAGE_ACCENTS.length];
              return (
                <Card
                  key={pkg.id}
                  elevation="surface"
                  className={cn('rounded-[24px] border p-5 space-y-4 flex flex-col', accent.card, accent.border)}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-start gap-3 min-w-0">
                      <IconChip tone="indigo" size="sm" icon={<CreditCard className="w-4 h-4" />} />
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <h3 className="text-[13px] font-bold text-ovs-crm-text-primary truncate">{pkg.name}</h3>
                          <span className={cn('w-1.5 h-1.5 rounded-full flex-shrink-0', accent.dot)} />
                        </div>
                        <p className="text-[18px] font-black text-ovs-crm-text-primary mt-0.5">
                          {formatINR(pkg.price)}
                          <span className="text-2xs font-normal text-ovs-crm-text-muted">/{pkg.billing_period === 'monthly' ? 'mo' : 'yr'}</span>
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-1 flex-shrink-0">
                      <button
                        type="button"
                        onClick={() => openEdit(pkg)}
                        className="w-7 h-7 rounded-full border border-ovs-crm-border bg-white hover:bg-ovs-crm-surface-inset flex items-center justify-center text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary cursor-pointer transition-colors"
                      >
                        <Pencil className="w-3.5 h-3.5" />
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDelete(pkg)}
                        className="w-7 h-7 rounded-full border border-ovs-crm-border bg-white hover:bg-ovs-crm-danger-soft flex items-center justify-center text-ovs-crm-text-secondary hover:text-ovs-crm-danger cursor-pointer transition-colors"
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>

                  {pkg.description && <p className="text-2xs text-ovs-crm-text-secondary">{pkg.description}</p>}

                  <div className="grid grid-cols-2 gap-2 text-2xs">
                    <div className="flex items-center gap-1.5 text-ovs-crm-text-secondary">
                      <Users className="w-3.5 h-3.5 text-ovs-crm-text-muted" /> {pkg.max_doctors} doctors · {pkg.max_users} users
                    </div>
                    <div className="flex items-center gap-1.5 text-ovs-crm-text-secondary">
                      <GitBranch className="w-3.5 h-3.5 text-ovs-crm-text-muted" /> {pkg.max_branches} branch{pkg.max_branches === 1 ? "" : "es"}
                    </div>
                    <div className="flex items-center gap-1.5 text-ovs-crm-text-secondary">
                      <HardDrive className="w-3.5 h-3.5 text-ovs-crm-text-muted" /> {pkg.storage_limit_gb} GB
                    </div>
                    {pkg.is_trial_available && (
                      <div className="flex items-center gap-1.5 text-ovs-crm-text-secondary">
                        <Clock className="w-3.5 h-3.5 text-ovs-crm-text-muted" /> {pkg.trial_duration_days}-day trial
                      </div>
                    )}
                  </div>

                  {pkg.module_keys.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                      {pkg.module_keys.map((key) => (
                        <span
                          key={key}
                          className="rounded-full bg-ovs-crm-surface-inset px-2 py-0.5 text-2xs text-ovs-crm-text-secondary"
                        >
                          {key}
                        </span>
                      ))}
                    </div>
                  )}

                  <div className="flex items-center gap-1.5 flex-wrap mt-auto pt-2">
                    <Badge variant={pkg.is_active ? 'success' : 'neutral'} size="compact">
                      {pkg.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                    {pkg.is_trial_available && <Badge variant="indigo" size="compact">Trial-eligible</Badge>}
                    {pkg.subscriptions_count > 0 && (
                      <Badge variant="outline" size="compact">{pkg.subscriptions_count} subscriber(s)</Badge>
                    )}
                  </div>
                </Card>
              );
            })}
          </div>
        )}
      </div>

      <PackageFormModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} pkg={editingPackage} features={features} />
      {dialog}
    </AdministrationShell>
  );
}
