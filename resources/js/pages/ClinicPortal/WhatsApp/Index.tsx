import { useMemo, useState } from 'react';
import { InfiniteScroll, Link, router } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Switch, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState } from '@/clinic/ui/states';
import { LinkTabs } from '@/clinic/ui/Tabs';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { SettingsTabs, type SettingsLinks } from '../Settings/SettingsTabs';

interface Automation {
  key: string;
  label: string;
  group: string;
  group_label: string;
  enabled: boolean;
  template_ready: boolean;
  sample: string;
}

interface Message {
  id: number;
  direction: 'outbound' | 'inbound';
  automation: string;
  patient: { name: string; uhid: string | null; url: string } | null;
  preview: string | null;
  status: string;
  reason: string | null;
  at: string | null;
}

/** `WhatsAppController::index`: the server is the contract. */
interface Props {
  settings_links: SettingsLinks;
  status: { state: string; label: string; number: string | null; managed_by: string; mode: string };
  settings: { enabled: boolean; mode: 'common' | 'own'; require_opt_in: boolean; own_requested_at: string | null };
  automations: Automation[];
  summary: Record<string, number>;
  messages: { data: Message[] };
  filter: { status: string | null };
  links: { update: string; request_own: string };
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';
const STATE: Record<string, [string, string]> = {
  connected: ['Connected', 'bg-cx-green-bg text-cx-green-ink'],
  pending_activation: ['Waiting for OralCares to activate your number', 'bg-cx-amber-bg text-cx-amber-ink'],
  not_requested: ['Your own number is not requested yet', 'bg-cx-slate-bg text-cx-slate-ink'],
  unavailable: ['The common number is not available right now', 'bg-cx-rose-bg text-cx-rose-ink'],
  not_in_plan: ['WhatsApp is not part of your plan', 'bg-cx-rose-bg text-cx-rose-ink'],
};
const MESSAGE_TONE: Record<string, string> = {
  queued: 'bg-cx-slate-bg text-cx-slate-ink',
  sent: 'bg-cx-blue-bg text-cx-blue-ink',
  delivered: 'bg-cx-green-bg text-cx-green-ink',
  read: 'bg-cx-green-bg text-cx-green-ink',
  failed: 'bg-cx-rose-bg text-cx-rose-ink',
  skipped: 'bg-cx-amber-bg text-cx-amber-ink',
  received: 'bg-cx-blue-bg text-cx-blue-ink',
};
const FILTERS: { key: string; label: string; status: string | null }[] = [
  { key: 'all', label: 'All', status: null },
  { key: 'delivered', label: 'Delivered', status: 'delivered' },
  { key: 'read', label: 'Read', status: 'read' },
  { key: 'failed', label: 'Failed', status: 'failed' },
  { key: 'skipped', label: 'Not sent', status: 'skipped' },
  { key: 'received', label: 'Replies', status: 'received' },
];
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '—');

export default function WhatsAppIndex(props: Props) {
  return (
    <ClinicPortalShell title="WhatsApp">
      <WhatsAppPage {...props} />
    </ClinicPortalShell>
  );
}

