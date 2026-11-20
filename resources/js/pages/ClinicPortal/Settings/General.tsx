import { useRef, useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Settings as SettingsIcon } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Input, Select, Switch } from '@/clinic/ui/controls';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { humanize } from '@/clinic/ui/visuals';
import { SettingsTabs, type SettingsLinks } from './SettingsTabs';

type State = 'required' | 'optional' | 'hidden';

interface CaseFormat {
  prefix: string;
  show_year: boolean;
  year_style: string;
  separator: string;
  digits: number;
  start_number: number;
  reset_yearly: boolean;
}

/** `SettingsController::index`: the server is the contract. */
interface Props {
  settings_links: SettingsLinks;
  links: { branding: string; check_in: string; quick_care: string; patient_flow: string; case_number: string };
  branding: { display_name: string | null; name: string; logo_url: string | null };
  invoiceBranding: { legal_name: string | null; use_clinic_logo_for_invoice: boolean; invoice_logo_url: string | null; has_own_invoice_logo: boolean };
  patientFlow: { rooms_enabled: boolean };
  intake: {
    check_in: { visit_reason: State; priority: boolean; priorities: string[]; queue: boolean; show_fee: boolean; payment: 'after_consultation' | 'at_check_in' };
    quick_care: { allergy_status: State; alerts: boolean; alert_roles: string[] | null; conditions_from_catalogue: boolean };
    priorities: { value: string; label: string }[];
    queue_available: boolean;
    roles: { value: string; label: string }[];
  };
  caseNumber: {
    active: CaseFormat;
    pending: (Partial<CaseFormat> & { effective_from: string }) | null;
    next_period_start: string;
    highest_issued: number;
    recent_changes: { id: number; changed_by: string; changed_at: string }[];
  };
}

const CARD = 'rounded-2xl border border-cx-line/80 bg-white p-5 shadow-cx-card';
const STATES: { value: State; label: string }[] = [
  { value: 'required', label: 'Must be filled' },
  { value: 'optional', label: 'Optional' },
  { value: 'hidden', label: 'Not asked' },
];

export default function General(props: Props) {
  return (
    <ClinicPortalShell title="Settings">
      <GeneralPage {...props} />
    </ClinicPortalShell>
  );
}

function Section({ title, hint, children, onSave, saving, dirty }: { title: string; hint?: string; children: ReactNode; onSave: () => void; saving: boolean; dirty: boolean }) {
  return (
    <section className={CARD} aria-label={title}>
      <header className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-[15px] font-semibold text-cx-nav-ink">{title}</h2>
          {hint && <p className="mt-0.5 max-w-xl text-[12.5px] text-cx-text-3">{hint}</p>}
        </div>
        <Button tone="primary" onClick={onSave} loading={saving} disabled={!dirty}>Save</Button>
      </header>
      {children}
    </section>
  );
}

/** Settings that apply to the whole clinic: name and logo, the front desk, and how case numbers look. */
function GeneralPage(props: Props) {
  return (
    <div className="mx-auto w-full max-w-[1100px] pb-12">
      <SettingsTabs active="general" links={props.settings_links} />
      <PageHeader icon={SettingsIcon} title="Settings" description="How the clinic appears to patients, and how the front desk works." />
      <div className="space-y-4">
        <BrandingSection {...props} />
        <CheckInSection {...props} />
        <QuickCareSection {...props} />
        <CaseNumberSection {...props} />
      </div>
    </div>
  );
}

