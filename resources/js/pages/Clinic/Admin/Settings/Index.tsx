import React, { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ImagePlus, ListChecks, Save, Trash2 } from 'lucide-react';
import { CrmButton, CrmFormField, CrmInput, CrmPageHead, CrmSection, toneHex } from '@/pages/Company/design';
import { Switch } from '@/pages/Company/design/CrmFormPrimitives';
import { cn } from '@/lib/utils';
import { ClinicShell } from '../../components/ClinicShell';
import { ClinicIdentity } from '../../components/ClinicIdentity';
import type { ConfigurableField, FieldState, PatientFieldConfig } from '../../Patients/components/PatientFields';
import { CaseNumberSection, type CaseNumberSettings } from './CaseNumberSection';
import { PatientFlowSection, type PatientFlowSettings } from './PatientFlowSection';

interface SettingsPageProps {
  branding: {
    display_name: string | null;
    name: string;
    logo_url: string | null;
  };
  /**
   * Schema-only today — no Billing/Invoice page reads these yet (see the
   * `Invoice` vs `PatientBilling` note left for whenever that module is
   * built). Captured here first so a clinic never has to backfill its
   * invoice identity later.
   */
  invoiceBranding: {
    legal_name: string | null;
    use_clinic_logo_for_invoice: boolean;
    invoice_logo_url: string | null;
    has_own_invoice_logo: boolean;
  };
  patientFieldConfig: PatientFieldConfig;
  caseNumber: CaseNumberSettings;
  patientFlow: PatientFlowSettings;
}

const FIELD_LABELS: Record<ConfigurableField, string> = {
  last_name: 'Last name',
  date_of_birth: 'Date of birth',
  gender: 'Gender',
  blood_group: 'Blood group',
  alternate_phone: 'Alternate phone',
  email: 'Email',
  address: 'Address',
  emergency_contact: 'Emergency contact',
  allergies: 'Allergies',
  doctor_id: 'Assigned doctor',
};

const STATE_OPTIONS: { value: FieldState; label: string }[] = [
  { value: 'required', label: 'Required' },
  { value: 'optional', label: 'Optional' },
  { value: 'hidden', label: 'Hidden' },
];