/** The clinic's WhatsApp: which patient messages go out, which number they come from, and what happened to every one. The number itself is set up by OralCares. */
function WhatsAppPage({ settings_links, status, settings, automations, summary, messages, filter, links }: Props) {
  const [enabled, setEnabled] = useState(settings.enabled);
  const [mode, setMode] = useState(settings.mode);
  const [optIn, setOptIn] = useState(settings.require_opt_in);
  const [picked, setPicked] = useState<Set<string>>(new Set(automations.filter((a) => a.enabled).map((a) => a.key)));
  const [saving, setSaving] = useState(false);
  const [requesting, setRequesting] = useState(false);
  const initial = useMemo(() => JSON.stringify([settings.enabled, settings.mode, settings.require_opt_in, automations.filter((a) => a.enabled).map((a) => a.key).sort()]), [settings, automations]);
  const dirty = JSON.stringify([enabled, mode, optIn, Array.from(picked).sort()]) !== initial;
  const [stateLabel, stateTone] = STATE[status.state] ?? [humanize(status.state), 'bg-cx-slate-bg text-cx-slate-ink'];
  const groups = useMemo(() => {
    const map = new Map<string, Automation[]>();
    automations.forEach((a) => map.set(a.group_label, [...(map.get(a.group_label) ?? []), a]));

    return Array.from(map.entries());
  }, [automations]);
  const flip = (keys: string[], on: boolean) => setPicked((c) => { const next = new Set(c); keys.forEach((k) => (on ? next.add(k) : next.delete(k))); return next; });
  const sent30 = Object.entries(summary).filter(([k]) => k !== 'received').reduce((n, [, v]) => n + v, 0);

  const save = () =>
    router.put(links.update, { enabled, mode, require_opt_in: optIn, automations: Array.from(picked) } as never, { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) });

  return (
    <div className="mx-auto w-full max-w-[1100px] pb-12">
      <SettingsTabs active="whatsapp" links={settings_links} />
      <PageHeader
        icon={MessageCircle}
        title="WhatsApp"
        description={`Messages to patients · ${sent30} in the last 30 days`}
        actions={<Button tone="primary" onClick={save} loading={saving} disabled={!dirty}>Save changes</Button>}
      />

      <div className="grid gap-4 lg:grid-cols-3">
        <section className={cn(SURFACE, 'space-y-4 p-5 lg:col-span-1')} aria-label="Connection">
          <h2 className="text-[14px] font-semibold text-cx-nav-ink">Connection</h2>
          <span className={cn('inline-block rounded-full px-3 py-1 text-[12px] font-semibold', stateTone)}>{stateLabel}</span>
          <p className="text-[13px] text-cx-text-2">{status.label}{status.number ? ` · ${status.number}` : ''}</p>
          <Switch checked={enabled} onCheckedChange={setEnabled} label="Send patient messages on WhatsApp" description="When off, patients get nothing on WhatsApp." />
          <fieldset className="space-y-2.5">
            <legend className="mb-1 text-[12px] font-semibold text-[#173B60]">Send from</legend>
            <label className="flex cursor-pointer items-start gap-2.5 text-[13px] text-cx-nav-ink"><input type="radio" name="wa-mode" checked={mode === 'common'} onChange={() => setMode('common')} className="mt-1" /><span><span className="font-medium">OralCares common number</span><span className="block text-[12px] text-cx-text-3">Ready to use, shared by clinics.</span></span></label>
            <label className="flex cursor-pointer items-start gap-2.5 text-[13px] text-cx-nav-ink"><input type="radio" name="wa-mode" checked={mode === 'own'} onChange={() => setMode('own')} className="mt-1" /><span><span className="font-medium">Your clinic's own number</span><span className="block text-[12px] text-cx-text-3">OralCares sets it up for you.</span></span></label>
          </fieldset>
          {mode === 'own' && status.state !== 'connected' && (
            settings.own_requested_at ? (
              <Notice tone="info">Requested on {when(settings.own_requested_at)}. OralCares will activate it.</Notice>
            ) : (
              <Button onClick={() => setRequesting(true)}>Ask for our own number</Button>
            )
          )}
          <Switch checked={optIn} onCheckedChange={setOptIn} label="Only message patients who agreed" description="Patients must have given the WhatsApp consent." />
        </section>

        <section className={cn(SURFACE, 'p-5 lg:col-span-2')} aria-label="Messages that go out">
          <header className="mb-4">
            <h2 className="text-[14px] font-semibold text-cx-nav-ink">Messages that go out</h2>
            <p className="text-[12.5px] text-cx-text-3">Tick the messages patients should get. “Not ready” means the message template is still being approved.</p>
          </header>
          {groups.length === 0 ? (
            <p className="text-[13px] text-cx-text-2">No automatic messages are available for your plan.</p>
          ) : (
            <div className="space-y-5">
              {groups.map(([name, rows]) => (
                <fieldset key={name}>
                  <legend className="mb-2 flex w-full items-center justify-between text-[12.5px] font-bold uppercase tracking-[0.06em] text-cx-nav-teal-strong">
                    {name}
                    <button type="button" className="text-[12px] font-semibold normal-case tracking-normal text-[#1769AA] hover:underline" onClick={() => flip(rows.map((r) => r.key), !rows.every((r) => picked.has(r.key)))}>
                      {rows.every((r) => picked.has(r.key)) ? 'Clear' : 'Select all'}
                    </button>
                  </legend>
                  <ul className="divide-y divide-cx-line/60 rounded-xl border border-cx-line/80">
                    {rows.map((a) => (
                      <li key={a.key} className="px-3 py-3">
                        <div className="flex items-start justify-between gap-3">
                          <Checkbox checked={picked.has(a.key)} onCheckedChange={(on) => flip([a.key], on)} label={a.label} description={<span className="mt-1 block whitespace-pre-line rounded-lg bg-[#E7F6EC] px-3 py-2 text-[12px] text-[#1D3B2A]">{a.sample}</span>} />
                          <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold', a.template_ready ? 'bg-cx-green-bg text-cx-green-ink' : 'bg-cx-amber-bg text-cx-amber-ink')}>{a.template_ready ? 'Ready' : 'Not ready'}</span>
                        </div>
                      </li>
                    ))}
                  </ul>
                </fieldset>
              ))}
            </div>
          )}
        </section>
      </div>

      <section className="mt-6" aria-label="Message log">
        <h2 className="mb-3 text-[15px] font-semibold text-cx-nav-ink">What happened to each message</h2>
        <div className="mb-4 flex flex-wrap gap-2">
          {Object.entries(summary).map(([k, n]) => <span key={k} className={cn('rounded-full px-3 py-1 text-[12px] font-semibold', MESSAGE_TONE[k] ?? 'bg-cx-slate-bg text-cx-slate-ink')}>{humanize(k)} {n}</span>)}
        </div>
        <LinkTabs
          label="Message filter"
          className="mb-4"
          tabs={FILTERS.map((f) => ({ key: f.key, label: f.label, active: (filter.status ?? null) === f.status, href: f.status ? `?status=${f.status}` : window.location.pathname }))}
        />
        {messages.data.length === 0 ? (
          <EmptyState title="No messages" description={filter.status ? 'Nothing matches this filter.' : 'No WhatsApp message has been sent yet.'} />
        ) : (
          <div className={SURFACE}>
            <InfiniteScroll data="messages" as="ul" className="divide-y divide-cx-line/60" loading={<p className="px-5 py-4 text-center text-[13px] text-cx-text-2">Loading more…</p>}>
              {messages.data.map((m) => (
                <li key={m.id} className="flex flex-wrap items-start gap-x-4 gap-y-1 px-5 py-3.5">
                  <div className="min-w-0 flex-1 basis-60">
                    <p className="text-[13.5px] font-semibold text-cx-nav-ink">
                      {m.patient ? <Link href={m.patient.url} className="hover:underline">{m.patient.name}</Link> : 'Unknown number'}
                      <span className="ml-2 text-[12px] font-normal text-cx-text-3">{m.direction === 'inbound' ? 'Reply' : m.automation}</span>
                    </p>
                    {m.preview && <p className="mt-0.5 line-clamp-2 text-[12.5px] text-cx-text-2">{m.preview}</p>}
                    {m.reason && <p className="mt-0.5 text-[12px] text-cx-rose-ink">{m.reason}</p>}
                  </div>
                  <span className="text-[12px] text-cx-text-3">{when(m.at)}</span>
                  <span className={cn('rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', MESSAGE_TONE[m.status] ?? 'bg-cx-slate-bg text-cx-slate-ink')}>{humanize(m.status)}</span>
                </li>
              ))}
            </InfiniteScroll>
          </div>
        )}
      </section>

      {requesting && <OwnNumberDialog url={links.request_own} onClose={() => setRequesting(false)} />}
    </div>
  );
}

function OwnNumberDialog({ url, onClose }: { url: string; onClose: () => void }) {
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="sm"
      title="Ask for your own WhatsApp number"
      description="OralCares sets up the number and switches it on for your clinic."
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button tone="primary" loading={busy} onClick={() => router.post(url, { note: note || null } as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose })}>Send request</Button>
        </>
      }
    >
      <Field label="Anything we should know (optional)" htmlFor="wa-note" hint="For example the number you would like to use.">
        <Textarea id="wa-note" value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />
      </Field>
    </Modal>
  );
}
