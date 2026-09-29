import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Armchair, Clock, GitBranch, MapPin, Pencil, Phone, Plus, Trash2 } from 'lucide-react';
import {
  CrmButton,
  CrmPageHead,
  CrmPageStat,
  useCrmConfirm,
} from '@/pages/Company/design';
import { Card, CardTitle } from '@/pages/Company/design/CrmCardPrimitives';
import { Badge } from '@/pages/Company/design/CrmBadgePrimitives';
import { IconChip } from '@/pages/Company/design/CrmFormPrimitives';
import { cn } from '@/lib/utils';
import { useClinicPermission } from '@/hooks/useClinicPermission';
import { ClinicShell } from '../../components/ClinicShell';
import { BranchFormModal } from './components/BranchFormModal';
import { Branch, BranchesPageProps } from './types';

/*
 * Branch admin — list, create, edit. Rebuilt onto Packages' card-grid
 * anatomy (icon chip, headline, fact grid, tag/badge row, top-right actions)
 * rather than CrmTable, now that a branch carries enough of its own detail
 * (address, contact, hours, compliance) to earn a real card.
 */

const BRANCH_ACCENTS = [
  { card: 'bg-gradient-to-br from-ovs-crm-surface-inset to-white', border: 'border-ovs-crm-border hover:border-ovs-crm-border', dot: 'bg-ovs-crm-text-secondary' },
  { card: 'bg-gradient-to-br from-ovs-crm-accent-teal-soft to-white', border: 'border-ovs-crm-accent-teal-border hover:border-ovs-crm-accent-teal-border', dot: 'bg-ovs-crm-accent-teal' },
  { card: 'bg-gradient-to-br from-ovs-crm-accent-violet-soft to-white', border: 'border-ovs-crm-accent-violet-border hover:border-ovs-crm-accent-violet-border', dot: 'bg-ovs-crm-accent-violet' },
] as const;

type ComplianceState = 'compliant' | 'partial' | 'pending';

function complianceState(branch: Branch): ComplianceState {
  const has = [branch.gstin, branch.clinical_establishment_registration_number].filter(Boolean).length;
  if (has === 2) return 'compliant';
  if (has === 1) return 'partial';
  return 'pending';
}

const COMPLIANCE_BADGE: Record<ComplianceState, { variant: 'success' | 'warning' | 'neutral'; label: string }> = {
  compliant: { variant: 'success', label: 'Compliance complete' },
  partial: { variant: 'warning', label: 'Compliance partial' },
  pending: { variant: 'neutral', label: 'Compliance pending' },
};

const STATUS_BADGE: Record<Branch['status'], { variant: 'success' | 'neutral' | 'danger'; label: string }> = {
  active: { variant: 'success', label: 'Active' },
  inactive: { variant: 'neutral', label: 'Inactive' },
  suspended: { variant: 'danger', label: 'Suspended' },
};

function locationHeadline(branch: Branch): string {
  if (branch.address_line2 && branch.city) return `${branch.address_line2}, ${branch.city}`;

  return branch.city ?? branch.address_line1 ?? 'Location not set';
}

