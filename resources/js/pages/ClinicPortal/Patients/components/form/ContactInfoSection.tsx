import { useId } from 'react';
import { Mail, MapPin, Phone } from 'lucide-react';
import { Field, Input } from '@/clinic/ui/controls';
import { SectionCard } from './SectionCard';

export interface ContactInfoData {
  phone: string;
  email: string;
  address: string;
  city: string;
  state: string;
  pincode: string;
}

interface ContactInfoSectionProps {
  data: ContactInfoData;
  onChange: <K extends keyof ContactInfoData>(field: K, value: ContactInfoData[K]) => void;
  errors: Partial<Record<keyof ContactInfoData, string>>;
}

export function ContactInfoSection({ data, onChange, errors }: ContactInfoSectionProps) {
  const baseId = useId();

  return (
    <SectionCard
      id="section-contact-info"
      title="Contact Details & Address"
      subtitle="Primary communication number and residential location."
      icon={Phone}
      tone="teal"
      badge="Contact"
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-6">
        {/* Primary Mobile */}
        <div className="sm:col-span-3">
          <Field
            label="Primary Mobile Number"
            htmlFor={`${baseId}-phone`}
            required
            error={errors.phone}
            hint="Primary line for clinic updates and appointment notices"
          >
            <div className="relative">
              <Input
                id={`${baseId}-phone`}
                type="tel"
                inputMode="tel"
                value={data.phone}
                onChange={(e) => onChange('phone', e.target.value)}
                placeholder="e.g. 98765 43210"
                autoComplete="tel"
                invalid={!!errors.phone}
                className="h-10 pl-9 text-[14px]"
              />
              <Phone className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            </div>
          </Field>
        </div>

        {/* Email Address */}
        <div className="sm:col-span-3">
          <Field
            label="Email Address"
            htmlFor={`${baseId}-email`}
            error={errors.email}
            hint="Optional; for invoices, receipts and digital summaries"
          >
            <div className="relative">
              <Input
                id={`${baseId}-email`}
                type="email"
                inputMode="email"
                value={data.email}
                onChange={(e) => onChange('email', e.target.value)}
                placeholder="e.g. rahul.sharma@example.com"
                autoComplete="email"
                invalid={!!errors.email}
                className="h-10 pl-9 text-[14px]"
              />
              <Mail className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            </div>
          </Field>
        </div>

        {/* Address */}
        <div className="sm:col-span-6">
          <Field
            label="Address (Street / House / Locality)"
            htmlFor={`${baseId}-address`}
            error={errors.address}
          >
            <div className="relative">
              <Input
                id={`${baseId}-address`}
                value={data.address}
                onChange={(e) => onChange('address', e.target.value)}
                placeholder="e.g. Flat 402, Sunshine Apartments, MG Road"
                autoComplete="street-address"
                invalid={!!errors.address}
                className="h-10 pl-9 text-[14px]"
              />
              <MapPin className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            </div>
          </Field>
        </div>

        {/* City */}
        <div className="sm:col-span-2">
          <Field label="City" htmlFor={`${baseId}-city`} error={errors.city}>
            <Input
              id={`${baseId}-city`}
              value={data.city}
              onChange={(e) => onChange('city', e.target.value)}
              placeholder="e.g. Mumbai"
              autoComplete="address-level2"
              invalid={!!errors.city}
              className="h-10 text-[14px]"
            />
          </Field>
        </div>

        {/* State */}
        <div className="sm:col-span-2">
          <Field label="State" htmlFor={`${baseId}-state`} error={errors.state}>
            <Input
              id={`${baseId}-state`}
              value={data.state}
              onChange={(e) => onChange('state', e.target.value)}
              placeholder="e.g. Maharashtra"
              autoComplete="address-level1"
              invalid={!!errors.state}
              className="h-10 text-[14px]"
            />
          </Field>
        </div>

        {/* PIN Code */}
        <div className="sm:col-span-2">
          <Field label="PIN Code" htmlFor={`${baseId}-pincode`} error={errors.pincode}>
            <Input
              id={`${baseId}-pincode`}
              inputMode="numeric"
              maxLength={10}
              value={data.pincode}
              onChange={(e) => onChange('pincode', e.target.value.replace(/\D/g, '').slice(0, 6))}
              placeholder="e.g. 400001"
              autoComplete="postal-code"
              invalid={!!errors.pincode}
              className="h-10 text-[14px]"
            />
          </Field>
        </div>
      </div>
    </SectionCard>
  );
}
