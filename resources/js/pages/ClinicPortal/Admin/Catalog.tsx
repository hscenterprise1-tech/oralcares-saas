import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Library, Pencil, Plus, Search, Tags } from 'lucide-react';
import { requestJson } from '@/clinic/http';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Input, Select, Switch } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState } from '@/clinic/ui/states';
import { PillTabs } from '@/clinic/ui/Tabs';
import { toast } from '@/clinic/ui/Toast';
import { formatMoney } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { problem } from './components/api';
import { TreatmentsTab } from './components/TreatmentsTab';

interface Service {
  id: number;
  code: string;
  name: string;
  category: string | null;
  duration: number | null;
  bookable: boolean;
  billable: boolean;
  active: boolean;
  price: number | null;
  specialty: string | null;
  /** The clinic's own setting, or null when the standard for this kind of service applies. */
  sittings: { visits: number; step: number } | null;
  usual_sittings: { visits: number; step: number };
  update_url: string;
  active_url: string;
}

interface LibraryItem {
  code: string;
  name: string;
  category: string;
  duration: number;
  price: number;
  specialty: string | null;
}

/** `CatalogPageController::index`: the server is the contract. */
interface Props {
  specialties: { key: string; label: string; targets: { key: string; label: string }[] }[];
  treatment_types: string[];
  charging_models: string[];
  services: Service[];
  library: LibraryItem[];
  api: { library: string; procedures: string; treatments: string; prices: string };
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';

export default function Catalog(props: Props) {
  return (
    <ClinicPortalShell title="Services">
      <CatalogPage {...props} />
    </ClinicPortalShell>
  );
}

/**
 * Services & prices: everything the clinic can do and charge for. The owner
 * switches each service on or off, sets its price and usual time, adds their
 * own, or adds from the ready-made list for the clinic's specialty. A service
 * switched off is never offered for booking or billing, and nothing already
 * recorded for a patient changes.
 */
function CatalogPage({ specialties, treatment_types, charging_models, services, library, api }: Props) {
  const [tab, setTab] = useState<'services' | 'treatments'>('services');
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [status, setStatus] = useState<'all' | 'on' | 'off'>('all');
  const [editing, setEditing] = useState<Service | 'new' | null>(null);
  const [picking, setPicking] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const categories = useMemo(() => Array.from(new Set(services.map((s) => s.category ?? 'Other'))).sort(), [services]);
  const shown = services.filter(
    (s) =>
      (category === '' || (s.category ?? 'Other') === category) &&
      (status === 'all' || (status === 'on') === s.active) &&
      (search.trim() === '' || `${s.name} ${s.code}`.toLowerCase().includes(search.trim().toLowerCase())),
  );
  const grouped = useMemo(() => {
    const map = new Map<string, Service[]>();
    shown.forEach((s) => map.set(s.category ?? 'Other', [...(map.get(s.category ?? 'Other') ?? []), s]));

    return Array.from(map.entries()).sort(([a], [b]) => a.localeCompare(b));
  }, [shown]);
  const onCount = services.filter((s) => s.active).length;
  const unpriced = services.filter((s) => s.active && s.price === null).length;

  const refresh = () => router.reload({ only: ['services', 'library'] });

  const toggle = async (service: Service, active: boolean) => {
    setBusyId(service.id);
    try {
      await requestJson('POST', service.active_url, { body: { active } });
      refresh();
    } catch (error) {
      toast.error('Could not change this service', problem(error));
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="w-full pb-12">
      <PageHeader
        icon={Tags}
        title="Services & prices"
        description={`${onCount} of ${services.length} services switched on${unpriced > 0 ? ` · ${unpriced} without a price` : ''}`}
        actions={
          tab !== 'services' ? undefined : (
          <>
            {library.length > 0 && (
              <Button onClick={() => setPicking(true)}>
                <Library className="h-4 w-4" aria-hidden="true" /> Add from ready-made list ({library.length})
              </Button>
            )}
            <Button tone="primary" onClick={() => setEditing('new')}>
              <Plus className="h-4 w-4" aria-hidden="true" /> New service
            </Button>
          </>
          )
        }
      />

      <PillTabs label="Catalogue" className="mb-4" value={tab} onChange={setTab} options={[{ key: 'services', label: 'Services & prices', count: services.length }, { key: 'treatments', label: 'Treatments & stages' }]} />

      {tab === 'treatments' ? (
        <TreatmentsTab specialties={specialties} treatment_types={treatment_types} charging_models={charging_models} services={services} api={api} />
      ) : (
      <>

      {unpriced > 0 && (
        <Notice tone="warning" className="mb-4">
          {unpriced} {unpriced === 1 ? 'service is' : 'services are'} switched on without a price. Reception cannot bill {unpriced === 1 ? 'it' : 'them'} until a price is set.
        </Notice>
      )}

      <div className={cn(SURFACE, 'mb-4 flex flex-wrap items-end gap-3 p-4')}>
        <div className="relative min-w-[220px] flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-cx-text-3" aria-hidden="true" />
          <Input aria-label="Search services" placeholder="Search by name or code" value={search} onChange={(event) => setSearch(event.target.value)} className="pl-9" />
        </div>
        <div className="w-full sm:w-52">
          <Select
            aria-label="Category"
            value={category}
            onChange={(event) => setCategory(event.target.value)}
            options={[{ value: '', label: 'All categories' }, ...categories.map((c) => ({ value: c, label: c }))]}
          />
        </div>
        <div className="w-full sm:w-40">
          <Select
            aria-label="Status"
            value={status}
            onChange={(event) => setStatus(event.target.value as 'all' | 'on' | 'off')}
            options={[
              { value: 'all', label: 'All' },
              { value: 'on', label: 'Switched on' },
              { value: 'off', label: 'Switched off' },
            ]}
          />
        </div>
      </div>

      {services.length === 0 ? (
        <EmptyState
          title="No services yet"
          description="Add the ready-made list for your specialty and switch off what you do not offer, or create your own services."
          action={library.length > 0 ? <Button tone="primary" onClick={() => setPicking(true)}>Add from ready-made list</Button> : undefined}
        />
      ) : grouped.length === 0 ? (
        <EmptyState title="Nothing matches" description="Change the search or the filters." />
      ) : (
        <div className="space-y-4">
          {grouped.map(([name, rows]) => (
            <section key={name} className={SURFACE} aria-label={name}>
              <header className="flex items-center justify-between gap-3 border-b border-cx-line/70 px-5 py-3">
                <h2 className="text-[14px] font-semibold text-cx-nav-ink">{name}</h2>
                <span className="text-[12px] text-cx-text-3">{rows.filter((r) => r.active).length} of {rows.length} on</span>
              </header>
              <ul className="divide-y divide-cx-line/60">
                {rows.map((service) => (
                  <li key={service.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
                    <div className="min-w-0 flex-1 basis-56">
                      <p className={cn('truncate text-[14px] font-semibold', service.active ? 'text-cx-nav-ink' : 'text-cx-text-3')}>{service.name}</p>
                      <p className="text-[12px] text-cx-text-3">
                        {service.code}
                        {service.duration ? ` · ${service.duration} min` : ''}
                        {!service.bookable ? ' · not bookable' : ''}
                      </p>
                    </div>
                    <p className={cn('w-28 text-right text-[14px] font-semibold tabular-nums', service.price === null && service.active ? 'text-cx-amber-ink' : 'text-cx-nav-ink')}>
                      {service.price === null ? 'No price' : formatMoney(service.price)}
                    </p>
                    <div className="flex items-center gap-2">
                      <Switch
                        aria-label={`${service.name} switched ${service.active ? 'on' : 'off'}`}
                        checked={service.active}
                        disabled={busyId === service.id}
                        onCheckedChange={(next) => toggle(service, next)}
                      />
                      <button
                        type="button"
                        aria-label={`Edit ${service.name}`}
                        onClick={() => setEditing(service)}
                        className="grid h-9 w-9 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-slate-bg hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35"
                      >
                        <Pencil className="h-4 w-4" aria-hidden="true" />
                      </button>
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
      </>
      )}

      {editing && <ServiceDialog service={editing === 'new' ? null : editing} categories={categories} api={api} onClose={() => setEditing(null)} onSaved={refresh} />}
      {picking && <LibraryDialog items={library} url={api.library} onClose={() => setPicking(false)} onSaved={refresh} />}
    </div>
  );
}

/** Add one service, or change one: its name, category, usual time and price. */
function ServiceDialog({
  service,
  categories,
  api,
  onClose,
  onSaved,
}: {
  service: Service | null;
  categories: string[];
  api: Props['api'];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(service?.name ?? '');
  const [code, setCode] = useState(service?.code ?? '');
  const [category, setCategory] = useState(service?.category ?? '');
  const [duration, setDuration] = useState(String(service?.duration ?? 30));
  const [price, setPrice] = useState(service?.price === null || service === null ? '' : String(service.price));
  const [bookable, setBookable] = useState(service?.bookable ?? true);
  const usual = service?.sittings ?? service?.usual_sittings ?? { visits: 1, step: 7 };
  const [visits, setVisits] = useState(String(usual.visits));
  const [step, setStep] = useState(String(usual.step));
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      const generated = code.trim() || `SVC-${name.trim().replace(/[^A-Za-z0-9]+/g, '').slice(0, 6).toUpperCase()}${Math.floor(Math.random() * 90 + 10)}`;
      const body = { code: generated, name: name.trim(), category: category.trim() || 'Other', default_duration_minutes: Number(duration) || 30,
        is_bookable: bookable,
        sittings: { visits: Math.max(1, Number(visits) || 1), step: Math.max(1, Number(step) || 7) },
      };
      const saved = service
        ? await requestJson<{ data: { id: number } }>('PUT', service.update_url, { body })
        : await requestJson<{ data: { id: number } }>('POST', api.procedures, { body });
      if (price.trim() !== '' && Number(price) !== service?.price) {
        await requestJson('POST', api.prices, { body: { target: 'clinical_procedure_id', target_id: saved.data.id, amount: Number(price) } });
      }
      toast.success(service ? 'Service updated' : 'Service added');
      onSaved();
      onClose();
    } catch (failure) {
      setError(problem(failure));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      title={service ? 'Edit service' : 'New service'}
      description="The price is the clinic-wide price. A branch can have its own price later."
      footer={
        <>
          <Button onClick={onClose} disabled={saving}>Cancel</Button>
          <Button tone="primary" onClick={save} loading={saving} disabled={name.trim() === ''}>
            {service ? 'Save changes' : 'Add service'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Notice tone="danger">{error}</Notice>}
        <Field label="Service name" htmlFor="svc-name" required>
          <Input id="svc-name" value={name} onChange={(event) => setName(event.target.value)} maxLength={160} placeholder="e.g. Root canal treatment" />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Category" htmlFor="svc-category">
            <Input id="svc-category" list="svc-categories" value={category} onChange={(event) => setCategory(event.target.value)} maxLength={80} placeholder="e.g. Endodontics" />
            <datalist id="svc-categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
          </Field>
          <Field label="Code" htmlFor="svc-code" hint={service ? undefined : 'Leave empty to generate one.'}>
            <Input id="svc-code" value={code} onChange={(event) => setCode(event.target.value)} maxLength={40} />
          </Field>
          <Field label="Price (₹)" htmlFor="svc-price">
            <Input id="svc-price" type="number" min={0} step={50} value={price} onChange={(event) => setPrice(event.target.value)} />
          </Field>
          <Field label="Usual time (minutes)" htmlFor="svc-duration">
            <Input id="svc-duration" type="number" min={5} max={480} value={duration} onChange={(event) => setDuration(event.target.value)} />
          </Field>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Visits it usually takes" htmlFor="svc-visits" hint="The next visits are planned from this.">
            <Input id="svc-visits" type="number" min={1} max={30} value={visits} onChange={(event) => setVisits(event.target.value)} />
          </Field>
          <Field label="Days between visits" htmlFor="svc-step">
            <Input id="svc-step" type="number" min={1} max={365} value={step} onChange={(event) => setStep(event.target.value)} />
          </Field>
        </div>
        <Switch checked={bookable} onCheckedChange={setBookable} label="Can be booked as an appointment" />
      </div>
    </Modal>
  );
}

/** Pick which ready-made services to add. Nothing is added unless ticked; prices are starting prices to edit after. */
function LibraryDialog({ items, url, onClose, onSaved }: { items: LibraryItem[]; url: string; onClose: () => void; onSaved: () => void }) {
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const byCategory = useMemo(() => {
    const map = new Map<string, LibraryItem[]>();
    items.forEach((item) => map.set(item.category, [...(map.get(item.category) ?? []), item]));

    return Array.from(map.entries());
  }, [items]);

  const flip = (codes: string[], on: boolean) =>
    setPicked((current) => {
      const next = new Set(current);
      codes.forEach((code) => (on ? next.add(code) : next.delete(code)));

      return next;
    });

  const add = async () => {
    setSaving(true);
    setError(null);
    try {
      const result = await requestJson<{ data: { added: number } }>('POST', url, { body: { codes: Array.from(picked) } });
      toast.success(`${result.data.added} services added`, 'Check each price before you switch them on at the desk.');
      onSaved();
      onClose();
    } catch (failure) {
      setError(problem(failure));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="lg"
      title="Ready-made services"
      description="Tick what your clinic offers. The prices are only a starting point; you set your own after adding."
      footer={
        <>
          <Button onClick={onClose} disabled={saving}>Cancel</Button>
          <Button tone="primary" onClick={add} loading={saving} disabled={picked.size === 0}>
            Add {picked.size > 0 ? picked.size : ''} {picked.size === 1 ? 'service' : 'services'}
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Notice tone="danger">{error}</Notice>}
        <div className="flex gap-2">
          <Button size="sm" onClick={() => flip(items.map((i) => i.code), true)}>Select all</Button>
          <Button size="sm" onClick={() => flip(items.map((i) => i.code), false)} disabled={picked.size === 0}>Clear</Button>
        </div>
        {byCategory.map(([name, rows]) => {
          const all = rows.every((row) => picked.has(row.code));

          return (
            <fieldset key={name}>
              <legend className="mb-2 flex w-full items-center justify-between text-[12.5px] font-bold uppercase tracking-[0.06em] text-cx-nav-teal-strong">
                {name}
                <button type="button" className="text-[12px] font-semibold normal-case tracking-normal text-[#1769AA] hover:underline" onClick={() => flip(rows.map((r) => r.code), !all)}>
                  {all ? 'Clear' : 'Select all'}
                </button>
              </legend>
              <ul className="divide-y divide-cx-line/60 rounded-xl border border-cx-line/80">
                {rows.map((row) => (
                  <li key={row.code} className="flex items-center gap-3 px-3 py-2.5">
                    <Checkbox checked={picked.has(row.code)} onCheckedChange={(on) => flip([row.code], Boolean(on))} label={row.name} />
                    <span className="ml-auto whitespace-nowrap text-[12.5px] tabular-nums text-cx-text-2">{row.duration} min · {formatMoney(row.price)}</span>
                  </li>
                ))}
              </ul>
            </fieldset>
          );
        })}
      </div>
    </Modal>
  );
}
