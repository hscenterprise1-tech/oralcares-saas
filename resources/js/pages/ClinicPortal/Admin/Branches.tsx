import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Building2, MapPin, Pencil, Phone, Plus, Trash2 } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Input, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState } from '@/clinic/ui/states';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { InfraTabs, keyOf, type InfraLinks } from './components/InfraTabs';

interface Hour {
  day_of_week: number;
  open_time: string;
  close_time: string;
  is_closed: boolean;
}

interface Branch {
  id: number;
  name: string;
  slug: string;
  status: string;
  is_main: boolean;
  address_line1: string | null;
  address_line2: string | null;
  city: string | null;
  state: string | null;
  pincode: string | null;
  phone: string | null;
  whatsapp_number: string | null;
  resource_capacity: number | null;
  gstin: string | null;
  clinical_establishment_registration_number: string | null;
  display_name: string | null;
  operating_hours: Hour[];
  today_hours_label: string;
}

/** `BranchController::index`: the server is the contract. */
interface Props {
  branches: Branch[];
  branchStatuses: string[];
  branchLimit: { current: number; allowed: number | null };
  links: InfraLinks;
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';
const DAYS = [1, 2, 3, 4, 5, 6, 0];
const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const STATUS_TONE: Record<string, string> = {
  active: 'bg-cx-green-bg text-cx-green-ink',
  inactive: 'bg-cx-slate-bg text-cx-slate-ink',
  suspended: 'bg-cx-rose-bg text-cx-rose-ink',
};

export default function Branches(props: Props) {
  return (
    <ClinicPortalShell title="Branches">
      <BranchesPage {...props} />
    </ClinicPortalShell>
  );
}

/** The clinic's locations: address, contacts, registration and opening hours of each. */
function BranchesPage({ branches, branchStatuses, branchLimit, links }: Props) {
  const [editing, setEditing] = useState<Branch | 'new' | null>(null);
  const [removing, setRemoving] = useState<Branch | null>(null);
  const [busy, setBusy] = useState(false);
  const atLimit = branchLimit.allowed !== null && branchLimit.current >= branchLimit.allowed;

  return (
    <div className="w-full pb-12">
      <PageHeader
        icon={Building2}
        title="Branches"
        description={branchLimit.allowed !== null ? `${branchLimit.current} of ${branchLimit.allowed} branches in your plan` : `${branchLimit.current} ${branchLimit.current === 1 ? 'branch' : 'branches'}`}
        actions={<Button tone="primary" onClick={() => setEditing('new')} disabled={atLimit}><Plus className="h-4 w-4" aria-hidden="true" /> New branch</Button>}
      />
      <InfraTabs active="branches" links={links} />
      {atLimit && <Notice tone="warning" className="mb-4">Your plan has no room for another branch. Ask OralCares to upgrade it.</Notice>}

      {branches.length === 0 ? (
        <EmptyState title="No branches yet" description="Add your first branch." action={<Button tone="primary" onClick={() => setEditing('new')}>New branch</Button>} />
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {branches.map((branch) => (
            <article key={branch.id} className={cn(SURFACE, 'p-5')}>
              <header className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <h2 className="truncate text-[15px] font-semibold text-cx-nav-ink">{branch.name}</h2>
                  <div className="mt-1.5 flex flex-wrap gap-1.5">
                    {branch.is_main && <span className="rounded-full bg-cx-blue-bg px-2.5 py-0.5 text-[11.5px] font-semibold text-cx-blue-ink">Main branch</span>}
                    <span className={cn('rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', STATUS_TONE[branch.status] ?? STATUS_TONE.inactive)}>{humanize(branch.status)}</span>
                  </div>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                  <button type="button" aria-label={`Edit ${branch.name}`} onClick={() => setEditing(branch)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-slate-bg hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35">
                    <Pencil className="h-4 w-4" aria-hidden="true" />
                  </button>
                  {!branch.is_main && (
                    <button type="button" aria-label={`Delete ${branch.name}`} onClick={() => setRemoving(branch)} className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35">
                      <Trash2 className="h-4 w-4" aria-hidden="true" />
                    </button>
                  )}
                </div>
              </header>
              <dl className="mt-4 space-y-2 text-[13px] text-cx-text-2">
                <div className="flex gap-2"><MapPin className="mt-0.5 h-4 w-4 shrink-0 text-cx-text-3" aria-hidden="true" /><dd>{[branch.address_line1, branch.address_line2, branch.city, branch.state, branch.pincode].filter(Boolean).join(', ') || 'No address'}</dd></div>
                <div className="flex gap-2"><Phone className="mt-0.5 h-4 w-4 shrink-0 text-cx-text-3" aria-hidden="true" /><dd>{branch.phone ?? 'No phone'}</dd></div>
                <p className="pl-6 text-[12.5px] text-cx-text-3">Today: {branch.today_hours_label}</p>
              </dl>
            </article>
          ))}
        </div>
      )}

      {editing && <BranchDialog branch={editing === 'new' ? null : editing} statuses={branchStatuses} base={links.branches} onClose={() => setEditing(null)} />}
      {removing && (
        <Modal
          open
          onOpenChange={(open) => !open && setRemoving(null)}
          size="sm"
          title={`Delete ${removing.name}?`}
          description="A branch that has seen patients cannot be deleted; switch it to inactive instead."
          footer={
            <>
              <Button onClick={() => setRemoving(null)} disabled={busy}>Cancel</Button>
              <Button tone="danger" loading={busy} onClick={() => router.delete(`${links.branches}/${removing.id}`, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setRemoving(null); } })}>Delete</Button>
            </>
          }
        >
          <p className="text-[13px] text-cx-text-2">This cannot be undone.</p>
        </Modal>
      )}
    </div>
  );
}

