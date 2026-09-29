import React from 'react';
import { router } from '@inertiajs/react';
import {
  CrmModal,
  CrmButton,
  CrmInput,
  CrmTextarea,
  CrmFormField,
  CrmBadge,
  specialtyTone,
} from '@/pages/Company/design';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/*
 * Create Deal, without leaving the list.
 *
 * The dedicated /create page cost a rep a navigation, a fresh page load, and
 * the loss of whatever queue or filter they were sitting in — friction that
 * matters most on exactly the call where it is worst tolerated, the first one.
 * This is the same five-field intake `Leads/Create.tsx` asks for, in a modal
 * that opens over the pipeline and hands the rep straight back to it.
 *
 * Deliberately not the full create form: no routing, no assignment, no
 * priority. Those are answered later by the deal itself — auto-assignment and
 * a medium priority are exactly what the full page defaults to when a rep
 * leaves them alone, so leaving them out here costs nothing.
 */

interface SpecialtyOption {
  value: string;
  label: string;
}

export interface QuickCreateLeadModalProps {
  open: boolean;
  onClose: () => void;
  specialties: SpecialtyOption[];
}

const emptyForm = {
  contact_name: '',
  phone: '',
  company_name: '',
  city: '',
  healthcare_specialties: [] as string[],
  notes: '',
};

export function QuickCreateLeadModal({ open, onClose, specialties }: QuickCreateLeadModalProps) {
  const [data, setData] = React.useState(emptyForm);
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [processing, setProcessing] = React.useState(false);

  /* A closed-then-reopened modal starts clean rather than showing the last attempt. */
  React.useEffect(() => {
    if (open) {
      setData(emptyForm);
      setErrors({});
    }
  }, [open]);

  const toggleSpecialty = (value: string) => {
    setData((prev) => ({
      ...prev,
      healthcare_specialties: prev.healthcare_specialties.includes(value)
        ? prev.healthcare_specialties.filter((s) => s !== value)
        : [...prev.healthcare_specialties, value],
    }));
  };

  const complete =
    data.contact_name.trim() !== '' &&
    data.phone.trim() !== '' &&
    data.company_name.trim() !== '' &&
    data.city.trim() !== '' &&
    data.healthcare_specialties.length > 0;

  const submit = () => {
    if (!complete || processing) return;

    setProcessing(true);

    /*
     * `leads.store` redirects through the retired `leads.index` alias before
     * landing back on this page — two hops, one Inertia response.
     * `preserveState` keeps the filters and scroll position a rep was sitting
     * in rather than resetting the page to its defaults. The success message
     * itself comes from the server's flash, read where the page mounts, not
     * from a callback closed over here.
     */
    router.post(route('company.crm.leads.store'), data, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: onClose,
      onError: (errs) => setErrors(errs as Record<string, string>),
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title="New deal"
      description="Five answers from the first call. Everything else is captured on the deal."
      size="md"
      footer={
        <>
          <CrmButton onClick={onClose}>Cancel</CrmButton>
          <CrmButton tone="primary" icon={Check} loading={processing} disabled={!complete} onClick={submit}>
            Create deal
          </CrmButton>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <CrmFormField label="Doctor's name" required error={errors.contact_name}>
            <CrmInput
              value={data.contact_name}
              onChange={(e) => setData((prev) => ({ ...prev, contact_name: e.target.value }))}
              placeholder="Dr Harshil Mehta"
              autoFocus
            />
          </CrmFormField>

          <CrmFormField label="Mobile" required error={errors.phone}>
            <CrmInput
              value={data.phone}
              onChange={(e) => setData((prev) => ({ ...prev, phone: e.target.value }))}
              placeholder="98765 43210"
              inputMode="tel"
            />
          </CrmFormField>

          <CrmFormField label="Clinic or hospital" required error={errors.company_name}>
            <CrmInput
              value={data.company_name}
              onChange={(e) => setData((prev) => ({ ...prev, company_name: e.target.value }))}
              placeholder="Mehta Spine & Physio Rehab"
            />
          </CrmFormField>

          <CrmFormField label="City" required error={errors.city}>
            <CrmInput
              value={data.city}
              onChange={(e) => setData((prev) => ({ ...prev, city: e.target.value }))}
              placeholder="Vadodara"
            />
          </CrmFormField>
        </div>

        <CrmFormField
          label="What they practise"
          required
          error={errors.healthcare_specialties}
          hint="Pick every one that applies."
        >
          <div className="flex flex-wrap gap-1.5">
            {specialties.map((specialty) => {
              const on = data.healthcare_specialties.includes(specialty.value);
              const tone = specialtyTone(specialty.value);

              return (
                <button key={specialty.value} type="button" onClick={() => toggleSpecialty(specialty.value)} aria-pressed={on}>
                  <CrmBadge
                    tone={tone}
                    variant={on ? 'solid' : 'soft'}
                    size="sm"
                    icon={on ? <Check className="h-3 w-3 shrink-0" aria-hidden="true" /> : undefined}
                    className={cn('cursor-pointer transition', !on && 'opacity-80 hover:opacity-100')}
                  >
                    {specialty.label}
                  </CrmBadge>
                </button>
              );
            })}
          </div>
        </CrmFormField>

        <CrmFormField
          label="Quick note"
          error={errors.notes}
          hint="What they said, in their words. Optional — becomes the first entry on the deal's timeline."
        >
          <CrmTextarea
            rows={2}
            value={data.notes}
            onChange={(e) => setData((prev) => ({ ...prev, notes: e.target.value }))}
            placeholder="Runs three sites, unhappy with current billing software, wants a demo next week."
          />
        </CrmFormField>
      </div>
    </CrmModal>
  );
}
