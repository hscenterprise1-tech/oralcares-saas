import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { BellRing, CalendarX2, MessageCircle, RotateCcw, Save, ShieldCheck, Smile } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Switch } from '@/clinic/ui/controls';
import { BTN_PRIMARY, FOCUS, IconTile, Pill, type Tone } from '../Patients/components/patient360/v2/ui';
import { SettingsTabs, type SettingsLinks } from './SettingsTabs';

type Props = {
  patient: { auto_no_show: boolean; no_show_after_minutes: number; recall_months: number; recall_choices: number[]; staff_alerts: boolean; morning_digest: boolean; auto_assign_doctor: boolean };
  whatsapp: {
    enabled: boolean;
    require_opt_in: boolean;
    status: { state: string; label: string; number: string | null };
    automations: { key: string; label: string; group: string; enabled: boolean }[];
  };
  links: { update: string; patient_form: string; self: string; physio: string | null };
  settings_links: SettingsLinks;
};

export default function AutomationSettings(props: Props) {
  return (
    <ClinicPortalShell title="Automation & messages">
      <AutomationPage {...props} />
    </ClinicPortalShell>
  );
}

const GROUP_TONE: Tone[] = ['blue', 'teal', 'violet', 'amber', 'mint', 'rose'];

function Panel({ icon, tone, title, caption, children }: { icon: typeof Smile; tone: Tone; title: string; caption: string; children: React.ReactNode }) {
  return (
    <section className="h-full rounded-[22px] border border-white/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_10px_28px_-18px_rgba(30,50,110,0.28)] sm:p-6">
      <header className="mb-4 flex items-start gap-3">
        <IconTile icon={icon} tone={tone} />
        <div>
          <h2 className="pt-0.5 text-[15px] font-semibold text-cx-nav-ink">{title}</h2>
          <p className="mt-0.5 text-[12.5px] text-cx-text-2">{caption}</p>
        </div>
      </header>
      {children}
    </section>
  );
}

/**
 * The owner's automation settings: what runs by itself for patients and
 * which WhatsApp messages go out. One Save for the page; the server checks
 * the permission and every value again.
 */