function BranchDialog({ branch, statuses, base, onClose }: { branch: Branch | null; statuses: string[]; base: string; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const text = (key: keyof Branch) => String((branch?.[key] as string | number | null | undefined) ?? '');
  const [f, setF] = useState({
    name: text('name'), slug: text('slug'), status: branch?.status ?? 'active', is_main: branch?.is_main ?? false,
    address_line1: text('address_line1'), address_line2: text('address_line2'), city: text('city'), state: text('state'), pincode: text('pincode'),
    phone: text('phone'), whatsapp_number: text('whatsapp_number'), gstin: text('gstin'),
    clinical_establishment_registration_number: text('clinical_establishment_registration_number'), display_name: text('display_name'),
  });
  const [hours, setHours] = useState<Hour[]>(() =>
    DAYS.map((d) => branch?.operating_hours.find((h) => h.day_of_week === d) ?? { day_of_week: d, open_time: '09:00', close_time: '18:00', is_closed: d === 0 }),
  );
  const [busy, setBusy] = useState(false);
  const set = (key: keyof typeof f, value: string | boolean) => setF((c) => ({ ...c, [key]: value }));
  const setHour = (day: number, patch: Partial<Hour>) => setHours((c) => c.map((h) => (h.day_of_week === day ? { ...h, ...patch } : h)));

  const submit = () => {
    const blank = (value: string) => (value.trim() === '' ? null : value.trim());
    const data = {
      ...f,
      name: f.name.trim(),
      slug: f.slug.trim() || keyOf(f.name),
      address_line1: blank(f.address_line1), address_line2: blank(f.address_line2), city: blank(f.city), state: blank(f.state), pincode: blank(f.pincode),
      phone: blank(f.phone), whatsapp_number: blank(f.whatsapp_number), gstin: blank(f.gstin),
      clinical_establishment_registration_number: blank(f.clinical_establishment_registration_number), display_name: blank(f.display_name),
      operating_hours: hours,
    };
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose };
    if (branch) router.put(`${base}/${branch.id}`, data as never, options);
    else router.post(base, data as never, options);
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="xl"
      title={branch ? `Edit ${branch.name}` : 'New branch'}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button tone="primary" loading={busy} disabled={f.name.trim() === ''} onClick={submit}>{branch ? 'Save changes' : 'Add branch'}</Button>
        </>
      }
    >
      <div className="space-y-6">
        {Object.keys(errors ?? {}).length > 0 && <Notice tone="danger">{Object.values(errors)[0]}</Notice>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Branch name" htmlFor="br-name" required error={errors.name}><Input id="br-name" value={f.name} maxLength={255} onChange={(e) => set('name', e.target.value)} /></Field>
          <Field label="Status" htmlFor="br-status"><Select id="br-status" value={f.status} onChange={(e) => set('status', e.target.value)} options={statuses.map((s) => ({ value: s, label: humanize(s) }))} /></Field>
          <Field label="Name shown to patients" htmlFor="br-display" hint="If different from the clinic name." error={errors.display_name}><Input id="br-display" value={f.display_name} maxLength={255} onChange={(e) => set('display_name', e.target.value)} /></Field>
          <Field label="Short key" htmlFor="br-slug" error={errors.slug} hint="Unique. Leave empty to make one from the name."><Input id="br-slug" value={f.slug} maxLength={255} onChange={(e) => set('slug', e.target.value)} /></Field>
        </div>
        <Checkbox checked={f.is_main} onCheckedChange={(on) => set('is_main', on)} label="This is the main branch" description="Only one branch is the main branch." />

        <fieldset>
          <legend className="mb-3 text-[13px] font-semibold text-cx-nav-ink">Address and contact</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Address line 1" htmlFor="br-a1" error={errors.address_line1}><Input id="br-a1" value={f.address_line1} onChange={(e) => set('address_line1', e.target.value)} /></Field>
            <Field label="Address line 2" htmlFor="br-a2"><Input id="br-a2" value={f.address_line2} onChange={(e) => set('address_line2', e.target.value)} /></Field>
            <Field label="City" htmlFor="br-city"><Input id="br-city" value={f.city} onChange={(e) => set('city', e.target.value)} /></Field>
            <Field label="State" htmlFor="br-state"><Input id="br-state" value={f.state} onChange={(e) => set('state', e.target.value)} /></Field>
            <Field label="Pincode" htmlFor="br-pin" error={errors.pincode}><Input id="br-pin" value={f.pincode} maxLength={6} inputMode="numeric" onChange={(e) => set('pincode', e.target.value)} /></Field>
            <Field label="Phone" htmlFor="br-phone" error={errors.phone}><Input id="br-phone" type="tel" value={f.phone} maxLength={20} onChange={(e) => set('phone', e.target.value)} /></Field>
            <Field label="WhatsApp number" htmlFor="br-wa" error={errors.whatsapp_number}><Input id="br-wa" type="tel" value={f.whatsapp_number} maxLength={20} onChange={(e) => set('whatsapp_number', e.target.value)} /></Field>
          </div>
        </fieldset>

        <fieldset>
          <legend className="mb-3 text-[13px] font-semibold text-cx-nav-ink">Registration</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="GSTIN" htmlFor="br-gst" error={errors.gstin}><Input id="br-gst" value={f.gstin} maxLength={15} onChange={(e) => set('gstin', e.target.value.toUpperCase())} /></Field>
            <Field label="Clinical establishment registration no." htmlFor="br-cer"><Input id="br-cer" value={f.clinical_establishment_registration_number} onChange={(e) => set('clinical_establishment_registration_number', e.target.value)} /></Field>
          </div>
        </fieldset>

        <fieldset>
          <legend className="mb-3 text-[13px] font-semibold text-cx-nav-ink">Opening hours</legend>
          <ul className="divide-y divide-cx-line/60 rounded-xl border border-cx-line/80">
            {hours.map((h) => (
              <li key={h.day_of_week} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5">
                <span className="w-24 text-[13.5px] font-semibold text-cx-nav-ink">{DAY_NAMES[h.day_of_week]}</span>
                <Checkbox checked={!h.is_closed} onCheckedChange={(open) => setHour(h.day_of_week, { is_closed: !open })} label="Open" />
                {!h.is_closed && (
                  <div className="ml-auto flex items-center gap-2">
                    <Input aria-label={`${DAY_NAMES[h.day_of_week]} opens`} type="time" value={h.open_time} onChange={(e) => setHour(h.day_of_week, { open_time: e.target.value })} className="w-32" />
                    <span className="text-cx-text-3">to</span>
                    <Input aria-label={`${DAY_NAMES[h.day_of_week]} closes`} type="time" value={h.close_time} onChange={(e) => setHour(h.day_of_week, { close_time: e.target.value })} className="w-32" />
                  </div>
                )}
              </li>
            ))}
          </ul>
        </fieldset>
      </div>
    </Modal>
  );
}