function BrandingSection({ branding, invoiceBranding, links }: Props) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [displayName, setDisplayName] = useState(branding.display_name ?? '');
  const [legalName, setLegalName] = useState(invoiceBranding.legal_name ?? '');
  const [useClinicLogo, setUseClinicLogo] = useState(invoiceBranding.use_clinic_logo_for_invoice);
  const [logo, setLogo] = useState<File | null>(null);
  const [invoiceLogo, setInvoiceLogo] = useState<File | null>(null);
  const [saving, setSaving] = useState(false);
  const logoInput = useRef<HTMLInputElement>(null);
  const invoiceInput = useRef<HTMLInputElement>(null);
  const dirty = displayName !== (branding.display_name ?? '') || legalName !== (invoiceBranding.legal_name ?? '') || useClinicLogo !== invoiceBranding.use_clinic_logo_for_invoice || logo !== null || invoiceLogo !== null;

  const save = () =>
    router.post(
      links.branding,
      { _method: 'put', display_name: displayName || '', legal_name: legalName || '', use_clinic_logo_for_invoice: useClinicLogo ? 1 : 0, ...(logo ? { logo } : {}), ...(invoiceLogo ? { invoice_logo: invoiceLogo } : {}) } as never,
      { forceFormData: true, preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false), onSuccess: () => { setLogo(null); setInvoiceLogo(null); } },
    );

  return (
    <Section title="Name and logo" hint="Shown on the sign-in page and in the top bar. The legal name and invoice logo are printed on bills." onSave={save} saving={saving} dirty={dirty}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Name shown to patients" htmlFor="gn-display" error={errors.display_name} hint={`Leave empty to use “${branding.name}”.`}>
          <Input id="gn-display" value={displayName} maxLength={255} onChange={(e) => setDisplayName(e.target.value)} />
        </Field>
        <Field label="Legal name on invoices" htmlFor="gn-legal" error={errors.legal_name}>
          <Input id="gn-legal" value={legalName} maxLength={255} onChange={(e) => setLegalName(e.target.value)} />
        </Field>
        <div>
          <p className="mb-1.5 text-[12px] font-semibold text-[#173B60]">Clinic logo</p>
          <div className="flex items-center gap-3">
            {branding.logo_url && <img src={branding.logo_url} alt="" className="h-12 w-12 rounded-lg border border-cx-line/80 object-contain" />}
            <input ref={logoInput} type="file" hidden accept="image/png,image/jpeg,image/webp" onChange={(e) => setLogo(e.target.files?.[0] ?? null)} />
            <Button size="sm" onClick={() => logoInput.current?.click()}>{logo ? logo.name : branding.logo_url ? 'Change logo' : 'Upload logo'}</Button>
          </div>
          {errors.logo && <p role="alert" className="mt-1.5 text-[12px] font-medium text-[#B42318]">{errors.logo}</p>}
        </div>
        <div>
          <Checkbox checked={useClinicLogo} onCheckedChange={setUseClinicLogo} label="Use the clinic logo on invoices" />
          {!useClinicLogo && (
            <div className="mt-3 flex items-center gap-3">
              {invoiceBranding.invoice_logo_url && <img src={invoiceBranding.invoice_logo_url} alt="" className="h-12 w-12 rounded-lg border border-cx-line/80 object-contain" />}
              <input ref={invoiceInput} type="file" hidden accept="image/png,image/jpeg,image/webp" onChange={(e) => setInvoiceLogo(e.target.files?.[0] ?? null)} />
              <Button size="sm" onClick={() => invoiceInput.current?.click()}>{invoiceLogo ? invoiceLogo.name : invoiceBranding.has_own_invoice_logo ? 'Change invoice logo' : 'Upload invoice logo'}</Button>
            </div>
          )}
          {errors.invoice_logo && <p role="alert" className="mt-1.5 text-[12px] font-medium text-[#B42318]">{errors.invoice_logo}</p>}
        </div>
      </div>
    </Section>
  );
}

