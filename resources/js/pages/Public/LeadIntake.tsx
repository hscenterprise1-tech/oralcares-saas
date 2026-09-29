import React from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Check, Loader2 } from 'lucide-react';

/**
 * The enquiry form, and the only page in the product a stranger ever sees.
 *
 * Deliberately not built on the CRM shell: that kit dresses an internal
 * workspace — sidebar, module rail, dense tables — and none of it means
 * anything to someone who has never logged in. What carries over is the design
 * language itself, so the form and the product it leads to look like the same
 * company made both.
 *
 * Short on purpose. Every field past the third costs submissions, so this asks
 * only what a first call cannot start without: who you are, how to reach you,
 * and what kind of practice you run. Everything else gets asked on the call.
 */

interface ClinicTypeOption {
  value: string;
  label: string;
}

interface PageProps {
  clinicTypes: ClinicTypeOption[];
  [key: string]: unknown;
}

export default function LeadIntake() {
  const { props } = usePage<PageProps>();
  const flash = (props.flash ?? {}) as { success?: string };

  const form = useForm({
    company_name: '',
    contact_name: '',
    phone: '',
    email: '',
    city: '',
    healthcare_specialty: '',
    message: '',
    /** The honeypot. Never shown, never filled by a person. */
    website_url: '',
  });

  const [submitted, setSubmitted] = React.useState(false);

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    form.post('/enquiry', {
      preserveScroll: true,
      onSuccess: () => {
        setSubmitted(true);
        form.reset();
      },
    });
  };

  if (submitted || flash.success) {
    return (
      <>
        <Head title="Thanks — OralCares" />
        <Shell>
          <div className="text-center">
            <span className="mx-auto mb-5 flex h-12 w-12 items-center justify-center rounded-full bg-[#E6F3EC]">
              <Check className="h-5 w-5 text-[#1F7A4C]" aria-hidden="true" />
            </span>
            <h1 className="mb-3 text-[28px] font-medium leading-tight tracking-[-0.02em] text-[#0B0B0F]">
              Thanks — we have it.
            </h1>
            <p className="mx-auto max-w-[42ch] text-[15px] leading-relaxed text-[#565F73]">
              Someone from our team will call you shortly. We have your number and your
              enquiry is already with them.
            </p>
          </div>
        </Shell>
      </>
    );
  }

  return (
    <>
      <Head title="Book a demo — OralCares" />
      <Shell>
        <div className="mb-8">
          <p className="mb-3 font-mono text-[11px] uppercase tracking-[0.14em] text-[#98A0B3]">
            OralCares
          </p>
          <h1 className="mb-3 text-[clamp(28px,5vw,40px)] font-medium leading-[1.08] tracking-[-0.02em] text-[#0B0B0F]">
            See it running on your own practice.
          </h1>
          <p className="max-w-[46ch] text-[15.5px] leading-relaxed text-[#565F73]">
            One system for appointments, records, billing and pharmacy — dental, IVF, physiotherapy
            or multi-speciality. Tell us where to reach you and we will walk you through it.
          </p>
        </div>

        <form onSubmit={submit} noValidate className="space-y-4">
          {/*
            * The honeypot. Hidden from sight and from screen readers, and
            * excluded from tab order, so no real visitor can reach it.
            */}
          <div className="hidden" aria-hidden="true">
            <label htmlFor="website_url">Leave this empty</label>
            <input
              id="website_url"
              type="text"
              tabIndex={-1}
              autoComplete="off"
              value={form.data.website_url}
              onChange={(e) => form.setData('website_url', e.target.value)}
            />
          </div>

          <Field label="Clinic or hospital name" error={form.errors.company_name} required>
            <input
              type="text"
              value={form.data.company_name}
              onChange={(e) => form.setData('company_name', e.target.value)}
              placeholder="Sunrise Dental Care"
              autoComplete="organization"
              className={inputClass}
            />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Your name" error={form.errors.contact_name} required>
              <input
                type="text"
                value={form.data.contact_name}
                onChange={(e) => form.setData('contact_name', e.target.value)}
                placeholder="Dr. Ananya Rao"
                autoComplete="name"
                className={inputClass}
              />
            </Field>

            <Field label="Phone" error={form.errors.phone} required>
              <input
                type="tel"
                value={form.data.phone}
                onChange={(e) => form.setData('phone', e.target.value)}
                placeholder="98765 43210"
                autoComplete="tel"
                inputMode="tel"
                className={inputClass}
              />
            </Field>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Email" error={form.errors.email} hint="Optional">
              <input
                type="email"
                value={form.data.email}
                onChange={(e) => form.setData('email', e.target.value)}
                placeholder="you@clinic.com"
                autoComplete="email"
                className={inputClass}
              />
            </Field>

            <Field label="City" error={form.errors.city} hint="Optional">
              <input
                type="text"
                value={form.data.city}
                onChange={(e) => form.setData('city', e.target.value)}
                placeholder="Ahmedabad"
                autoComplete="address-level2"
                className={inputClass}
              />
            </Field>
          </div>

          <Field
            label="What kind of practice?"
            error={form.errors.healthcare_specialty}
            hint="Optional"
          >
            <select
              value={form.data.healthcare_specialty}
              onChange={(e) => form.setData('healthcare_specialty', e.target.value)}
              className={inputClass}
            >
              <option value="">Choose one</option>
              {(props.clinicTypes ?? []).map((type) => (
                <option key={type.value} value={type.value}>
                  {type.label}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Anything we should know?" error={form.errors.message} hint="Optional">
            <textarea
              rows={3}
              value={form.data.message}
              onChange={(e) => form.setData('message', e.target.value)}
              placeholder="Two branches, currently on paper records…"
              className={`${inputClass} resize-y`}
            />
          </Field>

          <button
            type="submit"
            disabled={form.processing}
            className="group inline-flex w-full items-center justify-center gap-2 rounded-full bg-[#0B0B0F] px-6 py-3.5 text-[14px] font-medium text-white transition-opacity hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0B0B0F] disabled:opacity-60 sm:w-auto"
          >
            {form.processing ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                Sending
              </>
            ) : (
              <>
                Request a demo
                <ArrowRight
                  className="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                  aria-hidden="true"
                />
              </>
            )}
          </button>

          <p className="pt-1 text-[12.5px] text-[#98A0B3]">
            We will only use these details to contact you about OralCares.
          </p>
        </form>
      </Shell>
    </>
  );
}

const inputClass =
  'w-full rounded-[12px] border border-[#E4E8EF] bg-white px-4 py-3 text-[14.5px] text-[#24262E] ' +
  'placeholder:text-[#98A0B3] transition-colors focus:border-[#0B0B0F] focus:outline-none';

/**
 * The page ground, kept to the product's own canvas so the form and the
 * software behind it read as one company.
 */
function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen bg-[#F4F6F8] px-5 py-12 sm:py-20">
      <div className="mx-auto w-full max-w-[560px] rounded-[24px] bg-white p-7 shadow-[0_1px_2px_rgba(11,11,15,0.03),0_10px_30px_-18px_rgba(11,11,15,0.18)] sm:p-10">
        {children}
      </div>
    </div>
  );
}

function Field({
  label,
  hint,
  error,
  required,
  children,
}: {
  label: string;
  hint?: string;
  error?: string;
  required?: boolean;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-1.5 flex items-baseline justify-between gap-3">
        <span className="text-[13px] font-medium text-[#24262E]">
          {label}
          {required && <span className="ml-1 text-[#C4700F]">*</span>}
        </span>
        {hint && <span className="text-[11.5px] text-[#98A0B3]">{hint}</span>}
      </span>
      {children}
      {error && <span className="mt-1.5 block text-[12.5px] text-[#B3261E]">{error}</span>}
    </label>
  );
}
