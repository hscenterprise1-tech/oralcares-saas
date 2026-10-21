import React from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, MessageCircle, PauseCircle, Plus, RefreshCw, Search, Settings2, Sparkles } from 'lucide-react';
import {
  AdministrationShell,
  CrmButton,
  CrmCard,
  CrmCardIcon,
  CrmFormField,
  CrmInput,
  CrmModal,
  CrmStatusPill,
  CrmTextarea,
  type CrmStatusTone,
} from '@/pages/Company/design';

interface Connection {
  id: number;
  mode: 'company_shared' | 'clinic_owned';
  clinic: { id: number; name: string } | null;
  label: string;
  number: string | null;
  phone_number_id: string | null;
  business_account_id: string | null;
  has_access_token: boolean;
  has_verify_token: boolean;
  status: 'pending' | 'active' | 'disabled' | 'error';
  health: string | null;
  checked_at: string | null;
  activated_at: string | null;
  templates: number;
  week: Record<string, number>;
}

interface Props {
  connections: Connection[];
  requests: { clinic_id: number; clinic: string | null; requested_at: string | null; note: string | null }[];
  automations: { key: string; label: string; body: string; variables: string[] }[];
  webhook_url: string;
  can_manage: boolean;
}

const STATUS: Record<Connection['status'], { label: string; tone: CrmStatusTone }> = {
  active: { label: 'Active', tone: 'good' },
  pending: { label: 'Not activated', tone: 'warn' },
  disabled: { label: 'Disabled', tone: 'neutral' },
  error: { label: 'Error', tone: 'bad' },
};

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

type Draft = { id?: number; mode: Connection['mode']; clinic_id: number | null; clinic_name: string; label: string; display_phone_number: string; phone_number_id: string; business_account_id: string; access_token: string; webhook_verify_token: string };

const blank = (mode: Connection['mode'], clinic?: { id: number; name: string | null }): Draft => ({
  mode, clinic_id: clinic?.id ?? null, clinic_name: clinic?.name ?? '', label: mode === 'company_shared' ? 'OralCares Common WhatsApp' : `${clinic?.name ?? 'Clinic'} WhatsApp`,
  display_phone_number: '', phone_number_id: '', business_account_id: '', access_token: '', webhook_verify_token: '',
});

/**
 * Company → Clinic WhatsApp. OralCares runs every number clinics send on:
 * the common number (Common mode) and each clinic's own number (Own mode),
 * set up and activated here. Credentials are entered here and never shown
 * back. OralCares' own sales WhatsApp is Marketing's, not this page's.
 */