function CheckInSection({ intake, patientFlow, links }: Props) {
  const c = intake.check_in;
  const [f, setF] = useState({ ...c, priorities: c.priorities });
  const [rooms, setRooms] = useState(patientFlow.rooms_enabled);
  const [saving, setSaving] = useState(false);
  const dirty = JSON.stringify(f) !== JSON.stringify(c) || rooms !== patientFlow.rooms_enabled;
  const flip = (value: string, on: boolean) => setF((cur) => ({ ...cur, priorities: on ? [...cur.priorities, value] : cur.priorities.filter((p) => p !== value) }));

  const save = () => {
    const done = { preserveScroll: true, onFinish: () => setSaving(false) };
    setSaving(true);
    const after = () => (rooms !== patientFlow.rooms_enabled ? router.put(links.patient_flow, { rooms_enabled: rooms }, done) : setSaving(false));
    if (JSON.stringify(f) !== JSON.stringify(c)) {
      router.put(links.check_in, f as never, { preserveScroll: true, onSuccess: after, onError: () => setSaving(false) });
    } else {
      after();
    }
  };

  return (
    <Section title="Front desk and check-in" hint="What the desk asks when a patient arrives, and how the fee is collected." onSave={save} saving={saving} dirty={dirty}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Reason for the visit" htmlFor="ci-reason">
          <Select id="ci-reason" value={f.visit_reason} onChange={(e) => setF({ ...f, visit_reason: e.target.value as State })} options={STATES} />
        </Field>
        <Field label="Consultation fee is collected" htmlFor="ci-pay">
          <Select id="ci-pay" value={f.payment} onChange={(e) => setF({ ...f, payment: e.target.value as typeof f.payment })} options={[{ value: 'after_consultation', label: 'After the consultation' }, { value: 'at_check_in', label: 'At check-in' }]} />
        </Field>
      </div>
      <div className="mt-5 grid gap-4 sm:grid-cols-2">
        <Switch checked={f.show_fee} onCheckedChange={(on) => setF({ ...f, show_fee: on })} label="Show the doctor's fee at the desk" />
        <Switch checked={f.queue} disabled={!intake.queue_available} onCheckedChange={(on) => setF({ ...f, queue: on })} label="Show queue positions" description={intake.queue_available ? undefined : 'The queue is not part of your plan.'} />
        <Switch checked={rooms} onCheckedChange={setRooms} label="Show rooms and chairs" description="When off, rooms are hidden in the queue, check-in and booking." />
        <Switch checked={f.priority} onCheckedChange={(on) => setF({ ...f, priority: on })} label="Let the desk mark a patient's priority" />
      </div>
      {f.priority && (
        <fieldset className="mt-5">
          <legend className="mb-2 text-[12px] font-semibold text-[#173B60]">Priorities offered</legend>
          <div className="flex flex-wrap gap-x-6 gap-y-2">
            {intake.priorities.map((p) => <Checkbox key={p.value} checked={f.priorities.includes(p.value)} disabled={p.value === 'normal'} onCheckedChange={(on) => flip(p.value, on)} label={p.label} />)}
          </div>
        </fieldset>
      )}
    </Section>
  );
}

function QuickCareSection({ intake, links }: Props) {
  const q = intake.quick_care;
  const [f, setF] = useState(q);
  const [saving, setSaving] = useState(false);
  const dirty = JSON.stringify(f) !== JSON.stringify(q);
  const limited = f.alert_roles !== null;
  const flip = (value: string, on: boolean) => setF((cur) => ({ ...cur, alert_roles: on ? [...(cur.alert_roles ?? []), value] : (cur.alert_roles ?? []).filter((r) => r !== value) }));

  return (
    <Section
      title="Safety notes at the desk"
      hint="Allergy status and alerts the front desk can record when a patient comes in."
      saving={saving}
      dirty={dirty}
      onSave={() => router.put(links.quick_care, f as never, { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) })}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Allergy status" htmlFor="qc-allergy">
          <Select id="qc-allergy" value={f.allergy_status} onChange={(e) => setF({ ...f, allergy_status: e.target.value as State })} options={STATES} />
        </Field>
        <div className="space-y-4 pt-1 sm:pt-6">
          <Switch checked={f.alerts} onCheckedChange={(on) => setF({ ...f, alerts: on })} label="Allow alerts on a patient" />
          <Switch checked={f.conditions_from_catalogue} onCheckedChange={(on) => setF({ ...f, conditions_from_catalogue: on })} label="Medical conditions only from the clinic's own list" />
        </div>
      </div>
      {f.alerts && (
        <fieldset className="mt-5">
          <legend className="mb-2 text-[12px] font-semibold text-[#173B60]">Who may set an alert</legend>
          <Checkbox checked={!limited} onCheckedChange={(any) => setF({ ...f, alert_roles: any ? null : [] })} label="Anyone on the team" />
          {limited && (
            <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 pl-8">
              {intake.roles.map((r) => <Checkbox key={r.value} checked={(f.alert_roles ?? []).includes(r.value)} onCheckedChange={(on) => flip(r.value, on)} label={humanize(r.label)} />)}
            </div>
          )}
        </fieldset>
      )}
    </Section>
  );
}