/** A 3-way required/optional/hidden switch — one row per registration field. */
function FieldStateToggle({ value, onChange }: { value: FieldState; onChange: (next: FieldState) => void }) {
  return (
    <div role="tablist" className="inline-flex items-center gap-0.5 rounded-full bg-ovs-crm-surface-inset p-1">
      {STATE_OPTIONS.map((option) => {
        const active = option.value === value;

        return (
          <button
            key={option.value}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(option.value)}
            className={cn(
              'rounded-full px-3 py-1.5 text-[11.5px] font-medium transition-colors',
              active ? 'bg-ovs-crm-ink text-white' : 'text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary'
            )}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

/**
 * Clinic Admin > Settings. Branding is the one section today — the name and
 * logo shown on the pre-auth login page and in the portal header
 * (`ClinicPreAuthShell`, `ClinicShell`). More sections land here as the
 * admin area grows, same as `CompanyShell`'s settings hub.
 */
export default function Settings({ branding, invoiceBranding, patientFieldConfig, caseNumber, patientFlow }: SettingsPageProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<string | null>(null);

  const invoiceLogoInputRef = useRef<HTMLInputElement>(null);
  const [invoiceLogoPreview, setInvoiceLogoPreview] = useState<string | null>(null);

  const { data, setData, post, processing, errors } = useForm<{
    logo: File | null;
    display_name: string;
    legal_name: string;
    use_clinic_logo_for_invoice: boolean;
    invoice_logo: File | null;
    _method: 'put';
  }>({
    logo: null,
    display_name: branding.display_name ?? '',
    legal_name: invoiceBranding.legal_name ?? '',
    use_clinic_logo_for_invoice: invoiceBranding.use_clinic_logo_for_invoice,
    invoice_logo: null,
    _method: 'put',
  });

  const displayLogoUrl = preview ?? branding.logo_url;
  const fallbackName = branding.display_name || branding.name;
  const invoiceLogoUrl = invoiceLogoPreview ?? invoiceBranding.invoice_logo_url;

  const pickFile = () => fileInputRef.current?.click();
  const pickInvoiceLogoFile = () => invoiceLogoInputRef.current?.click();

  const onFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] ?? null;
    setData('logo', file);
    setPreview(file ? URL.createObjectURL(file) : null);
  };

  const onInvoiceLogoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] ?? null;
    setData('invoice_logo', file);
    setInvoiceLogoPreview(file ? URL.createObjectURL(file) : null);
  };

  const clearChosenFile = () => {
    setData('logo', null);
    setPreview(null);
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  const clearInvoiceLogoFile = () => {
    setData('invoice_logo', null);
    setInvoiceLogoPreview(null);
    if (invoiceLogoInputRef.current) invoiceLogoInputRef.current.value = '';
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('admin.settings.update'), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => { clearChosenFile(); clearInvoiceLogoFile(); },
    });
  };

  /*
   * A separate `useForm` and a separate `<form>` — nesting this inside the
   * branding form above would be invalid HTML, and the two save
   * independently on their own endpoints anyway (`admin.settings.update`
   * vs `admin.settings.patient-fields`).
   */
  const fieldForm = useForm<PatientFieldConfig>(patientFieldConfig);

  const submitFieldConfig = (e: React.FormEvent) => {
    e.preventDefault();
    fieldForm.put(route('admin.settings.patient-fields'), { preserveScroll: true });
  };

  return (
    <ClinicShell title="Settings">
      <CrmPageHead eyebrow="Admin" title="Settings" description="What your clinic looks like to patients and staff." />

      <form onSubmit={submit} className="max-w-[640px] space-y-8 pb-12">
        <CrmSection title="Clinic Identity" description="Shown on your login page and across the clinic portal.">
          <div className="space-y-6">
            <div className="flex items-center gap-5">
              <ClinicIdentity
                name={fallbackName}
                logoUrl={displayLogoUrl}
                logoHeight={64}
                nameSize={26}
                multiline={false}
                className="max-w-[220px]"
              />

              <div className="flex flex-col gap-2">
                <div className="flex items-center gap-2">
                  <CrmButton type="button" size="sm" icon={ImagePlus} onClick={pickFile}>
                    {branding.logo_url || preview ? 'Change logo' : 'Upload logo'}
                  </CrmButton>
                  {data.logo && (
                    <CrmButton type="button" size="sm" tone="ghost" icon={Trash2} onClick={clearChosenFile}>
                      Undo
                    </CrmButton>
                  )}
                </div>
                <p className="text-[11.5px] text-ovs-crm-text-muted">PNG, JPG or WebP. Up to 2MB.</p>
                {errors.logo && <p className="text-[11.5px] text-ovs-crm-accent-coral-text">{errors.logo}</p>}
              </div>

              <input
                ref={fileInputRef}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                className="hidden"
                onChange={onFileChange}
              />
            </div>

            <CrmFormField
              label="Display name"
              hint={`Shown instead of "${branding.name}" if set.`}
              error={errors.display_name}
            >
              <CrmInput
                value={data.display_name}
                onChange={(e) => setData('display_name', e.target.value)}
                placeholder={branding.name}
                invalid={!!errors.display_name}
              />
            </CrmFormField>
          </div>
        </CrmSection>

        <CrmSection
          title="Billing & Invoice Branding"
          description="Used on invoices once billing is enabled — nothing shows this yet, but it's ready when it does."
        >
          <div className="space-y-6">
            <CrmFormField
              label="Legal name"
              hint="The registered name printed on invoices — can differ from your display name above."
              error={errors.legal_name}
            >
              <CrmInput
                value={data.legal_name}
                onChange={(e) => setData('legal_name', e.target.value)}
                placeholder={branding.name}
                invalid={!!errors.legal_name}
              />
            </CrmFormField>

            <div className="flex items-center justify-between rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset px-4 py-3">
              <div>
                <p className="text-[13px] font-semibold text-ovs-crm-text-primary">Use clinic logo for invoices</p>
                <p className="text-[11.5px] text-ovs-crm-text-muted">Off lets you upload a separate logo just for invoices.</p>
              </div>
              <Switch
                checked={data.use_clinic_logo_for_invoice}
                onCheckedChange={(v: boolean) => setData('use_clinic_logo_for_invoice', v)}
              />
            </div>

            {!data.use_clinic_logo_for_invoice && (
              <div className="flex items-center gap-5">
                <ClinicIdentity
                  name={invoiceBranding.legal_name || fallbackName}
                  logoUrl={invoiceLogoUrl}
                  logoHeight={56}
                  nameSize={22}
                  multiline={false}
                  className="max-w-[220px]"
                />

                <div className="flex flex-col gap-2">
                  <div className="flex items-center gap-2">
                    <CrmButton type="button" size="sm" icon={ImagePlus} onClick={pickInvoiceLogoFile}>
                      {invoiceBranding.has_own_invoice_logo || invoiceLogoPreview ? 'Change logo' : 'Upload logo'}
                    </CrmButton>
                    {data.invoice_logo && (
                      <CrmButton type="button" size="sm" tone="ghost" icon={Trash2} onClick={clearInvoiceLogoFile}>
                        Undo
                      </CrmButton>
                    )}
                  </div>
                  <p className="text-[11.5px] text-ovs-crm-text-muted">PNG, JPG or WebP. Up to 2MB.</p>
                  {errors.invoice_logo && <p className="text-[11.5px] text-ovs-crm-accent-coral-text">{errors.invoice_logo}</p>}
                </div>

                <input
                  ref={invoiceLogoInputRef}
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  className="hidden"
                  onChange={onInvoiceLogoChange}
                />
              </div>
            )}
          </div>
        </CrmSection>

        <div className="flex justify-end">
          <CrmButton type="submit" tone="primary" icon={Save} loading={processing}>
            Save changes
          </CrmButton>
        </div>
      </form>

      <form onSubmit={submitFieldConfig} className="max-w-[640px] space-y-8 pb-12">
        <CrmSection
          title="Patient Registration Fields"
          description="First name and phone are always required. Everything else here can be required, optional, or hidden entirely from the registration form."
          icon={ListChecks}
          hue={toneHex('violet')}
        >
          <div className="divide-y divide-ovs-crm-line">
            {(Object.keys(FIELD_LABELS) as ConfigurableField[]).map((field) => (
              <div key={field} className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                <p className="text-[13px] font-medium text-ovs-crm-text-primary">{FIELD_LABELS[field]}</p>
                <FieldStateToggle value={fieldForm.data[field]} onChange={(next) => fieldForm.setData(field, next)} />
              </div>
            ))}
          </div>
        </CrmSection>

        <div className="flex justify-end">
          <CrmButton type="submit" tone="primary" icon={Save} loading={fieldForm.processing}>
            Save changes
          </CrmButton>
        </div>
      </form>

      <PatientFlowSection settings={patientFlow} />

      <CaseNumberSection settings={caseNumber} />
    </ClinicShell>
  );
}
