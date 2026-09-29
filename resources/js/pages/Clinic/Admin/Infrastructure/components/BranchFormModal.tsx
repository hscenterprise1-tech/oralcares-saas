import React, { useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ImagePlus, Loader2, Trash2 } from 'lucide-react';
import { CrmModal } from '@/pages/Company/design/CrmOverlay';
import { CrmButton } from '@/pages/Company/design/CrmControls';
import { Input, Switch } from '@/pages/Company/design/CrmFormPrimitives';
import { ClinicIdentity } from '../../../components/ClinicIdentity';
import { Branch, BranchStatus, OperatingHour } from '../types';

/**
 * Create/edit a branch — four sections, same bordered-box convention
 * `PackageFormModal` already established ("Capacity Limits", "Modules"),
 * extended from two boxes to four rather than inventing a new pattern.
 */

interface BranchFormModalProps {
  isOpen: boolean;
  onClose: () => void;
  branch?: Branch | null;
  statuses: BranchStatus[];
}

const STATUS_LABEL: Record<BranchStatus, string> = {
  active: 'Active',
  inactive: 'Inactive',
  suspended: 'Suspended',
};

/** Monday-first for display; `value` still matches PHP's `date('w')` (0 = Sunday), what the backend stores. */
const DAYS: { value: number; label: string }[] = [
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
  { value: 0, label: 'Sunday' },
];

function slugify(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '');
}

/** Every day, always — a branch with no hours saved yet still gets a sane, editable starting point. */
function hoursFromBranch(branch: Branch | null | undefined): OperatingHour[] {
  return DAYS.map(({ value }) => {
    const existing = branch?.operating_hours.find((hour) => hour.day_of_week === value);

    return existing ?? { day_of_week: value, open_time: '09:00', close_time: '18:00', is_closed: value === 0 };
  });
}

const inputClass =
  'w-full rounded-[16px] border border-ovs-crm-border bg-white px-3 py-2 text-[13px] text-ovs-crm-text-primary';

function SectionLabel({ children }: { children: React.ReactNode }) {
  return <span className="text-2xs font-bold uppercase tracking-wider text-ovs-crm-text-secondary">{children}</span>;
}