const yearLabel = (style: string) => {
  const year = new Date().getFullYear();
  const next = String(year + 1).slice(2);

  return style === 'YY' ? String(year).slice(2) : style === 'FY' ? `${year}-${next}` : style === 'FY_SHORT' ? `${String(year).slice(2)}-${next}` : String(year);
};

function CaseNumberSection({ caseNumber, links }: Props) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const a = caseNumber.active;
  const [f, setF] = useState({ ...a, separator: a.separator ?? '' });
  const [saving, setSaving] = useState(false);
  const dirty = JSON.stringify(f) !== JSON.stringify({ ...a, separator: a.separator ?? '' });
  const example = [f.prefix, f.show_year ? yearLabel(f.year_style) : null, String(f.start_number > caseNumber.highest_issued ? f.start_number : caseNumber.highest_issued + 1).padStart(f.digits, '0')].filter(Boolean).join(f.separator);

  return (
    <Section
      title="Case number format"
      hint="Prefix, separator, digits and start number apply to the next number issued. Year changes apply from the next period. Numbers already issued never change."
      saving={saving}
      dirty={dirty}
      onSave={() => router.put(links.case_number, { ...f, separator: f.separator || null } as never, { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) })}
    >
      <p className="mb-4 rounded-xl bg-cx-slate-bg/60 px-4 py-3 text-[13px] text-cx-text-2">Next number will look like <span className="ml-1 font-mono text-[14px] font-semibold text-cx-nav-ink">{example}</span></p>
      {caseNumber.pending && <Notice tone="info" className="mb-4">A year change is waiting and starts on {caseNumber.pending.effective_from}.</Notice>}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Field label="Prefix" htmlFor="cn-prefix" error={errors.prefix}><Input id="cn-prefix" value={f.prefix} maxLength={10} onChange={(e) => setF({ ...f, prefix: e.target.value.toUpperCase() })} /></Field>
        <Field label="Separator" htmlFor="cn-sep"><Select id="cn-sep" value={f.separator} onChange={(e) => setF({ ...f, separator: e.target.value })} options={[{ value: '-', label: 'Dash  -' }, { value: '/', label: 'Slash  /' }, { value: '.', label: 'Dot  .' }, { value: '', label: 'None' }]} /></Field>
        <Field label="Digits" htmlFor="cn-digits" error={errors.digits}><Input id="cn-digits" type="number" min={4} max={8} value={f.digits} onChange={(e) => setF({ ...f, digits: Number(e.target.value) })} /></Field>
        <Field label="Start number" htmlFor="cn-start" error={errors.start_number} hint={caseNumber.highest_issued > 0 ? `Highest issued so far: ${caseNumber.highest_issued}` : undefined}><Input id="cn-start" type="number" min={1} value={f.start_number} onChange={(e) => setF({ ...f, start_number: Number(e.target.value) })} /></Field>
      </div>
      <div className="mt-5 grid gap-4 sm:grid-cols-2">
        <Switch checked={f.show_year} onCheckedChange={(on) => setF({ ...f, show_year: on, reset_yearly: on ? f.reset_yearly : false })} label="Show the year in the number" />
        <Switch checked={f.reset_yearly} disabled={!f.show_year} onCheckedChange={(on) => setF({ ...f, reset_yearly: on })} label="Restart the count every year" description={errors.reset_yearly} />
      </div>
      {f.show_year && (
        <div className="mt-4 w-full sm:w-72">
          <Field label="Year style" htmlFor="cn-style"><Select id="cn-style" value={f.year_style} onChange={(e) => setF({ ...f, year_style: e.target.value })} options={[{ value: 'YYYY', label: '2026' }, { value: 'YY', label: '26' }, { value: 'FY', label: 'Financial year  2026-27' }, { value: 'FY_SHORT', label: 'Financial year  26-27' }]} /></Field>
        </div>
      )}
    </Section>
  );
}