export default function Branches({ branches, branchStatuses, branchLimit }: BranchesPageProps) {
  const hasPermission = useClinicPermission();
  const canManage = hasPermission('branch.manage');

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingBranch, setEditingBranch] = useState<Branch | null>(null);
  const { confirm, dialog } = useCrmConfirm();

  const openCreate = () => {
    setEditingBranch(null);
    setIsModalOpen(true);
  };

  const openEdit = (branch: Branch) => {
    setEditingBranch(branch);
    setIsModalOpen(true);
  };

  const confirmDelete = (branch: Branch) =>
    confirm({
      title: `Remove ${branch.name}?`,
      description: 'Staff, appointments and patients already scoped to this branch keep their records. This cannot be undone.',
      confirmLabel: 'Remove branch',
      destructive: true,
      onConfirm: () => router.delete(route('admin.branches.destroy', branch.id), { preserveScroll: true }),
    });

  const atLimit = branchLimit.allowed !== null && branchLimit.current >= branchLimit.allowed;

  return (
    <ClinicShell title="Branches">
      <CrmPageHead
        eyebrow="Admin"
        title="Branches"
        stats={
          <CrmPageStat
            icon={GitBranch}
            value={branchLimit.allowed !== null ? `${branchLimit.current}/${branchLimit.allowed}` : branchLimit.current}
            chip={atLimit ? 'at limit' : undefined}
            chipHue={atLimit ? '#E0B21E' : undefined}
            caption={branchLimit.allowed !== null ? 'Branches used' : 'Branches'}
          />
        }
        actions={
          canManage ? (
            <CrmButton tone="primary" icon={Plus} onClick={openCreate}>
              New Branch
            </CrmButton>
          ) : undefined
        }
      />

      <div className="space-y-6 pb-12">
        {branches.length === 0 ? (
          <Card elevation="surface" className="border border-ovs-crm-border p-10 text-center">
            <div className="w-12 h-12 rounded-[24px] bg-ovs-crm-border/80 border border-ovs-crm-border flex items-center justify-center mb-4 mx-auto text-ovs-crm-text-muted">
              <GitBranch className="w-6 h-6" />
            </div>
            <CardTitle className="text-[15px] font-extrabold text-ovs-crm-text-primary uppercase tracking-wide mb-2">No Branches Yet</CardTitle>
            <p className="text-[11.5px] text-ovs-crm-text-secondary max-w-md mx-auto mb-5">
              Every clinic needs at least one branch — appointments, staff and patients are all scoped to one.
            </p>
            {canManage && (
              <CrmButton size="sm" icon={Plus} onClick={openCreate} className="mx-auto">
                Create your first branch
              </CrmButton>
            )}
          </Card>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
            {branches.map((branch, idx) => {
              const accent = BRANCH_ACCENTS[idx % BRANCH_ACCENTS.length];
              const compliance = COMPLIANCE_BADGE[complianceState(branch)];
              const status = STATUS_BADGE[branch.status];

              return (
                <Card
                  key={branch.id}
                  elevation="surface"
                  className={cn('rounded-[24px] border p-5 space-y-4 flex flex-col', accent.card, accent.border)}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-start gap-3 min-w-0">
                      <IconChip tone="indigo" size="sm" icon={<GitBranch className="w-4 h-4" />} />
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <h3 className="text-[13px] font-bold text-ovs-crm-text-primary truncate">{branch.name}</h3>
                          <span className={cn('w-1.5 h-1.5 rounded-full flex-shrink-0', accent.dot)} />
                        </div>
                        <p className="text-[15px] font-black text-ovs-crm-text-primary mt-0.5 truncate">
                          {locationHeadline(branch)}
                        </p>
                      </div>
                    </div>
                    {canManage && (
                      <div className="flex items-center gap-1 flex-shrink-0">
                        <button
                          type="button"
                          onClick={() => openEdit(branch)}
                          aria-label={`Edit ${branch.name}`}
                          className="w-7 h-7 rounded-full border border-ovs-crm-border bg-white hover:bg-ovs-crm-surface-inset flex items-center justify-center text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary cursor-pointer transition-colors"
                        >
                          <Pencil className="w-3.5 h-3.5" />
                        </button>
                        {! branch.is_main && (
                          <button
                            type="button"
                            onClick={() => confirmDelete(branch)}
                            aria-label={`Remove ${branch.name}`}
                            className="w-7 h-7 rounded-full border border-ovs-crm-border bg-white hover:bg-ovs-crm-danger-soft flex items-center justify-center text-ovs-crm-text-secondary hover:text-ovs-crm-danger cursor-pointer transition-colors"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        )}
                      </div>
                    )}
                  </div>

                  <div className="grid grid-cols-2 gap-2 text-2xs">
                    <div className="flex items-center gap-1.5 text-ovs-crm-text-secondary">
                      <Phone className="w-3.5 h-3.5 text-ovs-crm-text-muted flex-shrink-0" />
                      <span className="truncate">{branch.phone ?? 'No phone on file'}</span>
                    </div>
                    <div className="flex items-center gap-1.5 text-ovs-crm-text-secondary">
                      <MapPin className="w-3.5 h-3.5 text-ovs-crm-text-muted flex-shrink-0" />
                      <span className="truncate">{branch.state ?? 'State not set'}</span>
                    </div>
                    <div className="flex items-center gap-1.5 text-ovs-crm-text-secondary">
                      <Armchair className="w-3.5 h-3.5 text-ovs-crm-text-muted flex-shrink-0" />
                      <span className="truncate">
                        {branch.resource_capacity !== null ? `${branch.resource_capacity} planned` : 'Capacity not set'}
                      </span>
                    </div>
                    <div className="flex items-center gap-1.5 text-ovs-crm-text-secondary">
                      <Clock className="w-3.5 h-3.5 text-ovs-crm-text-muted flex-shrink-0" />
                      <span className="truncate">{branch.today_hours_label}</span>
                    </div>
                  </div>

                  <div className="flex items-center gap-1.5 flex-wrap mt-auto pt-2">
                    <Badge variant={status.variant} size="compact">{status.label}</Badge>
                    {branch.is_main && <Badge variant="indigo" size="compact">Main branch</Badge>}
                    <Badge variant={compliance.variant} size="compact">{compliance.label}</Badge>
                  </div>
                </Card>
              );
            })}
          </div>
        )}
      </div>

      <BranchFormModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        branch={editingBranch}
        statuses={branchStatuses}
      />
      {dialog}
    </ClinicShell>
  );
}