export const BranchFormModal: React.FC<BranchFormModalProps> = ({ isOpen, onClose, branch, statuses }) => {
  const isEditing = !!branch;

  const { data, setData, post, transform, processing, errors, reset } = useForm({
    name: '',
    slug: '',
    status: 'active' as BranchStatus,
    is_main: false,
    address_line1: '',
    address_line2: '',
    city: '',
    state: '',
    pincode: '',
    phone: '',
    whatsapp_number: '',
    resource_capacity: '' as number | '',
    gstin: '',
    clinical_establishment_registration_number: '',
    display_name: '',
    logo: null as File | null,
    operating_hours: hoursFromBranch(null),
  });

  const [slugTouched, setSlugTouched] = useState(false);
  const logoInputRef = useRef<HTMLInputElement>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);

  useEffect(() => {
    if (! isOpen) return;
    if (branch) {
      setData({
        name: branch.name,
        slug: branch.slug,
        status: branch.status,
        is_main: branch.is_main,
        address_line1: branch.address_line1 ?? '',
        address_line2: branch.address_line2 ?? '',
        city: branch.city ?? '',
        state: branch.state ?? '',
        pincode: branch.pincode ?? '',
        phone: branch.phone ?? '',
        whatsapp_number: branch.whatsapp_number ?? '',
        resource_capacity: branch.resource_capacity ?? '',
        gstin: branch.gstin ?? '',
        clinical_establishment_registration_number: branch.clinical_establishment_registration_number ?? '',
        display_name: branch.display_name ?? '',
        logo: null,
        operating_hours: hoursFromBranch(branch),
      });
      setSlugTouched(true);
    } else {
      reset();
      setData('operating_hours', hoursFromBranch(null));
      setSlugTouched(false);
    }
    setLogoPreview(null);
    if (logoInputRef.current) logoInputRef.current.value = '';
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, branch]);

  const pickLogoFile = () => logoInputRef.current?.click();

  const onLogoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] ?? null;
    setData('logo', file);
    setLogoPreview(file ? URL.createObjectURL(file) : null);
  };

  const clearLogoFile = () => {
    setData('logo', null);
    setLogoPreview(null);
    if (logoInputRef.current) logoInputRef.current.value = '';
  };

  const handleNameChange = (value: string) => {
    setData('name', value);
    if (! slugTouched) {
      setData('slug', slugify(value));
    }
  };

  const updateDay = (dayValue: number, patch: Partial<OperatingHour>) => {
    setData(
      'operating_hours',
      data.operating_hours.map((day) => (day.day_of_week === dayValue ? { ...day, ...patch } : day))
    );
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const options = { forceFormData: true, preserveScroll: true, onSuccess: () => { reset(); setSlugTouched(false); onClose(); } };

    if (isEditing && branch) {
      // Inertia can't send a file over a real PUT — spoof it through POST
      // instead, the same workaround `SettingsController`'s form already
      // relies on for the clinic's own logo upload.
      transform((current) => ({ ...current, _method: 'put' }));
      post(route('admin.branches.update', branch.id), options);
    } else {
      post(route('admin.branches.store'), options);
    }
  };

  return (
    <CrmModal
      open={isOpen}
      onClose={onClose}
      title={isEditing ? 'Edit branch' : 'Create branch'}
      description="Branches are where staff, appointments and patients are scoped to."
      footer={
        <>
          <CrmButton tone="ghost" onClick={onClose}>Cancel</CrmButton>
          <CrmButton
            tone="primary"
            type="submit"
            form="branch-form"
            disabled={processing || ! data.name.trim() || ! data.slug.trim()}
          >
            {processing && <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />}
            {isEditing ? 'Save changes' : 'Create branch'}
          </CrmButton>
        </>
      }
    >
      <form id="branch-form" onSubmit={handleSubmit} className="space-y-5">
        {/* ── Basic Info ──────────────────────────────────────────── */}
        <div className="space-y-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset p-3">
          <SectionLabel>Basic Info</SectionLabel>

          <div className="space-y-1.5">
            <label className="block text-2xs text-ovs-crm-text-muted">
              Branch Name <span className="text-ovs-crm-danger">*</span>
            </label>
            <Input value={data.name} onChange={(e) => handleNameChange(e.target.value)} placeholder="e.g. Andheri West" />
            {errors.name && <p className="mt-1 text-2xs text-ovs-crm-danger">{errors.name}</p>}
          </div>

          <div className="space-y-1.5">
            <label className="block text-2xs text-ovs-crm-text-muted">
              Slug <span className="text-ovs-crm-danger">*</span>
            </label>
            <Input
              value={data.slug}
              onChange={(e) => { setSlugTouched(true); setData('slug', e.target.value); }}
              placeholder="e.g. andheri-west"
            />
            {errors.slug && <p className="mt-1 text-2xs text-ovs-crm-danger">{errors.slug}</p>}
          </div>

          <div className="grid grid-cols-2 gap-2.5">
            <div className="space-y-1">
              <label className="block text-2xs text-ovs-crm-text-muted">Status</label>
              <select
                value={data.status}
                onChange={(e) => setData('status', e.target.value as BranchStatus)}
                className={inputClass}
              >
                {statuses.map((status) => (
                  <option key={status} value={status}>{STATUS_LABEL[status]}</option>
                ))}
              </select>
              {errors.status && <p className="mt-1 text-2xs text-ovs-crm-danger">{errors.status}</p>}
            </div>
            <div className="space-y-1">
              <label className="block text-2xs text-ovs-crm-text-muted">Planning Capacity</label>
              <Input
                type="number"
                min={0}
                value={data.resource_capacity}
                placeholder="e.g. 4 chairs"
                onChange={(e) => setData('resource_capacity', e.target.value === '' ? '' : parseInt(e.target.value, 10) || 0)}
              />
            </div>
          </div>
          <p className="text-2xs text-ovs-crm-text-muted">
            Planning Capacity is a target — how many chairs/rooms this branch is meant to have. It doesn&apos;t count actual chairs yet; that comes from a future Resources screen.
          </p>

          <div className="flex items-center justify-between py-1">
            <SectionLabel>Main branch</SectionLabel>
            <Switch checked={data.is_main} onCheckedChange={(v) => setData('is_main', v)} />
          </div>
          <p className="text-2xs text-ovs-crm-text-muted">
            Only one branch can be main. Turning this on moves the flag off whichever branch currently holds it.
          </p>
        </div>

        {/* ── Branding ─────────────────────────────────────────────── */}
        <div className="space-y-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset p-3">
          <SectionLabel>Branding</SectionLabel>
          <p className="text-2xs text-ovs-crm-text-muted">
            Optional — overrides the clinic&apos;s own name and logo in the portal header while this branch is
            selected. Leave blank to use the clinic&apos;s default.
          </p>

          <div className="flex items-center gap-4">
            <ClinicIdentity
              name={data.display_name || branch?.name || data.name || 'This branch'}
              logoUrl={logoPreview ?? branch?.logo_url}
              logoHeight={48}
              nameSize={18}
              multiline={false}
              className="max-w-[160px]"
            />

            <div className="flex flex-col gap-1.5">
              <div className="flex items-center gap-2">
                <CrmButton type="button" size="sm" icon={ImagePlus} onClick={pickLogoFile}>
                  {branch?.logo_url || logoPreview ? 'Change logo' : 'Upload logo'}
                </CrmButton>
                {data.logo && (
                  <CrmButton type="button" size="sm" tone="ghost" icon={Trash2} onClick={clearLogoFile}>
                    Undo
                  </CrmButton>
                )}
              </div>
              <p className="text-2xs text-ovs-crm-text-muted">PNG, JPG or WebP. Up to 2MB.</p>
              {errors.logo && <p className="text-2xs text-ovs-crm-danger">{errors.logo}</p>}
            </div>

            <input
              ref={logoInputRef}
              type="file"
              accept="image/png,image/jpeg,image/webp"
              className="hidden"
              onChange={onLogoChange}
            />
          </div>

          <div className="space-y-1.5">
            <label className="block text-2xs text-ovs-crm-text-muted">Display name override</label>
            <Input
              value={data.display_name}
              onChange={(e) => setData('display_name', e.target.value)}
              placeholder={branch?.name ?? data.name ?? 'Uses the clinic name if left blank'}
            />
            {errors.display_name && <p className="mt-1 text-2xs text-ovs-crm-danger">{errors.display_name}</p>}
          </div>
        </div>

        {/* ── Location & Contact ──────────────────────────────────── */}
        <div className="space-y-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset p-3">
          <SectionLabel>Location &amp; Contact</SectionLabel>

          <div className="space-y-1.5">
            <label className="block text-2xs text-ovs-crm-text-muted">Address Line 1</label>
            <Input value={data.address_line1} onChange={(e) => setData('address_line1', e.target.value)} placeholder="Building, street" />
          </div>
          <div className="space-y-1.5">
            <label className="block text-2xs text-ovs-crm-text-muted">Address Line 2</label>
            <Input value={data.address_line2} onChange={(e) => setData('address_line2', e.target.value)} placeholder="Area, landmark (optional)" />
          </div>

          <div className="grid grid-cols-3 gap-2.5">
            <div className="space-y-1">
              <label className="block text-2xs text-ovs-crm-text-muted">City</label>
              <Input value={data.city} onChange={(e) => setData('city', e.target.value)} />
            </div>
            <div className="space-y-1">
              <label className="block text-2xs text-ovs-crm-text-muted">State</label>
              <Input value={data.state} onChange={(e) => setData('state', e.target.value)} />
            </div>
            <div className="space-y-1">
              <label className="block text-2xs text-ovs-crm-text-muted">Pincode</label>
              <Input value={data.pincode} onChange={(e) => setData('pincode', e.target.value)} maxLength={6} />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-2.5">
            <div className="space-y-1">
              <label className="block text-2xs text-ovs-crm-text-muted">Phone</label>
              <Input value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="Front desk number" />
            </div>
            <div className="space-y-1">
              <label className="block text-2xs text-ovs-crm-text-muted">WhatsApp Number</label>
              <Input value={data.whatsapp_number} onChange={(e) => setData('whatsapp_number', e.target.value)} placeholder="If different from phone" />
            </div>
          </div>
        </div>

        {/* ── Operating Hours ─────────────────────────────────────── */}
        <div className="space-y-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset p-3">
          <SectionLabel>Operating Hours</SectionLabel>
          <div className="space-y-1.5">
            {DAYS.map(({ value, label }) => {
              const day = data.operating_hours.find((d) => d.day_of_week === value)!;

              return (
                <div key={value} className="flex items-center gap-2 rounded-[12px] bg-white px-2.5 py-2">
                  <span className="w-20 shrink-0 text-2xs font-medium text-ovs-crm-text-primary">{label}</span>
                  {day.is_closed ? (
                    <span className="flex-1 text-2xs text-ovs-crm-text-muted">Closed</span>
                  ) : (
                    <div className="flex flex-1 items-center gap-1.5">
                      <input
                        type="time"
                        value={day.open_time}
                        onChange={(e) => updateDay(value, { open_time: e.target.value })}
                        className="h-8 flex-1 rounded-[12px] border border-ovs-crm-border px-2 text-2xs text-ovs-crm-text-primary"
                      />
                      <span className="text-ovs-crm-text-muted">–</span>
                      <input
                        type="time"
                        value={day.close_time}
                        onChange={(e) => updateDay(value, { close_time: e.target.value })}
                        className="h-8 flex-1 rounded-[12px] border border-ovs-crm-border px-2 text-2xs text-ovs-crm-text-primary"
                      />
                    </div>
                  )}
                  <label className="flex shrink-0 items-center gap-1.5 text-2xs text-ovs-crm-text-muted">
                    <input
                      type="checkbox"
                      checked={day.is_closed}
                      onChange={(e) => updateDay(value, { is_closed: e.target.checked })}
                      className="h-3.5 w-3.5 rounded border-ovs-crm-border"
                    />
                    Closed
                  </label>
                </div>
              );
            })}
          </div>
        </div>

        {/* ── Compliance ───────────────────────────────────────────── */}
        <div className="space-y-3 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset p-3">
          <SectionLabel>Compliance</SectionLabel>
          <p className="text-2xs text-ovs-crm-text-muted">
            Optional — most clinics don&apos;t have these on day one. Fill in whenever they&apos;re ready.
          </p>

          <div className="space-y-1.5">
            <label className="block text-2xs text-ovs-crm-text-muted">GSTIN</label>
            <Input value={data.gstin} onChange={(e) => setData('gstin', e.target.value)} placeholder="15-character GST number" maxLength={15} />
            {errors.gstin && <p className="mt-1 text-2xs text-ovs-crm-danger">{errors.gstin}</p>}
          </div>
          <div className="space-y-1.5">
            <label className="block text-2xs text-ovs-crm-text-muted">Clinical Establishment Registration Number</label>
            <Input
              value={data.clinical_establishment_registration_number}
              onChange={(e) => setData('clinical_establishment_registration_number', e.target.value)}
              placeholder="Under the Clinical Establishments Act, if registered"
            />
          </div>
        </div>
      </form>
    </CrmModal>
  );
};

export default BranchFormModal;