function AutomationPage({ patient, whatsapp, links, settings_links }: Props) {
  const initial = useMemo(
    () => ({
      auto_no_show: patient.auto_no_show,
      no_show_after_minutes: patient.no_show_after_minutes,
      recall_months: patient.recall_months,
      staff_alerts: patient.staff_alerts,
      morning_digest: patient.morning_digest,
      auto_assign_doctor: patient.auto_assign_doctor,
      whatsapp_enabled: whatsapp.enabled,
      require_opt_in: whatsapp.require_opt_in,
      automations: whatsapp.automations.filter((item) => item.enabled).map((item) => item.key),
    }),
    [patient, whatsapp],
  );
  const [form, setForm] = useState(initial);
  const [saving, setSaving] = useState(false);
  const dirty = JSON.stringify(form) !== JSON.stringify(initial);
  const groups = useMemo(() => {
    const map = new Map<string, Props['whatsapp']['automations']>();
    whatsapp.automations.forEach((item) => map.set(item.group, [...(map.get(item.group) ?? []), item]));

    return Array.from(map.entries());
  }, [whatsapp.automations]);
  const toggleMessage = (key: string, on: boolean) => setForm((current) => ({ ...current, automations: on ? [...current.automations, key] : current.automations.filter((item) => item !== key) }));

  const save = () =>
    router.put(links.update, form as never, {
      preserveScroll: true,
      onStart: () => setSaving(true),
      onFinish: () => setSaving(false),
    });

  return (
    <div className="mx-auto w-full max-w-[1200px] pb-24 lg:pb-8">
      <SettingsTabs active="automation" links={settings_links} />

      <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-[12px] font-semibold uppercase tracking-[0.06em] text-cx-teal-ink">Settings</p>
          <h1 className="text-[22px] font-semibold tracking-[-0.02em] text-[#1D2E4A]">Automation & messages</h1>
          <p className="text-[13px] text-cx-text-2">What the clinic does by itself for patients, and the WhatsApp messages they receive.</p>
        </div>
        <div className="hidden items-center gap-2 lg:flex">
          {dirty && (
            <button type="button" onClick={() => setForm(initial)} className={cn('inline-flex h-10 items-center gap-1.5 rounded-full px-3 text-[13px] font-semibold text-cx-text-2 hover:text-cx-nav-ink', FOCUS)}>
              <RotateCcw className="h-4 w-4" aria-hidden="true" /> Undo
            </button>
          )}
          <button type="button" className={BTN_PRIMARY} disabled={!dirty || saving} onClick={save}>
            <Save className="h-4 w-4" aria-hidden="true" /> {saving ? 'Saving…' : 'Save changes'}
          </button>
        </div>
      </div>

      <div className="grid items-stretch gap-5 lg:grid-cols-2">
        <Panel icon={CalendarX2} tone="rose" title="Missed appointments" caption="A booking nobody checked in for is marked a no-show by itself, and the patient gets the missed-appointment message.">
          <Switch checked={form.auto_no_show} onCheckedChange={(on) => setForm((current) => ({ ...current, auto_no_show: on }))} label="Mark missed bookings automatically" />
          <label className="mt-4 block text-[12.5px] font-semibold text-cx-nav-ink" htmlFor="no-show-after">
            Mark as missed after
          </label>
          <div className="mt-1.5 flex items-center gap-2">
            <input
              id="no-show-after"
              type="number"
              min={5}
              max={240}
              step={5}
              disabled={!form.auto_no_show}
              value={form.no_show_after_minutes}
              onChange={(e) => setForm((current) => ({ ...current, no_show_after_minutes: Number(e.target.value) }))}
              className="h-11 w-28 rounded-xl border border-[#DCE6F3] bg-white px-3 text-[14px] text-cx-nav-ink disabled:opacity-60"
            />
            <span className="text-[13px] text-cx-text-2">minutes past the booked time</span>
          </div>
        </Panel>

        <Panel icon={Smile} tone="blue" title="Dental recall" caption="When a dental treatment plan is completed, a routine check-up follow-up is booked; its reminders go out like any follow-up.">
          <p className="mb-2 text-[12.5px] font-semibold text-cx-nav-ink">Book the recall after</p>
          <div role="radiogroup" aria-label="Recall interval" className="flex flex-wrap gap-2">
            {patient.recall_choices.map((months) => (
              <button
                key={months}
                type="button"
                role="radio"
                aria-checked={form.recall_months === months}
                onClick={() => setForm((current) => ({ ...current, recall_months: months }))}
                className={cn('h-10 rounded-full border px-4 text-[13px] font-semibold transition-colors', FOCUS, form.recall_months === months ? 'border-transparent bg-[#1769AA] text-white' : 'border-[#DCE6F3] bg-white text-cx-text-2 hover:border-[#B9C6D8]')}
              >
                {months === 0 ? 'Off' : `${months} months`}
              </button>
            ))}
          </div>
        </Panel>
      </div>

      <div className="mt-5">
        <Panel icon={BellRing} tone="violet" title="Staff reminders" caption="Each evening, the people who own the work are told what is still open: unsigned consultations, results waiting for review, overdue lab cases, dental plans with no work for a month, and cases with no visit for 45 days (a suggestion only — nothing closes by itself).">
          <div className="space-y-3">
            <Switch checked={form.staff_alerts} onCheckedChange={(on) => setForm((current) => ({ ...current, staff_alerts: on }))} label="Evening reminders of open work" />
            <Switch checked={form.morning_digest} onCheckedChange={(on) => setForm((current) => ({ ...current, morning_digest: on }))} label="Morning summary — each doctor's day, and bookings, money owed and cases to close for the owner" />
            <Switch checked={form.auto_assign_doctor} onCheckedChange={(on) => setForm((current) => ({ ...current, auto_assign_doctor: on }))} label="Assign a patient with no doctor to the doctor of their first consultation — after that, only that doctor can write in their record" />
          </div>
        </Panel>
      </div>

      <div className="mt-5">
        <Panel icon={MessageCircle} tone="teal" title="WhatsApp messages" caption="Which messages patients receive. A message goes out only when WhatsApp is on and that message is ticked.">
          <div className="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-2xl bg-cx-teal-bg/40 p-4">
            <Switch checked={form.whatsapp_enabled} onCheckedChange={(on) => setForm((current) => ({ ...current, whatsapp_enabled: on }))} label="Send WhatsApp messages" />
            <Switch checked={form.require_opt_in} onCheckedChange={(on) => setForm((current) => ({ ...current, require_opt_in: on }))} label="Only to patients who agreed" />
            <Pill tone={whatsapp.status.state === 'connected' ? 'mint' : 'amber'} className="ml-auto">
              <ShieldCheck className="h-3 w-3" aria-hidden="true" /> {whatsapp.status.label}
              {whatsapp.status.number ? ` · ${whatsapp.status.number}` : ''}
            </Pill>
          </div>
          <div className={cn('mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3', !form.whatsapp_enabled && 'opacity-60')}>
            {groups.map(([group, items], index) => (
              <div key={group} className="rounded-2xl border border-[#EDF1F7] bg-[#FBFCFE] p-4">
                <p className="mb-2 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-[0.06em] text-cx-text-2">
                  <span className={cn('h-2 w-2 rounded-full', ['bg-[#2B6FD8]', 'bg-[#0E9F87]', 'bg-[#6B48DE]', 'bg-[#E9821A]', 'bg-[#12A877]', 'bg-[#E5484D]'][index % GROUP_TONE.length])} aria-hidden="true" />
                  {group}
                </p>
                <ul className="space-y-2">
                  {items.map((item) => (
                    <li key={item.key}>
                      <Switch checked={form.automations.includes(item.key)} disabled={!form.whatsapp_enabled} onCheckedChange={(on) => toggleMessage(item.key, on)} label={item.label} />
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </Panel>
      </div>

      <div className="fixed inset-x-0 bottom-0 z-30 flex items-center justify-end gap-3 border-t border-[#E6EBF1] bg-white/95 px-4 py-3 backdrop-blur lg:hidden">
        <button type="button" className={cn(BTN_PRIMARY, 'flex-1')} disabled={!dirty || saving} onClick={save}>
          <Save className="h-4 w-4" aria-hidden="true" /> {saving ? 'Saving…' : 'Save changes'}
        </button>
      </div>
    </div>
  );
}