export default function ClinicWhatsApp({ connections, requests, automations, webhook_url, can_manage }: Props) {
  const errors = usePage<{ errors: Record<string, string> }>().props.errors ?? {};
  const [draft, setDraft] = React.useState<Draft | null>(null);
  const [template, setTemplate] = React.useState<{ connection: Connection; key: string; name: string; body: string } | null>(null);
  const shared = connections.filter((c) => c.mode === 'company_shared');
  const owned = connections.filter((c) => c.mode === 'clinic_owned');
  const post = (url: string) => router.post(url, {}, { preserveScroll: true });

  const card = (c: Connection) => (
    <CrmCard key={c.id} className="p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <CrmCardIcon icon={MessageCircle} />
          <div className="min-w-0">
            <h3 className="truncate text-[15px] font-semibold text-ovs-crm-ink">{c.label}</h3>
            <p className="text-[12.5px] text-ovs-crm-text-secondary">{c.clinic ? c.clinic.name : 'Shared by every clinic in Common mode'}</p>
          </div>
        </div>
        <CrmStatusPill tone={STATUS[c.status].tone}>{STATUS[c.status].label}</CrmStatusPill>
      </div>
      <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-[12.5px] sm:grid-cols-4">
        {[
          ['Number', c.number ?? '—'],
          ['Phone number ID', c.phone_number_id ?? '—'],
          ['Business account', c.business_account_id ?? '—'],
          ['Access token', c.has_access_token ? 'Stored' : 'Missing'],
          ['Webhook token', c.has_verify_token ? 'Stored' : 'Uses the platform token'],
          ['Templates', String(c.templates)],
          ['Last check', c.health ? `${c.health} ${when(c.checked_at)}` : '—'],
          ['Last 7 days', `${(c.week.sent ?? 0) + (c.week.delivered ?? 0) + (c.week.read ?? 0)} sent · ${c.week.failed ?? 0} failed`],
        ].map(([label, value]) => (
          <div key={label} className="min-w-0">
            <dt className="text-[11px] text-ovs-crm-text-secondary">{label}</dt>
            <dd className="truncate font-medium text-ovs-crm-ink">{value}</dd>
          </div>
        ))}
      </dl>
      {can_manage && (
        <div className="mt-4 flex flex-wrap gap-2">
          <CrmButton size="sm" icon={Settings2} onClick={() => setDraft({ ...blank(c.mode, c.clinic ?? undefined), id: c.id, label: c.label, display_phone_number: c.number ?? '', phone_number_id: c.phone_number_id ?? '', business_account_id: c.business_account_id ?? '' })}>Configure</CrmButton>
          {c.status !== 'active' ? (
            <CrmButton size="sm" tone="primary" icon={CheckCircle2} onClick={() => post(`/company/clinic-whatsapp/connections/${c.id}/activate`)}>Activate</CrmButton>
          ) : (
            <CrmButton size="sm" icon={PauseCircle} onClick={() => post(`/company/clinic-whatsapp/connections/${c.id}/disable`)}>Disable</CrmButton>
          )}
          <CrmButton size="sm" icon={RefreshCw} onClick={() => post(`/company/clinic-whatsapp/connections/${c.id}/check`)}>Check connection</CrmButton>
          <CrmButton size="sm" icon={Sparkles} onClick={() => post(`/company/clinic-whatsapp/connections/${c.id}/templates/defaults`)}>Register default templates</CrmButton>
          <CrmButton size="sm" icon={Plus} onClick={() => setTemplate({ connection: c, key: automations[0]?.key ?? '', name: `oc_${automations[0]?.key ?? ''}`, body: automations[0]?.body ?? '' })}>Template</CrmButton>
        </div>
      )}
    </CrmCard>
  );

  return (
    <AdministrationShell title="Clinic WhatsApp">
      <Head title="Clinic WhatsApp | Company" />
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div className="min-w-0">
          <h1 className="text-[26px] font-semibold tracking-tight text-ovs-crm-ink">Clinic WhatsApp</h1>
          <p className="max-w-2xl text-[13px] text-ovs-crm-text-secondary">The numbers clinics message their patients on. Clinics choose Common or their own number and which messages go out; OralCares sets up, activates and watches every number here. Patient messages never use the Marketing number.</p>
        </div>
        {can_manage && <CrmButton tone="primary" icon={Plus} onClick={() => setDraft(blank('company_shared'))}>Add common number</CrmButton>}
      </div>

      {errors.whatsapp && <p role="alert" className="mb-4 rounded-2xl bg-rose-50 px-4 py-3 text-[13px] text-rose-800">{errors.whatsapp}</p>}

      <CrmCard className="mb-5 p-5">
        <h2 className="text-[13px] font-semibold text-ovs-crm-ink">Webhook for clinic numbers</h2>
        <p className="mt-1 break-all font-mono text-[12.5px] text-ovs-crm-ink">{webhook_url}</p>
        <p className="mt-1 text-[12px] text-ovs-crm-text-secondary">Set this as the callback URL of the Meta app that holds the clinic numbers, with the platform verify token or the number’s own.</p>
      </CrmCard>

      {requests.length > 0 && (
        <section className="mb-6" aria-labelledby="requests">
          <h2 id="requests" className="mb-2 text-[15px] font-semibold text-ovs-crm-ink">Clinics waiting for their own number</h2>
          <div className="grid gap-3 md:grid-cols-2">
            {requests.map((r) => (
              <CrmCard key={r.clinic_id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                <div className="min-w-0">
                  <p className="font-medium text-ovs-crm-ink">{r.clinic ?? `Clinic ${r.clinic_id}`}</p>
                  <p className="text-[12px] text-ovs-crm-text-secondary">Requested {when(r.requested_at)}{r.note ? ` · ${r.note}` : ''}</p>
                </div>
                {can_manage && <CrmButton size="sm" tone="primary" icon={Settings2} onClick={() => setDraft(blank('clinic_owned', { id: r.clinic_id, name: r.clinic }))}>Set up number</CrmButton>}
              </CrmCard>
            ))}
          </div>
        </section>
      )}

      <section className="mb-6 space-y-3" aria-labelledby="common">
        <h2 id="common" className="text-[15px] font-semibold text-ovs-crm-ink">OralCares Common WhatsApp</h2>
        {shared.length === 0 ? <CrmCard className="p-5 text-[13px] text-ovs-crm-text-secondary">No common number yet. Clinics in Common mode send nothing until one is active.</CrmCard> : shared.map(card)}
      </section>

      <section className="space-y-3" aria-labelledby="owned">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="owned" className="text-[15px] font-semibold text-ovs-crm-ink">Clinics’ own numbers</h2>
          {can_manage && <CrmButton size="sm" icon={Plus} onClick={() => setDraft(blank('clinic_owned'))}>Add a clinic’s number</CrmButton>}
        </div>
        {owned.length === 0 ? <CrmCard className="p-5 text-[13px] text-ovs-crm-text-secondary">No clinic has its own number yet.</CrmCard> : owned.map(card)}
      </section>

      {draft && <ConnectionDialog draft={draft} onClose={() => setDraft(null)} />}
      {template && (
        <CrmModal open onClose={() => setTemplate(null)} title={`Template — ${template.connection.label}`} description="The name and text approved for this number in Meta. {{1}} is always the clinic’s name."
          footer={<>
            <CrmButton tone="ghost" onClick={() => setTemplate(null)}>Cancel</CrmButton>
            <CrmButton tone="primary" onClick={() => router.post(`/company/clinic-whatsapp/connections/${template.connection.id}/templates`, { automation_key: template.key, name: template.name, body: template.body }, { preserveScroll: true, onSuccess: () => setTemplate(null) })}>Save template</CrmButton>
          </>}>
          <div className="space-y-3">
            <CrmFormField label="Message">
              <select className="h-10 w-full rounded-xl border border-ovs-crm-border bg-white px-3 text-[13px]" value={template.key}
                onChange={(e) => { const a = automations.find((x) => x.key === e.target.value); setTemplate({ ...template, key: e.target.value, name: `oc_${e.target.value}`, body: a?.body ?? '' }); }}>
                {automations.map((a) => <option key={a.key} value={a.key}>{a.label}</option>)}
              </select>
            </CrmFormField>
            <CrmFormField label="Template name in Meta" error={errors.name}><CrmInput value={template.name} onChange={(e) => setTemplate({ ...template, name: e.target.value })} /></CrmFormField>
            <CrmFormField label="Approved text" hint={`Values: ${automations.find((a) => a.key === template.key)?.variables.map((v, i) => `{{${i + 1}}} ${v}`).join(', ')}`} error={errors.body}>
              <CrmTextarea rows={4} value={template.body} onChange={(e) => setTemplate({ ...template, body: e.target.value })} />
            </CrmFormField>
          </div>
        </CrmModal>
      )}
    </AdministrationShell>
  );
}

function ConnectionDialog({ draft: initial, onClose }: { draft: Draft; onClose: () => void }) {
  const errors = usePage<{ errors: Record<string, string> }>().props.errors ?? {};
  const [draft, setDraft] = React.useState(initial);
  const [query, setQuery] = React.useState(initial.clinic_name);
  const [matches, setMatches] = React.useState<{ id: number; name: string }[]>([]);
  const [busy, setBusy] = React.useState(false);
  const set = (key: keyof Draft, value: string | number | null) => setDraft((d) => ({ ...d, [key]: value }));
  const search = async (q: string) => {
    setQuery(q);
    if (q.trim().length < 2) {
      setMatches([]);

      return;
    }
    const response = await fetch(`/company/clinic-whatsapp/clinics?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' } });
    setMatches(response.ok ? (await response.json()).data : []);
  };
  const save = () => {
    setBusy(true);
    const { id, clinic_name: _ignored, ...data } = draft;
    const options = { preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false) };
    id ? router.put(`/company/clinic-whatsapp/connections/${id}`, data, options) : router.post('/company/clinic-whatsapp/connections', data, options);
  };

  return (
    <CrmModal open onClose={onClose} size="lg" title={draft.mode === 'company_shared' ? 'OralCares common number' : 'A clinic’s own number'}
      description="From the WhatsApp Business account in Meta. Leave a token blank to keep the stored one."
      footer={<>
        <CrmButton tone="ghost" onClick={onClose}>Cancel</CrmButton>
        <CrmButton tone="primary" loading={busy} onClick={save}>Save</CrmButton>
      </>}>
      <div className="grid gap-3 sm:grid-cols-2">
        {draft.mode === 'clinic_owned' && (
          <CrmFormField label="Clinic" error={errors.clinic_id} className="sm:col-span-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-ovs-crm-text-secondary" aria-hidden="true" />
              <CrmInput className="pl-9" value={query} onChange={(e) => search(e.target.value)} placeholder="Search clinics by name" disabled={Boolean(draft.id)} />
              {matches.length > 0 && (
                <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-ovs-crm-border bg-white py-1 shadow-lg">
                  {matches.map((m) => (
                    <li key={m.id}><button type="button" className="w-full px-3 py-2 text-left text-[13px] hover:bg-slate-50" onClick={() => { set('clinic_id', m.id); setQuery(m.name); setMatches([]); }}>{m.name}</button></li>
                  ))}
                </ul>
              )}
            </div>
          </CrmFormField>
        )}
        <CrmFormField label="Label" error={errors.label}><CrmInput value={draft.label} onChange={(e) => set('label', e.target.value)} /></CrmFormField>
        <CrmFormField label="Display number" hint="As patients see it, e.g. 919876543210" error={errors.display_phone_number}><CrmInput value={draft.display_phone_number} onChange={(e) => set('display_phone_number', e.target.value)} /></CrmFormField>
        <CrmFormField label="Phone number ID" error={errors.phone_number_id}><CrmInput value={draft.phone_number_id} onChange={(e) => set('phone_number_id', e.target.value)} /></CrmFormField>
        <CrmFormField label="WhatsApp Business account ID" error={errors.business_account_id}><CrmInput value={draft.business_account_id} onChange={(e) => set('business_account_id', e.target.value)} /></CrmFormField>
        <CrmFormField label="Access token" hint="Write-only" error={errors.access_token}><CrmInput type="password" autoComplete="off" value={draft.access_token} onChange={(e) => set('access_token', e.target.value)} /></CrmFormField>
        <CrmFormField label="Webhook verify token" hint="Optional; write-only" error={errors.webhook_verify_token}><CrmInput type="password" autoComplete="off" value={draft.webhook_verify_token} onChange={(e) => set('webhook_verify_token', e.target.value)} /></CrmFormField>
      </div>
    </CrmModal>
  );
}
